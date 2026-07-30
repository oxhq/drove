use crate::protocol::{
    decode_available, monotonic_ns, write_frame, Frame, ProtocolError, Validator, MAX_BUFFER_BYTES,
    MAX_STREAM_BYTES,
};
use base64::engine::general_purpose::STANDARD;
use base64::Engine as _;
use serde::Serialize;
use serde_json::{json, Value};
use std::collections::hash_map::Entry;
use std::collections::{HashMap, HashSet, VecDeque};
use std::io;
use std::os::fd::RawFd;
use std::time::Duration;

const GLOBAL_POOL: &str = "@global";
const ARMED_POOL: &str = "@armed";
const MAX_QUEUE_CAPACITY: usize = 1_000_000;
const TOPOLOGY_SCHEMA: u32 = 1;
const NESTED_GROUP_MAGIC: [u8; 4] = *b"DRPG";
const NESTED_GROUP_VERSION: u8 = 1;
const NESTED_GROUP_MESSAGE_BYTES: usize = 32;
const PROCESS_BOUNDARY_TIMEOUT_NS: u64 = 1_000_000_000;
const EXECUTOR_START_BYTE: u8 = b'>';
#[cfg(target_os = "linux")]
const SOCKET_SEND_FLAGS: libc::c_int = libc::MSG_NOSIGNAL;
#[cfg(not(target_os = "linux"))]
const SOCKET_SEND_FLAGS: libc::c_int = 0;
#[cfg(target_os = "macos")]
const MAX_DARWIN_PROCESS_GROUP_PIDS: usize = 65_536;

