mod protocol;
mod scheduler;

use crate::protocol::{
    validate_standalone, write_frame, Frame, ERR_JSON, ERR_NULL, MAX_FRAME_BYTES, PROTOCOL_VERSION,
};
use crate::scheduler::{Scheduler, Step, TaskResult};
use libc::{c_char, c_int, c_void};
use std::ffi::CStr;
use std::ptr;
use std::time::Duration;

pub const ROLE_ERROR: i32 = -1;
pub const ROLE_PROGRESS: i32 = 0;
pub const ROLE_CHILD: i32 = 1;
pub const ROLE_RESULT: i32 = 2;
pub const ROLE_DONE: i32 = 3;

#[repr(C)]
pub struct DroverAction {
    pub role: i32,
    pub ordinal: u32,
    pub pid: i32,
    pub child_fd: i32,
    pub status: i32,
    pub timed_out: i32,
    pub exit_code: i32,
    pub term_signal: i32,
    pub protocol_version: u32,
    pub active_count: u32,
    pub max_active: u32,
    pub frame_count: u32,
    pub task_id: [c_char; 128],
    pub failure_kind: [c_char; 64],
    pub message: [c_char; 256],
}

impl Default for DroverAction {
    fn default() -> Self {
        Self {
            role: ROLE_PROGRESS,
            ordinal: 0,
            pid: -1,
            child_fd: -1,
            status: 0,
            timed_out: 0,
            exit_code: -1,
            term_signal: 0,
            protocol_version: PROTOCOL_VERSION,
            active_count: 0,
            max_active: 0,
            frame_count: 0,
            task_id: [0; 128],
            failure_kind: [0; 64],
            message: [0; 256],
        }
    }
}

struct SchedulerHandle {
    scheduler: Scheduler,
    last_result: Vec<u8>,
}

#[no_mangle]
pub extern "C" fn drover_protocol_version() -> u32 {
    PROTOCOL_VERSION
}

#[no_mangle]
pub extern "C" fn drover_protocol_max_frame_bytes() -> usize {
    MAX_FRAME_BYTES
}

#[no_mangle]
/// Creates a single-threaded Drover scheduler.
///
/// # Safety
///
/// `run_id` must point to a readable, NUL-terminated UTF-8 string.
pub unsafe extern "C" fn drover_scheduler_new(
    run_id: *const c_char,
    concurrency: u32,
    queue_capacity: u32,
    term_grace_ms: u64,
) -> *mut c_void {
    let Ok(run_id) = c_string(run_id) else {
        return ptr::null_mut();
    };
    let Ok(scheduler) = Scheduler::new(
        run_id,
        concurrency as usize,
        queue_capacity as usize,
        Duration::from_millis(term_grace_ms),
    ) else {
        return ptr::null_mut();
    };

    Box::into_raw(Box::new(SchedulerHandle {
        scheduler,
        last_result: Vec::new(),
    }))
    .cast()
}

#[no_mangle]
pub extern "C" fn drover_child_exit(status: c_int) -> ! {
    unsafe {
        libc::_exit(status);
    }
}

#[no_mangle]
/// Adds a task descriptor to the native pending queue.
///
/// # Safety
///
/// `scheduler` must be a live pointer returned by `drover_scheduler_new`, used
/// without concurrent aliases, and `task_id` must be readable NUL-terminated
/// UTF-8.
pub unsafe extern "C" fn drover_scheduler_submit(
    scheduler: *mut c_void,
    task_id: *const c_char,
    timeout_ms: u64,
) -> i32 {
    let Some(handle) = scheduler_handle(scheduler) else {
        return ERR_NULL;
    };
    let Ok(task_id) = c_string(task_id) else {
        return ERR_NULL;
    };

    match handle
        .scheduler
        .submit(task_id, Duration::from_millis(timeout_ms))
    {
        Ok(ordinal) => ordinal as i32,
        Err(_) => ROLE_ERROR,
    }
}

#[no_mangle]
/// Advances the native event loop by one scheduling action.
///
/// # Safety
///
/// `scheduler` must be a live, exclusively used Drover scheduler and `action`
/// must point to writable memory large enough for `DroverAction`.
pub unsafe extern "C" fn drover_scheduler_step(
    scheduler: *mut c_void,
    action: *mut DroverAction,
) -> i32 {
    if action.is_null() {
        return ERR_NULL;
    }

    ptr::write(action, DroverAction::default());
    let Some(handle) = scheduler_handle(scheduler) else {
        return ERR_NULL;
    };
    let action = &mut *action;

    match handle.scheduler.step() {
        Ok(Step::Child(child)) => {
            action.role = ROLE_CHILD;
            action.ordinal = child.ordinal;
            action.pid = child.pid;
            action.child_fd = child.fd;
            fill_array(&mut action.task_id, &child.task_id);
        }
        Ok(Step::Result(result)) => {
            populate_result(action, &result);
            handle.last_result = serde_json::to_vec(&result).unwrap_or_default();
        }
        Ok(Step::Progress) => {
            action.role = ROLE_PROGRESS;
        }
        Ok(Step::Done) => {
            action.role = ROLE_DONE;
        }
        Err(error) => {
            action.role = ROLE_ERROR;
            action.status = 2;
            fill_array(&mut action.failure_kind, "native_engine_crash");
            fill_array(&mut action.message, &error);
        }
    }

    action.active_count = handle.scheduler.active_count() as u32;
    action.max_active = handle.scheduler.max_active() as u32;
    action.role
}

