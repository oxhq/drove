use crate::protocol::{decode_available, Frame, Validator, MAX_BUFFER_BYTES, PROTOCOL_VERSION};
use serde::Serialize;
use std::collections::{HashMap, HashSet, VecDeque};
use std::io;
use std::os::fd::RawFd;
use std::time::{Duration, Instant};

#[derive(Clone, Debug)]
pub struct Task {
    pub ordinal: u32,
    pub id: String,
    pub timeout: Duration,
}

#[derive(Debug)]
pub struct ChildRole {
    pub ordinal: u32,
    pub task_id: String,
    pub pid: libc::pid_t,
    pub fd: RawFd,
}

#[derive(Clone, Debug, Serialize)]
pub struct TaskResult {
    pub protocol: &'static str,
    pub version: u32,
    pub run_id: String,
    pub task_id: String,
    pub ordinal: u32,
    pub status: String,
    pub failure_kind: Option<String>,
    pub message: Option<String>,
    pub pid: Option<libc::pid_t>,
    pub exit_code: Option<i32>,
    pub signal: Option<i32>,
    pub timed_out: bool,
    pub duration_ms: u64,
    pub frames: Vec<Frame>,
}

#[derive(Debug)]
pub enum Step {
    Child(ChildRole),
    Result(TaskResult),
    Progress,
    Done,
}

#[derive(Debug)]
struct ActiveTask {
    task: Task,
    pid: libc::pid_t,
    fd: RawFd,
    started_at: Instant,
    deadline: Instant,
    cleanup_at: Option<Instant>,
    timed_out: bool,
    kill_sent: bool,
    reaped: bool,
    wait_status: Option<i32>,
    eof: bool,
    buffer: Vec<u8>,
    validator: Validator,
    frames: Vec<Frame>,
    terminal: Option<Frame>,
    protocol_error: Option<String>,
}

