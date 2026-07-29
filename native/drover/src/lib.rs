mod protocol;
mod scheduler;

use crate::protocol::{
    validate_standalone, write_frame, Frame, ERR_JSON, ERR_NULL, MAX_FRAME_BYTES, PROTOCOL_VERSION,
};
use crate::scheduler::{Engine, Scheduler, Step, TaskResult};
use libc::{c_char, c_int, c_void};
use std::collections::HashMap;
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
    pub task_id: [c_char; 512],
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
            task_id: [0; 512],
            failure_kind: [0; 64],
            message: [0; 256],
        }
    }
}

struct MapHandle {
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
/// Creates the shared native permit engine inherited by every scope host.
///
/// # Safety
///
/// Both string pointers must be readable, NUL-terminated UTF-8. The scope
/// limits string must contain a JSON object whose values are positive integers.
pub unsafe extern "C" fn drover_engine_new(
    run_id: *const c_char,
    concurrency: u32,
    scope_limits_json: *const c_char,
    term_grace_ms: u64,
) -> *mut c_void {
    let Ok(run_id) = c_string(run_id) else {
        return ptr::null_mut();
    };
    let Ok(scope_limits_json) = c_string(scope_limits_json) else {
        return ptr::null_mut();
    };
    let Ok(scope_limits) = serde_json::from_str::<HashMap<String, usize>>(&scope_limits_json)
    else {
        return ptr::null_mut();
    };
    let Ok(engine) = Engine::new(
        run_id,
        concurrency as usize,
        scope_limits,
        Duration::from_millis(term_grace_ms),
    ) else {
        return ptr::null_mut();
    };

    Box::into_raw(Box::new(engine)).cast()
}

#[no_mangle]
/// Acquires the shared global and configured scope permits for host work.
///
/// # Safety
///
/// `engine` must be live and exclusively used. `scopes_json` must point to a
/// readable NUL-terminated JSON list of scope IDs.
pub unsafe extern "C" fn drover_engine_acquire(
    engine: *mut c_void,
    scopes_json: *const c_char,
) -> i32 {
    let Some(engine) = engine_handle(engine) else {
        return ERR_NULL;
    };
    let Ok(scopes) = json_string_list(scopes_json) else {
        return ERR_JSON;
    };

    engine.acquire(&scopes).map(|_| 0).unwrap_or(ROLE_ERROR)
}

#[no_mangle]
/// Releases one matching set of shared host-work permits.
///
/// # Safety
///
/// `engine` must be live and exclusively used. `scopes_json` must point to the
/// same readable JSON list previously passed to `drover_engine_acquire`.
pub unsafe extern "C" fn drover_engine_release(
    engine: *mut c_void,
    scopes_json: *const c_char,
) -> i32 {
    let Some(engine) = engine_handle(engine) else {
        return ERR_NULL;
    };
    let Ok(scopes) = json_string_list(scopes_json) else {
        return ERR_JSON;
    };

    engine.release(&scopes).map(|_| 0).unwrap_or(ROLE_ERROR)
}

#[no_mangle]
/// Releases every host-work permit held by this process-local engine copy.
///
/// # Safety
///
/// `engine` must be a live, exclusively used pointer returned by
/// `drover_engine_new`.
pub unsafe extern "C" fn drover_engine_release_all(engine: *mut c_void) -> i32 {
    let Some(engine) = engine_handle(engine) else {
        return ERR_NULL;
    };

    engine.release_all().map(|_| 0).unwrap_or(ROLE_ERROR)
}

#[no_mangle]
/// Frees the process-local engine copy and closes its permit descriptors.
///
/// # Safety
///
/// `engine` must be null or a live pointer returned by `drover_engine_new`. It
/// must be passed at most once and no map may outlive it.
pub unsafe extern "C" fn drover_engine_free(engine: *mut c_void) {
    if !engine.is_null() {
        drop(Box::from_raw(engine.cast::<Engine>()));
    }
}

#[no_mangle]
/// Creates a fresh native queue for one `Scheduler::map` call.
///
/// # Safety
///
/// `engine` must be live and exclusively used for the duration of this call.
/// It must remain alive until the returned map is freed.
pub unsafe extern "C" fn drover_map_new(engine: *mut c_void, queue_capacity: u32) -> *mut c_void {
    let Some(engine) = engine_handle(engine) else {
        return ptr::null_mut();
    };
    let Ok(scheduler) = engine.scheduler(queue_capacity as usize) else {
        return ptr::null_mut();
    };

    Box::into_raw(Box::new(MapHandle {
        scheduler,
        last_result: Vec::new(),
    }))
    .cast()
}

#[no_mangle]
/// Adds a fully normalized task descriptor to a native map queue.
///
/// # Safety
///
/// `map` must be live and exclusively used. Every string pointer must be
/// readable NUL-terminated UTF-8; `scopes_json` must contain a JSON list.
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn drover_map_submit(
    map: *mut c_void,
    task_id: *const c_char,
    task_kind: *const c_char,
    scope_id: *const c_char,
    scopes_json: *const c_char,
    timeout_ms: u64,
    permit: c_int,
) -> i32 {
    let Some(handle) = map_handle(map) else {
        return ERR_NULL;
    };
    let Ok(task_id) = c_string(task_id) else {
        return ERR_NULL;
    };
    let Ok(task_kind) = c_string(task_kind) else {
        return ERR_NULL;
    };
    let Ok(scope_id) = c_string(scope_id) else {
        return ERR_NULL;
    };
    let Ok(scopes) = json_string_list(scopes_json) else {
        return ERR_JSON;
    };

    if !matches!(permit, 0 | 1) {
        return ROLE_ERROR;
    }

    match handle.scheduler.submit(
        task_id,
        task_kind,
        scope_id,
        scopes,
        timeout_ms,
        permit == 1,
    ) {
        Ok(ordinal) => ordinal as i32,
        Err(_) => ROLE_ERROR,
    }
}

#[no_mangle]
/// Advances one native map queue by one scheduling action.
///
/// # Safety
///
/// `map` must be live and exclusively used. `action` must point to writable
/// memory large enough for `DroverAction`.
pub unsafe extern "C" fn drover_map_step(map: *mut c_void, action: *mut DroverAction) -> i32 {
    if action.is_null() {
        return ERR_NULL;
    }

    ptr::write(action, DroverAction::default());
    let Some(handle) = map_handle(map) else {
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
/// Interrupts pending and active work in a native map.
///
/// # Safety
///
/// `map` must be a live, exclusively used pointer returned by `drover_map_new`.
pub unsafe extern "C" fn drover_map_interrupt(map: *mut c_void, signal: c_int) -> i32 {
    let Some(handle) = map_handle(map) else {
        return ERR_NULL;
    };

    handle
        .scheduler
        .interrupt(signal)
        .map(|_| 0)
        .unwrap_or(ROLE_ERROR)
}

#[no_mangle]
/// Immediately kills/reaps active descendants and releases their permits.
///
/// # Safety
///
/// `map` must be a live, exclusively used pointer returned by `drover_map_new`.
pub unsafe extern "C" fn drover_map_cancel(map: *mut c_void) {
    if let Some(handle) = map_handle(map) {
        handle.scheduler.cancel();
    }
}

#[no_mangle]
/// Returns the byte length of the most recently emitted map result.
///
/// # Safety
///
/// `map` must be a live, non-concurrently-used pointer returned by
/// `drover_map_new`.
pub unsafe extern "C" fn drover_map_last_result_len(map: *mut c_void) -> usize {
    map_handle(map)
        .map(|handle| handle.last_result.len())
        .unwrap_or(0)
}

#[no_mangle]
/// Copies the most recently emitted result and appends a NUL terminator.
///
/// # Safety
///
/// `map` must be live and exclusively used. `destination` must be writable for
/// `capacity` bytes and must not overlap map-owned memory.
pub unsafe extern "C" fn drover_map_copy_last_result(
    map: *mut c_void,
    destination: *mut c_char,
    capacity: usize,
) -> i32 {
    let Some(handle) = map_handle(map) else {
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
/// Returns the process-local active-child high-water mark for this map.
///
/// # Safety
///
/// `map` must be a live, non-concurrently-used pointer returned by
/// `drover_map_new`.
pub unsafe extern "C" fn drover_map_max_active(map: *mut c_void) -> u32 {
    map_handle(map)
        .map(|handle| handle.scheduler.max_active() as u32)
        .unwrap_or(0)
}

#[no_mangle]
/// Frees a native map, killing/reaping active children and releasing permits.
///
/// # Safety
///
/// `map` must be null or a live pointer returned by `drover_map_new`. It must
/// be passed at most once.
pub unsafe extern "C" fn drover_map_free(map: *mut c_void) {
    if !map.is_null() {
        drop(Box::from_raw(map.cast::<MapHandle>()));
    }
}

#[no_mangle]
pub extern "C" fn drover_child_exit(status: c_int) -> ! {
    unsafe {
        libc::_exit(status);
    }
}

#[no_mangle]
/// Emits one canonical ChildProtocol v1 frame to a child channel.
///
/// # Safety
///
/// `fd` must identify a writable Drover child channel. Every string pointer
/// must point to readable NUL-terminated UTF-8 for the duration of the call.
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn drover_emit_frame(
    fd: c_int,
    run_id: *const c_char,
    task_id: *const c_char,
    task_kind: *const c_char,
    scope_id: *const c_char,
    ordinal: u32,
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
    let Ok(task_kind) = c_string(task_kind) else {
        return ERR_NULL;
    };
    let Ok(scope_id) = c_string(scope_id) else {
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
    let frame = match Frame::new(
        run_id, task_id, task_kind, scope_id, ordinal, sequence, event_type, payload,
    ) {
        Ok(frame) => frame,
        Err(error) => return error.code(),
    };

    write_frame(fd, &frame)
        .map(|_| 0)
        .unwrap_or_else(|error| error.code())
}

#[no_mangle]
/// Validates one canonical ChildProtocol v1 JSON frame.
///
/// # Safety
///
/// Every string pointer must point to readable NUL-terminated UTF-8 for the
/// duration of the call.
#[allow(clippy::too_many_arguments)]
pub unsafe extern "C" fn drover_validate_frame_json(
    expected_run_id: *const c_char,
    expected_task_id: *const c_char,
    expected_task_kind: *const c_char,
    expected_scope_id: *const c_char,
    expected_ordinal: u32,
    expected_sequence: u32,
    frame_json: *const c_char,
) -> i32 {
    let Ok(run_id) = c_string(expected_run_id) else {
        return ERR_NULL;
    };
    let Ok(task_id) = c_string(expected_task_id) else {
        return ERR_NULL;
    };
    let Ok(task_kind) = c_string(expected_task_kind) else {
        return ERR_NULL;
    };
    let Ok(scope_id) = c_string(expected_scope_id) else {
        return ERR_NULL;
    };
    let Ok(frame_json) = c_string(frame_json) else {
        return ERR_NULL;
    };

    validate_standalone(
        &frame_json,
        &run_id,
        &task_id,
        &task_kind,
        &scope_id,
        expected_ordinal,
        expected_sequence,
    )
    .map(|_| 0)
    .unwrap_or_else(|error| error.code())
}

fn populate_result(action: &mut DroverAction, result: &TaskResult) {
    action.role = ROLE_RESULT;
    action.ordinal = result.ordinal;
    action.pid = result.telemetry.pid.unwrap_or(-1);
    action.status = if result.status == "passed" { 1 } else { 2 };
    action.timed_out = i32::from(
        result
            .failure
            .as_ref()
            .and_then(|failure| failure["kind"].as_str())
            == Some("timeout"),
    );
    action.exit_code = result.telemetry.exit_code.unwrap_or(-1);
    action.term_signal = result.telemetry.signal.unwrap_or(0);
    action.frame_count = result.events.len() as u32;
    fill_array(&mut action.task_id, &result.id);

    if let Some(failure) = result.failure.as_ref() {
        if let Some(kind) = failure["kind"].as_str() {
            fill_array(&mut action.failure_kind, kind);
        }

        if let Some(message) = failure["message"].as_str() {
            fill_array(&mut action.message, message);
        }
    }
}

unsafe fn engine_handle<'a>(engine: *mut c_void) -> Option<&'a mut Engine> {
    engine.cast::<Engine>().as_mut()
}

unsafe fn map_handle<'a>(map: *mut c_void) -> Option<&'a mut MapHandle> {
    map.cast::<MapHandle>().as_mut()
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

unsafe fn json_string_list(pointer: *const c_char) -> Result<Vec<String>, ()> {
    let json = c_string(pointer)?;

    serde_json::from_str(&json).map_err(|_| ())
}

fn fill_array<const N: usize>(target: &mut [c_char; N], value: &str) {
    let bytes = value.as_bytes();
    let length = bytes.len().min(N.saturating_sub(1));

    for (target, source) in target.iter_mut().zip(bytes.iter()).take(length) {
        *target = *source as c_char;
    }

    target[length] = 0;
}