#[no_mangle]
/// Returns the byte length of the most recently emitted result.
///
/// # Safety
///
/// `scheduler` must be a live, non-concurrently-used pointer returned by
/// `drover_scheduler_new`.
pub unsafe extern "C" fn drover_scheduler_last_result_len(scheduler: *mut c_void) -> usize {
    scheduler_handle(scheduler)
        .map(|handle| handle.last_result.len())
        .unwrap_or(0)
}

#[no_mangle]
/// Copies the most recently emitted result and appends a NUL terminator.
///
/// # Safety
///
/// `scheduler` must be live and exclusively used. `destination` must be
/// writable for `capacity` bytes and must not overlap scheduler-owned memory.
pub unsafe extern "C" fn drover_scheduler_copy_last_result(
    scheduler: *mut c_void,
    destination: *mut c_char,
    capacity: usize,
) -> i32 {
    let Some(handle) = scheduler_handle(scheduler) else {
        return ERR_NULL;
    };

    if destination.is_null() || capacity <= handle.last_result.len() {
        return ERR_NULL;
    }

    ptr::copy_nonoverlapping(
        handle.last_result.as_ptr(),
        destination.cast(),
        handle.last_result.len(),
    );
    *destination.add(handle.last_result.len()) = 0;

    0
}

#[no_mangle]
/// Returns the high-water mark of concurrently registered children.
///
/// # Safety
///
/// `scheduler` must be a live, non-concurrently-used pointer returned by
/// `drover_scheduler_new`.
pub unsafe extern "C" fn drover_scheduler_max_active(scheduler: *mut c_void) -> u32 {
    scheduler_handle(scheduler)
        .map(|handle| handle.scheduler.max_active() as u32)
        .unwrap_or(0)
}

#[no_mangle]
/// Frees a scheduler and synchronously kills/reaps any registered children.
///
/// # Safety
///
/// `scheduler` must be null or a live pointer returned by
/// `drover_scheduler_new`; it must be passed at most once and have no aliases
/// in use.
pub unsafe extern "C" fn drover_scheduler_free(scheduler: *mut c_void) {
    if !scheduler.is_null() {
        drop(Box::from_raw(scheduler.cast::<SchedulerHandle>()));
    }
}

#[no_mangle]
/// Emits one validated, length-prefixed task frame to a child channel.
///
/// # Safety
///
/// `fd` must identify a writable Drover child channel. Every string pointer
/// must point to readable NUL-terminated UTF-8 for the duration of the call.
pub unsafe extern "C" fn drover_emit_frame(
    fd: c_int,
    run_id: *const c_char,
    task_id: *const c_char,
    sequence: u32,
    event_type: *const c_char,
    payload_json: *const c_char,
) -> i32 {
    let Ok(run_id) = c_string(run_id) else {
        return ERR_NULL;
    };
    let Ok(task_id) = c_string(task_id) else {
        return ERR_NULL;
    };
    let Ok(event_type) = c_string(event_type) else {
        return ERR_NULL;
    };
    let Ok(payload_json) = c_string(payload_json) else {
        return ERR_NULL;
    };
    let Ok(payload) = serde_json::from_str(&payload_json) else {
        return ERR_JSON;
    };
    let frame = match Frame::new(run_id, task_id, sequence, event_type, payload) {
        Ok(frame) => frame,
        Err(error) => return error.code(),
    };

    write_frame(fd, &frame)
        .map(|_| 0)
        .unwrap_or_else(|error| error.code())
}

#[no_mangle]
/// Validates a JSON frame against an expected v1 envelope and sequence.
///
/// # Safety
///
/// Every string pointer must point to readable NUL-terminated UTF-8 for the
/// duration of the call.
pub unsafe extern "C" fn drover_validate_frame_json(
    expected_run_id: *const c_char,
    expected_task_id: *const c_char,
    expected_sequence: u32,
    frame_json: *const c_char,
) -> i32 {
    let Ok(run_id) = c_string(expected_run_id) else {
        return ERR_NULL;
    };
    let Ok(task_id) = c_string(expected_task_id) else {
        return ERR_NULL;
    };
    let Ok(frame_json) = c_string(frame_json) else {
        return ERR_NULL;
    };

    validate_standalone(&frame_json, &run_id, &task_id, expected_sequence)
        .map(|_| 0)
        .unwrap_or_else(|error| error.code())
}

fn populate_result(action: &mut DroverAction, result: &TaskResult) {
    action.role = ROLE_RESULT;
    action.ordinal = result.ordinal;
    action.pid = result.pid.unwrap_or(-1);
    action.status = if result.status == "passed" { 1 } else { 2 };
    action.timed_out = i32::from(result.timed_out);
    action.exit_code = result.exit_code.unwrap_or(-1);
    action.term_signal = result.signal.unwrap_or(0);
    action.frame_count = result.frames.len() as u32;
    fill_array(&mut action.task_id, &result.task_id);

    if let Some(kind) = result.failure_kind.as_deref() {
        fill_array(&mut action.failure_kind, kind);
    }

    if let Some(message) = result.message.as_deref() {
        fill_array(&mut action.message, message);
    }
}

unsafe fn scheduler_handle<'a>(scheduler: *mut c_void) -> Option<&'a mut SchedulerHandle> {
    scheduler.cast::<SchedulerHandle>().as_mut()
}

unsafe fn c_string(pointer: *const c_char) -> Result<String, ()> {
    if pointer.is_null() {
        return Err(());
    }

    CStr::from_ptr(pointer)
        .to_str()
        .map(str::to_owned)
        .map_err(|_| ())
}

fn fill_array<const N: usize>(target: &mut [c_char; N], value: &str) {
    let bytes = value.as_bytes();
    let length = bytes.len().min(N.saturating_sub(1));

    for (target, source) in target.iter_mut().zip(bytes.iter()).take(length) {
        *target = *source as c_char;
    }

    target[length] = 0;
}