impl ActiveTask {
    fn new(task: Task, pid: libc::pid_t, fd: RawFd, run_id: &str) -> Self {
        let started_at = Instant::now();

        Self {
            deadline: started_at + task.timeout,
            validator: Validator::new(run_id.into(), task.id.clone()),
            task,
            pid,
            fd,
            started_at,
            cleanup_at: None,
            timed_out: false,
            kill_sent: false,
            reaped: false,
            wait_status: None,
            eof: false,
            buffer: Vec::new(),
            frames: Vec::new(),
            terminal: None,
            protocol_error: None,
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

                if self.buffer.len() > MAX_BUFFER_BYTES {
                    self.protocol_error
                        .get_or_insert_with(|| "Drover child buffer exceeded the v1 limit.".into());
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

        if self.protocol_error.is_none() {
            match decode_available(&mut self.buffer, &mut self.validator) {
                Ok(frames) => {
                    for frame in frames {
                        if frame.event_type == "task.finished" {
                            self.terminal = Some(frame.clone());
                        }

                        self.frames.push(frame);
                    }
                }
                Err(error) => {
                    self.protocol_error = Some(error.to_string());
                }
            }
        }

        if self.eof && !self.buffer.is_empty() {
            self.protocol_error
                .get_or_insert_with(|| "Drover received a truncated child frame.".into());
        }
    }

    fn reap(&mut self) {
        if self.reaped {
            return;
        }

        let mut status = 0;
        let waited = unsafe { libc::waitpid(self.pid, &mut status, libc::WNOHANG) };

        if waited == self.pid {
            self.reaped = true;
            self.wait_status = Some(status);
            return;
        }

        if waited == -1 {
            let error = io::Error::last_os_error();

            if error.kind() == io::ErrorKind::Interrupted {
                return;
            }

            self.reaped = true;
            self.protocol_error
                .get_or_insert_with(|| format!("Drover lost a child while waiting: {error}."));
        }
    }

    fn exit_code(&self) -> Option<i32> {
        let status = self.wait_status?;

        if libc::WIFEXITED(status) {
            Some(libc::WEXITSTATUS(status))
        } else {
            None
        }
    }

    fn signal(&self) -> Option<i32> {
        let status = self.wait_status?;

        if libc::WIFSIGNALED(status) {
            Some(libc::WTERMSIG(status))
        } else {
            None
        }
    }

    fn normalize_terminal_consistency(&mut self) {
        if !self.reaped || self.timed_out || self.protocol_error.is_some() {
            return;
        }

        let Some(terminal) = self.terminal.as_ref() else {
            if self.signal().is_none() {
                self.protocol_error = Some("Drover child exited without a terminal result.".into());
            }

            return;
        };

        let status = terminal
            .payload
            .get("status")
            .and_then(serde_json::Value::as_str);

        match (status, self.exit_code()) {
            (Some("passed"), Some(0)) => {}
            (Some("failed"), Some(code)) if code != 0 => {}
            (Some("passed"), code) => {
                self.protocol_error = Some(format!(
                    "A passing Drover child exited with {}.",
                    code.map_or_else(|| "no exit code".into(), |value| value.to_string())
                ));
            }
            (Some("failed"), code) => {
                self.protocol_error = Some(format!(
                    "A failed Drover child exited with {}.",
                    code.map_or_else(|| "no exit code".into(), |value| value.to_string())
                ));
            }
            _ => {
                self.protocol_error =
                    Some("Drover received a terminal result without a valid status.".into());
            }
        }
    }

    fn begin_cleanup(&mut self, now: Instant) {
        if self.cleanup_at.is_some() {
            return;
        }

        unsafe {
            libc::kill(-self.pid, libc::SIGTERM);
        }
        self.cleanup_at = Some(now);
    }

    fn enforce(&mut self, now: Instant, grace: Duration) {
        if !self.reaped && !self.timed_out && now >= self.deadline {
            self.timed_out = true;
            self.begin_cleanup(now);
        }

        self.normalize_terminal_consistency();

        if self.protocol_error.is_some() || (self.reaped && self.signal().is_some()) {
            self.begin_cleanup(now);
        }

        if let Some(cleanup_at) = self.cleanup_at {
            if !self.kill_sent && now.saturating_duration_since(cleanup_at) >= grace {
                unsafe {
                    libc::kill(-self.pid, libc::SIGKILL);
                }
                self.kill_sent = true;
            }
        }
    }

    fn ready(&self) -> bool {
        self.reaped && self.eof && self.cleanup_at.map(|_| self.kill_sent).unwrap_or(true)
    }

    fn into_result(self, run_id: &str) -> TaskResult {
        let exit_code = self.exit_code();
        let signal = self.signal();
        let duration_ms = self.started_at.elapsed().as_millis() as u64;

        let (status, failure_kind, message) = if self.timed_out {
            (
                "failed".into(),
                Some("timeout".into()),
                Some("The Drover task exceeded its monotonic timeout.".into()),
            )
        } else if let Some(error) = self.protocol_error {
            (
                "failed".into(),
                Some("child_protocol_failure".into()),
                Some(error),
            )
        } else if let Some(signal) = signal {
            (
                "failed".into(),
                Some("signal_termination".into()),
                Some(format!("The Drover task ended from signal {signal}.")),
            )
        } else if let Some(terminal) = self.terminal {
            let terminal_status = terminal
                .payload
                .get("status")
                .and_then(serde_json::Value::as_str)
                .unwrap_or("failed");

            if terminal_status == "passed" {
                ("passed".into(), None, None)
            } else {
                (
                    "failed".into(),
                    terminal
                        .payload
                        .get("failure_kind")
                        .and_then(serde_json::Value::as_str)
                        .map(str::to_owned),
                    terminal
                        .payload
                        .get("message")
                        .and_then(serde_json::Value::as_str)
                        .map(str::to_owned),
                )
            }
        } else {
            (
                "failed".into(),
                Some("child_protocol_failure".into()),
                Some("The Drover task produced no terminal result.".into()),
            )
        };

        TaskResult {
            protocol: "drover.result",
            version: PROTOCOL_VERSION,
            run_id: run_id.into(),
            task_id: self.task.id,
            ordinal: self.task.ordinal,
            status,
            failure_kind,
            message,
            pid: Some(self.pid),
            exit_code,
            signal,
            timed_out: self.timed_out,
            duration_ms,
            frames: self.frames,
        }
    }
}

#[derive(Debug)]
pub struct Scheduler {
    run_id: String,
    concurrency: usize,
    queue_capacity: usize,
    grace: Duration,
    next_ordinal: u32,
    submitted: HashSet<String>,
    pending: VecDeque<Task>,
    active: HashMap<libc::pid_t, ActiveTask>,
    completed: VecDeque<TaskResult>,
    max_active: usize,
}

impl Scheduler {
    pub fn new(
        run_id: String,
        concurrency: usize,
        queue_capacity: usize,
        grace: Duration,
    ) -> Result<Self, String> {
        if run_id.is_empty() {
            return Err("Drover run IDs cannot be empty.".into());
        }

        if !(1..=256).contains(&concurrency) {
            return Err("Drover concurrency must be between 1 and 256.".into());
        }

        if !(1..=1_000_000).contains(&queue_capacity) {
            return Err("Drover queue capacity must be between 1 and 1000000.".into());
        }

        if grace.is_zero() {
            return Err("Drover termination grace must be positive.".into());
        }

        Ok(Self {
            run_id,
            concurrency,
            queue_capacity,
            grace,
            next_ordinal: 0,
            submitted: HashSet::new(),
            pending: VecDeque::new(),
            active: HashMap::new(),
            completed: VecDeque::new(),
            max_active: 0,
        })
    }

    pub fn submit(&mut self, task_id: String, timeout: Duration) -> Result<u32, String> {
        if task_id.is_empty() || task_id.len() >= 128 {
            return Err("Drover task IDs must contain between 1 and 127 bytes.".into());
        }

        if timeout.is_zero() {
            return Err("Drover task timeouts must be positive.".into());
        }

        if self.pending.len() >= self.queue_capacity {
            return Err(format!(
                "Drover pending queue reached its {} task capacity.",
                self.queue_capacity
            ));
        }

        if !self.submitted.insert(task_id.clone()) {
            return Err(format!("Duplicate Drover task ID {task_id}."));
        }

        let ordinal = self.next_ordinal;
        self.next_ordinal = self
            .next_ordinal
            .checked_add(1)
            .ok_or_else(|| "Drover task ordinal overflowed.".to_string())?;
        self.pending.push_back(Task {
            ordinal,
            id: task_id,
            timeout,
        });

        Ok(ordinal)
    }

    pub fn step(&mut self) -> Result<Step, String> {
        self.collect();

        if let Some(result) = self.completed.pop_front() {
            return Ok(Step::Result(result));
        }

        if self.active.len() < self.concurrency {
            if let Some(task) = self.pending.pop_front() {
                return self.spawn(task);
            }
        }

        if self.pending.is_empty() && self.active.is_empty() {
            return Ok(Step::Done);
        }

        self.poll_once()?;
        self.collect();

        if let Some(result) = self.completed.pop_front() {
            return Ok(Step::Result(result));
        }

        Ok(Step::Progress)
    }

    pub fn active_count(&self) -> usize {
        self.active.len()
    }

    pub fn max_active(&self) -> usize {
        self.max_active
    }

    fn spawn(&mut self, task: Task) -> Result<Step, String> {
        // The active slot is checked before this method and this scheduler is
        // deliberately single-threaded, so capacity is reserved before fork.
        let mut sockets = [0_i32; 2];
        let socket_result = unsafe {
            libc::socketpair(
                libc::AF_UNIX,
                libc::SOCK_STREAM | libc::SOCK_CLOEXEC,
                0,
                sockets.as_mut_ptr(),
            )
        };

        if socket_result != 0 {
            self.completed.push_back(self.immediate_failure(
                task,
                "fork_failure",
                format!(
                    "Drover could not create a child channel: {}.",
                    io::Error::last_os_error()
                ),
            ));

            return Ok(Step::Progress);
        }

        let pid = unsafe { libc::fork() };

        if pid == -1 {
            unsafe {
                libc::close(sockets[0]);
                libc::close(sockets[1]);
            }
            self.completed.push_back(self.immediate_failure(
                task,
                "fork_failure",
                format!(
                    "Drover could not fork a PHP task: {}.",
                    io::Error::last_os_error()
                ),
            ));

            return Ok(Step::Progress);
        }

        if pid == 0 {
            unsafe {
                libc::close(sockets[0]);

                for active in self.active.values() {
                    libc::close(active.fd);
                }

                libc::signal(libc::SIGPIPE, libc::SIG_IGN);

                if libc::setpgid(0, 0) != 0 {
                    libc::_exit(126);
                }
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
            libc::setpgid(pid, pid);
        }

        if let Err(error) = set_nonblocking(sockets[0]) {
            unsafe {
                libc::kill(-pid, libc::SIGKILL);
                libc::close(sockets[0]);
                libc::waitpid(pid, std::ptr::null_mut(), 0);
            }
            self.completed
                .push_back(self.immediate_failure(task, "fork_failure", error));

            return Ok(Step::Progress);
        }
        self.active
            .insert(pid, ActiveTask::new(task, pid, sockets[0], &self.run_id));
        self.max_active = self.max_active.max(self.active.len());

        Ok(Step::Progress)
    }

    fn collect(&mut self) {
        let now = Instant::now();
        let pids: Vec<_> = self.active.keys().copied().collect();
        let mut ready = Vec::new();

        for pid in pids {
            let Some(active) = self.active.get_mut(&pid) else {
                continue;
            };

            active.read_available();
            active.reap();
            active.enforce(now, self.grace);
            active.read_available();

            if active.ready() {
                ready.push(pid);
            }
        }

        for pid in ready {
            if let Some(active) = self.active.remove(&pid) {
                unsafe {
                    libc::close(active.fd);
                }
                self.completed.push_back(active.into_result(&self.run_id));
            }
        }
    }

    fn poll_once(&self) -> Result<(), String> {
        let mut descriptors: Vec<_> = self
            .active
            .values()
            .map(|active| libc::pollfd {
                fd: active.fd,
                events: libc::POLLIN | libc::POLLHUP | libc::POLLERR,
                revents: 0,
            })
            .collect();

        if descriptors.is_empty() {
            return Ok(());
        }

        let timeout = self.poll_timeout_ms();
        let result = unsafe {
            libc::poll(
                descriptors.as_mut_ptr(),
                descriptors.len() as libc::nfds_t,
                timeout,
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

    fn poll_timeout_ms(&self) -> i32 {
        let now = Instant::now();
        let mut timeout = Duration::from_millis(5);

        for active in self.active.values() {
            let target = if let Some(cleanup_at) = active.cleanup_at {
                if active.kill_sent {
                    continue;
                }

                cleanup_at + self.grace
            } else {
                active.deadline
            };

            timeout = timeout.min(target.saturating_duration_since(now));
        }

        timeout.as_millis().min(5) as i32
    }

    fn immediate_failure(&self, task: Task, failure_kind: &str, message: String) -> TaskResult {
        TaskResult {
            protocol: "drover.result",
            version: PROTOCOL_VERSION,
            run_id: self.run_id.clone(),
            task_id: task.id,
            ordinal: task.ordinal,
            status: "failed".into(),
            failure_kind: Some(failure_kind.into()),
            message: Some(message),
            pid: None,
            exit_code: None,
            signal: None,
            timed_out: false,
            duration_ms: 0,
            frames: Vec::new(),
        }
    }
}

impl Drop for Scheduler {
    fn drop(&mut self) {
        for active in self.active.values() {
            unsafe {
                libc::kill(-active.pid, libc::SIGKILL);
                libc::close(active.fd);
            }
        }

        for pid in self.active.keys() {
            unsafe {
                libc::waitpid(*pid, std::ptr::null_mut(), 0);
            }
        }
    }
}

fn set_nonblocking(fd: RawFd) -> Result<(), String> {
    let flags = unsafe { libc::fcntl(fd, libc::F_GETFL) };

    if flags == -1 || unsafe { libc::fcntl(fd, libc::F_SETFL, flags | libc::O_NONBLOCK) } == -1 {
        return Err(format!(
            "Drover could not make a child channel nonblocking: {}.",
            io::Error::last_os_error()
        ));
    }

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_work_beyond_the_pending_queue_capacity() {
        let mut scheduler =
            Scheduler::new("bounded-run".into(), 1, 1, Duration::from_millis(10)).unwrap();

        assert_eq!(
            scheduler
                .submit("task:first".into(), Duration::from_millis(10))
                .unwrap(),
            0
        );
        assert!(scheduler
            .submit("task:overflow".into(), Duration::from_millis(10))
            .unwrap_err()
            .contains("reached its 1 task capacity"));
    }
}
