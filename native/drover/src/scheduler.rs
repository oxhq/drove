use crate::protocol::{
    decode_available, monotonic_ns, write_frame, Frame, ProtocolError, Validator, MAX_BUFFER_BYTES,
    MAX_STREAM_BYTES,
};
use base64::engine::general_purpose::STANDARD;
use base64::Engine as _;
use serde::Serialize;
use serde_json::{json, Value};
use std::collections::{HashMap, HashSet, VecDeque};
use std::io;
use std::os::fd::RawFd;
use std::time::Duration;

const GLOBAL_POOL: &str = "@global";
const MAX_QUEUE_CAPACITY: usize = 1_000_000;

#[derive(Clone, Copy, Debug)]
struct PermitPool {
    read: RawFd,
    write: RawFd,
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
                    let _ = self.release(&acquired);

                    return Err(error);
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

    fn release(&self, names: &[String]) -> Result<(), String> {
        for name in names.iter().rev() {
            let pool = self
                .pools
                .get(name)
                .ok_or_else(|| format!("Drover has no permit pool named {name}."))?;
            write_byte(pool.write)?;
        }

        Ok(())
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

        Ok(Self {
            run_id,
            grace_ns: grace.as_nanos() as u64,
            registry: PermitRegistry::new(concurrency, scope_limits)?,
            held: HashMap::new(),
        })
    }

    pub fn scheduler(&self, queue_capacity: usize) -> Result<Scheduler, String> {
        Scheduler::new(
            self.run_id.clone(),
            queue_capacity,
            self.grace_ns,
            self.registry.clone(),
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

        self.registry.release(&names)?;

        for name in names {
            let count = self
                .held
                .get_mut(&name)
                .expect("held permit was checked above");
            *count -= 1;

            if *count == 0 {
                self.held.remove(&name);
            }
        }

        Ok(())
    }

    pub fn release_all(&mut self) -> Result<(), String> {
        let held = std::mem::take(&mut self.held);
        let mut names = Vec::new();

        for (name, count) in held {
            names.extend(std::iter::repeat_n(name, count));
        }

        self.registry.release(&names)
    }
}

impl Drop for Engine {
    fn drop(&mut self) {
        let _ = self.release_all();
        self.registry.close();
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

#[derive(Debug)]
struct ActiveTask {
    task: Task,
    pid: libc::pid_t,
    pgid: libc::pid_t,
    fd: RawFd,
    anchor_fd: RawFd,
    exited: bool,
    reaped: bool,
    anchor_reaped: bool,
    wait_status: Option<i32>,
    eof: bool,
    eof_ns: Option<u64>,
    buffer: Vec<u8>,
    validator: Validator,
    frames: Vec<Frame>,
    stdout: Vec<u8>,
    stderr: Vec<u8>,
    value_buffer: Vec<u8>,
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
}

impl ActiveTask {
    fn new(
        task: Task,
        pid: libc::pid_t,
        pgid: libc::pid_t,
        fd: RawFd,
        anchor_fd: RawFd,
        spawned_ns: u64,
        run_id: &str,
    ) -> Self {
        let deadline_ns = (task.timeout_ms > 0)
            .then(|| spawned_ns.saturating_add(task.timeout_ms.saturating_mul(1_000_000)));

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
            anchor_fd,
            exited: false,
            reaped: false,
            anchor_reaped: false,
            wait_status: None,
            eof: false,
            eof_ns: None,
            buffer: Vec::new(),
            frames: Vec::new(),
            stdout: Vec::new(),
            stderr: Vec::new(),
            value_buffer: Vec::new(),
            started_ns: None,
            deadline_ns,
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
        }
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
                self.finished_ns.get_or_insert(now_ns);
            }

            return;
        }

        let error = io::Error::last_os_error();

        if error.kind() == io::ErrorKind::Interrupted {
            return;
        }

        self.exited = true;
        self.reaped = true;
        self.finished_ns.get_or_insert(now_ns);
        self.protocol_error
            .get_or_insert_with(|| format!("waitid() lost the Drove child: {error}."));
    }