#[cfg(target_os = "macos")]
#[link(name = "proc")]
unsafe extern "C" {
    fn proc_listpgrppids(
        pgrpid: libc::pid_t,
        buffer: *mut libc::c_void,
        buffersize: libc::c_int,
    ) -> libc::c_int;
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
#[repr(u8)]
enum NestedGroupOperation {
    Register = 1,
    Retire = 2,
    Unregister = 3,
}

impl TryFrom<u8> for NestedGroupOperation {
    type Error = String;

    fn try_from(value: u8) -> Result<Self, Self::Error> {
        match value {
            1 => Ok(Self::Register),
            2 => Ok(Self::Retire),
            3 => Ok(Self::Unregister),
            _ => Err("Drover received an unknown nested-group operation.".into()),
        }
    }
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
struct NestedGroupMessage {
    operation: NestedGroupOperation,
    origin_pid: libc::pid_t,
    owner_pid: libc::pid_t,
    child_pid: libc::pid_t,
    child_pgid: libc::pid_t,
}

impl NestedGroupMessage {
    fn encode(self) -> [u8; NESTED_GROUP_MESSAGE_BYTES] {
        let mut bytes = [0_u8; NESTED_GROUP_MESSAGE_BYTES];
        bytes[..4].copy_from_slice(&NESTED_GROUP_MAGIC);
        bytes[4] = NESTED_GROUP_VERSION;
        bytes[5] = self.operation as u8;
        bytes[8..12].copy_from_slice(&self.origin_pid.to_ne_bytes());
        bytes[12..16].copy_from_slice(&self.owner_pid.to_ne_bytes());
        bytes[16..20].copy_from_slice(&self.child_pid.to_ne_bytes());
        bytes[20..24].copy_from_slice(&self.child_pgid.to_ne_bytes());
        let checksum = nested_group_checksum(&bytes[..24]);
        bytes[24..32].copy_from_slice(&checksum.to_ne_bytes());
        bytes
    }

    fn decode(bytes: &[u8]) -> Result<Self, String> {
        if bytes.len() != NESTED_GROUP_MESSAGE_BYTES
            || bytes[..4] != NESTED_GROUP_MAGIC
            || bytes[4] != NESTED_GROUP_VERSION
            || bytes[6..8] != [0, 0]
        {
            return Err("Drover received an invalid nested-group record.".into());
        }

        let expected = u64::from_ne_bytes(
            bytes[24..32]
                .try_into()
                .expect("nested-group checksum is eight bytes"),
        );

        if nested_group_checksum(&bytes[..24]) != expected {
            return Err("Drover received a corrupt nested-group record.".into());
        }

        Ok(Self {
            operation: NestedGroupOperation::try_from(bytes[5])?,
            origin_pid: libc::pid_t::from_ne_bytes(
                bytes[8..12]
                    .try_into()
                    .expect("nested-group origin PID is four bytes"),
            ),
            owner_pid: libc::pid_t::from_ne_bytes(
                bytes[12..16]
                    .try_into()
                    .expect("nested-group owner PID is four bytes"),
            ),
            child_pid: libc::pid_t::from_ne_bytes(
                bytes[16..20]
                    .try_into()
                    .expect("nested-group child PID is four bytes"),
            ),
            child_pgid: libc::pid_t::from_ne_bytes(
                bytes[20..24]
                    .try_into()
                    .expect("nested-group child PGID is four bytes"),
            ),
        })
    }
}

#[derive(Clone, Debug)]
struct NestedGroupChannel {
    origin_pid: libc::pid_t,
    origin_pgid: libc::pid_t,
    read: RawFd,
    write: RawFd,
}

impl NestedGroupChannel {
    fn new() -> Result<Self, String> {
        let descriptors = datagram_socket_pair().map_err(|error| {
            format!("Drover could not create the nested-group registry: {error}.")
        })?;

        for descriptor in descriptors {
            if let Err(error) = set_nonblocking(descriptor) {
                close_descriptors(descriptors);

                return Err(error);
            }
        }

        Ok(Self {
            origin_pid: unsafe { libc::getpid() },
            origin_pgid: unsafe { libc::getpgrp() },
            read: descriptors[0],
            write: descriptors[1],
        })
    }

    fn emit(
        &self,
        operation: NestedGroupOperation,
        owner_pid: libc::pid_t,
        child_pid: libc::pid_t,
        child_pgid: libc::pid_t,
    ) -> Result<(), String> {
        let message = NestedGroupMessage {
            operation,
            origin_pid: self.origin_pid,
            owner_pid,
            child_pid,
            child_pgid,
        };
        validate_nested_group_message(&message, self)?;
        let bytes = message.encode();

        let written = send_socket(self.write, &bytes)
            .map_err(|error| format!("Drover could not publish a nested-group record: {error}."))?;

        if written == bytes.len() {
            Ok(())
        } else {
            Err("Drover could not publish a complete nested-group record.".into())
        }
    }

    fn receive(&self) -> Result<Option<NestedGroupMessage>, String> {
        let mut bytes = [0_u8; NESTED_GROUP_MESSAGE_BYTES + 1];

        loop {
            let received =
                unsafe { libc::recv(self.read, bytes.as_mut_ptr().cast(), bytes.len(), 0) };

            if received == -1 {
                let error = io::Error::last_os_error();

                if error.kind() == io::ErrorKind::Interrupted {
                    continue;
                }

                if error.kind() == io::ErrorKind::WouldBlock {
                    return Ok(None);
                }

                return Err(format!(
                    "Drover could not read the nested-group registry: {error}."
                ));
            }

            if received != NESTED_GROUP_MESSAGE_BYTES as isize {
                return Err("Drover received a truncated nested-group record.".into());
            }

            return Ok(Some(NestedGroupMessage::decode(
                &bytes[..NESTED_GROUP_MESSAGE_BYTES],
            )?));
        }
    }

    fn close(&self) {
        unsafe {
            libc::close(self.read);
            libc::close(self.write);
        }
    }
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum NestedGroupState {
    Active,
    Retired,
}

#[derive(Clone, Copy, Debug)]
struct NestedGroup {
    owner_pid: libc::pid_t,
    child_pid: libc::pid_t,
    child_pgid: libc::pid_t,
    state: NestedGroupState,
}

#[derive(Debug, Default)]
struct NestedGroupGraph {
    groups: HashMap<libc::pid_t, NestedGroup>,
}

impl NestedGroupGraph {
    fn apply(
        &mut self,
        message: NestedGroupMessage,
        channel: &NestedGroupChannel,
        direct_owners: &HashSet<libc::pid_t>,
    ) -> Result<(), String> {
        validate_nested_group_message(&message, channel)?;

        match message.operation {
            NestedGroupOperation::Register => {
                if let Entry::Occupied(mut entry) = self.groups.entry(message.child_pid) {
                    let mut replacement = NestedGroup {
                        owner_pid: message.owner_pid,
                        child_pid: message.child_pid,
                        child_pgid: message.child_pgid,
                        state: NestedGroupState::Active,
                    };
                    let cleanup = signal_registered_group(&replacement, libc::SIGKILL);

                    if cleanup.is_ok() {
                        replacement.state = NestedGroupState::Retired;
                    }

                    entry.insert(replacement);
                    cleanup?;

                    return Err(format!(
                        "Drover received a duplicate nested-group registration for PID {}; forced cleanup was issued.",
                        message.child_pid
                    ));
                }

                let owner_is_active = direct_owners.contains(&message.owner_pid)
                    || self
                        .groups
                        .get(&message.owner_pid)
                        .is_some_and(|owner| owner.state == NestedGroupState::Active);
                let mut group = NestedGroup {
                    owner_pid: message.owner_pid,
                    child_pid: message.child_pid,
                    child_pgid: message.child_pgid,
                    state: NestedGroupState::Active,
                };

                if !owner_is_active {
                    let cleanup = signal_registered_group(&group, libc::SIGKILL);

                    if cleanup.is_ok() {
                        group.state = NestedGroupState::Retired;
                    }

                    self.groups.insert(message.child_pid, group);

                    return cleanup;
                }

                self.groups.insert(message.child_pid, group);
            }
            NestedGroupOperation::Retire => {
                if !self.groups.contains_key(&message.child_pid) {
                    return Ok(());
                }

                self.assert_exact(message)?;

                if let Some(group) = self.groups.get_mut(&message.child_pid) {
                    group.state = NestedGroupState::Retired;
                }

                self.cleanup_active_descendants(message.child_pid)?;
            }
            NestedGroupOperation::Unregister => {
                if !self.groups.contains_key(&message.child_pid) {
                    return Ok(());
                }

                self.assert_exact(message)?;
                let group = self
                    .groups
                    .get(&message.child_pid)
                    .expect("nested group was checked above");

                if group.state != NestedGroupState::Retired {
                    return Err(format!(
                        "Drover received a nested-group unregister before RETIRED for PID {}.",
                        message.child_pid
                    ));
                }

                if self.groups.values().any(|candidate| {
                    candidate.owner_pid == message.child_pid
                        && candidate.state == NestedGroupState::Active
                }) {
                    return Err(format!(
                        "Drover cannot unregister nested-group owner {} with active children.",
                        message.child_pid
                    ));
                }

                self.groups.remove(&message.child_pid);
            }
        }

        Ok(())
    }

    fn assert_exact(&self, message: NestedGroupMessage) -> Result<(), String> {
        let Some(group) = self.groups.get(&message.child_pid) else {
            return Err(format!(
                "Drover received a late nested-group record for unknown PID {}.",
                message.child_pid
            ));
        };

        if group.owner_pid != message.owner_pid
            || group.child_pgid != message.child_pgid
            || group.child_pid != message.child_pid
        {
            return Err(format!(
                "Drover received a mismatched nested-group record for PID {}.",
                message.child_pid
            ));
        }

        Ok(())
    }

    fn cleanup_active_descendants(&mut self, owner_pid: libc::pid_t) -> Result<(), String> {
        let mut descendants = self.descendants(owner_pid);
        descendants.reverse();
        let mut cleanup_error = None;

        for child_pid in descendants {
            let Some(group) = self.groups.get_mut(&child_pid) else {
                continue;
            };

            if group.state == NestedGroupState::Active {
                match signal_registered_group(group, libc::SIGKILL) {
                    Ok(()) => group.state = NestedGroupState::Retired,
                    Err(error) => {
                        cleanup_error.get_or_insert(error);
                    }
                }
            }
        }

        cleanup_error.map_or(Ok(()), Err)
    }

    fn descendants(&self, owner_pid: libc::pid_t) -> Vec<libc::pid_t> {
        let mut descendants = Vec::new();
        let mut pending = VecDeque::from([owner_pid]);

        while let Some(owner) = pending.pop_front() {
            let mut children: Vec<_> = self
                .groups
                .values()
                .filter(|group| group.owner_pid == owner)
                .map(|group| group.child_pid)
                .collect();
            children.sort_unstable();

            for child in children {
                if !descendants.contains(&child) {
                    descendants.push(child);
                    pending.push_back(child);
                }
            }
        }

        descendants
    }

    fn finish(&mut self) -> Result<(), String> {
        let first_active = self
            .groups
            .values()
            .find(|group| group.state == NestedGroupState::Active)
            .map(|group| group.child_pgid);

        if let Some(first_active) = first_active {
            let mut active: Vec<_> = self
                .groups
                .values()
                .filter(|group| group.state == NestedGroupState::Active)
                .map(|group| (self.depth(group.child_pid), group.child_pid))
                .collect();
            active.sort_by_key(|(depth, child_pid)| (std::cmp::Reverse(*depth), *child_pid));
            let mut cleanup_error = None;

            for (_, child_pid) in active {
                let Some(group) = self.groups.get_mut(&child_pid) else {
                    continue;
                };

                if let Err(error) = signal_registered_group(group, libc::SIGKILL) {
                    cleanup_error.get_or_insert(error);
                }

                group.state = NestedGroupState::Retired;
            }

            self.groups.clear();

            return Err(cleanup_error.unwrap_or_else(|| {
                format!(
                    "Drover finished with active nested process group {first_active}; forced cleanup was issued."
                )
            }));
        }

        self.groups.clear();

        Ok(())
    }

    fn depth(&self, child_pid: libc::pid_t) -> usize {
        let mut depth = 0;
        let mut current = child_pid;

        while let Some(group) = self.groups.get(&current) {
            depth += 1;
            current = group.owner_pid;

            if depth > self.groups.len() {
                break;
            }
        }

        depth
    }
}

fn nested_group_checksum(bytes: &[u8]) -> u64 {
    bytes.iter().fold(0xcbf29ce484222325_u64, |hash, byte| {
        (hash ^ u64::from(*byte)).wrapping_mul(0x100000001b3)
    })
}

fn validate_nested_group_message(
    message: &NestedGroupMessage,
    channel: &NestedGroupChannel,
) -> Result<(), String> {
    if message.origin_pid != channel.origin_pid
        || message.owner_pid <= 0
        || message.child_pid <= 0
        || message.child_pgid <= 0
        || message.owner_pid == channel.origin_pid
        || message.owner_pid == message.child_pid
        || message.child_pid != message.child_pgid
        || message.child_pid == channel.origin_pid
        || message.child_pgid == channel.origin_pgid
    {
        return Err("Drover received unsafe nested-group process identities.".into());
    }

    Ok(())
}

fn signal_registered_group(group: &NestedGroup, signal: i32) -> Result<(), String> {
    if group.state == NestedGroupState::Retired {
        return Ok(());
    }

    match signal_process_group_checked(group.child_pgid, signal) {
        Ok(()) => Ok(()),
        Err(error) if error.raw_os_error() == Some(libc::ESRCH) => Ok(()),
        Err(error) => Err(format!(
            "Drover could not signal registered nested process group {}: {error}.",
            group.child_pgid
        )),
    }
}

#[derive(Clone, Copy, Debug)]
struct PermitPool {
    read: RawFd,
    write: RawFd,
}

#[derive(Debug)]
struct PermitReleaseError {
    failures: Vec<(String, String)>,
}

impl PermitReleaseError {
    fn failed_names(&self) -> impl Iterator<Item = String> + '_ {
        self.failures.iter().map(|(name, _)| name.clone())
    }
}

impl std::fmt::Display for PermitReleaseError {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(
            formatter,
            "Drover permit release failed: {}",
            self.failures
                .iter()
                .map(|(name, error)| format!("{name}: {error}"))
                .collect::<Vec<_>>()
                .join(" | ")
        )
    }
}

impl From<PermitReleaseError> for String {
    fn from(error: PermitReleaseError) -> Self {
        error.to_string()
    }
}

#[derive(Clone, Debug)]
struct PermitRegistry {
    pools: HashMap<String, PermitPool>,
}

impl PermitRegistry {
    fn new(global: usize, scope_limits: HashMap<String, usize>) -> Result<Self, String> {
        if !(1..=256).contains(&global) {
            return Err("Drover concurrency must be between 1 and 256.".into());
        }

        let mut pools = HashMap::new();
        pools.insert(GLOBAL_POOL.into(), create_pool(global)?);
        let armed = match create_pool(global) {
            Ok(armed) => armed,
            Err(error) => {
                close_pools(&pools);

                return Err(error);
            }
        };
        pools.insert(ARMED_POOL.into(), armed);

        for (scope_id, limit) in scope_limits {
            if scope_id.is_empty() || !(1..=256).contains(&limit) {
                close_pools(&pools);

                return Err("Drover scope concurrency limits must be between 1 and 256.".into());
            }

            let name = format!("scope:{scope_id}");

            if pools.contains_key(&name) {
                close_pools(&pools);

                return Err(format!("Drover received duplicate scope pool {scope_id}."));
            }

            match create_pool(limit) {
                Ok(pool) => {
                    pools.insert(name, pool);
                }
                Err(error) => {
                    close_pools(&pools);

                    return Err(error);
                }
            }
        }

        Ok(Self { pools })
    }

    fn names(&self, scopes: &[String]) -> Vec<String> {
        let mut names = Vec::new();

        for scope in scopes.iter().rev() {
            let name = format!("scope:{scope}");

            if self.pools.contains_key(&name) {
                names.push(name);
            }
        }

        names.push(GLOBAL_POOL.into());
        names
    }

    fn try_acquire_armed(&self) -> Result<bool, String> {
        self.take(ARMED_POOL)
    }

    fn try_acquire(&self, names: &[String]) -> Result<bool, String> {
        let mut acquired = Vec::new();

        for name in names {
            match self.take(name) {
                Ok(true) => acquired.push(name.clone()),
                Ok(false) => {
                    self.release(&acquired)?;

                    return Ok(false);
                }
                Err(error) => {
                    return match self.release(&acquired) {
                        Ok(()) => Err(error),
                        Err(release) => {
                            Err(format!("{error} Permit rollback also failed: {release}"))
                        }
                    };
                }
            }
        }

        Ok(true)
    }

    fn acquire(&self, names: &[String]) -> Result<(), String> {
        loop {
            if self.try_acquire(names)? {
                return Ok(());
            }

            self.poll_names(names, 5)?;
        }
    }

    fn release(&self, names: &[String]) -> Result<(), PermitReleaseError> {
        let mut failures = Vec::new();

        for name in names.iter().rev() {
            let Some(pool) = self.pools.get(name) else {
                failures.push((
                    name.clone(),
                    format!("Drover has no permit pool named {name}."),
                ));

                continue;
            };

            if let Err(error) = write_byte(pool.write) {
                failures.push((name.clone(), error));
            }
        }

        if failures.is_empty() {
            Ok(())
        } else {
            Err(PermitReleaseError { failures })
        }
    }

    fn poll_names(&self, names: &[String], timeout_ms: i32) -> Result<(), String> {
        let mut seen = HashSet::new();
        let mut descriptors = Vec::new();

        for name in names {
            let pool = self
                .pools
                .get(name)
                .ok_or_else(|| format!("Drover has no permit pool named {name}."))?;

            if seen.insert(pool.read) {
                descriptors.push(libc::pollfd {
                    fd: pool.read,
                    events: libc::POLLIN | libc::POLLHUP | libc::POLLERR,
                    revents: 0,
                });
            }
        }

        poll_descriptors(&mut descriptors, timeout_ms)
    }

    fn append_poll_descriptors(
        &self,
        names: impl Iterator<Item = String>,
        seen: &mut HashSet<RawFd>,
        descriptors: &mut Vec<libc::pollfd>,
    ) -> Result<(), String> {
        for name in names {
            let pool = self
                .pools
                .get(&name)
                .ok_or_else(|| format!("Drover has no permit pool named {name}."))?;

            if seen.insert(pool.read) {
                descriptors.push(libc::pollfd {
                    fd: pool.read,
                    events: libc::POLLIN | libc::POLLHUP | libc::POLLERR,
                    revents: 0,
                });
            }
        }

        Ok(())
    }

    fn take(&self, name: &str) -> Result<bool, String> {
        let pool = self
            .pools
            .get(name)
            .ok_or_else(|| format!("Drover has no permit pool named {name}."))?;
        let mut byte = 0_u8;

        loop {
            let result = unsafe { libc::read(pool.read, (&mut byte as *mut u8).cast(), 1) };

            if result == 1 {
                return if byte == b'.' {
                    Ok(true)
                } else {
                    Err(format!("Drover permit pool {name} was corrupted."))
                };
            }

            if result == 0 {
                return Err(format!("Drover permit pool {name} closed unexpectedly."));
            }

            let error = io::Error::last_os_error();

            if error.kind() == io::ErrorKind::Interrupted {
                continue;
            }

            if error.kind() == io::ErrorKind::WouldBlock {
                return Ok(false);
            }

            return Err(format!("Drover could not acquire permit {name}: {error}."));
        }
    }

    fn close(&self) {
        close_pools(&self.pools);
    }
}

#[derive(Debug)]
pub struct Engine {
    run_id: String,
    grace_ns: u64,
    registry: PermitRegistry,
    nested_groups: NestedGroupChannel,
    held: HashMap<String, usize>,
}

impl Engine {
    pub fn new(
        run_id: String,
        concurrency: usize,
        scope_limits: HashMap<String, usize>,
        grace: Duration,
    ) -> Result<Self, String> {
        if run_id.is_empty() {
            return Err("Drover run IDs cannot be empty.".into());
        }

        if grace.is_zero() || grace > Duration::from_secs(60) {
            return Err("Drover termination grace must be between 1 ms and 60 seconds.".into());
        }

        let registry = PermitRegistry::new(concurrency, scope_limits)?;
        let nested_groups = match NestedGroupChannel::new() {
            Ok(nested_groups) => nested_groups,
            Err(error) => {
                registry.close();

                return Err(error);
            }
        };

        Ok(Self {
            run_id,
            grace_ns: grace.as_nanos() as u64,
            registry,
            nested_groups,
            held: HashMap::new(),
        })
    }

    pub fn scheduler(&self, queue_capacity: usize) -> Result<Scheduler, String> {
        Scheduler::new(
            self.run_id.clone(),
            queue_capacity,
            self.grace_ns,
            self.registry.clone(),
            self.nested_groups.clone(),
        )
    }

    pub fn acquire(&mut self, scopes: &[String]) -> Result<(), String> {
        validate_scopes(scopes)?;
        let names = self.registry.names(scopes);
        self.registry.acquire(&names)?;

        for name in names {
            *self.held.entry(name).or_default() += 1;
        }

        Ok(())
    }

    pub fn release(&mut self, scopes: &[String]) -> Result<(), String> {
        validate_scopes(scopes)?;
        let names = self.registry.names(scopes);

        if names
            .iter()
            .any(|name| self.held.get(name).copied().unwrap_or_default() == 0)
        {
            return Err("Drover attempted to release an unheld permit.".into());
        }

        self.release_tracked(&names)
    }

    pub fn release_all(&mut self) -> Result<(), String> {
        let mut names = Vec::new();

        for (name, count) in &self.held {
            names.extend(std::iter::repeat_n(name.clone(), *count));
        }

        self.release_tracked(&names)
    }

    fn release_tracked(&mut self, names: &[String]) -> Result<(), String> {
        match self.registry.release(names) {
            Ok(()) => {
                for name in names {
                    self.decrement_held(name);
                }

                Ok(())
            }
            Err(error) => {
                let mut failed = HashMap::<String, usize>::new();

                for name in error.failed_names() {
                    *failed.entry(name).or_default() += 1;
                }

                for name in names {
                    if let Some(remaining) = failed.get_mut(name) {
                        if *remaining > 0 {
                            *remaining -= 1;

                            continue;
                        }
                    }

                    self.decrement_held(name);
                }

                Err(error.into())
            }
        }
    }

    fn decrement_held(&mut self, name: &str) {
        let count = self
            .held
            .get_mut(name)
            .expect("released permit was held by this engine");
        *count -= 1;

        if *count == 0 {
            self.held.remove(name);
        }
    }
}

impl Drop for Engine {
    fn drop(&mut self) {
        if let Err(error) = self.release_all() {
            write_stderr(b"Drover best-effort engine cleanup failure: ");
            write_stderr(error.as_bytes());
            write_stderr(b"\n");
        }
        self.registry.close();
        self.nested_groups.close();
    }
}

#[derive(Clone, Debug)]
pub struct Task {
    pub ordinal: u32,
    pub id: String,
    pub kind: String,
    pub scope_id: String,
    pub timeout_ms: u64,
    permit_names: Vec<String>,
}

impl Task {
    fn can_prearm(&self) -> bool {
        self.permit_names.len() == 1 && self.permit_names[0] == GLOBAL_POOL
    }
}

#[derive(Debug)]
pub struct ChildRole {
    pub ordinal: u32,
    pub task_id: String,
    pub pid: libc::pid_t,
    pub fd: RawFd,
}

#[derive(Clone, Debug, Serialize)]
pub struct Telemetry {
    pub pid: Option<libc::pid_t>,
    pub pgid: Option<libc::pid_t>,
    pub started_ns: Option<u64>,
    pub finished_ns: Option<u64>,
    pub duration_ms: Option<f64>,
    pub exit_code: Option<i32>,
    pub signal: Option<i32>,
    pub interrupted_signal: Option<i32>,
    pub forks: u32,
    pub scope_workers: u32,
    pub executor_workers: u32,
    pub process_anchors: u32,
}

/// Versioned, process-local topology counters for one scheduler map.
#[derive(Clone, Copy, Debug, Serialize)]
pub struct TopologyTelemetry {
    pub schema: u32,
    pub forks: u64,
    pub scope_workers: u64,
    pub executor_workers: u64,
    pub process_anchors: u64,
    pub peak_live_pids: u32,
    pub peak_outstanding_tasks: u32,
    pub outstanding_task_limit: u32,
}

#[derive(Clone, Debug, Serialize)]
pub struct TaskResult {
    pub id: String,
    pub kind: String,
    pub scope_id: String,
    pub ordinal: u32,
    pub status: String,
    pub failure: Option<Value>,
    pub value: Value,
    pub stdout: String,
    pub stderr: String,
    pub memory_peak_bytes: Option<u64>,
    pub events: Vec<Frame>,
    pub telemetry: Telemetry,
}

#[derive(Debug)]
pub enum Step {
    Child(ChildRole),
    Result(Box<TaskResult>),
    Progress,
    Done,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum ExecutorState {
    Armed {
        armed_permit: bool,
        task_permits: bool,
    },
    Running {
        task_permits: bool,
    },
    FailedToStart {
        armed_permit: bool,
    },
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum SpawnPermits {
    None,
    Armed,
    Task,
}

#[derive(Debug)]
struct ActiveTask {
    task: Task,
    pid: libc::pid_t,
    pgid: libc::pid_t,
    fd: RawFd,
    exited: bool,
    reaped: bool,
    wait_status: Option<i32>,
    eof: bool,
    eof_ns: Option<u64>,
    buffer: Vec<u8>,
    validator: Validator,
    frames: Vec<Frame>,
    stdout: Vec<u8>,
    stderr: Vec<u8>,
    value_buffer: Vec<u8>,
    state: ExecutorState,
    started_ns: Option<u64>,
    deadline_ns: Option<u64>,
    finished_ns: Option<u64>,
    terminal_received_ns: Option<u64>,
    terminal: Option<Frame>,
    memory_peak_bytes: Option<u64>,
    protocol_error: Option<String>,
    timed_out: bool,
    term_ns: Option<u64>,
    interrupted_signal: Option<i32>,
    cleanup_term_ns: Option<u64>,
    kill_ns: Option<u64>,
    cleanup_failed: bool,
    registered_nested: bool,
    retired_nested: bool,
}

impl ActiveTask {
    fn new(
        task: Task,
        pid: libc::pid_t,
        pgid: libc::pid_t,
        fd: RawFd,
        _spawned_ns: u64,
        run_id: &str,
        registered_nested: bool,
    ) -> Self {
        Self {
            validator: Validator::new(
                run_id.into(),
                task.id.clone(),
                task.kind.clone(),
                task.scope_id.clone(),
                task.ordinal,
            ),
            task,
            pid,
            pgid,
            fd,
            exited: false,
            reaped: false,
            wait_status: None,
            eof: false,
            eof_ns: None,
            buffer: Vec::new(),
            frames: Vec::new(),
            stdout: Vec::new(),
            stderr: Vec::new(),
            value_buffer: Vec::new(),
            state: ExecutorState::Armed {
                armed_permit: false,
                task_permits: false,
            },
            started_ns: None,
            deadline_ns: None,
            finished_ns: None,
            terminal_received_ns: None,
            terminal: None,
            memory_peak_bytes: None,
            protocol_error: None,
            timed_out: false,
            term_ns: None,
            interrupted_signal: None,
            cleanup_term_ns: None,
            kill_ns: None,
            cleanup_failed: false,
            registered_nested,
            retired_nested: false,
        }
    }

    fn armed(&self) -> bool {
        matches!(
            self.state,
            ExecutorState::Armed {
                armed_permit: true,
                task_permits: false,
            }
        ) && self.protocol_error.is_none()
            && !self.exited
            && !self.reaped
    }

    fn running(&self) -> bool {
        matches!(self.state, ExecutorState::Running { .. })
    }

    fn owns_armed_permit(&self) -> bool {
        matches!(
            self.state,
            ExecutorState::Armed {
                armed_permit: true,
                ..
            } | ExecutorState::FailedToStart { armed_permit: true }
        )
    }

    fn owns_task_permits(&self) -> bool {
        matches!(
            self.state,
            ExecutorState::Armed {
                task_permits: true,
                ..
            } | ExecutorState::Running { task_permits: true }
        )
    }

    fn mark_armed_permit_acquired(&mut self) {
        self.state = ExecutorState::Armed {
            armed_permit: true,
            task_permits: false,
        };
    }

    fn mark_task_permits_acquired(&mut self) {
        self.state = ExecutorState::Armed {
            armed_permit: false,
            task_permits: true,
        };
    }

    fn mark_started(&mut self, started_ns: u64, task_permits: bool) {
        self.state = ExecutorState::Running { task_permits };
        self.deadline_ns = (self.task.timeout_ms > 0)
            .then(|| started_ns.saturating_add(self.task.timeout_ms.saturating_mul(1_000_000)));
    }

    fn mark_start_failed(&mut self) {
        self.state = ExecutorState::FailedToStart {
            armed_permit: self.owns_armed_permit(),
        };
    }

    fn read_available(&mut self) {
        if self.eof {
            return;
        }

        let mut chunk = [0_u8; 65_536];

        loop {
            let read = unsafe { libc::read(self.fd, chunk.as_mut_ptr().cast(), chunk.len()) };

            if read > 0 {
                self.buffer.extend_from_slice(&chunk[..read as usize]);

                match decode_available(&mut self.buffer, &mut self.validator) {
                    Ok(frames) => {
                        for frame in frames {
                            if let Err(error) = self.accept_frame(frame) {
                                self.protocol_error = Some(error);
                                break;
                            }
                        }
                    }
                    Err(error) => {
                        self.protocol_error = Some(error.to_string());
                    }
                }

                if self.protocol_error.is_some() {
                    break;
                }

                if self.buffer.len() > MAX_BUFFER_BYTES {
                    self.protocol_error
                        .get_or_insert_with(|| "Drove child buffer exceeded the v1 limit.".into());
                    break;
                }

                continue;
            }

            if read == 0 {
                self.eof = true;
                break;
            }

            let error = io::Error::last_os_error();

            if error.kind() == io::ErrorKind::Interrupted {
                continue;
            }

            if error.kind() == io::ErrorKind::WouldBlock {
                break;
            }

            self.protocol_error
                .get_or_insert_with(|| format!("Drover could not read a child channel: {error}."));
            break;
        }

        if self.eof && !self.buffer.is_empty() {
            self.protocol_error
                .get_or_insert_with(|| "Drove received a truncated child frame.".into());
        }
    }

    fn accept_frame(&mut self, mut frame: Frame) -> Result<(), String> {
        match frame.event_type.as_str() {
            "task.started" => {
                let started_ns = frame.payload["started_ns"]
                    .as_u64()
                    .ok_or_else(|| "Drove received an invalid child start.".to_string())?;
                self.started_ns = Some(started_ns);
                if let Some(finished_ns) = self.finished_ns.as_mut() {
                    *finished_ns = (*finished_ns).max(started_ns);
                }
            }
            "task.stdout" | "task.stderr" | "task.value" => {
                let encoded = frame.payload["data"]
                    .as_str()
                    .ok_or_else(|| "Drove received an invalid child data chunk.".to_string())?;
                let bytes = STANDARD
                    .decode(encoded)
                    .map_err(|_| "Drove received invalid base64 child data.".to_string())?;
                let target = match frame.event_type.as_str() {
                    "task.stdout" => &mut self.stdout,
                    "task.stderr" => &mut self.stderr,
                    "task.value" => &mut self.value_buffer,
                    _ => unreachable!(),
                };

                if target.len().saturating_add(bytes.len()) > MAX_STREAM_BYTES {
                    return Err("A Drove child stream exceeded 64 MiB.".into());
                }

                target.extend_from_slice(&bytes);
                frame.payload = json!({ "bytes": encoded.len() });
            }
            "task.finished" => {
                self.finished_ns = frame.payload["finished_ns"].as_u64();
                self.memory_peak_bytes = frame.payload["memory_peak_bytes"].as_u64();
                self.terminal_received_ns =
                    Some(monotonic_ns().map_err(|error| error.to_string())?);
                self.terminal = Some(frame.clone());
            }
            _ => return Err("Drove received an invalid child event sequence.".into()),
        }

        self.frames.push(frame);
        Ok(())
    }

    fn observe_exit(&mut self, now_ns: u64) {
        if self.exited || self.reaped {
            return;
        }

        // Keep an exited worker waitable until its channel is drained or cleanup begins.
        let mut info = unsafe { std::mem::zeroed::<libc::siginfo_t>() };
        let waited = unsafe {
            libc::waitid(
                libc::P_PID,
                self.pid as libc::id_t,
                &mut info,
                libc::WEXITED | libc::WNOHANG | libc::WNOWAIT,
            )
        };

        if waited == 0 {
            if unsafe { info.si_pid() } == self.pid {
                self.exited = true;
                self.record_observed_finish(now_ns);
            }

            return;
        }

        let error = io::Error::last_os_error();

        if error.kind() == io::ErrorKind::Interrupted {
            return;
        }

        self.exited = true;
        self.reaped = true;
        self.record_observed_finish(now_ns);
        self.protocol_error
            .get_or_insert_with(|| format!("waitid() lost the Drove child: {error}."));
    }

    fn record_observed_finish(&mut self, stale_ns: u64) {
        let observed_ns = monotonic_ns().unwrap_or(stale_ns);
        let started_ns = self.started_ns.unwrap_or(observed_ns);
        let finished_ns = self.finished_ns.get_or_insert(observed_ns);
        *finished_ns = (*finished_ns).max(started_ns);
    }

    fn reap(&mut self, now_ns: u64) {
        // The executor is also the process-group leader. Keep its waitable
        // identity reserved until group cleanup has been issued so a reused
        // PID can never receive a descendant-cleanup signal.
        if self.reaped || !self.exited || self.kill_ns.is_none() {
            return;
        }

        let mut status = 0;
        let waited = unsafe { libc::waitpid(self.pid, &mut status, libc::WNOHANG) };

        if waited == self.pid {
            self.reaped = true;
            self.wait_status = Some(status);
            self.record_observed_finish(now_ns);

            return;
        }

        if waited == -1 {
            let error = io::Error::last_os_error();

            if error.kind() == io::ErrorKind::Interrupted {
                return;
            }

            self.reaped = true;
            self.record_observed_finish(now_ns);
            self.protocol_error
                .get_or_insert_with(|| format!("waitpid() lost the Drove child: {error}."));
        }
    }

    fn exit_code(&self) -> Option<i32> {
        let status = self.wait_status?;

        libc::WIFEXITED(status).then(|| libc::WEXITSTATUS(status))
    }

    fn signal(&self) -> Option<i32> {
        let status = self.wait_status?;

        libc::WIFSIGNALED(status).then(|| libc::WTERMSIG(status))
    }

    fn normalize_terminal_consistency(&mut self) {
        if !self.reaped
            || !self.eof
            || self.timed_out
            || self.interrupted_signal.is_some()
            || self.protocol_error.is_some()
            || self.signal().is_some()
        {
            return;
        }

        let Some(terminal) = self.terminal.as_ref() else {
            self.protocol_error = Some(format!(
                "The Drove task exited without a terminal result (exit {}).",
                self.exit_code()
                    .map_or_else(|| "unknown".into(), |code| code.to_string())
            ));

            return;
        };

        if terminal.payload["status"].as_str() == Some("passed") && self.exit_code() != Some(0) {
            self.protocol_error = Some("A passing Drove task exited unsuccessfully.".into());
        }
    }

    fn begin_cleanup(&mut self, now_ns: u64) {
        if self.cleanup_term_ns.is_some() || self.kill_ns.is_some() {
            return;
        }

        self.signal_group(libc::SIGTERM);
        self.cleanup_term_ns = Some(now_ns);
    }

    fn signal_group(&mut self, signal: i32) {
        if self.reaped {
            self.protocol_error.get_or_insert_with(|| {
                "The Drove executor was reaped before descendant cleanup.".into()
            });
            return;
        }

        if let Err(error) = signal_process_group_checked(self.pgid, signal) {
            if error.raw_os_error() == Some(libc::ESRCH) && self.exited {
                // No signalable process remains in the still-reserved group.
                return;
            }

            if !self.exited {
                unsafe {
                    libc::kill(self.pid, signal);
                }
            }

            self.protocol_error.get_or_insert_with(|| {
                format!(
                    "Drover could not send signal {signal} to process group {}: {error}. Direct fallback cannot guarantee descendant cleanup.",
                    self.pgid,
                )
            });
        }
    }

    fn interrupt(&mut self, signal: i32, now_ns: u64) {
        if self.interrupted_signal.is_some() {
            return;
        }

        self.interrupted_signal = Some(signal);
        self.begin_cleanup(now_ns);
    }

    fn enforce(&mut self, now_ns: u64, grace_ns: u64) {
        if self.eof {
            self.eof_ns.get_or_insert(now_ns);
        }

        if !self.exited
            && !self.timed_out
            && self.interrupted_signal.is_none()
            && self.task.timeout_ms > 0
        {
            if let Some(deadline_ns) = self.deadline_ns {
                let terminal_grace = self
                    .terminal_received_ns
                    .map(|received| received.saturating_add(grace_ns))
                    .unwrap_or_default();

                if now_ns >= deadline_ns.max(terminal_grace) {
                    self.signal_group(libc::SIGTERM);
                    self.timed_out = true;
                    self.term_ns = Some(now_ns);
                }
            }
        }

        self.normalize_terminal_consistency();

        let protocol_stopped_ns = self.terminal_received_ns.or(self.eof_ns);

        if !self.exited
            && protocol_stopped_ns.is_some_and(|stopped| {
                now_ns.saturating_sub(stopped) >= grace_ns.max(PROCESS_BOUNDARY_TIMEOUT_NS)
            })
        {
            self.protocol_error.get_or_insert_with(|| {
                "The Drove child stopped its result protocol without exiting.".into()
            });
        }

        if self.protocol_error.is_some() && self.cleanup_term_ns.is_none() && self.kill_ns.is_none()
        {
            self.begin_cleanup(now_ns);
        }

        if self.exited && !self.eof {
            self.begin_cleanup(now_ns);
        }

        let escalation_ns = self.term_ns.or(self.cleanup_term_ns);

        if self.kill_ns.is_none()
            && escalation_ns.is_some_and(|started| now_ns.saturating_sub(started) >= grace_ns)
        {
            self.signal_group(libc::SIGKILL);
            self.kill_ns = Some(now_ns);
        }

        if self.exited && self.eof && escalation_ns.is_none() && self.kill_ns.is_none() {
            self.signal_group(libc::SIGKILL);
            self.kill_ns = Some(now_ns);
        }

        if !self.eof
            && self.kill_ns.is_some_and(|killed| {
                now_ns.saturating_sub(killed) >= grace_ns.max(PROCESS_BOUNDARY_TIMEOUT_NS)
            })
        {
            self.cleanup_failed = true;
            self.eof = true;
        }
    }

    fn ready(&self) -> bool {
        self.reaped && self.eof && self.kill_ns.is_some()
    }

    fn needs_nested_retirement(&self) -> bool {
        self.exited && (self.kill_ns.is_some() || self.reaped) && !self.retired_nested
    }

    fn into_result(self) -> TaskResult {
        let exit_code = self.exit_code();
        let signal = self.signal();
        let (scope_workers, executor_workers) = worker_counts(&self.task.kind, self.running());
        let telemetry = telemetry(
            Some(self.pid),
            Some(self.pgid),
            self.started_ns,
            self.finished_ns,
            exit_code,
            signal,
            self.interrupted_signal,
            1,
            scope_workers,
            executor_workers,
            0,
        );

        if let Some(error) = self.protocol_error {
            return failed_result(
                self.task,
                "child_protocol_failure",
                error,
                telemetry,
                self.frames,
            );
        }

        if self.cleanup_failed {
            return failed_result(
                self.task,
                "blocked_descendant",
                "A Drove descendant kept the task channel open after forced cleanup.".into(),
                telemetry,
                self.frames,
            );
        }

        if let Some(interrupted) = self.interrupted_signal {
            return failed_result(
                self.task,
                "user_interruption",
                format!("The Drove run was interrupted by signal {interrupted}."),
                telemetry,
                self.frames,
            );
        }

        if self.timed_out {
            return failed_result(
                self.task,
                "timeout",
                "The Drove task exceeded its timeout.".into(),
                telemetry,
                self.frames,
            );
        }

        if let Some(signal) = signal {
            return failed_result(
                self.task,
                "signal_termination",
                format!("The Drove task ended from signal {signal}."),
                telemetry,
                self.frames,
            );
        }

        let Some(terminal) = self.terminal else {
            return failed_result(
                self.task,
                "child_protocol_failure",
                format!(
                    "The Drove task exited without a terminal result (exit {}).",
                    exit_code.map_or_else(|| "unknown".into(), |code| code.to_string())
                ),
                telemetry,
                self.frames,
            );
        };

        let value = match serde_json::from_slice(&self.value_buffer) {
            Ok(value) => value,
            Err(error) => {
                return failed_result(
                    self.task,
                    "child_protocol_failure",
                    error.to_string(),
                    telemetry,
                    self.frames,
                );
            }
        };
        let stdout = match String::from_utf8(self.stdout) {
            Ok(stdout) => stdout,
            Err(error) => {
                return failed_result(
                    self.task,
                    "child_protocol_failure",
                    error.to_string(),
                    telemetry,
                    self.frames,
                );
            }
        };
        let stderr = match String::from_utf8(self.stderr) {
            Ok(stderr) => stderr,
            Err(error) => {
                return failed_result(
                    self.task,
                    "child_protocol_failure",
                    error.to_string(),
                    telemetry,
                    self.frames,
                );
            }
        };

        TaskResult {
            id: self.task.id,
            kind: self.task.kind,
            scope_id: self.task.scope_id,
            ordinal: self.task.ordinal,
            status: terminal.payload["status"]
                .as_str()
                .unwrap_or("failed")
                .into(),
            failure: terminal
                .payload
                .get("failure")
                .filter(|failure| !failure.is_null())
                .cloned(),
            value,
            stdout,
            stderr,
            memory_peak_bytes: self.memory_peak_bytes,
            events: self.frames,
            telemetry,
        }
    }
}

#[derive(Debug)]
pub struct Scheduler {
    run_id: String,
    queue_capacity: usize,
    grace_ns: u64,
    registry: PermitRegistry,
    nested_groups: NestedGroupChannel,
    nested_group_graph: Option<NestedGroupGraph>,
    owner_pid: libc::pid_t,
    next_ordinal: u32,
    submitted: HashSet<String>,
    pending: VecDeque<Task>,
    active: HashMap<libc::pid_t, ActiveTask>,
    completed: VecDeque<TaskResult>,
    unreleased_permits: Vec<String>,
    max_active: usize,
    forks: u64,
    scope_workers: u64,
    executor_workers: u64,
    peak_live_pids: u32,
    peak_outstanding_tasks: u32,
    interrupted_signal: Option<i32>,
    refill_before_collect: bool,
    #[cfg(test)]
    readiness_delay: Duration,
    #[cfg(test)]
    fail_readiness: bool,
    #[cfg(test)]
    fail_pre_ready_reap: bool,
}

impl Scheduler {
    fn new(
        run_id: String,
        queue_capacity: usize,
        grace_ns: u64,
        registry: PermitRegistry,
        nested_groups: NestedGroupChannel,
    ) -> Result<Self, String> {
        if !(1..=MAX_QUEUE_CAPACITY).contains(&queue_capacity) {
            return Err(format!(
                "Drover queue capacity must be between 1 and {MAX_QUEUE_CAPACITY}."
            ));
        }

        let owner_pid = unsafe { libc::getpid() };
        let nested_group_graph =
            (owner_pid == nested_groups.origin_pid).then(NestedGroupGraph::default);
        Ok(Self {
            run_id,
            queue_capacity,
            grace_ns,
            registry,
            nested_groups,
            nested_group_graph,
            owner_pid,
            next_ordinal: 0,
            submitted: HashSet::new(),
            pending: VecDeque::new(),
            active: HashMap::new(),
            completed: VecDeque::new(),
            unreleased_permits: Vec::new(),
            max_active: 0,
            forks: 0,
            scope_workers: 0,
            executor_workers: 0,
            peak_live_pids: 0,
            peak_outstanding_tasks: 0,
            interrupted_signal: None,
            refill_before_collect: false,
            #[cfg(test)]
            readiness_delay: Duration::ZERO,
            #[cfg(test)]
            fail_readiness: false,
            #[cfg(test)]
            fail_pre_ready_reap: false,
        })
    }

    fn drain_nested_groups(&mut self) -> Result<(), String> {
        if self.nested_group_graph.is_none() {
            return Ok(());
        }

        let direct_owners: HashSet<_> = self
            .active
            .iter()
            .filter_map(|(pid, active)| {
                (!active.exited
                    && !active.timed_out
                    && active.interrupted_signal.is_none()
                    && active.term_ns.is_none()
                    && active.cleanup_term_ns.is_none()
                    && active.kill_ns.is_none())
                .then_some(*pid)
            })
            .collect();

        while let Some(message) = self.nested_groups.receive()? {
            self.nested_group_graph
                .as_mut()
                .expect("origin scheduler owns its nested-group graph")
                .apply(message, &self.nested_groups, &direct_owners)?;
        }

        Ok(())
    }

    fn cleanup_nested_descendants(&mut self, owner_pid: libc::pid_t) -> Result<(), String> {
        if let Some(graph) = self.nested_group_graph.as_mut() {
            graph.cleanup_active_descendants(owner_pid)?;
        }

        Ok(())
    }

    fn emit_nested_group(
        &self,
        operation: NestedGroupOperation,
        child_pid: libc::pid_t,
        child_pgid: libc::pid_t,
    ) -> Result<(), String> {
        if self.owner_pid == self.nested_groups.origin_pid {
            return Ok(());
        }

        self.nested_groups
            .emit(operation, self.owner_pid, child_pid, child_pgid)
    }

    #[allow(clippy::too_many_arguments)]
    pub fn submit(
        &mut self,
        id: String,
        kind: String,
        scope_id: String,
        scopes: Vec<String>,
        timeout_ms: u64,
        permit: bool,
    ) -> Result<u32, String> {
        if id.is_empty() || id.len() >= 512 {
            return Err("Drove task IDs must contain between 1 and 511 bytes.".into());
        }

        if timeout_ms > u64::MAX / 1_000_000 {
            return Err("The Drove task deadline overflowed.".into());
        }

        if !matches!(kind.as_str(), "scope" | "test") || scope_id.is_empty() {
            return Err("Drove received an invalid process task identity.".into());
        }

        validate_scopes(&scopes)?;

        let outstanding = self.pending.len() + self.active.len() + self.completed.len();

        if outstanding >= self.queue_capacity {
            return Err(format!(
                "Drover outstanding window reached its {} task capacity.",
                self.queue_capacity
            ));
        }

        if !self.submitted.insert(id.clone()) {
            return Err(format!("Duplicate Drove task ID {id}."));
        }

        let ordinal = self.next_ordinal;
        self.next_ordinal = self
            .next_ordinal
            .checked_add(1)
            .ok_or_else(|| "Drove task ordinal overflowed.".to_string())?;
        let permit_names = if permit {
            self.registry.names(&scopes)
        } else {
            Vec::new()
        };
        self.pending.push_back(Task {
            ordinal,
            id,
            kind,
            scope_id,
            timeout_ms,
            permit_names,
        });
        self.peak_outstanding_tasks = self.peak_outstanding_tasks.max((outstanding + 1) as u32);

        Ok(ordinal)
    }

    pub fn step(&mut self) -> Result<Step, String> {
        if std::mem::take(&mut self.refill_before_collect) && self.interrupted_signal.is_none() {
            if let Some(step) = self.spawn_available()? {
                return Ok(step);
            }
        }

        self.collect()?;

        if self.interrupted_signal.is_none() {
            if let Some(step) = self.spawn_available()? {
                return Ok(step);
            }
        }

        if let Some(result) = self.completed.pop_front() {
            return Ok(Step::Result(Box::new(result)));
        }

        if self.pending.is_empty() && self.active.is_empty() {
            if let Some(graph) = self.nested_group_graph.as_mut() {
                graph.finish()?;
            }

            return Ok(Step::Done);
        }

        self.poll_once()?;
        self.collect()?;
        self.refill_before_collect = true;

        // Return to PHP before refilling so pending signals and cancellation
        // requests are dispatched before another task can be forked.
        Ok(Step::Progress)
    }

    pub fn interrupt(&mut self, signal: i32) -> Result<(), String> {
        if self.interrupted_signal.is_some() {
            return Ok(());
        }

        self.interrupted_signal = Some(signal);
        let now_ns = monotonic_ns().map_err(|error| error.to_string())?;

        while let Some(task) = self.pending.pop_front() {
            self.completed.push_back(failed_result(
                task,
                "user_interruption",
                format!("The Drove run was interrupted by signal {signal}."),
                empty_telemetry(Some(signal)),
                Vec::new(),
            ));
        }

        for active in self.active.values_mut() {
            active.interrupt(signal, now_ns);
        }

        Ok(())
    }

    pub fn active_count(&self) -> usize {
        self.active
            .values()
            .filter(|active| active.running())
            .count()
    }

    pub fn max_active(&self) -> usize {
        self.max_active
    }

    pub fn topology(&self) -> TopologyTelemetry {
        TopologyTelemetry {
            schema: TOPOLOGY_SCHEMA,
            forks: self.forks,
            scope_workers: self.scope_workers,
            executor_workers: self.executor_workers,
            process_anchors: 0,
            peak_live_pids: self.peak_live_pids,
            peak_outstanding_tasks: self.peak_outstanding_tasks,
            outstanding_task_limit: self.queue_capacity as u32,
        }
    }

    fn release_permits(&mut self, names: &[String]) -> Result<(), String> {
        match self.registry.release(names) {
            Ok(()) => Ok(()),
            Err(error) => {
                self.unreleased_permits.extend(error.failed_names());

                Err(error.into())
            }
        }
    }

    fn release_armed_permit(&mut self) -> Result<(), String> {
        self.release_permits(&[ARMED_POOL.into()])
    }

    fn release_spawn_permits(&mut self, task: &Task, permits: SpawnPermits) -> Result<(), String> {
        match permits {
            SpawnPermits::None => Ok(()),
            SpawnPermits::Armed => self.release_armed_permit(),
            SpawnPermits::Task => self.release_permits(&task.permit_names),
        }
    }

    fn wait_pre_ready_child(&mut self, pid: libc::pid_t) -> Result<(), ReapError> {
        #[cfg(test)]
        if std::mem::take(&mut self.fail_pre_ready_reap) {
            return Err(ReapError::TimedOut(format!(
                "injected timeout waiting to reap Drove child {pid}."
            )));
        }

        wait_bounded_checked(pid)
    }

    #[allow(clippy::too_many_arguments)]
    fn retain_pre_ready_child(
        &mut self,
        task: Task,
        pid: libc::pid_t,
        fd: RawFd,
        spawned_ns: u64,
        permits: SpawnPermits,
        registered_nested: bool,
        error: String,
    ) {
        let cleanup_ns = monotonic_ns().unwrap_or(spawned_ns);
        let mut active = ActiveTask::new(
            task,
            pid,
            pid,
            fd,
            spawned_ns,
            &self.run_id,
            registered_nested,
        );

        match permits {
            SpawnPermits::None => {}
            SpawnPermits::Armed => active.mark_armed_permit_acquired(),
            SpawnPermits::Task => active.mark_task_permits_acquired(),
        }

        active.protocol_error = Some(error);
        active.begin_cleanup(cleanup_ns);
        active.kill_ns = Some(cleanup_ns);
        self.active.insert(pid, active);
        self.refill_before_collect = true;
    }

    pub fn cancel(&mut self) -> Result<(), String> {
        let mut failures = Vec::new();

        let retained_permits = std::mem::take(&mut self.unreleased_permits);

        if let Err(error) = self.release_permits(&retained_permits) {
            failures.push(format!("retained permit cleanup: {error}"));
        }

        if let Err(error) = self.drain_nested_groups() {
            failures.push(format!("registry drain before cleanup: {error}"));
        }

        let active = std::mem::take(&mut self.active);
        let mut children: Vec<_> = active.into_values().collect();
        children.sort_by_key(|child| child.task.ordinal);

        for mut child in children {
            let had_protocol_error = child.protocol_error.is_some();
            child.observe_exit(monotonic_ns().unwrap_or_default());

            if child.reaped && !had_protocol_error {
                if let Some(error) = child.protocol_error.as_ref() {
                    failures.push(format!(
                        "executor observation for task {}: {error}",
                        child.task.id
                    ));
                }
            }

            if let Err(error) = self.cleanup_nested_descendants(child.pid) {
                failures.push(format!(
                    "nested cleanup for task {}: {error}",
                    child.task.id
                ));
            }

            if !child.reaped {
                if let Err(error) = signal_process_group_checked(child.pgid, libc::SIGKILL) {
                    if error.raw_os_error() != Some(libc::ESRCH) {
                        failures.push(format!("group cleanup for task {}: {error}", child.task.id));
                    }
                }

                if unsafe { libc::kill(child.pid, libc::SIGKILL) } != 0 {
                    let error = io::Error::last_os_error();

                    if error.raw_os_error() != Some(libc::ESRCH) {
                        failures.push(format!(
                            "executor cleanup for task {}: {error}",
                            child.task.id
                        ));
                    }
                }
            }

            if child.registered_nested && !child.retired_nested {
                match self.emit_nested_group(NestedGroupOperation::Retire, child.pid, child.pgid) {
                    Ok(()) => child.retired_nested = true,
                    Err(error) => {
                        failures.push(format!("nested RETIRE for task {}: {error}", child.task.id));
                    }
                }
            }

            if !child.reaped {
                match wait_bounded_checked(child.pid) {
                    Ok(()) => child.reaped = true,
                    Err(ReapError::TimedOut(error)) => {
                        failures.push(format!("reap for task {}: {error}", child.task.id));
                        self.active.insert(child.pid, child);

                        continue;
                    }
                    Err(error) => {
                        failures.push(format!("reap for task {}: {error}", child.task.id));
                    }
                }
            }

            if child.registered_nested {
                if let Err(error) =
                    self.emit_nested_group(NestedGroupOperation::Unregister, child.pid, child.pgid)
                {
                    failures.push(format!(
                        "nested UNREGISTER for task {}: {error}",
                        child.task.id
                    ));
                }
            }

            if unsafe { libc::close(child.fd) } != 0 {
                failures.push(format!(
                    "channel close for task {}: {}",
                    child.task.id,
                    io::Error::last_os_error()
                ));
            }

            if child.owns_task_permits() {
                if let Err(error) = self.release_permits(&child.task.permit_names) {
                    failures.push(format!(
                        "permit release for task {}: {error}",
                        child.task.id
                    ));
                }
            }

            if child.owns_armed_permit() {
                if let Err(error) = self.release_armed_permit() {
                    failures.push(format!(
                        "armed-permit release for task {}: {error}",
                        child.task.id
                    ));
                }
            }
        }

        if let Err(error) = self.drain_nested_groups() {
            failures.push(format!("registry drain after cleanup: {error}"));
        }

        if let Some(graph) = self.nested_group_graph.as_mut() {
            if let Err(error) = graph.finish() {
                failures.push(format!("registry finish: {error}"));
            }
        }

        self.pending.clear();

        if failures.is_empty() {
            Ok(())
        } else {
            Err(format!(
                "Drover cancellation failed: {}",
                failures.join(" | ")
            ))
        }
    }

    fn spawn_available(&mut self) -> Result<Option<Step>, String> {
        let mut progressed = self.start_available()?;

        if let Some(index) = self
            .pending
            .iter()
            .position(|task| task.permit_names.is_empty())
        {
            let task = self
                .pending
                .remove(index)
                .expect("permit-free task existed while spawning");

            return self.spawn(task, true, SpawnPermits::None).map(Some);
        }

        loop {
            let mut reserved = None;

            for index in 0..self.pending.len() {
                let task = self
                    .pending
                    .get(index)
                    .expect("pending index came from its length");

                if task.can_prearm() {
                    if self.registry.try_acquire_armed()? {
                        reserved = Some((index, SpawnPermits::Armed));
                    } else if self.registry.try_acquire(&task.permit_names)? {
                        reserved = Some((index, SpawnPermits::Task));
                    }

                    break;
                }

                if self.registry.try_acquire(&task.permit_names)? {
                    reserved = Some((index, SpawnPermits::Task));

                    break;
                }
            }

            let Some((index, permits)) = reserved else {
                break;
            };
            let task = self
                .pending
                .remove(index)
                .expect("pending task existed while reserving its launch");
            let active = self.active.len();
            let start_immediately = matches!(permits, SpawnPermits::Task);
            let step = self.spawn(task, start_immediately, permits)?;

            if !matches!(step, Step::Progress) {
                return Ok(Some(step));
            }

            if self.active.len() == active {
                return Ok(Some(Step::Progress));
            }

            progressed = true;

            if matches!(permits, SpawnPermits::Armed) && self.start_available()? {
                progressed = true;
            }
        }

        Ok(progressed.then_some(Step::Progress))
    }

    fn start_available(&mut self) -> Result<bool, String> {
        let mut pids: Vec<_> = self
            .active
            .iter()
            .filter_map(|(pid, active)| active.armed().then_some(*pid))
            .collect();
        pids.sort_by_key(|pid| {
            self.active
                .get(pid)
                .map(|active| active.task.ordinal)
                .unwrap_or(u32::MAX)
        });
        let mut progressed = false;

        for pid in pids {
            if !self.registry.take(GLOBAL_POOL)? {
                continue;
            }

            let started_ns = match monotonic_ns() {
                Ok(started_ns) => started_ns,
                Err(error) => {
                    self.release_permits(&[GLOBAL_POOL.into()])?;

                    return Err(error.to_string());
                }
            };
            self.start_task(pid, started_ns, true)?;
            progressed = true;
        }

        Ok(progressed)
    }

    fn start_task(
        &mut self,
        pid: libc::pid_t,
        start_attempt_ns: u64,
        task_permits: bool,
    ) -> Result<(), String> {
        let start_error = {
            let active = self
                .active
                .get_mut(&pid)
                .ok_or_else(|| format!("Drover lost pre-armed executor {pid}."))?;

            write_start(active.fd).err()
        };

        if let Some(error) = start_error {
            {
                let active = self
                    .active
                    .get_mut(&pid)
                    .expect("pre-armed executor existed while handling start failure");
                active.mark_start_failed();
                active.protocol_error = Some(format!(
                    "Drover could not start task {} in its pre-armed executor: {error}.",
                    active.task.id
                ));
                active.begin_cleanup(start_attempt_ns);
            }

            if task_permits {
                let permit_names = self
                    .active
                    .get(&pid)
                    .expect("failed-to-start executor existed while releasing permits")
                    .task
                    .permit_names
                    .clone();
                self.release_permits(&permit_names)?;
            }
        } else {
            // The task timeout covers task execution, not time spent armed or
            // descheduled before the start byte reaches the executor.
            let started_ns = monotonic_ns().unwrap_or(start_attempt_ns);
            let (kind, owned_armed_permit) = {
                let active = self
                    .active
                    .get_mut(&pid)
                    .expect("pre-armed executor existed while starting");
                let owned_armed_permit = active.owns_armed_permit();
                active.mark_started(started_ns, task_permits);

                (active.task.kind.clone(), owned_armed_permit)
            };
            let (scope_workers, executor_workers) = worker_counts(&kind, true);
            self.scope_workers += u64::from(scope_workers);
            self.executor_workers += u64::from(executor_workers);
            self.max_active = self.max_active.max(self.active_count());

            if owned_armed_permit {
                self.release_armed_permit()?;
            }
        }

        self.refill_before_collect = true;

        Ok(())
    }

    fn spawn(
        &mut self,
        task: Task,
        start_immediately: bool,
        permits: SpawnPermits,
    ) -> Result<Step, String> {
        if let Err(error) = validate_sigchld_disposition() {
            self.release_spawn_permits(&task, permits)?;

            return Err(error);
        }

        let spawned_ns = match monotonic_ns() {
            Ok(spawned_ns) => spawned_ns,
            Err(error) => {
                self.release_spawn_permits(&task, permits)?;

                return Err(error.to_string());
            }
        };
        let sockets = match socket_pair() {
            Ok(sockets) => sockets,
            Err(error) => {
                self.release_spawn_permits(&task, permits)?;
                self.completed.push_back(failed_result(
                    task,
                    "fork_failure",
                    format!("Unable to create a Drove child channel: {error}."),
                    empty_telemetry(None),
                    Vec::new(),
                ));

                return Ok(Step::Progress);
            }
        };

        let ready_sockets = match socket_pair() {
            Ok(sockets) => sockets,
            Err(error) => {
                close_descriptors(sockets);
                self.release_spawn_permits(&task, permits)?;
                self.completed.push_back(failed_result(
                    task,
                    "fork_failure",
                    format!("Unable to create a Drove process-group channel: {error}."),
                    empty_telemetry(None),
                    Vec::new(),
                ));

                return Ok(Step::Progress);
            }
        };

        if let Err(error) = set_nonblocking(sockets[0]) {
            close_descriptors(sockets);
            close_descriptors(ready_sockets);
            self.release_spawn_permits(&task, permits)?;
            self.completed.push_back(failed_result(
                task,
                "fork_failure",
                error,
                empty_telemetry(None),
                Vec::new(),
            ));

            return Ok(Step::Progress);
        }

        let registered_nested = self.owner_pid != self.nested_groups.origin_pid;
        let pid = unsafe { libc::fork() };

        if pid == -1 {
            close_descriptors(sockets);
            close_descriptors(ready_sockets);
            self.release_spawn_permits(&task, permits)?;
            self.completed.push_back(failed_result(
                task,
                "fork_failure",
                format!(
                    "Unable to fork a Drove task: {}.",
                    io::Error::last_os_error()
                ),
                empty_telemetry(None),
                Vec::new(),
            ));

            return Ok(Step::Progress);
        }

        if pid == 0 {
            unsafe {
                libc::close(sockets[0]);
                libc::close(ready_sockets[1]);

                for active in self.active.values() {
                    libc::close(active.fd);
                }

                // The executor may host a nested Scope IR dispatch. Permit
                // registry descriptors are inherited shared state and must
                // remain live for nested maps/withPermit() in this process.
            }

            if unsafe { libc::setpgid(0, 0) } != 0 {
                write_group_ready(ready_sockets[0], false);
                write_process_group_failure(sockets[1], &self.run_id, &task);

                unsafe {
                    libc::_exit(1);
                }
            }

            if registered_nested {
                let child_pid = unsafe { libc::getpid() };
                let child_pgid = unsafe { libc::getpgrp() };

                if self
                    .emit_nested_group(NestedGroupOperation::Register, child_pid, child_pgid)
                    .is_err()
                {
                    write_nested_group_ready_failure(ready_sockets[0]);

                    unsafe {
                        libc::_exit(1);
                    }
                }
            }

            #[cfg(test)]
            if !self.readiness_delay.is_zero() {
                std::thread::sleep(self.readiness_delay);
            }

            #[cfg(test)]
            if self.fail_readiness {
                write_nested_group_ready_failure(ready_sockets[0]);

                unsafe {
                    libc::_exit(1);
                }
            }

            if registered_nested && !write_group_ready(ready_sockets[0], true) {
                unsafe {
                    libc::_exit(1);
                }
            }

            unsafe {
                libc::close(ready_sockets[0]);
            }

            if read_start(sockets[1]).is_err() {
                unsafe {
                    libc::close(sockets[1]);
                    libc::_exit(1);
                }
            }

            return Ok(Step::Child(ChildRole {
                ordinal: task.ordinal,
                task_id: task.id,
                pid: unsafe { libc::getpid() },
                fd: sockets[1],
            }));
        }

        let parent_group_error = unsafe {
            libc::close(sockets[1]);
            libc::close(ready_sockets[0]);
            // Root executors never exec, so a successful parent setpgid()
            // establishes their process group without waiting for the child.
            (libc::setpgid(pid, pid) != 0).then(|| {
                format!(
                    "Drover could not create the executor process group: {}.",
                    io::Error::last_os_error()
                )
            })
        };
        self.forks += 1;
        self.peak_live_pids = self.peak_live_pids.max((self.active.len() + 1) as u32);

        let group_ready = if registered_nested {
            read_group_ready(
                ready_sockets[1],
                spawned_ns.saturating_add(PROCESS_BOUNDARY_TIMEOUT_NS),
            )
        } else if let Some(error) = parent_group_error {
            Err(error)
        } else {
            Ok(GroupReadiness::Ready)
        };

        unsafe {
            libc::close(ready_sockets[1]);
        }

        if let Err(error) = group_ready {
            let mut failures = vec![error];

            for (target, label) in [(-pid, "process group"), (pid, "executor")] {
                if unsafe { libc::kill(target, libc::SIGKILL) } != 0 {
                    let error = io::Error::last_os_error();

                    if error.raw_os_error() != Some(libc::ESRCH) {
                        failures.push(format!(
                            "Drover could not kill the pre-ready {label} for task {}: {error}.",
                            task.id,
                        ));
                    }
                }
            }

            match self.wait_pre_ready_child(pid) {
                Ok(()) => unsafe {
                    libc::close(sockets[0]);
                },
                Err(reap_error) => {
                    failures.push(reap_error.to_string());
                    self.retain_pre_ready_child(
                        task,
                        pid,
                        sockets[0],
                        spawned_ns,
                        permits,
                        registered_nested,
                        failures.join(" "),
                    );

                    return Ok(Step::Progress);
                }
            }

            if registered_nested {
                if let Err(registry_error) =
                    self.emit_nested_group(NestedGroupOperation::Retire, pid, pid)
                {
                    failures.push(registry_error);
                }
                if let Err(registry_error) =
                    self.emit_nested_group(NestedGroupOperation::Unregister, pid, pid)
                {
                    failures.push(registry_error);
                }
            }
            self.release_spawn_permits(&task, permits)?;

            self.completed.push_back(failed_result(
                task,
                "fork_failure",
                failures.join(" "),
                telemetry(None, None, None, None, None, None, None, 1, 0, 0, 0),
                Vec::new(),
            ));

            return Ok(Step::Progress);
        }

        let mut active = ActiveTask::new(
            task,
            pid,
            pid,
            sockets[0],
            spawned_ns,
            &self.run_id,
            registered_nested,
        );

        match permits {
            SpawnPermits::None => {}
            SpawnPermits::Armed => active.mark_armed_permit_acquired(),
            SpawnPermits::Task => active.mark_task_permits_acquired(),
        }

        self.active.insert(pid, active);
        if start_immediately {
            let started_ns = monotonic_ns().map_err(|error| error.to_string())?;
            self.start_task(pid, started_ns, matches!(permits, SpawnPermits::Task))?;
        }
        self.refill_before_collect = true;

        Ok(Step::Progress)
    }

    fn collect(&mut self) -> Result<(), String> {
        self.drain_nested_groups()?;
        let now_ns = monotonic_ns().map_err(|error| error.to_string())?;
        let mut pids: Vec<_> = self.active.keys().copied().collect();
        pids.sort_by_key(|pid| {
            self.active
                .get(pid)
                .map(|active| active.task.ordinal)
                .unwrap_or(u32::MAX)
        });
        let mut ready = Vec::new();

        for pid in pids {
            self.drain_nested_groups()?;

            let should_retire = {
                let Some(active) = self.active.get_mut(&pid) else {
                    continue;
                };

                active.read_available();
                active.observe_exit(now_ns);
                active.read_available();
                active.enforce(now_ns, self.grace_ns);

                active.needs_nested_retirement()
            };

            if should_retire {
                self.cleanup_nested_descendants(pid)?;
                let should_emit = self
                    .active
                    .get(&pid)
                    .is_some_and(|active| active.registered_nested && !active.retired_nested);

                if should_emit {
                    self.emit_nested_group(NestedGroupOperation::Retire, pid, pid)?;
                }

                if let Some(active) = self.active.get_mut(&pid) {
                    active.retired_nested = true;
                }
            }

            self.drain_nested_groups()?;

            let active_ready = {
                let Some(active) = self.active.get_mut(&pid) else {
                    continue;
                };

                active.reap(now_ns);
                active.normalize_terminal_consistency();
                active.ready()
            };

            self.drain_nested_groups()?;

            if active_ready {
                ready.push(pid);

                if self.interrupted_signal.is_none()
                    && self
                        .active
                        .get(&pid)
                        .is_some_and(ActiveTask::owns_task_permits)
                    && self.active.values().any(ActiveTask::armed)
                {
                    break;
                }
            }
        }

        for pid in ready {
            if let Some(active) = self.active.remove(&pid) {
                if active.registered_nested {
                    self.emit_nested_group(NestedGroupOperation::Unregister, pid, active.pgid)?;
                }
                unsafe {
                    libc::close(active.fd);
                }
                if active.owns_task_permits() {
                    self.release_permits(&active.task.permit_names)?;
                }
                if active.owns_armed_permit() {
                    self.release_armed_permit()?;
                }
                self.completed.push_back(active.into_result());
            }
        }

        self.drain_nested_groups()?;

        Ok(())
    }

    fn poll_once(&self) -> Result<(), String> {
        let mut descriptors = Vec::new();
        let mut seen = HashSet::new();

        for active in self.active.values() {
            if !active.eof && seen.insert(active.fd) {
                descriptors.push(libc::pollfd {
                    fd: active.fd,
                    events: libc::POLLIN | libc::POLLHUP | libc::POLLERR,
                    revents: 0,
                });
            }
        }

        if self.nested_group_graph.is_some() && seen.insert(self.nested_groups.read) {
            descriptors.push(libc::pollfd {
                fd: self.nested_groups.read,
                events: libc::POLLIN | libc::POLLHUP | libc::POLLERR,
                revents: 0,
            });
        }

        let pending_needs_armed_permit = self.pending.iter().any(Task::can_prearm);
        self.registry.append_poll_descriptors(
            self.active
                .values()
                .filter(|active| active.armed())
                .flat_map(|active| active.task.permit_names.iter().cloned())
                .chain(
                    self.pending
                        .iter()
                        .filter(|task| !task.can_prearm())
                        .flat_map(|task| task.permit_names.iter().cloned()),
                )
                .chain(
                    pending_needs_armed_permit
                        .then(|| ARMED_POOL.to_string())
                        .into_iter(),
                ),
            &mut seen,
            &mut descriptors,
        )?;

        poll_descriptors(&mut descriptors, self.poll_timeout_ms())
    }

    fn poll_timeout_ms(&self) -> i32 {
        let Ok(now_ns) = monotonic_ns() else {
            return 0;
        };
        let mut timeout_ns = 5_000_000_u64;

        for active in self.active.values() {
            let target = if let Some(kill_ns) = active.kill_ns {
                kill_ns.saturating_add(self.grace_ns.max(PROCESS_BOUNDARY_TIMEOUT_NS))
            } else if let Some(term_ns) = active.term_ns.or(active.cleanup_term_ns) {
                term_ns.saturating_add(self.grace_ns)
            } else if let Some(stopped_ns) = active.terminal_received_ns.or(active.eof_ns) {
                stopped_ns.saturating_add(self.grace_ns.max(PROCESS_BOUNDARY_TIMEOUT_NS))
            } else if let Some(deadline_ns) = active.deadline_ns {
                deadline_ns.max(
                    active
                        .terminal_received_ns
                        .map(|received| received.saturating_add(self.grace_ns))
                        .unwrap_or_default(),
                )
            } else {
                continue;
            };

            timeout_ns = timeout_ns.min(target.saturating_sub(now_ns));
        }

        (timeout_ns / 1_000_000).min(5) as i32
    }
}

impl Drop for Scheduler {
    fn drop(&mut self) {
        if let Err(error) = self.cancel() {
            write_stderr(b"Drover best-effort cleanup failure: ");
            write_stderr(error.as_bytes());
            write_stderr(b"\n");
        }
    }
}

fn validate_scopes(scopes: &[String]) -> Result<(), String> {
    if scopes.iter().any(String::is_empty) {
        return Err("Drove task scopes cannot be empty.".into());
    }

    let unique: HashSet<_> = scopes.iter().collect();

    if unique.len() != scopes.len() {
        return Err("Drove task scopes cannot contain duplicates.".into());
    }

    Ok(())
}

fn create_pool(limit: usize) -> Result<PermitPool, String> {
    let sockets = socket_pair()
        .map_err(|error| format!("Drover could not create a concurrency permit pool: {error}."))?;

    if let Err(error) = set_nonblocking(sockets[0]) {
        close_descriptors(sockets);

        return Err(error);
    }

    for _ in 0..limit {
        if let Err(error) = write_byte(sockets[1]) {
            close_descriptors(sockets);

            return Err(error);
        }
    }

    Ok(PermitPool {
        read: sockets[0],
        write: sockets[1],
    })
}

fn socket_pair() -> Result<[RawFd; 2], io::Error> {
    socket_pair_of_type(libc::SOCK_STREAM)
}

fn datagram_socket_pair() -> Result<[RawFd; 2], io::Error> {
    socket_pair_of_type(libc::SOCK_DGRAM)
}

fn socket_pair_of_type(socket_type: libc::c_int) -> Result<[RawFd; 2], io::Error> {
    let mut sockets = [0_i32; 2];

    #[cfg(target_os = "linux")]
    let socket_type = socket_type | libc::SOCK_CLOEXEC;

    if unsafe { libc::socketpair(libc::AF_UNIX, socket_type, 0, sockets.as_mut_ptr()) } != 0 {
        return Err(io::Error::last_os_error());
    }

    #[cfg(not(target_os = "linux"))]
    for fd in sockets {
        let flags = unsafe { libc::fcntl(fd, libc::F_GETFD) };

        if flags == -1 || unsafe { libc::fcntl(fd, libc::F_SETFD, flags | libc::FD_CLOEXEC) } == -1
        {
            let error = io::Error::last_os_error();
            close_descriptors(sockets);

            return Err(error);
        }
    }

    #[cfg(target_os = "macos")]
    for fd in sockets {
        let enabled: libc::c_int = 1;
        let result = unsafe {
            libc::setsockopt(
                fd,
                libc::SOL_SOCKET,
                libc::SO_NOSIGPIPE,
                (&enabled as *const libc::c_int).cast(),
                std::mem::size_of_val(&enabled) as libc::socklen_t,
            )
        };

        if result != 0 {
            let error = io::Error::last_os_error();
            close_descriptors(sockets);

            return Err(error);
        }
    }

    Ok(sockets)
}

fn close_descriptors(descriptors: [RawFd; 2]) {
    for descriptor in descriptors {
        unsafe {
            libc::close(descriptor);
        }
    }
}

fn close_pools(pools: &HashMap<String, PermitPool>) {
    for pool in pools.values() {
        unsafe {
            libc::close(pool.read);
            libc::close(pool.write);
        }
    }
}

fn write_byte(fd: RawFd) -> Result<(), String> {
    let byte = b'.';

    match send_socket(fd, &[byte]) {
        Ok(1) => Ok(()),
        Ok(_) => Err("Drover could not release a complete concurrency permit.".into()),
        Err(error) => Err(format!(
            "Drover could not release a concurrency permit: {error}."
        )),
    }
}

fn write_start(fd: RawFd) -> Result<(), io::Error> {
    match send_socket(fd, &[EXECUTOR_START_BYTE])? {
        1 => Ok(()),
        _ => Err(io::Error::new(
            io::ErrorKind::WriteZero,
            "Drover could not write the executor start signal.",
        )),
    }
}

fn send_socket(fd: RawFd, bytes: &[u8]) -> Result<usize, io::Error> {
    loop {
        let written =
            unsafe { libc::send(fd, bytes.as_ptr().cast(), bytes.len(), SOCKET_SEND_FLAGS) };

        if written >= 0 {
            return Ok(written as usize);
        }

        let error = io::Error::last_os_error();

        if error.kind() == io::ErrorKind::Interrupted {
            continue;
        }

        return Err(error);
    }
}

fn read_start(fd: RawFd) -> Result<(), io::Error> {
    let mut byte = 0_u8;

    loop {
        let read = unsafe { libc::read(fd, (&mut byte as *mut u8).cast(), 1) };

        if read == 1 {
            return if byte == EXECUTOR_START_BYTE {
                Ok(())
            } else {
                Err(io::Error::new(
                    io::ErrorKind::InvalidData,
                    "Drover received an invalid executor start signal.",
                ))
            };
        }

        if read == 0 {
            return Err(io::Error::new(
                io::ErrorKind::UnexpectedEof,
                "Drover closed an executor before signaling its start.",
            ));
        }

        let error = io::Error::last_os_error();

        if error.kind() == io::ErrorKind::Interrupted {
            continue;
        }

        return Err(error);
    }
}

fn set_nonblocking(fd: RawFd) -> Result<(), String> {
    let flags = unsafe { libc::fcntl(fd, libc::F_GETFL) };

    if flags == -1 || unsafe { libc::fcntl(fd, libc::F_SETFL, flags | libc::O_NONBLOCK) } == -1 {
        return Err(format!(
            "Drover could not make a descriptor nonblocking: {}.",
            io::Error::last_os_error()
        ));
    }

    Ok(())
}

fn poll_descriptors(descriptors: &mut [libc::pollfd], timeout_ms: i32) -> Result<(), String> {
    if descriptors.is_empty() {
        return Ok(());
    }

    let result = unsafe {
        libc::poll(
            descriptors.as_mut_ptr(),
            descriptors.len() as libc::nfds_t,
            timeout_ms,
        )
    };

    if result == -1 {
        let error = io::Error::last_os_error();

        if error.kind() != io::ErrorKind::Interrupted {
            return Err(format!("Drover poll failed: {error}."));
        }
    }

    Ok(())
}

#[derive(Debug)]
enum ReapError {
    TimedOut(String),
    Lost(String),
}

impl std::fmt::Display for ReapError {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::TimedOut(error) | Self::Lost(error) => formatter.write_str(error),
        }
    }
}

fn wait_bounded_checked(pid: libc::pid_t) -> Result<(), ReapError> {
    let deadline_ns = monotonic_ns()
        .map_err(|error| ReapError::Lost(error.to_string()))?
        .saturating_add(PROCESS_BOUNDARY_TIMEOUT_NS);

    loop {
        let result = unsafe { libc::waitpid(pid, std::ptr::null_mut(), libc::WNOHANG) };

        if result == pid {
            return Ok(());
        }

        if result == 0 {
            let now_ns = monotonic_ns().map_err(|error| ReapError::Lost(error.to_string()))?;

            if now_ns >= deadline_ns {
                return Err(ReapError::TimedOut(format!(
                    "timed out waiting to reap Drove child {pid}."
                )));
            }

            std::thread::sleep(Duration::from_millis(1));

            continue;
        }

        if result == -1 {
            let error = io::Error::last_os_error();

            if error.kind() == io::ErrorKind::Interrupted {
                continue;
            }

            return Err(ReapError::Lost(format!(
                "waitpid() lost Drove child {pid}: {error}."
            )));
        }
    }
}

fn write_stderr(mut bytes: &[u8]) {
    while !bytes.is_empty() {
        let written =
            unsafe { libc::write(libc::STDERR_FILENO, bytes.as_ptr().cast(), bytes.len()) };

        if written > 0 {
            bytes = &bytes[written as usize..];
            continue;
        }

        if written == -1 && io::Error::last_os_error().kind() == io::ErrorKind::Interrupted {
            continue;
        }

        return;
    }
}

fn validate_sigchld_disposition() -> Result<(), String> {
    let mut action = unsafe { std::mem::zeroed::<libc::sigaction>() };

    if unsafe { libc::sigaction(libc::SIGCHLD, std::ptr::null(), &mut action) } != 0 {
        return Err(format!(
            "Drover could not inspect SIGCHLD: {}.",
            io::Error::last_os_error()
        ));
    }

    if action.sa_sigaction != libc::SIG_DFL || action.sa_flags & libc::SA_NOCLDWAIT != 0 {
        return Err(
            "Drover requires SIGCHLD to use the default waitable-child disposition.".into(),
        );
    }

    Ok(())
}

fn write_group_ready(fd: RawFd, ready: bool) -> bool {
    let byte = if ready { b'.' } else { b'!' };

    matches!(send_socket(fd, &[byte]), Ok(1))
}

fn write_nested_group_ready_failure(fd: RawFd) -> bool {
    let byte = b'?';

    matches!(send_socket(fd, &[byte]), Ok(1))
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum GroupReadiness {
    Ready,
}

fn read_group_ready(fd: RawFd, deadline_ns: u64) -> Result<GroupReadiness, String> {
    let mut byte = 0_u8;

    loop {
        let now_ns = monotonic_ns().map_err(|error| error.to_string())?;

        if now_ns >= deadline_ns {
            return Err("The Drove executor timed out before its process group was ready.".into());
        }

        let timeout_ms = deadline_ns
            .saturating_sub(now_ns)
            .div_ceil(1_000_000)
            .min(i32::MAX as u64) as i32;
        let mut descriptor = libc::pollfd {
            fd,
            events: libc::POLLIN | libc::POLLHUP | libc::POLLERR,
            revents: 0,
        };
        let polled = unsafe { libc::poll(&mut descriptor, 1, timeout_ms) };

        if polled == 0 {
            return Err("The Drove executor timed out before its process group was ready.".into());
        }

        if polled == -1 {
            let error = io::Error::last_os_error();

            if error.kind() == io::ErrorKind::Interrupted {
                continue;
            }

            return Err(format!(
                "Drover could not wait for executor process-group readiness: {error}."
            ));
        }

        let read = unsafe { libc::read(fd, (&mut byte as *mut u8).cast(), 1) };

        if read == 1 {
            return match byte {
                b'.' => Ok(GroupReadiness::Ready),
                b'!' => Err("The Drove executor could not create its process group.".into()),
                b'?' => {
                    Err("The Drove executor could not register its nested process group.".into())
                }
                _ => Err("The Drove executor sent an invalid process-group signal.".into()),
            };
        }

        if read == -1 {
            let error = io::Error::last_os_error();

            if error.kind() == io::ErrorKind::Interrupted {
                continue;
            }

            return Err(format!(
                "Drover could not read executor process-group readiness: {error}."
            ));
        }

        return Err("The Drove executor exited before its process group was ready.".into());
    }
}

#[cfg(any(target_os = "macos", test))]
fn confirm_enumerated_process_group<F>(
    pgid: libc::pid_t,
    members: &[libc::pid_t],
    returned: usize,
    mut probe_member: F,
) -> Result<(), String>
where
    F: FnMut(libc::pid_t, i32) -> Result<(), io::Error>,
{
    if returned == 0 {
        return Err(format!(
            "Darwin returned no members for process group {pgid}."
        ));
    }

    if returned >= members.len() {
        return Err(format!(
            "Darwin returned {returned} PIDs for the {}-PID buffer for process group {pgid}; \
             enumeration may be truncated.",
            members.len()
        ));
    }

    let listed = &members[..returned];

    if listed.iter().any(|member| *member <= 0) {
        return Err(format!(
            "Darwin returned an invalid member for process group {pgid}."
        ));
    }

    if !listed.contains(&pgid) {
        return Err(format!(
            "Darwin omitted the retained leader from process group {pgid}."
        ));
    }

    for member in listed {
        if let Err(error) = probe_member(*member, 0) {
            if error.raw_os_error() == Some(libc::ESRCH) {
                continue;
            }

            return Err(format!(
                "Drover could not confirm Darwin process-group member {member}: {error}."
            ));
        }
    }

    Ok(())
}

#[cfg(target_os = "macos")]
fn confirm_darwin_process_group(pgid: libc::pid_t) -> Result<(), String> {
    let buffer_bytes = MAX_DARWIN_PROCESS_GROUP_PIDS
        .checked_mul(std::mem::size_of::<libc::pid_t>())
        .and_then(|bytes| libc::c_int::try_from(bytes).ok())
        .ok_or_else(|| format!("Darwin process group {pgid} exceeded its buffer bound."))?;
    let mut members = vec![0; MAX_DARWIN_PROCESS_GROUP_PIDS];

    unsafe {
        *libc::__error() = 0;
    }
    let returned = unsafe {
        proc_listpgrppids(
            pgid,
            members.as_mut_ptr().cast::<libc::c_void>(),
            buffer_bytes,
        )
    };
    let list_error = io::Error::last_os_error();

    if returned <= 0 {
        return if list_error.raw_os_error() == Some(0) {
            Err(format!(
                "Darwin returned no members for process group {pgid}."
            ))
        } else {
            Err(format!(
                "Darwin could not enumerate process group {pgid}: {list_error}."
            ))
        };
    }

    confirm_enumerated_process_group(
        pgid,
        &members,
        usize::try_from(returned)
            .map_err(|_| format!("Darwin returned an invalid count for process group {pgid}."))?,
        |member, signal| {
            if unsafe { libc::kill(member, signal) } == 0 {
                Ok(())
            } else {
                Err(io::Error::last_os_error())
            }
        },
    )
}

fn signal_process_group_checked(pgid: libc::pid_t, signal: i32) -> Result<(), io::Error> {
    let error = match signal_process_group(pgid, signal) {
        Ok(()) => return Ok(()),
        Err(error) => error,
    };

    #[cfg(target_os = "macos")]
    if signal == libc::SIGKILL && error.raw_os_error() == Some(libc::EPERM) {
        return confirm_darwin_process_group(pgid).map_err(io::Error::other);
    }

    Err(error)
}

fn signal_process_group(pgid: libc::pid_t, signal: i32) -> Result<(), io::Error> {
    if unsafe { libc::kill(-pgid, signal) } == 0 {
        return Ok(());
    }

    Err(io::Error::last_os_error())
}

#[allow(clippy::too_many_arguments)]
fn telemetry(
    pid: Option<libc::pid_t>,
    pgid: Option<libc::pid_t>,
    started_ns: Option<u64>,
    finished_ns: Option<u64>,
    exit_code: Option<i32>,
    signal: Option<i32>,
    interrupted_signal: Option<i32>,
    forks: u32,
    scope_workers: u32,
    executor_workers: u32,
    process_anchors: u32,
) -> Telemetry {
    let duration_ms = started_ns.zip(finished_ns).map(|(started, finished)| {
        let duration = finished.saturating_sub(started) as f64 / 1_000_000.0;

        (duration * 1_000.0).round() / 1_000.0
    });

    Telemetry {
        pid,
        pgid,
        started_ns,
        finished_ns,
        duration_ms,
        exit_code,
        signal,
        interrupted_signal,
        forks,
        scope_workers,
        executor_workers,
        process_anchors,
    }
}

fn empty_telemetry(interrupted_signal: Option<i32>) -> Telemetry {
    telemetry(
        None,
        None,
        None,
        None,
        None,
        None,
        interrupted_signal,
        0,
        0,
        0,
        0,
    )
}

fn worker_counts(kind: &str, ready: bool) -> (u32, u32) {
    if !ready {
        return (0, 0);
    }

    if kind == "scope" {
        (1, 0)
    } else {
        (0, 1)
    }
}

fn scheduler_failure(kind: &str, message: String) -> Value {
    json!({
        "kind": kind,
        "message": message,
        "class": null,
        "file": null,
        "line": null,
        "phase": "scheduler",
        "hook_id": null
    })
}

fn failed_result(
    task: Task,
    kind: &str,
    message: String,
    telemetry: Telemetry,
    events: Vec<Frame>,
) -> TaskResult {
    TaskResult {
        id: task.id,
        kind: task.kind,
        scope_id: task.scope_id,
        ordinal: task.ordinal,
        status: "failed".into(),
        failure: Some(scheduler_failure(kind, message)),
        value: Value::Null,
        stdout: String::new(),
        stderr: String::new(),
        memory_peak_bytes: None,
        events,
        telemetry,
    }
}

fn write_process_group_failure(fd: RawFd, run_id: &str, task: &Task) {
    let Ok(started_ns) = monotonic_ns() else {
        return;
    };
    let Ok(started) = Frame::new(
        run_id.into(),
        task.id.clone(),
        task.kind.clone(),
        task.scope_id.clone(),
        task.ordinal,
        0,
        "task.started".into(),
        json!({ "started_ns": started_ns }),
    ) else {
        return;
    };
    let finished_ns = monotonic_ns().unwrap_or(started_ns);
    let Ok(finished) = Frame::new(
        run_id.into(),
        task.id.clone(),
        task.kind.clone(),
        task.scope_id.clone(),
        task.ordinal,
        1,
        "task.finished".into(),
        json!({
            "status": "failed",
            "failure": {
                "kind": "fork_failure",
                "message": "The Drove child could not create its process group.",
                "class": null,
                "file": null,
                "line": null,
                "phase": "process_group",
                "hook_id": null
            },
            "finished_ns": finished_ns,
            "memory_peak_bytes": null
        }),
    ) else {
        return;
    };

    let _ = write_frame(fd, &started);
    let _ = write_frame(fd, &finished);
}

impl From<ProtocolError> for String {
    fn from(error: ProtocolError) -> Self {
        error.to_string()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::{Seek, SeekFrom, Write};
    use std::os::fd::AsRawFd;
    use std::sync::atomic::{AtomicUsize, Ordering};

    static READINESS_SIGNAL_COUNT: AtomicUsize = AtomicUsize::new(0);
    static SIGPIPE_SIGNAL_COUNT: AtomicUsize = AtomicUsize::new(0);

    extern "C" fn readiness_signal_handler(_: libc::c_int) {
        READINESS_SIGNAL_COUNT.fetch_add(1, Ordering::Relaxed);
    }

    extern "C" fn sigpipe_signal_handler(_: libc::c_int) {
        SIGPIPE_SIGNAL_COUNT.fetch_add(1, Ordering::Relaxed);
    }

    struct SignalDispositionGuard {
        signal: libc::c_int,
        previous: libc::sigaction,
    }

    impl SignalDispositionGuard {
        fn install(signal: libc::c_int, handler: usize) -> Self {
            let mut action: libc::sigaction = unsafe { std::mem::zeroed() };
            let mut previous: libc::sigaction = unsafe { std::mem::zeroed() };
            action.sa_sigaction = handler;
            action.sa_flags = 0;
            unsafe {
                libc::sigemptyset(&mut action.sa_mask);
            }
            assert_eq!(
                unsafe { libc::sigaction(signal, &action, &mut previous) },
                0
            );

            Self { signal, previous }
        }
    }

    impl Drop for SignalDispositionGuard {
        fn drop(&mut self) {
            unsafe {
                libc::sigaction(self.signal, &self.previous, std::ptr::null_mut());
            }
        }
    }

    fn current_signal_handler(signal: libc::c_int) -> usize {
        let mut action: libc::sigaction = unsafe { std::mem::zeroed() };
        assert_eq!(
            unsafe { libc::sigaction(signal, std::ptr::null(), &mut action) },
            0
        );

        action.sa_sigaction
    }

    fn exit_passing_child(run_id: &str, child: ChildRole) -> ! {
        let started_ns = monotonic_ns().unwrap();
        let started = Frame::new(
            run_id.into(),
            child.task_id.clone(),
            "test".into(),
            "scope:root".into(),
            child.ordinal,
            0,
            "task.started".into(),
            json!({ "started_ns": started_ns }),
        )
        .unwrap();
        let value = Frame::new(
            run_id.into(),
            child.task_id.clone(),
            "test".into(),
            "scope:root".into(),
            child.ordinal,
            1,
            "task.value".into(),
            json!({
                "encoding": "base64",
                "data": STANDARD.encode(b"null")
            }),
        )
        .unwrap();
        let finished = Frame::new(
            run_id.into(),
            child.task_id,
            "test".into(),
            "scope:root".into(),
            child.ordinal,
            2,
            "task.finished".into(),
            json!({
                "status": "passed",
                "failure": null,
                "finished_ns": monotonic_ns().unwrap(),
                "memory_peak_bytes": null
            }),
        )
        .unwrap();
        let passed = write_frame(child.fd, &started).is_ok()
            && write_frame(child.fd, &value).is_ok()
            && write_frame(child.fd, &finished).is_ok();
        unsafe {
            libc::close(child.fd);
            libc::_exit(i32::from(!passed));
        }
    }

    fn assert_descriptors_close_on_exec(descriptors: [RawFd; 2]) {
        let flags = descriptors.map(|descriptor| unsafe { libc::fcntl(descriptor, libc::F_GETFD) });
        close_descriptors(descriptors);

        for flags in flags {
            assert_ne!(flags, -1);
            assert_ne!(flags & libc::FD_CLOEXEC, 0);
        }
    }

    fn nested_message(
        channel: &NestedGroupChannel,
        operation: NestedGroupOperation,
        owner_pid: libc::pid_t,
        child_pid: libc::pid_t,
    ) -> NestedGroupMessage {
        NestedGroupMessage {
            operation,
            origin_pid: channel.origin_pid,
            owner_pid,
            child_pid,
            child_pgid: child_pid,
        }
    }

    fn spawn_stubborn_group() -> libc::pid_t {
        let sockets = socket_pair().unwrap();
        let pid = unsafe { libc::fork() };
        assert_ne!(pid, -1);

        if pid == 0 {
            unsafe {
                libc::close(sockets[1]);
                libc::signal(libc::SIGTERM, libc::SIG_IGN);
            }

            if unsafe { libc::setpgid(0, 0) } != 0 || !write_group_ready(sockets[0], true) {
                unsafe {
                    libc::_exit(1);
                }
            }

            loop {
                unsafe {
                    libc::pause();
                }
            }
        }

        unsafe {
            libc::close(sockets[0]);
            libc::setpgid(pid, pid);
        }
        assert_eq!(
            read_group_ready(
                sockets[1],
                monotonic_ns().unwrap() + PROCESS_BOUNDARY_TIMEOUT_NS,
            )
            .unwrap(),
            GroupReadiness::Ready,
        );
        unsafe {
            libc::close(sockets[1]);
        }

        pid
    }

    #[test]
    fn stream_socket_pairs_are_close_on_exec() {
        assert_descriptors_close_on_exec(socket_pair().unwrap());
    }

    #[test]
    fn datagram_socket_pairs_are_close_on_exec() {
        assert_descriptors_close_on_exec(datagram_socket_pair().unwrap());
    }

    #[test]
    fn socket_writes_surface_closed_peers_without_mutating_or_raising_sigpipe() {
        SIGPIPE_SIGNAL_COUNT.store(0, Ordering::Relaxed);
        let _guard =
            SignalDispositionGuard::install(libc::SIGPIPE, sigpipe_signal_handler as usize);
        let closed_pair = || {
            let sockets = socket_pair().unwrap();
            unsafe {
                libc::close(sockets[1]);
            }

            sockets[0]
        };

        let start = closed_pair();
        let error = write_start(start).unwrap_err();
        assert_eq!(error.raw_os_error(), Some(libc::EPIPE));
        unsafe {
            libc::close(start);
        }

        let permit = closed_pair();
        assert!(write_byte(permit).is_err());
        unsafe {
            libc::close(permit);
        }

        let readiness = closed_pair();
        assert!(!write_group_ready(readiness, true));
        unsafe {
            libc::close(readiness);
        }

        let nested_readiness = closed_pair();
        assert!(!write_nested_group_ready_failure(nested_readiness));
        unsafe {
            libc::close(nested_readiness);
        }

        assert_eq!(
            current_signal_handler(libc::SIGPIPE),
            sigpipe_signal_handler as usize
        );
        assert_eq!(SIGPIPE_SIGNAL_COUNT.load(Ordering::Relaxed), 0);
    }

    #[test]
    fn executor_preserves_the_inherited_sigpipe_disposition() {
        let _guard =
            SignalDispositionGuard::install(libc::SIGPIPE, sigpipe_signal_handler as usize);
        let run_id = "sigpipe-disposition-run";
        let engine =
            Engine::new(run_id.into(), 1, HashMap::new(), Duration::from_millis(10)).unwrap();
        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler
            .submit(
                "task:sigpipe-disposition".into(),
                "test".into(),
                "scope:root".into(),
                Vec::new(),
                1_000,
                false,
            )
            .unwrap();

        let result = loop {
            match scheduler.step().unwrap() {
                Step::Child(child) => {
                    if current_signal_handler(libc::SIGPIPE) != sigpipe_signal_handler as usize {
                        unsafe {
                            libc::_exit(3);
                        }
                    }

                    exit_passing_child(run_id, child);
                }
                Step::Result(result) => break result,
                Step::Progress => {}
                Step::Done => panic!("SIGPIPE disposition task produced no terminal result"),
            }
        };

        assert_eq!(result.status, "passed", "{:?}", result.failure);
        assert_eq!(scheduler.topology().forks, 1);
        assert_eq!(
            current_signal_handler(libc::SIGPIPE),
            sigpipe_signal_handler as usize
        );
    }

    #[test]
    fn failed_prearmed_start_releases_task_permits_then_cancel_reaps_and_releases_armed() {
        let engine = Engine::new(
            "failed-prearmed-start-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let global = engine.registry.names(&["scope:root".into()]);
        assert_eq!(global, [GLOBAL_POOL]);
        assert!(engine.registry.try_acquire(&global).unwrap());

        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler
            .submit(
                "task:failed-start".into(),
                "test".into(),
                "scope:root".into(),
                vec!["scope:root".into()],
                1_000,
                true,
            )
            .unwrap();
        assert!(matches!(scheduler.step().unwrap(), Step::Progress));

        let (&pid, active) = scheduler.active.iter().next().expect("task was pre-armed");
        let fd = active.fd;
        assert!(active.armed());
        assert!(active.owns_armed_permit());
        assert!(!active.owns_task_permits());
        assert_eq!(unsafe { libc::kill(-pid, libc::SIGKILL) }, 0);

        let mut descriptors = [libc::pollfd {
            fd,
            events: libc::POLLHUP | libc::POLLERR,
            revents: 0,
        }];
        poll_descriptors(&mut descriptors, 1_000).unwrap();
        assert_ne!(descriptors[0].revents & (libc::POLLHUP | libc::POLLERR), 0);

        engine.registry.release(&global).unwrap();
        assert!(scheduler.start_available().unwrap());

        let active = scheduler
            .active
            .get(&pid)
            .expect("failed task remains owned");
        assert!(matches!(
            active.state,
            ExecutorState::FailedToStart { armed_permit: true }
        ));
        assert!(active.protocol_error.as_ref().is_some_and(|error| {
            error.contains("could not start task task:failed-start")
                && error.contains("Broken pipe")
        }));
        assert!(!active.owns_task_permits());
        assert!(active.owns_armed_permit());
        assert_eq!(scheduler.active_count(), 0);

        assert!(engine.registry.try_acquire(&global).unwrap());
        assert!(!engine.registry.try_acquire(&global).unwrap());
        engine.registry.release(&global).unwrap();
        assert!(!engine.registry.try_acquire_armed().unwrap());

        scheduler.cancel().unwrap();
        assert!(scheduler.active.is_empty());
        assert!(engine.registry.try_acquire(&global).unwrap());
        assert!(!engine.registry.try_acquire(&global).unwrap());
        engine.registry.release(&global).unwrap();
        assert!(engine.registry.try_acquire_armed().unwrap());
        assert!(!engine.registry.try_acquire_armed().unwrap());
        engine.registry.release(&[ARMED_POOL.into()]).unwrap();
    }

    #[test]
    fn armed_executor_timeout_begins_only_when_start_is_signaled() {
        let task = Task {
            ordinal: 0,
            id: "task:armed-timeout".into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 25,
            permit_names: vec![GLOBAL_POOL.into()],
        };
        let mut active = ActiveTask::new(task, 41, 41, -1, 100, "armed-timeout-run", false);
        active.mark_armed_permit_acquired();

        assert!(active.armed());
        assert!(active.deadline_ns.is_none());
        assert!(!active.owns_task_permits());

        active.mark_started(1_000_000, true);

        assert!(active.running());
        assert_eq!(active.deadline_ns, Some(26_000_000));
        assert!(active.owns_task_permits());
    }

    #[test]
    fn exit_fallback_is_sampled_after_wait_and_never_precedes_child_start() {
        let run_id = "stale-exit-sample-run";
        let task = Task {
            ordinal: 0,
            id: "task:stale-exit-sample".into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 1_000,
            permit_names: Vec::new(),
        };
        let sockets = socket_pair().unwrap();
        set_nonblocking(sockets[0]).unwrap();
        let stale_ns = monotonic_ns().unwrap();
        let child_task = task.clone();
        let pid = unsafe { libc::fork() };
        assert_ne!(pid, -1);

        if pid == 0 {
            unsafe {
                libc::close(sockets[0]);
            }
            let mut started_ns = monotonic_ns().unwrap_or(stale_ns);

            while started_ns <= stale_ns {
                started_ns = monotonic_ns().unwrap_or(stale_ns.saturating_add(1));
            }

            let started = Frame::new(
                run_id.into(),
                child_task.id,
                child_task.kind,
                child_task.scope_id,
                child_task.ordinal,
                0,
                "task.started".into(),
                json!({ "started_ns": started_ns }),
            )
            .unwrap();
            let wrote = write_frame(sockets[1], &started).is_ok();
            unsafe {
                libc::close(sockets[1]);
            }

            if !wrote {
                unsafe {
                    libc::_exit(2);
                }
            }

            unsafe {
                libc::kill(libc::getpid(), libc::SIGKILL);
                libc::_exit(3);
            }
        }

        unsafe {
            libc::close(sockets[1]);
        }
        let mut active = ActiveTask::new(task, pid, pid, sockets[0], stale_ns, run_id, false);
        active.mark_started(stale_ns, false);
        let deadline_ns = monotonic_ns().unwrap() + PROCESS_BOUNDARY_TIMEOUT_NS;

        while !active.eof || active.started_ns.is_none() {
            active.read_available();
            assert!(
                monotonic_ns().unwrap() < deadline_ns,
                "child start and EOF were not drained"
            );
            std::thread::yield_now();
        }

        let started_ns = active.started_ns.expect("child start was drained");
        assert!(started_ns > stale_ns);

        while !active.exited {
            active.observe_exit(stale_ns);
            assert!(
                monotonic_ns().unwrap() < deadline_ns,
                "child did not become waitable"
            );
            std::thread::yield_now();
        }

        assert!(
            active.finished_ns.expect("exit fallback was recorded") >= started_ns,
            "exit fallback reused the stale pre-start scheduler sample"
        );
        active.kill_ns = Some(stale_ns);

        while !active.reaped {
            active.reap(stale_ns);
            assert!(
                monotonic_ns().unwrap() < deadline_ns,
                "child was not reaped"
            );
            std::thread::yield_now();
        }

        unsafe {
            libc::close(active.fd);
        }
        let result = active.into_result();
        assert_eq!(result.status, "failed");
        assert_eq!(
            result.failure.as_ref().unwrap()["kind"],
            "signal_termination"
        );
        assert_eq!(result.telemetry.pid, Some(pid));
        assert_eq!(result.telemetry.signal, Some(libc::SIGKILL));
        assert_eq!(result.telemetry.forks, 1);
        assert_eq!(result.telemetry.scope_workers, 0);
        assert_eq!(result.telemetry.executor_workers, 1);
        assert_eq!(result.telemetry.process_anchors, 0);
        assert!(result.telemetry.finished_ns.unwrap() >= result.telemetry.started_ns.unwrap());
    }

    fn assert_group_reaped(pid: libc::pid_t) {
        let mut status = 0;
        assert_eq!(unsafe { libc::waitpid(pid, &mut status, 0) }, pid);
        assert!(libc::WIFSIGNALED(status));
        assert_eq!(libc::WTERMSIG(status), libc::SIGKILL);
    }

    #[test]
    fn nested_group_messages_are_fixed_size_and_validated() {
        let channel = NestedGroupChannel::new().unwrap();
        assert_ne!(
            unsafe { libc::fcntl(channel.read, libc::F_GETFL) } & libc::O_NONBLOCK,
            0
        );
        assert_ne!(
            unsafe { libc::fcntl(channel.write, libc::F_GETFL) } & libc::O_NONBLOCK,
            0
        );
        let message = nested_message(
            &channel,
            NestedGroupOperation::Register,
            channel.origin_pid + 1,
            channel.origin_pid + 2,
        );
        let encoded = message.encode();

        assert_eq!(encoded.len(), NESTED_GROUP_MESSAGE_BYTES);
        assert_eq!(NestedGroupMessage::decode(&encoded).unwrap(), message);

        let mut corrupt = encoded;
        corrupt[12] ^= 0xff;
        assert!(NestedGroupMessage::decode(&corrupt)
            .unwrap_err()
            .contains("corrupt"));

        channel.close();
    }

    #[test]
    fn executor_readiness_timeout_kills_and_reaps_a_stopped_child() {
        let sockets = socket_pair().unwrap();
        let pid = unsafe { libc::fork() };
        assert_ne!(pid, -1);

        if pid == 0 {
            unsafe {
                libc::close(sockets[1]);
                libc::setpgid(0, 0);
                libc::raise(libc::SIGSTOP);
                libc::_exit(99);
            }
        }

        unsafe {
            libc::close(sockets[0]);
            libc::setpgid(pid, pid);
        }
        let error = read_group_ready(
            sockets[1],
            monotonic_ns().unwrap().saturating_add(25_000_000),
        )
        .unwrap_err();
        assert!(error.contains("timed out"));

        unsafe {
            libc::close(sockets[1]);
            libc::kill(-pid, libc::SIGKILL);
            libc::kill(pid, libc::SIGKILL);
        }
        wait_bounded_checked(pid).unwrap();
    }

    #[test]
    fn executor_readiness_retries_an_interrupted_wait_on_the_same_child() {
        let sockets = socket_pair().unwrap();
        let control = socket_pair().unwrap();
        let pid = unsafe { libc::fork() };
        assert_ne!(pid, -1);

        if pid == 0 {
            unsafe {
                libc::close(sockets[1]);
                libc::close(control[1]);
            }
            let mut action: libc::sigaction = unsafe { std::mem::zeroed() };
            action.sa_sigaction = readiness_signal_handler as usize;
            action.sa_flags = 0;
            unsafe {
                libc::sigemptyset(&mut action.sa_mask);
            }

            if unsafe { libc::sigaction(libc::SIGUSR1, &action, std::ptr::null_mut()) } != 0
                || !write_group_ready(control[0], true)
            {
                unsafe {
                    libc::_exit(2);
                }
            }

            let readiness = read_group_ready(
                sockets[0],
                monotonic_ns().unwrap() + PROCESS_BOUNDARY_TIMEOUT_NS,
            );
            unsafe {
                libc::_exit(i32::from(readiness != Ok(GroupReadiness::Ready)));
            }
        }

        unsafe {
            libc::close(sockets[0]);
            libc::close(control[0]);
        }
        assert_eq!(
            read_group_ready(
                control[1],
                monotonic_ns().unwrap() + PROCESS_BOUNDARY_TIMEOUT_NS,
            )
            .unwrap(),
            GroupReadiness::Ready,
        );

        for _ in 0..4 {
            unsafe {
                libc::kill(pid, libc::SIGUSR1);
            }
            std::thread::sleep(Duration::from_millis(1));
        }
        assert!(write_group_ready(sockets[1], true));
        let mut status = 0;
        assert_eq!(unsafe { libc::waitpid(pid, &mut status, 0) }, pid);

        unsafe {
            libc::close(sockets[1]);
            libc::close(control[1]);
        }
        assert!(libc::WIFEXITED(status));
        assert_eq!(libc::WEXITSTATUS(status), 0);
    }

    #[test]
    fn scheduler_retries_nested_readiness_after_sigusr1_without_reforking() {
        READINESS_SIGNAL_COUNT.store(0, Ordering::Relaxed);
        let _guard =
            SignalDispositionGuard::install(libc::SIGUSR1, readiness_signal_handler as usize);
        let run_id = "nested-readiness-eintr-run";
        let mut engine =
            Engine::new(run_id.into(), 1, HashMap::new(), Duration::from_millis(10)).unwrap();
        engine.nested_groups.origin_pid = unsafe { libc::getpid() } + 100_000;
        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler.readiness_delay = Duration::from_millis(50);
        scheduler
            .submit(
                "task:nested-readiness-eintr".into(),
                "test".into(),
                "scope:root".into(),
                Vec::new(),
                1_000,
                false,
            )
            .unwrap();

        let parent_pid = unsafe { libc::getpid() };
        let signaler = unsafe { libc::fork() };
        assert_ne!(signaler, -1);

        if signaler == 0 {
            for _ in 0..20 {
                unsafe {
                    libc::usleep(1_000);
                    libc::kill(parent_pid, libc::SIGUSR1);
                }
            }

            unsafe {
                libc::_exit(0);
            }
        }

        let mut results = Vec::new();

        loop {
            match scheduler.step().unwrap() {
                Step::Child(child) => exit_passing_child(run_id, child),
                Step::Result(result) => results.push(result),
                Step::Progress => {}
                Step::Done => break,
            }
        }

        let mut signaler_status = 0;
        assert_eq!(
            unsafe { libc::waitpid(signaler, &mut signaler_status, 0) },
            signaler
        );
        assert!(libc::WIFEXITED(signaler_status));
        assert_eq!(libc::WEXITSTATUS(signaler_status), 0);
        assert!(READINESS_SIGNAL_COUNT.load(Ordering::Relaxed) > 0);
        assert_eq!(results.len(), 1);
        assert_eq!(results[0].status, "passed", "{:?}", results[0].failure);
        assert!(results[0].telemetry.pid.is_some());
        assert_eq!(scheduler.topology().forks, 1);
    }

    #[test]
    fn readiness_reap_timeout_retains_child_and_permits_until_cancel() {
        let mut engine = Engine::new(
            "readiness-reap-timeout-run".into(),
            1,
            HashMap::from([("limited".into(), 1)]),
            Duration::from_millis(10),
        )
        .unwrap();
        engine.nested_groups.origin_pid = unsafe { libc::getpid() } + 100_000;
        let names = engine.registry.names(&["limited".into()]);
        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler.fail_readiness = true;
        scheduler.fail_pre_ready_reap = true;
        scheduler
            .submit(
                "task:readiness-reap-timeout".into(),
                "test".into(),
                "scope:limited".into(),
                vec!["limited".into()],
                1_000,
                true,
            )
            .unwrap();

        assert!(matches!(scheduler.step().unwrap(), Step::Progress));
        assert!(scheduler.pending.is_empty());
        assert!(scheduler.completed.is_empty());
        assert_eq!(scheduler.active.len(), 1);
        assert_eq!(scheduler.topology().forks, 1);
        let active = scheduler.active.values().next().unwrap();
        assert!(active.owns_task_permits());
        assert!(!active.owns_armed_permit());
        assert!(active.protocol_error.as_ref().is_some_and(|error| {
            error.contains("could not register its nested process group")
                && error.contains("injected timeout waiting to reap")
        }));
        assert!(!engine.registry.try_acquire(&names).unwrap());
        let register = engine
            .nested_groups
            .receive()
            .unwrap()
            .expect("nested child registered before readiness failed");
        assert_eq!(register.operation, NestedGroupOperation::Register);
        assert!(engine.nested_groups.receive().unwrap().is_none());

        scheduler.cancel().unwrap();
        assert!(scheduler.active.is_empty());
        assert!(scheduler.completed.is_empty());
        let retire = engine.nested_groups.receive().unwrap().unwrap();
        let unregister = engine.nested_groups.receive().unwrap().unwrap();
        assert_eq!(retire.operation, NestedGroupOperation::Retire);
        assert_eq!(unregister.operation, NestedGroupOperation::Unregister);
        assert!(engine.nested_groups.receive().unwrap().is_none());
        assert!(engine.registry.try_acquire(&names).unwrap());
        assert!(!engine.registry.try_acquire(&names).unwrap());
        engine.registry.release(&names).unwrap();
    }

    #[test]
    fn cancellation_reports_registry_failure_after_reaping_active_work() {
        let engine = Engine::new(
            "cancel-failure-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler
            .submit(
                "task:cancel-failure".into(),
                "test".into(),
                "scope:root".into(),
                Vec::new(),
                0,
                false,
            )
            .unwrap();

        loop {
            match scheduler.step().unwrap() {
                Step::Child(child) => {
                    unsafe {
                        libc::close(child.fd);
                    }

                    loop {
                        unsafe {
                            libc::pause();
                        }
                    }
                }
                Step::Progress if scheduler.active_count() == 1 => break,
                Step::Progress => {}
                Step::Result(_) | Step::Done => {
                    panic!("cancellation fixture completed before cancellation")
                }
            }
        }

        let pid = *scheduler
            .active
            .keys()
            .next()
            .expect("cancellation fixture has one active executor");
        assert_eq!(unsafe { libc::kill(pid, libc::SIGKILL) }, 0);
        let mut status = 0;
        assert_eq!(unsafe { libc::waitpid(pid, &mut status, 0) }, pid);
        assert!(libc::WIFSIGNALED(status));
        assert_eq!(libc::WTERMSIG(status), libc::SIGKILL);

        let malformed = [0_u8];
        assert_eq!(
            unsafe {
                libc::send(
                    scheduler.nested_groups.write,
                    malformed.as_ptr().cast(),
                    malformed.len(),
                    0,
                )
            },
            malformed.len() as isize
        );

        let error = scheduler.cancel().unwrap_err();
        assert!(error.starts_with("Drover cancellation failed:"));
        let expected_observation = format!(
            "executor observation for task task:cancel-failure: \
             waitid() lost the Drove child: {}.",
            io::Error::from_raw_os_error(libc::ECHILD)
        );
        assert!(error.contains(&expected_observation), "{error}");
        assert!(error.contains("truncated nested-group record"));
        assert_eq!(scheduler.active_count(), 0);
        assert!(scheduler.pending.is_empty());
        assert!(scheduler
            .nested_group_graph
            .as_ref()
            .is_some_and(|graph| graph.groups.is_empty()));
        assert_eq!(unsafe { libc::kill(pid, 0) }, -1);
        assert_eq!(io::Error::last_os_error().raw_os_error(), Some(libc::ESRCH));
    }

    #[test]
    fn nested_group_normal_flow_retires_before_unregistering() {
        let channel = NestedGroupChannel::new().unwrap();
        let owner = channel.origin_pid + 10_000;
        let child = owner + 1;
        let direct = HashSet::from([owner]);
        let mut graph = NestedGroupGraph::default();

        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Register, owner, child),
                &channel,
                &direct,
            )
            .unwrap();
        assert_eq!(graph.groups[&child].state, NestedGroupState::Active);
        assert!(graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Unregister, owner, child,),
                &channel,
                &direct,
            )
            .unwrap_err()
            .contains("before RETIRED"));
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Retire, owner, child),
                &channel,
                &direct,
            )
            .unwrap();
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Unregister, owner, child),
                &channel,
                &direct,
            )
            .unwrap();
        assert!(graph.groups.is_empty());

        channel.close();
    }

    #[test]
    fn readiness_failure_is_safe_before_or_after_registration() {
        let channel = NestedGroupChannel::new().unwrap();
        let owner = channel.origin_pid + 10_000;
        let child = owner + 1;
        let direct = HashSet::from([owner]);
        let mut before_register = NestedGroupGraph::default();

        for operation in [
            NestedGroupOperation::Retire,
            NestedGroupOperation::Unregister,
        ] {
            before_register
                .apply(
                    nested_message(&channel, operation, owner, child),
                    &channel,
                    &direct,
                )
                .unwrap();
        }
        assert!(before_register.groups.is_empty());

        let mut after_register = NestedGroupGraph::default();
        for operation in [
            NestedGroupOperation::Register,
            NestedGroupOperation::Retire,
            NestedGroupOperation::Unregister,
        ] {
            after_register
                .apply(
                    nested_message(&channel, operation, owner, child),
                    &channel,
                    &direct,
                )
                .unwrap();
        }
        assert!(after_register.groups.is_empty());

        channel.close();
    }

    #[test]
    fn owner_death_before_retire_kills_an_active_nested_group() {
        let channel = NestedGroupChannel::new().unwrap();
        let owner = channel.origin_pid + 10_000;
        let child = spawn_stubborn_group();
        let direct = HashSet::from([owner]);
        let mut graph = NestedGroupGraph::default();
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Register, owner, child),
                &channel,
                &direct,
            )
            .unwrap();

        graph.cleanup_active_descendants(owner).unwrap();
        assert_eq!(graph.groups[&child].state, NestedGroupState::Retired);
        assert_group_reaped(child);

        channel.close();
    }

    #[test]
    fn owner_death_after_retire_never_resignals_the_tombstone() {
        let channel = NestedGroupChannel::new().unwrap();
        let owner = channel.origin_pid + 10_000;
        let child = spawn_stubborn_group();
        let direct = HashSet::from([owner]);
        let mut graph = NestedGroupGraph::default();
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Register, owner, child),
                &channel,
                &direct,
            )
            .unwrap();
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Retire, owner, child),
                &channel,
                &direct,
            )
            .unwrap();

        graph.cleanup_active_descendants(owner).unwrap();
        assert_eq!(unsafe { libc::kill(child, 0) }, 0);

        unsafe {
            libc::kill(-child, libc::SIGKILL);
        }
        assert_group_reaped(child);
        channel.close();
    }

    #[test]
    fn owner_death_cleans_a_transitive_nested_group_tree() {
        let channel = NestedGroupChannel::new().unwrap();
        let owner = channel.origin_pid + 10_000;
        let child = spawn_stubborn_group();
        let grandchild = spawn_stubborn_group();
        let direct = HashSet::from([owner]);
        let mut graph = NestedGroupGraph::default();

        for (parent, nested) in [(owner, child), (child, grandchild)] {
            graph
                .apply(
                    nested_message(&channel, NestedGroupOperation::Register, parent, nested),
                    &channel,
                    &direct,
                )
                .unwrap();
        }

        graph.cleanup_active_descendants(owner).unwrap();
        assert_group_reaped(child);
        assert_group_reaped(grandchild);
        assert!(graph
            .groups
            .values()
            .all(|group| group.state == NestedGroupState::Retired));

        channel.close();
    }

    #[test]
    fn crashed_nested_owner_unregisters_after_its_children_become_tombstones() {
        let channel = NestedGroupChannel::new().unwrap();
        let owner = channel.origin_pid + 10_000;
        let child = owner + 1;
        let grandchild = spawn_stubborn_group();
        let direct = HashSet::from([owner]);
        let mut graph = NestedGroupGraph::default();
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Register, owner, child),
                &channel,
                &direct,
            )
            .unwrap();
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Register, child, grandchild),
                &channel,
                &direct,
            )
            .unwrap();

        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Retire, owner, child),
                &channel,
                &direct,
            )
            .unwrap();
        assert_group_reaped(grandchild);
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Unregister, owner, child),
                &channel,
                &direct,
            )
            .unwrap();

        assert!(!graph.groups.contains_key(&child));
        assert_eq!(graph.groups[&grandchild].state, NestedGroupState::Retired);
        graph.finish().unwrap();
        channel.close();
    }

    #[test]
    fn late_owner_registration_is_killed_and_invalid_records_are_rejected() {
        let channel = NestedGroupChannel::new().unwrap();
        let missing_owner = channel.origin_pid + 10_000;
        let child = spawn_stubborn_group();
        let mut graph = NestedGroupGraph::default();
        graph
            .apply(
                nested_message(
                    &channel,
                    NestedGroupOperation::Register,
                    missing_owner,
                    child,
                ),
                &channel,
                &HashSet::new(),
            )
            .unwrap();
        assert_group_reaped(child);
        assert_eq!(graph.groups[&child].state, NestedGroupState::Retired);

        let invalid = NestedGroupMessage {
            operation: NestedGroupOperation::Register,
            origin_pid: channel.origin_pid,
            owner_pid: missing_owner,
            child_pid: channel.origin_pgid,
            child_pgid: channel.origin_pgid,
        };
        assert!(graph
            .apply(invalid, &channel, &HashSet::new())
            .unwrap_err()
            .contains("unsafe"));
        let forged_origin_owner = NestedGroupMessage {
            operation: NestedGroupOperation::Register,
            origin_pid: channel.origin_pid,
            owner_pid: channel.origin_pid,
            child_pid: missing_owner + 1,
            child_pgid: missing_owner + 1,
        };
        assert!(graph
            .apply(forged_origin_owner, &channel, &HashSet::new())
            .unwrap_err()
            .contains("unsafe"));

        channel.close();
    }

    #[test]
    fn registration_after_owner_cleanup_is_killed_before_owner_reap() {
        let channel = NestedGroupChannel::new().unwrap();
        let owner = channel.origin_pid + 10_000;
        let first_child = spawn_stubborn_group();
        let mut graph = NestedGroupGraph::default();
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Register, owner, first_child),
                &channel,
                &HashSet::from([owner]),
            )
            .unwrap();
        graph.cleanup_active_descendants(owner).unwrap();
        assert_group_reaped(first_child);

        let late_child = spawn_stubborn_group();
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Register, owner, late_child),
                &channel,
                &HashSet::new(),
            )
            .unwrap();
        assert_group_reaped(late_child);
        assert_eq!(graph.groups[&late_child].state, NestedGroupState::Retired);

        channel.close();
    }

    #[test]
    fn reused_pid_registration_replaces_a_tombstone_only_after_forced_cleanup() {
        let channel = NestedGroupChannel::new().unwrap();
        let old_owner = channel.origin_pid + 10_000;
        let new_owner = old_owner + 1;
        let child = spawn_stubborn_group();
        let mut graph = NestedGroupGraph::default();
        graph.groups.insert(
            child,
            NestedGroup {
                owner_pid: old_owner,
                child_pid: child,
                child_pgid: child,
                state: NestedGroupState::Retired,
            },
        );

        assert!(graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Register, new_owner, child,),
                &channel,
                &HashSet::from([new_owner]),
            )
            .unwrap_err()
            .contains("forced cleanup"));
        assert_group_reaped(child);
        assert_eq!(graph.groups[&child].owner_pid, new_owner);
        assert_eq!(graph.groups[&child].state, NestedGroupState::Retired);

        channel.close();
    }

    #[test]
    fn finish_force_cleans_active_groups_before_reporting_the_protocol_error() {
        let channel = NestedGroupChannel::new().unwrap();
        let owner = channel.origin_pid + 10_000;
        let child = spawn_stubborn_group();
        let mut graph = NestedGroupGraph::default();
        graph
            .apply(
                nested_message(&channel, NestedGroupOperation::Register, owner, child),
                &channel,
                &HashSet::from([owner]),
            )
            .unwrap();

        assert!(graph.finish().unwrap_err().contains("forced cleanup"));
        assert_group_reaped(child);
        assert!(graph.groups.is_empty());

        channel.close();
    }

    #[test]
    fn lost_waitable_identity_still_requires_retire_before_unregister() {
        let task = Task {
            ordinal: 0,
            id: "task:lost-waitable-identity".into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 0,
            permit_names: Vec::new(),
        };
        let mut active = ActiveTask::new(task, 41, 41, -1, 0, "lost-waitable-run", true);
        active.exited = true;
        active.reaped = true;

        assert!(active.needs_nested_retirement());
        active.retired_nested = true;
        assert!(!active.needs_nested_retirement());
    }

    #[test]
    fn drains_multiframe_result_after_reap() {
        let run_id = "multiframe-run";
        let task = Task {
            ordinal: 0,
            id: "task:multiframe".into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 1_000,
            permit_names: Vec::new(),
        };
        let path = std::env::temp_dir().join(format!(
            "drover-multiframe-{}-{}",
            std::process::id(),
            monotonic_ns().unwrap()
        ));
        let mut file = std::fs::OpenOptions::new()
            .read(true)
            .write(true)
            .create_new(true)
            .open(&path)
            .unwrap();
        {
            let mut write = |sequence, event_type: &str, payload| {
                let frame = Frame::new(
                    run_id.into(),
                    task.id.clone(),
                    task.kind.clone(),
                    task.scope_id.clone(),
                    task.ordinal,
                    sequence,
                    event_type.into(),
                    payload,
                )
                .unwrap();
                file.write_all(&crate::protocol::encode_frame(&frame).unwrap())
                    .unwrap();
            };
            let data = STANDARD.encode(vec![b'x'; 524_288]);
            write(
                0,
                "task.started",
                json!({ "started_ns": monotonic_ns().unwrap() }),
            );

            for sequence in 1..=4 {
                write(
                    sequence,
                    "task.stdout",
                    json!({ "encoding": "base64", "data": data }),
                );
            }

            write(
                5,
                "task.value",
                json!({ "encoding": "base64", "data": STANDARD.encode(b"null") }),
            );
            write(
                6,
                "task.finished",
                json!({
                    "status": "passed",
                    "failure": null,
                    "finished_ns": monotonic_ns().unwrap(),
                    "memory_peak_bytes": null
                }),
            );
        }
        file.seek(SeekFrom::Start(0)).unwrap();
        let mut active = ActiveTask::new(task, 42, 43, file.as_raw_fd(), 0, run_id, false);
        active.reaped = true;
        active.wait_status = Some(0);
        active.normalize_terminal_consistency();
        assert!(active.protocol_error.is_none());

        active.read_available();
        assert!(active.eof);
        assert!(active.protocol_error.is_none());
        assert_eq!(active.stdout.len(), 2_097_152);
        assert!(active.terminal.is_some());

        drop(file);
        std::fs::remove_file(path).unwrap();
    }

    #[test]
    fn rejects_work_beyond_the_outstanding_task_capacity() {
        let engine = Engine::new(
            "bounded-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(1).unwrap();

        assert_eq!(
            scheduler
                .submit(
                    "task:first".into(),
                    "test".into(),
                    "scope:root".into(),
                    vec!["scope:root".into()],
                    10,
                    true,
                )
                .unwrap(),
            0
        );
        assert!(scheduler
            .submit(
                "task:overflow".into(),
                "test".into(),
                "scope:root".into(),
                vec!["scope:root".into()],
                10,
                true,
            )
            .unwrap_err()
            .contains("reached its 1 task capacity"));

        let task = scheduler
            .pending
            .pop_front()
            .expect("the capacity fixture has one pending task");
        scheduler.completed.push_back(failed_result(
            task,
            "fork_failure",
            "fixture".into(),
            empty_telemetry(None),
            Vec::new(),
        ));
        assert!(scheduler
            .submit(
                "task:completed-overflow".into(),
                "test".into(),
                "scope:root".into(),
                vec!["scope:root".into()],
                10,
                true,
            )
            .unwrap_err()
            .contains("reached its 1 task capacity"));

        scheduler.completed.pop_front();
        assert_eq!(
            scheduler
                .submit(
                    "task:after-delivery".into(),
                    "test".into(),
                    "scope:root".into(),
                    vec!["scope:root".into()],
                    10,
                    true,
                )
                .unwrap(),
            1
        );
    }

    #[test]
    fn refills_a_released_lane_before_delivering_a_buffered_result() {
        let engine = Engine::new(
            "refill-before-result-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(2).unwrap();
        scheduler
            .submit(
                "task:completed".into(),
                "test".into(),
                "scope:root".into(),
                vec!["scope:root".into()],
                100,
                true,
            )
            .unwrap();
        let completed = scheduler.pending.pop_front().unwrap();
        scheduler.completed.push_back(failed_result(
            completed,
            "fork_failure",
            "fixture".into(),
            empty_telemetry(None),
            Vec::new(),
        ));
        scheduler
            .submit(
                "task:pending".into(),
                "test".into(),
                "scope:root".into(),
                vec!["scope:root".into()],
                100,
                true,
            )
            .unwrap();

        match scheduler.step().unwrap() {
            Step::Child(child) => unsafe {
                libc::close(child.fd);
                libc::_exit(0);
            },
            Step::Progress => {}
            Step::Result(_) | Step::Done => {
                panic!("Drover delivered a buffered result before refilling its free lane")
            }
        }

        assert_eq!(scheduler.active_count(), 1);
        assert_eq!(scheduler.completed.len(), 1);
        scheduler.cancel().unwrap();
    }

    #[test]
    fn starts_the_same_prearmed_pid_before_delivering_a_buffered_result() {
        let engine = Engine::new(
            "prearmed-refill-before-result-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(2).unwrap();

        for id in ["task:first", "task:second"] {
            scheduler
                .submit(
                    id.into(),
                    "test".into(),
                    "scope:root".into(),
                    vec!["scope:root".into()],
                    1_000,
                    true,
                )
                .unwrap();
        }

        match scheduler.step().unwrap() {
            Step::Child(child) => {
                assert_eq!(child.task_id, "task:first");
                unsafe {
                    libc::close(child.fd);
                    libc::_exit(1);
                }
            }
            Step::Progress => {}
            Step::Result(_) | Step::Done => panic!("Drover did not pre-arm the refill fixture"),
        }

        let prearmed_pid = scheduler
            .active
            .iter()
            .find_map(|(pid, active)| {
                (active.task.id == "task:second" && active.armed()).then_some(*pid)
            })
            .expect("second task was pre-armed");
        let deadline = std::time::Instant::now() + Duration::from_secs(2);

        loop {
            match scheduler.step().unwrap() {
                Step::Child(child) => {
                    assert_eq!(child.task_id, "task:second");

                    loop {
                        unsafe {
                            libc::pause();
                        }
                    }
                }
                Step::Progress => {
                    if scheduler.completed.len() == 1
                        && scheduler
                            .active
                            .get(&prearmed_pid)
                            .is_some_and(|active| active.running())
                    {
                        break;
                    }

                    if std::time::Instant::now() >= deadline {
                        let _ = scheduler.cancel();
                        panic!("Drover did not start its pre-armed refill in time");
                    }
                }
                Step::Result(_) | Step::Done => {
                    panic!("Drover delivered the buffered result before its pre-armed refill")
                }
            }
        }

        assert_eq!(scheduler.forks, 2);
        assert_eq!(scheduler.active_count(), 1);
        assert_eq!(scheduler.max_active(), 1);
        assert!(scheduler.pending.is_empty());
        scheduler.cancel().unwrap();
    }

    #[test]
    fn stops_collection_for_prearmed_refill_without_serializing_interruption_cleanup() {
        let engine = Engine::new(
            "bounded-collection-run".into(),
            3,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(8).unwrap();
        let global = [GLOBAL_POOL.into()];

        for _ in 0..3 {
            assert!(scheduler.registry.try_acquire(&global).unwrap());
        }
        assert!(scheduler.registry.try_acquire_armed().unwrap());

        let task = |ordinal, id: &str| Task {
            ordinal,
            id: id.into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 0,
            permit_names: global.to_vec(),
        };
        for (ordinal, pid) in [(0, 41), (1, 42), (2, 44)] {
            let mut active = ActiveTask::new(
                task(ordinal, &format!("task:ready-{ordinal}")),
                pid,
                pid,
                -1,
                0,
                "bounded-collection-run",
                false,
            );
            active.mark_started(1, true);
            active.exited = true;
            active.reaped = true;
            active.wait_status = Some(0);
            active.eof = true;
            active.kill_ns = Some(1);
            scheduler.active.insert(pid, active);
        }

        let mut armed = ActiveTask::new(
            task(3, "task:armed"),
            43,
            43,
            -1,
            0,
            "bounded-collection-run",
            false,
        );
        armed.mark_armed_permit_acquired();
        scheduler.active.insert(43, armed);

        scheduler.collect().unwrap();

        assert_eq!(scheduler.completed.len(), 1);
        assert!(scheduler.active.contains_key(&42));
        assert!(scheduler.active.contains_key(&44));
        assert!(scheduler.active.get(&43).is_some_and(ActiveTask::armed));

        scheduler.interrupted_signal = Some(libc::SIGINT);
        scheduler.collect().unwrap();

        assert_eq!(scheduler.completed.len(), 3);
        assert!(!scheduler.active.contains_key(&42));
        assert!(!scheduler.active.contains_key(&44));
        scheduler.interrupted_signal = None;
        scheduler.active.remove(&43);
        scheduler.release_armed_permit().unwrap();
        scheduler.completed.clear();

        for _ in 0..3 {
            assert!(scheduler.registry.try_acquire(&global).unwrap());
        }
        assert!(!scheduler.registry.try_acquire(&global).unwrap());
        for _ in 0..3 {
            scheduler.registry.release(&global).unwrap();
        }
    }

    #[test]
    fn armed_refill_latch_does_not_fork_interrupted_pending_work() {
        let engine = Engine::new(
            "interrupted-refill-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler
            .submit(
                "task:pending".into(),
                "test".into(),
                "scope:root".into(),
                vec!["scope:root".into()],
                100,
                true,
            )
            .unwrap();
        scheduler.refill_before_collect = true;
        scheduler.interrupted_signal = Some(libc::SIGINT);

        assert!(matches!(scheduler.step().unwrap(), Step::Progress));
        assert_eq!(scheduler.forks, 0);
        assert_eq!(scheduler.active_count(), 0);
        assert_eq!(scheduler.pending.len(), 1);
        assert_eq!(scheduler.pending.front().unwrap().id, "task:pending");

        scheduler.cancel().unwrap();
        assert!(scheduler.pending.is_empty());
    }

    #[test]
    fn fills_every_available_lane_before_returning_to_the_parent() {
        let engine = Engine::new(
            "multi-lane-refill-run".into(),
            3,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(6).unwrap();

        for id in [
            "task:first",
            "task:second",
            "task:third",
            "task:fourth",
            "task:fifth",
            "task:sixth",
        ] {
            scheduler
                .submit(
                    id.into(),
                    "test".into(),
                    "scope:root".into(),
                    vec!["scope:root".into()],
                    1_000,
                    true,
                )
                .unwrap();
        }

        match scheduler.step().unwrap() {
            Step::Child(_) => loop {
                unsafe {
                    libc::pause();
                }
            },
            Step::Progress => {}
            Step::Result(_) | Step::Done => {
                panic!("Drover did not fill every available lane")
            }
        }

        assert_eq!(scheduler.forks, 6);
        assert_eq!(scheduler.active.len(), 6);
        assert_eq!(scheduler.active_count(), 3);
        assert_eq!(scheduler.max_active(), 3);
        assert_eq!(
            scheduler
                .active
                .values()
                .filter(|active| active.armed())
                .count(),
            3
        );
        assert_eq!(scheduler.topology().peak_live_pids, 6);
        assert!(scheduler.pending.is_empty());

        for pid in scheduler.active.keys().copied() {
            assert_eq!(unsafe { libc::getpgid(pid) }, pid);
        }
        let names = engine.registry.names(&["scope:root".into()]);
        assert!(!engine.registry.try_acquire(&names).unwrap());
        assert!(!engine.registry.try_acquire_armed().unwrap());

        scheduler.cancel().unwrap();
    }

    #[test]
    fn starts_unrestricted_work_when_another_map_owns_the_armed_window() {
        let engine = Engine::new(
            "shared-armed-window-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        assert!(engine.registry.try_acquire_armed().unwrap());
        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler
            .submit(
                "task:unrestricted".into(),
                "test".into(),
                "scope:root".into(),
                vec!["scope:root".into()],
                1_000,
                true,
            )
            .unwrap();

        match scheduler.step().unwrap() {
            Step::Child(_) => loop {
                unsafe {
                    libc::pause();
                }
            },
            Step::Progress => {}
            Step::Result(_) | Step::Done => {
                panic!("Drover left a global lane idle behind another map's armed window")
            }
        }

        let active = scheduler
            .active
            .values()
            .next()
            .expect("unrestricted task was started");
        assert!(active.running());
        assert!(!active.owns_armed_permit());
        assert!(active.owns_task_permits());
        assert_eq!(scheduler.forks, 1);
        assert_eq!(scheduler.active_count(), 1);
        assert_eq!(scheduler.topology().peak_live_pids, 1);

        scheduler.cancel().unwrap();
        engine.registry.release(&[ARMED_POOL.into()]).unwrap();
    }

    #[test]
    fn two_schedulers_share_one_running_and_armed_capacity_bound() {
        let engine = Engine::new(
            "two-scheduler-capacity-run".into(),
            2,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut first = engine.scheduler(4).unwrap();
        let mut second = engine.scheduler(4).unwrap();

        for (scheduler, id) in [
            (&mut first, "task:first-running"),
            (&mut second, "task:second-running"),
        ] {
            scheduler
                .submit(
                    id.into(),
                    "test".into(),
                    "scope:root".into(),
                    vec!["scope:root".into()],
                    1_000,
                    true,
                )
                .unwrap();
            match scheduler.step().unwrap() {
                Step::Child(_) => loop {
                    unsafe {
                        libc::pause();
                    }
                },
                Step::Progress => {}
                Step::Result(_) | Step::Done => panic!("scheduler did not start its first task"),
            }
        }

        for id in ["task:first-armed-a", "task:first-armed-b"] {
            first
                .submit(
                    id.into(),
                    "test".into(),
                    "scope:root".into(),
                    vec!["scope:root".into()],
                    1_000,
                    true,
                )
                .unwrap();
        }
        assert!(matches!(first.step().unwrap(), Step::Progress));

        for id in ["task:second-blocked-a", "task:second-blocked-b"] {
            second
                .submit(
                    id.into(),
                    "test".into(),
                    "scope:root".into(),
                    vec!["scope:root".into()],
                    1_000,
                    true,
                )
                .unwrap();
        }
        assert!(matches!(second.step().unwrap(), Step::Progress));

        let running = [&first, &second]
            .into_iter()
            .flat_map(|scheduler| scheduler.active.values())
            .filter(|active| active.running())
            .count();
        let armed = [&first, &second]
            .into_iter()
            .flat_map(|scheduler| scheduler.active.values())
            .filter(|active| active.armed())
            .count();
        let live = first.active.len() + second.active.len();

        assert_eq!(running, 2);
        assert_eq!(armed, 2);
        assert_eq!(live, 4);
        assert_eq!(first.pending.len() + second.pending.len(), 2);
        assert!(!engine.registry.take(GLOBAL_POOL).unwrap());
        assert!(!engine.registry.try_acquire_armed().unwrap());

        first.cancel().unwrap();
        second.cancel().unwrap();

        let global = engine.registry.names(&["scope:root".into()]);
        assert!(engine.registry.try_acquire(&global).unwrap());
        assert!(engine.registry.try_acquire(&global).unwrap());
        assert!(!engine.registry.try_acquire(&global).unwrap());
        engine.registry.release(&global).unwrap();
        engine.registry.release(&global).unwrap();
        assert!(engine.registry.try_acquire_armed().unwrap());
        assert!(engine.registry.try_acquire_armed().unwrap());
        assert!(!engine.registry.try_acquire_armed().unwrap());
        engine.registry.release(&[ARMED_POOL.into()]).unwrap();
        engine.registry.release(&[ARMED_POOL.into()]).unwrap();
    }

    #[test]
    fn constrained_tasks_scan_past_a_saturated_scope_without_prearming_it() {
        let engine = Engine::new(
            "scope-head-of-line-run".into(),
            3,
            HashMap::from([("hot".into(), 1), ("cold".into(), 2)]),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(6).unwrap();

        for (id, scope) in [
            ("task:hot-first", "hot"),
            ("task:hot-second", "hot"),
            ("task:hot-third", "hot"),
            ("task:cold-first", "cold"),
            ("task:cold-second", "cold"),
        ] {
            scheduler
                .submit(
                    id.into(),
                    "test".into(),
                    scope.into(),
                    vec![scope.into()],
                    1_000,
                    true,
                )
                .unwrap();
        }

        match scheduler.step().unwrap() {
            Step::Child(_) => loop {
                unsafe {
                    libc::pause();
                }
            },
            Step::Progress => {}
            Step::Result(_) | Step::Done => {
                panic!("Drover did not scan past the saturated hot scope")
            }
        }

        let running_ids: HashSet<_> = scheduler
            .active
            .values()
            .filter(|active| active.running())
            .map(|active| active.task.id.as_str())
            .collect();
        assert_eq!(
            running_ids,
            HashSet::from(["task:hot-first", "task:cold-first", "task:cold-second",])
        );
        assert_eq!(scheduler.forks, 3);
        assert_eq!(scheduler.active.len(), 3);
        assert_eq!(scheduler.active_count(), 3);
        assert_eq!(scheduler.max_active(), 3);
        assert_eq!(scheduler.topology().peak_live_pids, 3);
        assert!(scheduler.active.values().all(|active| active.running()));
        assert_eq!(
            scheduler
                .pending
                .iter()
                .map(|task| task.id.as_str())
                .collect::<Vec<_>>(),
            ["task:hot-second", "task:hot-third"]
        );

        scheduler.cancel().unwrap();
    }

    #[test]
    fn returns_after_one_permit_free_scope_host_fork() {
        let engine = Engine::new(
            "scope-host-refill-run".into(),
            3,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(6).unwrap();
        let names = engine.registry.names(&["scope:first".into()]);

        for _ in 0..3 {
            assert!(engine.registry.try_acquire(&names).unwrap());
            assert!(engine.registry.try_acquire_armed().unwrap());
        }
        assert!(!engine.registry.try_acquire(&names).unwrap());
        assert!(!engine.registry.try_acquire_armed().unwrap());

        for id in ["scope:first", "scope:second", "scope:third"] {
            scheduler
                .submit(
                    id.into(),
                    "scope".into(),
                    id.into(),
                    vec![id.into()],
                    1_000,
                    false,
                )
                .unwrap();
        }

        match scheduler.step().unwrap() {
            Step::Child(_) => loop {
                unsafe {
                    libc::pause();
                }
            },
            Step::Progress => {}
            Step::Result(_) | Step::Done => {
                panic!("Drover did not return after the first scope host fork")
            }
        }

        assert_eq!(scheduler.forks, 1);
        assert_eq!(scheduler.active_count(), 1);
        assert_eq!(scheduler.pending.len(), 2);
        assert!(!engine.registry.try_acquire(&names).unwrap());
        assert!(!engine.registry.try_acquire_armed().unwrap());
        scheduler.cancel().unwrap();

        for _ in 0..3 {
            engine.registry.release(&names).unwrap();
            engine.registry.release(&[ARMED_POOL.into()]).unwrap();
        }
    }

    #[test]
    fn prearms_the_outstanding_window_without_exceeding_global_permits() {
        let engine = Engine::new(
            "capacity-refill-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(2).unwrap();

        for id in ["task:first", "task:second"] {
            scheduler
                .submit(
                    id.into(),
                    "test".into(),
                    "scope:root".into(),
                    vec!["scope:root".into()],
                    1_000,
                    true,
                )
                .unwrap();
        }

        match scheduler.step().unwrap() {
            Step::Child(_) => loop {
                unsafe {
                    libc::pause();
                }
            },
            Step::Progress => {}
            Step::Result(_) | Step::Done => {
                panic!("Drover did not start the first capacity fixture")
            }
        }

        assert!(scheduler.refill_before_collect);
        assert!(matches!(scheduler.step().unwrap(), Step::Progress));
        assert_eq!(scheduler.forks, 2);
        assert_eq!(scheduler.active.len(), 2);
        assert_eq!(scheduler.active_count(), 1);
        assert!(scheduler.pending.is_empty());
        assert_eq!(scheduler.max_active(), 1);
        assert_eq!(
            scheduler
                .active
                .values()
                .filter(|active| active.armed())
                .count(),
            1
        );
        assert_eq!(
            scheduler
                .active
                .values()
                .filter(|active| active.running())
                .count(),
            1
        );
        assert!(scheduler.active.values().any(|active| {
            active.armed()
                && active.owns_armed_permit()
                && !active.owns_task_permits()
                && active.deadline_ns.is_none()
        }));
        assert!(scheduler.active.values().any(|active| {
            active.running()
                && !active.owns_armed_permit()
                && active.owns_task_permits()
                && active.deadline_ns.is_some()
        }));

        for pid in scheduler.active.keys().copied() {
            assert_eq!(unsafe { libc::getpgid(pid) }, pid);
        }

        let names = engine.registry.names(&["scope:root".into()]);
        assert!(!engine.registry.try_acquire(&names).unwrap());
        assert!(!engine.registry.try_acquire_armed().unwrap());

        scheduler.cancel().unwrap();
        assert_eq!(scheduler.active_count(), 0);
        assert!(scheduler.pending.is_empty());
        assert!(engine.registry.try_acquire(&names).unwrap());
        assert!(!engine.registry.try_acquire(&names).unwrap());
        engine.registry.release(&names).unwrap();
        assert!(engine.registry.try_acquire_armed().unwrap());
        assert!(!engine.registry.try_acquire_armed().unwrap());
        engine.registry.release(&[ARMED_POOL.into()]).unwrap();
    }

    #[test]
    fn reaps_an_armed_crash_without_releasing_unacquired_task_permits() {
        let engine = Engine::new(
            "armed-crash-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let names = engine.registry.names(&["scope:root".into()]);
        assert!(engine.registry.try_acquire(&names).unwrap());
        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler
            .submit(
                "task:armed-crash".into(),
                "test".into(),
                "scope:root".into(),
                vec!["scope:root".into()],
                25,
                true,
            )
            .unwrap();

        assert!(matches!(scheduler.step().unwrap(), Step::Progress));
        let pid = *scheduler.active.keys().next().expect("task was pre-armed");
        let active = scheduler.active.get(&pid).unwrap();
        assert!(active.armed());
        assert!(active.deadline_ns.is_none());
        assert!(!active.owns_task_permits());
        std::thread::sleep(Duration::from_millis(30));
        scheduler.collect().unwrap();
        let active = scheduler.active.get(&pid).unwrap();
        assert!(active.armed());
        assert!(!active.timed_out);
        assert!(active.deadline_ns.is_none());
        assert_eq!(unsafe { libc::kill(-pid, libc::SIGKILL) }, 0);

        let deadline = std::time::Instant::now() + Duration::from_secs(2);
        let result = loop {
            match scheduler.step().unwrap() {
                Step::Result(result) => break result,
                Step::Progress => {
                    if std::time::Instant::now() >= deadline {
                        let _ = scheduler.cancel();
                        panic!("armed crash cleanup exceeded its bound");
                    }
                }
                Step::Child(_) => panic!("an armed crash reached its task body"),
                Step::Done => panic!("an armed crash produced no terminal result"),
            }
        };

        assert_eq!(
            result.failure.as_ref().unwrap()["kind"],
            "signal_termination"
        );
        assert_eq!(result.telemetry.forks, 1);
        assert_eq!(result.telemetry.scope_workers, 0);
        assert_eq!(result.telemetry.executor_workers, 0);
        assert!(engine.registry.try_acquire_armed().unwrap());
        assert!(!engine.registry.try_acquire_armed().unwrap());
        engine.registry.release(&[ARMED_POOL.into()]).unwrap();
        assert!(!engine.registry.try_acquire(&names).unwrap());
        engine.registry.release(&names).unwrap();
        assert!(engine.registry.try_acquire(&names).unwrap());
        assert!(!engine.registry.try_acquire(&names).unwrap());
        engine.registry.release(&names).unwrap();
    }

    #[test]
    fn records_the_requested_interruption_signal_separately() {
        let engine = Engine::new(
            "interrupted-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler
            .submit(
                "task:pending".into(),
                "test".into(),
                "scope:root".into(),
                vec!["scope:root".into()],
                10,
                true,
            )
            .unwrap();

        scheduler.interrupt(libc::SIGINT).unwrap();

        let Step::Result(result) = scheduler.step().unwrap() else {
            panic!("interrupted pending work must produce a result");
        };
        assert_eq!(result.telemetry.interrupted_signal, Some(libc::SIGINT));
        assert_eq!(result.telemetry.signal, None);
    }

    #[test]
    fn reports_cleanup_signal_errors_before_timeout() {
        let task = Task {
            ordinal: 0,
            id: "task:cleanup-error".into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 1,
            permit_names: Vec::new(),
        };
        let mut active = ActiveTask::new(
            task,
            unsafe { libc::getpid() },
            unsafe { libc::getpgrp() },
            -1,
            0,
            "cleanup-error-run",
            false,
        );
        active.timed_out = true;
        active.protocol_error = Some("killpg failed".into());

        let result = active.into_result();

        assert_eq!(
            result.failure.as_ref().unwrap()["kind"],
            "child_protocol_failure"
        );
        assert_eq!(result.failure.unwrap()["message"], "killpg failed");
    }

    #[test]
    fn does_not_resignal_a_reaped_group_after_forced_cleanup() {
        let task = Task {
            ordinal: 0,
            id: "task:forced-cleanup".into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 1,
            permit_names: Vec::new(),
        };
        let mut active = ActiveTask::new(task, 41, 42, -1, 0, "forced-cleanup-run", false);
        active.exited = true;
        active.reaped = true;
        active.timed_out = true;
        active.term_ns = Some(1);
        active.kill_ns = Some(2);

        active.enforce(3, 10);

        assert!(active.protocol_error.is_none());
        assert!(active.cleanup_term_ns.is_none());
    }

    #[test]
    fn reports_blocked_descendant_before_timeout_or_interruption() {
        let task = Task {
            ordinal: 0,
            id: "task:blocked-descendant".into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 1,
            permit_names: Vec::new(),
        };
        let mut active = ActiveTask::new(
            task,
            unsafe { libc::getpid() },
            unsafe { libc::getpgrp() },
            -1,
            0,
            "blocked-descendant-run",
            false,
        );
        active.cleanup_failed = true;
        active.timed_out = true;
        active.interrupted_signal = Some(libc::SIGINT);

        let result = active.into_result();

        assert_eq!(result.failure.unwrap()["kind"], "blocked_descendant");
    }

    #[test]
    fn reports_group_signal_failures() {
        let error = signal_process_group(unsafe { libc::getpgrp() }, i32::MAX).unwrap_err();
        assert!(error.raw_os_error().is_some());
    }

    #[test]
    fn darwin_group_confirmation_accepts_authorized_zombies_and_disappeared_members() {
        let pgid = 40;
        let members = [pgid, 41, 42, 0];
        let mut attempted = Vec::new();

        confirm_enumerated_process_group(pgid, &members, 3, |member, signal| {
            attempted.push((member, signal));

            match member {
                41 => Err(io::Error::from_raw_os_error(libc::ESRCH)),
                _ => Ok(()),
            }
        })
        .unwrap();

        assert_eq!(attempted, vec![(40, 0), (41, 0), (42, 0)]);
    }

    #[test]
    fn darwin_group_confirmation_rejects_an_unauthorized_member() {
        let pgid = 40;
        let members = [pgid, 41, 42, 0];
        let mut attempted = Vec::new();

        let error = confirm_enumerated_process_group(pgid, &members, 3, |member, signal| {
            attempted.push((member, signal));

            match member {
                42 => Err(io::Error::from_raw_os_error(libc::EPERM)),
                _ => Ok(()),
            }
        })
        .unwrap_err();

        assert_eq!(attempted, vec![(40, 0), (41, 0), (42, 0)]);
        assert!(error.contains("member 42"));
    }

    #[test]
    fn darwin_group_confirmation_rejects_untrusted_enumerations_without_probing() {
        let pgid = 40;
        let probes = std::cell::Cell::new(0);
        let mut probe = |_: libc::pid_t, signal: i32| {
            assert_eq!(signal, 0);
            probes.set(probes.get() + 1);

            Ok(())
        };

        assert!(confirm_enumerated_process_group(pgid, &[0], 0, &mut probe)
            .unwrap_err()
            .contains("no members"));
        assert!(
            confirm_enumerated_process_group(pgid, &[pgid, 41], 2, &mut probe)
                .unwrap_err()
                .contains("may be truncated")
        );
        assert!(
            confirm_enumerated_process_group(pgid, &[41, 42, 0], 2, &mut probe)
                .unwrap_err()
                .contains("omitted the retained leader")
        );
        assert!(
            confirm_enumerated_process_group(pgid, &[pgid, 0, 0], 2, &mut probe)
                .unwrap_err()
                .contains("invalid member")
        );
        assert_eq!(probes.get(), 0);
    }

    #[test]
    fn rejects_a_nonwaitable_sigchld_disposition() {
        let pid = unsafe { libc::fork() };
        assert_ne!(pid, -1);

        if pid == 0 {
            unsafe {
                libc::signal(libc::SIGCHLD, libc::SIG_IGN);
                libc::_exit(i32::from(validate_sigchld_disposition().is_ok()));
            }
        }

        let mut status = 0;
        assert_eq!(unsafe { libc::waitpid(pid, &mut status, 0) }, pid);
        assert!(libc::WIFEXITED(status));
        assert_eq!(libc::WEXITSTATUS(status), 0);
    }

    #[test]
    fn gives_terminal_and_forced_cleanup_a_process_boundary_before_latching_failure() {
        let task = Task {
            ordinal: 0,
            id: "task:blocked-channel".into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 0,
            permit_names: Vec::new(),
        };
        let mut active = ActiveTask::new(task, 41, 42, -1, 0, "blocked-channel-run", false);
        active.terminal_received_ns = Some(0);
        active.kill_ns = Some(0);

        active.enforce(PROCESS_BOUNDARY_TIMEOUT_NS - 1, 1);
        assert!(active.protocol_error.is_none());
        assert!(!active.cleanup_failed);
        assert!(!active.eof);

        active.enforce(PROCESS_BOUNDARY_TIMEOUT_NS, 1);
        assert!(active.protocol_error.is_some());
        assert!(active.cleanup_failed);
        assert!(active.eof);
    }

    #[test]
    fn bounds_live_executors_after_their_result_protocol_stops() {
        let run_id = "stalled-protocol-run";
        let engine =
            Engine::new(run_id.into(), 3, HashMap::new(), Duration::from_millis(20)).unwrap();
        let mut scheduler = engine.scheduler(3).unwrap();

        for (id, timeout_ms) in [
            ("task:stalled-eof", 0),
            ("task:stalled-terminal", 0),
            ("task:no-start-timeout", 30),
        ] {
            scheduler
                .submit(
                    id.into(),
                    "test".into(),
                    "scope:root".into(),
                    vec!["scope:root".into()],
                    timeout_ms,
                    false,
                )
                .unwrap();
        }

        let deadline = std::time::Instant::now() + Duration::from_secs(2);
        let mut results = HashMap::new();

        while results.len() < 3 {
            match scheduler.step().unwrap() {
                Step::Child(child) => {
                    unsafe {
                        libc::signal(libc::SIGTERM, libc::SIG_IGN);
                    }

                    if child.task_id == "task:stalled-eof" {
                        unsafe {
                            libc::close(child.fd);
                        }
                    } else if child.task_id == "task:stalled-terminal" {
                        let started_ns = monotonic_ns().unwrap();
                        write_frame(
                            child.fd,
                            &Frame::new(
                                run_id.into(),
                                child.task_id.clone(),
                                "test".into(),
                                "scope:root".into(),
                                child.ordinal,
                                0,
                                "task.started".into(),
                                json!({ "started_ns": started_ns }),
                            )
                            .unwrap(),
                        )
                        .unwrap();
                        write_frame(
                            child.fd,
                            &Frame::new(
                                run_id.into(),
                                child.task_id.clone(),
                                "test".into(),
                                "scope:root".into(),
                                child.ordinal,
                                1,
                                "task.finished".into(),
                                json!({
                                    "status": "passed",
                                    "failure": null,
                                    "finished_ns": monotonic_ns().unwrap(),
                                    "memory_peak_bytes": null
                                }),
                            )
                            .unwrap(),
                        )
                        .unwrap();
                    }

                    loop {
                        unsafe {
                            libc::pause();
                        }
                    }
                }
                Step::Result(result) => {
                    results.insert(result.id.clone(), result);
                }
                Step::Progress => {
                    if std::time::Instant::now() >= deadline {
                        let _ = scheduler.cancel();
                        panic!("stalled executor cleanup exceeded its bound");
                    }
                }
                Step::Done => panic!("stalled executors did not produce results"),
            }
        }

        assert_eq!(scheduler.active_count(), 0);
        for id in ["task:stalled-eof", "task:stalled-terminal"] {
            let failure = results[id].failure.as_ref().unwrap();
            assert_eq!(failure["kind"], "child_protocol_failure");
            assert_eq!(
                failure["message"],
                "The Drove child stopped its result protocol without exiting."
            );
        }
        assert_eq!(
            results["task:no-start-timeout"].failure.as_ref().unwrap()["kind"],
            "timeout"
        );
        assert!(results.values().all(|result| {
            result.telemetry.forks == 1
                && result.telemetry.scope_workers == 0
                && result.telemetry.executor_workers == 1
                && result.telemetry.process_anchors == 0
                && result.telemetry.pid == result.telemetry.pgid
        }));
        let topology = scheduler.topology();
        assert_eq!(topology.schema, 1);
        assert_eq!(topology.forks, 3);
        assert_eq!(topology.scope_workers, 0);
        assert_eq!(topology.executor_workers, 3);
        assert_eq!(topology.process_anchors, 0);
        assert_eq!(topology.peak_live_pids, 3);
        assert_eq!(topology.peak_outstanding_tasks, 3);
        assert_eq!(topology.outstanding_task_limit, 3);
    }

    #[test]
    fn counts_a_pre_readiness_fork_without_a_typed_worker() {
        let (scope_workers, executor_workers) = worker_counts("test", false);
        let telemetry = telemetry(
            None,
            None,
            None,
            None,
            None,
            None,
            None,
            1,
            scope_workers,
            executor_workers,
            0,
        );

        assert_eq!(telemetry.forks, 1);
        assert_eq!(telemetry.scope_workers, 0);
        assert_eq!(telemetry.executor_workers, 0);
        assert_eq!(telemetry.process_anchors, 0);
        assert_eq!(worker_counts("scope", false), (0, 0));
        assert_eq!(worker_counts("scope", true), (1, 0));
        assert_eq!(worker_counts("test", true), (0, 1));
    }

    #[test]
    fn does_not_signal_reaped_process_identities() {
        let sockets = socket_pair().unwrap();
        let sentinel = unsafe { libc::fork() };
        assert_ne!(sentinel, -1);

        if sentinel == 0 {
            unsafe {
                libc::close(sockets[1]);
            }
            if unsafe { libc::setpgid(0, 0) } != 0 || !write_group_ready(sockets[0], true) {
                unsafe {
                    libc::_exit(1);
                }
            }

            loop {
                unsafe {
                    libc::pause();
                }
            }
        }

        unsafe {
            libc::close(sockets[0]);
        }
        assert_eq!(
            read_group_ready(
                sockets[1],
                monotonic_ns().unwrap() + PROCESS_BOUNDARY_TIMEOUT_NS,
            )
            .unwrap(),
            GroupReadiness::Ready,
        );
        unsafe {
            libc::close(sockets[1]);
        }

        let task = Task {
            ordinal: 0,
            id: "task:reused-identity".into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 0,
            permit_names: Vec::new(),
        };
        let mut active = ActiveTask::new(
            task,
            sentinel,
            sentinel,
            -1,
            0,
            "reused-identity-run",
            false,
        );
        active.reaped = true;
        active.signal_group(libc::SIGKILL);
        let survived_signal = unsafe { libc::kill(sentinel, 0) } == 0;

        let engine = Engine::new(
            "reused-identity-run".into(),
            1,
            HashMap::new(),
            Duration::from_millis(10),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler.active.insert(sentinel, active);
        assert!(scheduler
            .cancel()
            .unwrap_err()
            .contains("channel close for task task:reused-identity"));
        let survived_cancel = unsafe { libc::kill(sentinel, 0) } == 0;

        unsafe {
            libc::kill(-sentinel, libc::SIGKILL);
        }
        wait_bounded_checked(sentinel).unwrap();

        assert!(survived_signal);
        assert!(survived_cancel);
    }

    #[test]
    fn executor_readiness_confirms_its_process_group() {
        let sockets = socket_pair().unwrap();
        let pid = unsafe { libc::fork() };
        assert_ne!(pid, -1);

        if pid == 0 {
            unsafe {
                libc::close(sockets[1]);
            }
            if unsafe { libc::setpgid(0, 0) } != 0 || !write_group_ready(sockets[0], true) {
                unsafe {
                    libc::_exit(1);
                }
            }

            loop {
                unsafe {
                    libc::pause();
                }
            }
        }

        unsafe {
            libc::close(sockets[0]);
            libc::setpgid(pid, pid);
        }
        assert_eq!(
            read_group_ready(
                sockets[1],
                monotonic_ns().unwrap() + PROCESS_BOUNDARY_TIMEOUT_NS,
            )
            .unwrap(),
            GroupReadiness::Ready,
        );
        assert_eq!(unsafe { libc::getpgid(pid) }, pid);
        unsafe {
            libc::close(sockets[1]);
            libc::kill(-pid, libc::SIGKILL);
        }
        let mut status = 0;
        assert_eq!(unsafe { libc::waitpid(pid, &mut status, 0) }, pid);
        assert!(libc::WIFSIGNALED(status));
        assert_eq!(libc::WTERMSIG(status), libc::SIGKILL);
    }

    #[test]
    fn shared_pool_reserves_before_any_fork() {
        let registry = PermitRegistry::new(1, HashMap::new()).unwrap();
        let names = registry.names(&["scope:root".into()]);

        assert!(registry.try_acquire(&names).unwrap());
        assert!(!registry.try_acquire(&names).unwrap());
        registry.release(&names).unwrap();
        assert!(registry.try_acquire(&names).unwrap());
        registry.release(&names).unwrap();
        registry.close();
    }

    #[test]
    fn release_all_attempts_every_pool_and_retains_only_failures() {
        let mut engine = Engine::new(
            "permit-release-run".into(),
            1,
            HashMap::from([("limited".into(), 1)]),
            Duration::from_millis(10),
        )
        .unwrap();
        engine.acquire(&["limited".into()]).unwrap();
        let limited = engine
            .registry
            .pools
            .get_mut("scope:limited")
            .expect("limited pool exists");

        unsafe {
            libc::close(limited.write);
        }
        limited.write = -1;

        let error = engine.release_all().unwrap_err();
        assert!(error.contains("scope:limited"));
        assert_eq!(engine.held, HashMap::from([("scope:limited".into(), 1)]));
        assert!(engine.registry.take(GLOBAL_POOL).unwrap());

        engine.held.clear();
    }

    #[test]
    fn executor_child_retains_registry_for_nested_scope_permits() {
        let run_id = "nested-permit-run";
        let engine = Engine::new(
            run_id.into(),
            2,
            HashMap::from([("scope:nested".into(), 1)]),
            Duration::from_millis(20),
        )
        .unwrap();
        let mut scheduler = engine.scheduler(1).unwrap();
        scheduler
            .submit(
                "scope:outer".into(),
                "scope".into(),
                "scope:outer".into(),
                vec!["scope:root".into()],
                1_000,
                false,
            )
            .unwrap();
        let deadline = std::time::Instant::now() + Duration::from_secs(2);

        loop {
            match scheduler.step().unwrap() {
                Step::Child(child) => {
                    let names = engine
                        .registry
                        .names(&["scope:root".into(), "scope:nested".into()]);
                    let permit_works = engine.registry.try_acquire(&names).unwrap_or(false);

                    if permit_works {
                        engine.registry.release(&names).unwrap();
                    }

                    let started_ns = monotonic_ns().unwrap();
                    write_frame(
                        child.fd,
                        &Frame::new(
                            run_id.into(),
                            child.task_id.clone(),
                            "scope".into(),
                            "scope:outer".into(),
                            child.ordinal,
                            0,
                            "task.started".into(),
                            json!({ "started_ns": started_ns }),
                        )
                        .unwrap(),
                    )
                    .unwrap();
                    write_frame(
                        child.fd,
                        &Frame::new(
                            run_id.into(),
                            child.task_id.clone(),
                            "scope".into(),
                            "scope:outer".into(),
                            child.ordinal,
                            1,
                            "task.value".into(),
                            json!({
                                "encoding": "base64",
                                "data": STANDARD.encode(b"null")
                            }),
                        )
                        .unwrap(),
                    )
                    .unwrap();
                    write_frame(
                        child.fd,
                        &Frame::new(
                            run_id.into(),
                            child.task_id.clone(),
                            "scope".into(),
                            "scope:outer".into(),
                            child.ordinal,
                            2,
                            "task.finished".into(),
                            json!({
                                "status": if permit_works { "passed" } else { "failed" },
                                "failure": if permit_works {
                                    Value::Null
                                } else {
                                    scheduler_failure(
                                        "permit_failure",
                                        "The executor lost inherited permit descriptors.".into(),
                                    )
                                },
                                "finished_ns": monotonic_ns().unwrap(),
                                "memory_peak_bytes": null
                            }),
                        )
                        .unwrap(),
                    )
                    .unwrap();

                    unsafe {
                        libc::close(child.fd);
                        libc::_exit(0);
                    }
                }
                Step::Result(result) => {
                    assert_eq!(result.status, "passed", "{:?}", result.failure);
                    assert!(result.failure.is_none());
                    break;
                }
                Step::Progress => {
                    if std::time::Instant::now() >= deadline {
                        let _ = scheduler.cancel();
                        panic!("nested permit regression exceeded its bound");
                    }
                }
                Step::Done => panic!("nested permit regression produced no result"),
            }
        }
    }
}