    fn reap(&mut self, now_ns: u64) {
        if self.reaped
            || !self.exited
            || self.kill_ns.is_none()
                && (self.term_ns.is_some() || self.cleanup_term_ns.is_some() || !self.eof)
        {
            return;
        }

        let mut status = 0;
        let waited = unsafe { libc::waitpid(self.pid, &mut status, libc::WNOHANG) };

        if waited == self.pid {
            self.reaped = true;
            self.wait_status = Some(status);
            self.finished_ns.get_or_insert(now_ns);

            return;
        }

        if waited == -1 {
            let error = io::Error::last_os_error();

            if error.kind() == io::ErrorKind::Interrupted {
                return;
            }

            self.reaped = true;
            self.finished_ns.get_or_insert(now_ns);
            self.protocol_error
                .get_or_insert_with(|| format!("waitpid() lost the Drove child: {error}."));
        }
    }

    fn reap_anchor(&mut self) {
        if self.anchor_reaped {
            return;
        }

        let waited = unsafe { libc::waitpid(self.pgid, std::ptr::null_mut(), libc::WNOHANG) };

        if waited == self.pgid {
            self.anchor_reaped = true;

            if self.kill_ns.is_none() {
                self.protocol_error.get_or_insert_with(|| {
                    "The Drove process-group anchor exited before cleanup.".into()
                });
            }

            return;
        }

        if waited == -1 {
            let error = io::Error::last_os_error();

            if error.kind() != io::ErrorKind::Interrupted {
                self.anchor_reaped = true;
                self.protocol_error.get_or_insert_with(|| {
                    format!("waitpid() lost the Drove process-group anchor: {error}.")
                });
            }
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
        if self.anchor_reaped {
            if !self.reaped {
                unsafe {
                    libc::kill(self.pid, signal);
                }
            }

            self.protocol_error.get_or_insert_with(|| {
                "The Drove process-group anchor exited before cleanup. Direct fallback cannot guarantee descendant cleanup.".into()
            });

            return;
        }

        if let Err(error) = signal_process_group(self.pgid, signal) {
            if !self.reaped {
                unsafe {
                    libc::kill(self.pid, signal);
                }
            }
            if !self.anchor_reaped {
                unsafe {
                    libc::kill(self.pgid, signal);
                }
            }

            self.protocol_error.get_or_insert_with(|| {
                format!("{error} Direct fallback cannot guarantee descendant cleanup.")
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
            && protocol_stopped_ns.is_some_and(|stopped| now_ns.saturating_sub(stopped) >= grace_ns)
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

        if self.reaped && self.eof && escalation_ns.is_none() && self.kill_ns.is_none() {
            self.signal_group(libc::SIGKILL);
            self.kill_ns = Some(now_ns);
        }

        if !self.eof
            && self
                .kill_ns
                .is_some_and(|killed| now_ns.saturating_sub(killed) >= grace_ns)
        {
            self.cleanup_failed = true;
            self.eof = true;
        }
    }

    fn ready(&self) -> bool {
        self.reaped && self.anchor_reaped && self.eof && self.kill_ns.is_some()
    }

    fn into_result(self) -> TaskResult {
        let exit_code = self.exit_code();
        let signal = self.signal();
        let telemetry = telemetry(
            Some(self.pid),
            Some(self.pgid),
            self.started_ns,
            self.finished_ns,
            exit_code,
            signal,
            self.interrupted_signal,
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
    next_ordinal: u32,
    submitted: HashSet<String>,
    pending: VecDeque<Task>,
    active: HashMap<libc::pid_t, ActiveTask>,
    completed: VecDeque<TaskResult>,
    max_active: usize,
    interrupted_signal: Option<i32>,
}

impl Scheduler {
    fn new(
        run_id: String,
        queue_capacity: usize,
        grace_ns: u64,
        registry: PermitRegistry,
    ) -> Result<Self, String> {
        if !(1..=MAX_QUEUE_CAPACITY).contains(&queue_capacity) {
            return Err(format!(
                "Drover queue capacity must be between 1 and {MAX_QUEUE_CAPACITY}."
            ));
        }

        Ok(Self {
            run_id,
            queue_capacity,
            grace_ns,
            registry,
            next_ordinal: 0,
            submitted: HashSet::new(),
            pending: VecDeque::new(),
            active: HashMap::new(),
            completed: VecDeque::new(),
            max_active: 0,
            interrupted_signal: None,
        })
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

        if self.pending.len() >= self.queue_capacity {
            return Err(format!(
                "Drover pending queue reached its {} task capacity.",
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

        Ok(ordinal)
    }

    pub fn step(&mut self) -> Result<Step, String> {
        self.collect()?;

        if let Some(result) = self.completed.pop_front() {
            return Ok(Step::Result(Box::new(result)));
        }

        if self.interrupted_signal.is_none() {
            if let Some(step) = self.spawn_available()? {
                return Ok(step);
            }
        }

        if self.pending.is_empty() && self.active.is_empty() {
            return Ok(Step::Done);
        }

        self.poll_once()?;
        self.collect()?;

        if let Some(result) = self.completed.pop_front() {
            return Ok(Step::Result(Box::new(result)));
        }

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
        self.active.len()
    }

    pub fn max_active(&self) -> usize {
        self.max_active
    }

    pub fn cancel(&mut self) {
        let active = std::mem::take(&mut self.active);

        for (_, child) in active {
            unsafe {
                if !child.anchor_reaped {
                    libc::kill(-child.pgid, libc::SIGKILL);
                    libc::kill(child.pgid, libc::SIGKILL);
                }
                if !child.reaped {
                    libc::kill(child.pid, libc::SIGKILL);
                }
                libc::close(child.fd);
                libc::close(child.anchor_fd);
            }

            if !child.reaped {
                wait_blocking(child.pid);
            }
            if !child.anchor_reaped {
                wait_blocking(child.pgid);
            }
            let _ = self.registry.release(&child.task.permit_names);
        }

        self.pending.clear();
    }

    fn spawn_available(&mut self) -> Result<Option<Step>, String> {
        for index in 0..self.pending.len() {
            let permit_names = &self
                .pending
                .get(index)
                .expect("pending index came from its length")
                .permit_names;

            if !self.registry.try_acquire(permit_names)? {
                continue;
            }

            let task = self
                .pending
                .remove(index)
                .expect("pending task existed while reserving permits");

            return self.spawn(task).map(Some);
        }

        Ok(None)
    }

    fn spawn(&mut self, task: Task) -> Result<Step, String> {
        if let Err(error) = validate_sigchld_disposition() {
            self.registry.release(&task.permit_names)?;

            return Err(error);
        }

        let spawned_ns = match monotonic_ns() {
            Ok(spawned_ns) => spawned_ns,
            Err(error) => {
                self.registry.release(&task.permit_names)?;

                return Err(error.to_string());
            }
        };
        let sockets = match socket_pair() {
            Ok(sockets) => sockets,
            Err(error) => {
                self.registry.release(&task.permit_names)?;
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

        let anchor_sockets = match socket_pair() {
            Ok(sockets) => sockets,
            Err(error) => {
                close_descriptors(sockets);
                self.registry.release(&task.permit_names)?;
                self.completed.push_back(failed_result(
                    task,
                    "fork_failure",
                    format!("Unable to create a Drove anchor channel: {error}."),
                    empty_telemetry(None),
                    Vec::new(),
                ));

                return Ok(Step::Progress);
            }
        };

        // An inert sibling keeps the task group alive after the PHP executor exits.
        let pgid = unsafe { libc::fork() };

        if pgid == -1 {
            close_descriptors(sockets);
            close_descriptors(anchor_sockets);
            self.registry.release(&task.permit_names)?;
            self.completed.push_back(failed_result(
                task,
                "fork_failure",
                format!(
                    "Unable to fork a Drove process-group anchor: {}.",
                    io::Error::last_os_error()
                ),
                empty_telemetry(None),
                Vec::new(),
            ));

            return Ok(Step::Progress);
        }

        if pgid == 0 {
            unsafe {
                libc::close(sockets[0]);
                libc::close(sockets[1]);
                libc::close(anchor_sockets[1]);

                for active in self.active.values() {
                    libc::close(active.fd);
                    libc::close(active.anchor_fd);
                }

                self.registry.close();

                if !block_anchor_signals() {
                    libc::_exit(1);
                }
            }

            if unsafe { libc::setpgid(0, 0) } != 0 {
                unsafe {
                    libc::_exit(1);
                }
            }

            if !write_anchor_ready(anchor_sockets[0]) {
                unsafe {
                    libc::_exit(1);
                }
            }

            wait_for_parent(anchor_sockets[0]);
        }

        unsafe {
            libc::close(anchor_sockets[0]);
        }

        if unsafe { libc::setpgid(pgid, pgid) } != 0 {
            let error = io::Error::last_os_error();

            unsafe {
                libc::kill(pgid, libc::SIGKILL);
                libc::close(anchor_sockets[1]);
            }
            close_descriptors(sockets);
            wait_blocking(pgid);
            self.registry.release(&task.permit_names)?;
            self.completed.push_back(failed_result(
                task,
                "fork_failure",
                format!("Unable to create a Drove process-group anchor: {error}."),
                empty_telemetry(None),
                Vec::new(),
            ));

            return Ok(Step::Progress);
        }

        if let Err(error) = read_anchor_ready(anchor_sockets[1]) {
            unsafe {
                libc::kill(pgid, libc::SIGKILL);
                libc::close(anchor_sockets[1]);
            }
            close_descriptors(sockets);
            wait_blocking(pgid);
            self.registry.release(&task.permit_names)?;
            self.completed.push_back(failed_result(
                task,
                "fork_failure",
                error,
                empty_telemetry(None),
                Vec::new(),
            ));

            return Ok(Step::Progress);
        }

        let pid = unsafe { libc::fork() };

        if pid == -1 {
            unsafe {
                libc::kill(-pgid, libc::SIGKILL);
                libc::kill(pgid, libc::SIGKILL);
                libc::close(anchor_sockets[1]);
            }
            close_descriptors(sockets);
            wait_blocking(pgid);
            self.registry.release(&task.permit_names)?;
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

                for active in self.active.values() {
                    libc::close(active.fd);
                    libc::close(active.anchor_fd);
                }

                libc::signal(libc::SIGPIPE, libc::SIG_IGN);
            }

            if unsafe { libc::setpgid(0, pgid) } != 0 {
                write_process_group_failure(sockets[1], &self.run_id, &task);

                unsafe {
                    libc::_exit(1);
                }
            }

            unsafe {
                libc::close(anchor_sockets[1]);
            }

            return Ok(Step::Child(ChildRole {
                ordinal: task.ordinal,
                task_id: task.id,
                pid: unsafe { libc::getpid() },
                fd: sockets[1],
            }));
        }

        unsafe {
            libc::close(sockets[1]);
            libc::setpgid(pid, pgid);
        }

        if let Err(error) = set_nonblocking(sockets[0]) {
            unsafe {
                libc::kill(-pgid, libc::SIGKILL);
                libc::kill(pid, libc::SIGKILL);
                libc::kill(pgid, libc::SIGKILL);
                libc::close(sockets[0]);
                libc::close(anchor_sockets[1]);
            }
            wait_blocking(pid);
            wait_blocking(pgid);
            self.registry.release(&task.permit_names)?;
            self.completed.push_back(failed_result(
                task,
                "fork_failure",
                error,
                empty_telemetry(None),
                Vec::new(),
            ));

            return Ok(Step::Progress);
        }

        self.active.insert(
            pid,
            ActiveTask::new(
                task,
                pid,
                pgid,
                sockets[0],
                anchor_sockets[1],
                spawned_ns,
                &self.run_id,
            ),
        );
        self.max_active = self.max_active.max(self.active.len());

        Ok(Step::Progress)
    }

    fn collect(&mut self) -> Result<(), String> {
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
            let Some(active) = self.active.get_mut(&pid) else {
                continue;
            };

            active.read_available();
            active.observe_exit(now_ns);
            active.reap(now_ns);
            active.reap_anchor();
            active.read_available();
            active.enforce(now_ns, self.grace_ns);
            active.reap_anchor();

            if active.ready() {
                ready.push(pid);
            }
        }

        for pid in ready {
            if let Some(active) = self.active.remove(&pid) {
                unsafe {
                    libc::close(active.fd);
                    libc::close(active.anchor_fd);
                }
                self.registry.release(&active.task.permit_names)?;
                self.completed.push_back(active.into_result());
            }
        }

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

            if seen.insert(active.anchor_fd) {
                descriptors.push(libc::pollfd {
                    fd: active.anchor_fd,
                    events: libc::POLLHUP | libc::POLLERR,
                    revents: 0,
                });
            }
        }

        self.registry.append_poll_descriptors(
            self.pending
                .iter()
                .flat_map(|task| task.permit_names.iter().cloned()),
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
                kill_ns.saturating_add(self.grace_ns)
            } else if let Some(term_ns) = active.term_ns.or(active.cleanup_term_ns) {
                term_ns.saturating_add(self.grace_ns)
            } else if let Some(stopped_ns) = active.terminal_received_ns.or(active.eof_ns) {
                stopped_ns.saturating_add(self.grace_ns)
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
        self.cancel();
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
    let mut sockets = [0_i32; 2];

    if unsafe { libc::socketpair(libc::AF_UNIX, libc::SOCK_STREAM, 0, sockets.as_mut_ptr()) } != 0 {
        return Err(io::Error::last_os_error());
    }

    for fd in sockets {
        let flags = unsafe { libc::fcntl(fd, libc::F_GETFD) };

        if flags == -1 || unsafe { libc::fcntl(fd, libc::F_SETFD, flags | libc::FD_CLOEXEC) } == -1
        {
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

    loop {
        let result = unsafe { libc::write(fd, (&byte as *const u8).cast(), 1) };

        if result == 1 {
            return Ok(());
        }

        let error = io::Error::last_os_error();

        if error.kind() == io::ErrorKind::Interrupted {
            continue;
        }

        return Err(format!(
            "Drover could not release a concurrency permit: {error}."
        ));
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

fn wait_blocking(pid: libc::pid_t) {
    loop {
        let result = unsafe { libc::waitpid(pid, std::ptr::null_mut(), 0) };

        if result == pid
            || result == -1 && io::Error::last_os_error().raw_os_error() == Some(libc::ECHILD)
        {
            return;
        }

        if result == -1 && io::Error::last_os_error().kind() != io::ErrorKind::Interrupted {
            return;
        }
    }
}

fn block_anchor_signals() -> bool {
    let mut signals = unsafe { std::mem::zeroed::<libc::sigset_t>() };

    unsafe {
        libc::sigfillset(&mut signals) == 0
            && libc::pthread_sigmask(libc::SIG_SETMASK, &signals, std::ptr::null_mut()) == 0
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

fn write_anchor_ready(fd: RawFd) -> bool {
    let byte = b'.';

    loop {
        let written = unsafe { libc::write(fd, (&byte as *const u8).cast(), 1) };

        if written == 1 {
            return true;
        }

        if io::Error::last_os_error().kind() != io::ErrorKind::Interrupted {
            return false;
        }
    }
}

fn read_anchor_ready(fd: RawFd) -> Result<(), String> {
    let mut byte = 0_u8;

    loop {
        let read = unsafe { libc::read(fd, (&mut byte as *mut u8).cast(), 1) };

        if read == 1 {
            return if byte == b'.' {
                Ok(())
            } else {
                Err("The Drove process-group anchor sent an invalid ready signal.".into())
            };
        }

        if read == -1 && io::Error::last_os_error().kind() == io::ErrorKind::Interrupted {
            continue;
        }

        return Err("The Drove process-group anchor exited before it was ready.".into());
    }
}

fn wait_for_parent(fd: RawFd) -> ! {
    let mut byte = 0_u8;

    loop {
        let read = unsafe { libc::read(fd, (&mut byte as *mut u8).cast(), 1) };

        if read > 0 {
            continue;
        }

        if read == -1 && io::Error::last_os_error().kind() == io::ErrorKind::Interrupted {
            continue;
        }

        unsafe {
            libc::kill(0, libc::SIGKILL);
            libc::_exit(1);
        }
    }
}

fn signal_process_group(pgid: libc::pid_t, signal: i32) -> Result<(), String> {
    if unsafe { libc::kill(-pgid, signal) } == 0 {
        return Ok(());
    }

    Err(format!(
        "Drover could not send signal {signal} to process group {pgid}: {}.",
        io::Error::last_os_error()
    ))
}

fn telemetry(
    pid: Option<libc::pid_t>,
    pgid: Option<libc::pid_t>,
    started_ns: Option<u64>,
    finished_ns: Option<u64>,
    exit_code: Option<i32>,
    signal: Option<i32>,
    interrupted_signal: Option<i32>,
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
    }
}

fn empty_telemetry(interrupted_signal: Option<i32>) -> Telemetry {
    telemetry(None, None, None, None, None, None, interrupted_signal)
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
        let mut active = ActiveTask::new(task, 42, 43, file.as_raw_fd(), -1, 0, run_id);
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
    fn rejects_work_beyond_the_pending_queue_capacity() {
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
            -1,
            0,
            "cleanup-error-run",
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
        let mut active = ActiveTask::new(task, 41, 42, -1, -1, 0, "forced-cleanup-run");
        active.exited = true;
        active.reaped = true;
        active.anchor_reaped = true;
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
            -1,
            0,
            "blocked-descendant-run",
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
        assert!(error.contains("process group"));
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
    fn latches_a_blocked_channel_after_forced_cleanup() {
        let task = Task {
            ordinal: 0,
            id: "task:blocked-channel".into(),
            kind: "test".into(),
            scope_id: "scope:root".into(),
            timeout_ms: 0,
            permit_names: Vec::new(),
        };
        let mut active = ActiveTask::new(task, 41, 42, -1, -1, 0, "blocked-channel-run");
        active.kill_ns = Some(1);

        active.enforce(3, 1);

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
                        scheduler.cancel();
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
            if unsafe { libc::setpgid(0, 0) } != 0 || !write_anchor_ready(sockets[0]) {
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
        read_anchor_ready(sockets[1]).unwrap();
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
        let mut active =
            ActiveTask::new(task, sentinel, sentinel, -1, -1, 0, "reused-identity-run");
        active.reaped = true;
        active.anchor_reaped = true;
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
        scheduler.cancel();
        let survived_cancel = unsafe { libc::kill(sentinel, 0) } == 0;

        unsafe {
            libc::kill(-sentinel, libc::SIGKILL);
        }
        wait_blocking(sentinel);

        assert!(survived_signal);
        assert!(survived_cancel);
    }

    #[test]
    fn anchor_dies_when_its_parent_channel_closes() {
        let sockets = socket_pair().unwrap();
        let pid = unsafe { libc::fork() };
        assert_ne!(pid, -1);

        if pid == 0 {
            unsafe {
                libc::close(sockets[1]);
            }
            if !block_anchor_signals()
                || unsafe { libc::setpgid(0, 0) } != 0
                || !write_anchor_ready(sockets[0])
            {
                unsafe {
                    libc::_exit(1);
                }
            }
            wait_for_parent(sockets[0]);
        }

        unsafe {
            libc::close(sockets[0]);
            libc::setpgid(pid, pid);
        }
        read_anchor_ready(sockets[1]).unwrap();
        assert_eq!(unsafe { libc::kill(-pid, libc::SIGUSR1) }, 0);
        std::thread::sleep(Duration::from_millis(10));
        assert_eq!(unsafe { libc::kill(pid, 0) }, 0);

        unsafe {
            libc::close(sockets[1]);
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
}
