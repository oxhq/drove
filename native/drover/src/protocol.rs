use base64::engine::general_purpose::STANDARD;
use base64::Engine;
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::fmt::{Display, Formatter};
use std::io;

pub const PROTOCOL_VERSION: u32 = 1;
pub const MAX_FRAME_BYTES: usize = 1_048_576;
pub const MAX_BUFFER_BYTES: usize = MAX_FRAME_BYTES * 2;
pub const MAX_STREAM_BYTES: usize = 67_108_864;

pub const ERR_NULL: i32 = -1;
pub const ERR_JSON: i32 = -2;
pub const ERR_ENVELOPE: i32 = -3;
pub const ERR_VERSION: i32 = -4;
pub const ERR_SEQUENCE: i32 = -5;
pub const ERR_TYPE: i32 = -6;
pub const ERR_PAYLOAD: i32 = -7;
pub const ERR_FRAME_SIZE: i32 = -8;
pub const ERR_IO: i32 = -9;

#[derive(Clone, Debug, Deserialize, PartialEq, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Frame {
    pub protocol_version: u32,
    pub run_id: String,
    pub task_id: String,
    pub task_kind: String,
    pub scope_id: String,
    pub ordinal: u32,
    pub sequence: u32,
    #[serde(rename = "type")]
    pub event_type: String,
    pub payload: Value,
}

impl Frame {
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        run_id: String,
        task_id: String,
        task_kind: String,
        scope_id: String,
        ordinal: u32,
        sequence: u32,
        event_type: String,
        payload: Value,
    ) -> Result<Self, ProtocolError> {
        if !payload.is_object() {
            return Err(ProtocolError::Payload(
                "Drove frame payloads must be JSON objects.".into(),
            ));
        }

        Ok(Self {
            protocol_version: PROTOCOL_VERSION,
            run_id,
            task_id,
            task_kind,
            scope_id,
            ordinal,
            sequence,
            event_type,
            payload,
        })
    }
}

#[derive(Debug, Clone, PartialEq)]
pub enum ProtocolError {
    Json(String),
    Envelope(String),
    Version(u32),
    Sequence { expected: u32, actual: u32 },
    Type(String),
    Payload(String),
    FrameSize(usize),
    Io(String),
}

impl ProtocolError {
    pub fn code(&self) -> i32 {
        match self {
            Self::Json(_) => ERR_JSON,
            Self::Envelope(_) => ERR_ENVELOPE,
            Self::Version(_) => ERR_VERSION,
            Self::Sequence { .. } => ERR_SEQUENCE,
            Self::Type(_) => ERR_TYPE,
            Self::Payload(_) => ERR_PAYLOAD,
            Self::FrameSize(_) => ERR_FRAME_SIZE,
            Self::Io(_) => ERR_IO,
        }
    }
}

impl Display for ProtocolError {
    fn fmt(&self, formatter: &mut Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Json(message)
            | Self::Envelope(message)
            | Self::Type(message)
            | Self::Payload(message)
            | Self::Io(message) => formatter.write_str(message),
            Self::Version(version) => {
                write!(
                    formatter,
                    "Unsupported Drove child protocol version {version}."
                )
            }
            Self::Sequence { expected, actual } => {
                write!(
                    formatter,
                    "Drove expected frame sequence {expected}, received {actual}."
                )
            }
            Self::FrameSize(size) => {
                write!(
                    formatter,
                    "Drove frame size {size} is outside the v1 limit."
                )
            }
        }
    }
}

#[derive(Debug)]
pub struct Validator {
    run_id: String,
    task_id: String,
    task_kind: String,
    scope_id: String,
    ordinal: u32,
    next_sequence: u32,
    finished: bool,
}

impl Validator {
    pub fn new(
        run_id: String,
        task_id: String,
        task_kind: String,
        scope_id: String,
        ordinal: u32,
    ) -> Self {
        Self {
            run_id,
            task_id,
            task_kind,
            scope_id,
            ordinal,
            next_sequence: 0,
            finished: false,
        }
    }

    pub fn accept(&mut self, frame: &Frame) -> Result<(), ProtocolError> {
        validate_envelope(
            frame,
            &self.run_id,
            &self.task_id,
            &self.task_kind,
            &self.scope_id,
            self.ordinal,
            self.next_sequence,
        )?;

        if self.finished {
            return Err(ProtocolError::Type(
                "Drove received a frame after task.finished.".into(),
            ));
        }

        if self.next_sequence == 0 {
            if frame.event_type != "task.started" {
                return Err(ProtocolError::Type(
                    "The first Drove frame must be task.started.".into(),
                ));
            }

            validate_started_payload(&frame.payload)?;
        } else {
            match frame.event_type.as_str() {
                "task.stdout" | "task.stderr" | "task.value" => {
                    validate_data_payload(&frame.payload)?;
                }
                "task.finished" => {
                    validate_terminal_payload(&frame.payload)?;
                    self.finished = true;
                }
                event_type => {
                    return Err(ProtocolError::Type(format!(
                        "Unsupported Drove child event type {event_type}."
                    )));
                }
            }
        }

        self.next_sequence = self
            .next_sequence
            .checked_add(1)
            .ok_or(ProtocolError::Sequence {
                expected: self.next_sequence,
                actual: frame.sequence,
            })?;

        Ok(())
    }
}

pub fn known_failure_kind(kind: &str) -> bool {
    matches!(
        kind,
        "assertion_failure"
            | "php_exception"
            | "php_fatal_error"
            | "setup_failure"
            | "teardown_failure"
            | "timeout"
            | "signal_termination"
            | "out_of_memory"
            | "state_adapter_failure"
            | "fork_failure"
            | "child_protocol_failure"
            | "native_engine_crash"
            | "blocked_descendant"
            | "user_interruption"
    )
}

#[allow(clippy::too_many_arguments)]
pub fn validate_envelope(
    frame: &Frame,
    run_id: &str,
    task_id: &str,
    task_kind: &str,
    scope_id: &str,
    ordinal: u32,
    expected_sequence: u32,
) -> Result<(), ProtocolError> {
    if frame.run_id != run_id
        || frame.task_id != task_id
        || frame.task_kind != task_kind
        || frame.scope_id != scope_id
        || frame.ordinal != ordinal
    {
        return Err(ProtocolError::Envelope(
            "Drove received an inconsistent child frame envelope.".into(),
        ));
    }

    if frame.protocol_version != PROTOCOL_VERSION {
        return Err(ProtocolError::Version(frame.protocol_version));
    }

    if frame.sequence != expected_sequence {
        return Err(ProtocolError::Sequence {
            expected: expected_sequence,
            actual: frame.sequence,
        });
    }

    if !frame.payload.is_object() {
        return Err(ProtocolError::Payload(
            "Drove frame payloads must be JSON objects.".into(),
        ));
    }

    Ok(())
}

#[allow(clippy::too_many_arguments)]
pub fn validate_standalone(
    json: &str,
    run_id: &str,
    task_id: &str,
    task_kind: &str,
    scope_id: &str,
    ordinal: u32,
    expected_sequence: u32,
) -> Result<(), ProtocolError> {
    let frame: Frame =
        serde_json::from_str(json).map_err(|error| ProtocolError::Json(error.to_string()))?;
    validate_envelope(
        &frame,
        run_id,
        task_id,
        task_kind,
        scope_id,
        ordinal,
        expected_sequence,
    )?;

    match frame.event_type.as_str() {
        "task.started" if expected_sequence == 0 => validate_started_payload(&frame.payload),
        "task.stdout" | "task.stderr" | "task.value" if expected_sequence > 0 => {
            validate_data_payload(&frame.payload)
        }
        "task.finished" if expected_sequence > 0 => validate_terminal_payload(&frame.payload),
        _ => Err(ProtocolError::Type(
            "Drove received an event in an invalid protocol position.".into(),
        )),
    }
}

pub fn validate_started_payload(payload: &Value) -> Result<(), ProtocolError> {
    if payload.get("started_ns").and_then(Value::as_u64).is_none() {
        return Err(ProtocolError::Payload(
            "A Drove start frame requires started_ns.".into(),
        ));
    }

    Ok(())
}

pub fn validate_data_payload(payload: &Value) -> Result<(), ProtocolError> {
    if payload.get("encoding").and_then(Value::as_str) != Some("base64") {
        return Err(ProtocolError::Payload(
            "A Drove data frame requires base64 encoding.".into(),
        ));
    }

    let data = payload
        .get("data")
        .and_then(Value::as_str)
        .ok_or_else(|| ProtocolError::Payload("A Drove data frame requires data.".into()))?;

    STANDARD
        .decode(data)
        .map(|_| ())
        .map_err(|_| ProtocolError::Payload("Drove received invalid base64 child data.".into()))
}

pub fn validate_terminal_payload(payload: &Value) -> Result<(), ProtocolError> {
    let status = payload
        .get("status")
        .and_then(Value::as_str)
        .ok_or_else(|| {
            ProtocolError::Payload("A terminal Drove frame requires a status.".into())
        })?;
    let failure = payload.get("failure").unwrap_or(&Value::Null);

    if !matches!(status, "passed" | "failed") {
        return Err(ProtocolError::Payload(format!(
            "Unsupported Drove terminal status {status}."
        )));
    }

    if payload.get("finished_ns").and_then(Value::as_u64).is_none() {
        return Err(ProtocolError::Payload(
            "A terminal Drove frame requires finished_ns.".into(),
        ));
    }

    if payload
        .get("memory_peak_bytes")
        .is_some_and(|value| !value.is_null() && value.as_u64().is_none())
    {
        return Err(ProtocolError::Payload(
            "A terminal Drove frame has invalid memory telemetry.".into(),
        ));
    }

    if status == "passed" && !failure.is_null() {
        return Err(ProtocolError::Payload(
            "A passing Drove frame cannot contain a failure.".into(),
        ));
    }

    if status == "failed" && !valid_failure(failure) {
        return Err(ProtocolError::Payload(
            "A failed Drove frame requires a classified failure.".into(),
        ));
    }

    Ok(())
}

fn valid_failure(failure: &Value) -> bool {
    let Some(failure) = failure.as_object() else {
        return false;
    };

    failure
        .get("kind")
        .and_then(Value::as_str)
        .is_some_and(known_failure_kind)
        && failure.get("message").and_then(Value::as_str).is_some()
        && failure.get("phase").and_then(Value::as_str).is_some()
        && failure.contains_key("hook_id")
}

pub fn encode_frame(frame: &Frame) -> Result<Vec<u8>, ProtocolError> {
    let json = serde_json::to_vec(frame).map_err(|error| ProtocolError::Json(error.to_string()))?;

    if json.len() < 2 || json.len() > MAX_FRAME_BYTES {
        return Err(ProtocolError::FrameSize(json.len()));
    }

    let mut encoded = Vec::with_capacity(4 + json.len());
    encoded.extend_from_slice(&(json.len() as u32).to_be_bytes());
    encoded.extend_from_slice(&json);

    Ok(encoded)
}

pub fn decode_available(
    buffer: &mut Vec<u8>,
    validator: &mut Validator,
) -> Result<Vec<Frame>, ProtocolError> {
    let mut frames = Vec::new();

    loop {
        if buffer.len() < 4 {
            break;
        }

        let length =
            u32::from_be_bytes(buffer[0..4].try_into().expect("four byte header")) as usize;

        if !(2..=MAX_FRAME_BYTES).contains(&length) {
            return Err(ProtocolError::FrameSize(length));
        }

        if buffer.len() < 4 + length {
            break;
        }

        let frame: Frame = serde_json::from_slice(&buffer[4..4 + length])
            .map_err(|error| ProtocolError::Json(error.to_string()))?;
        validator.accept(&frame)?;
        buffer.drain(..4 + length);
        frames.push(frame);
    }

    Ok(frames)
}

pub fn write_frame(fd: libc::c_int, frame: &Frame) -> Result<(), ProtocolError> {
    let encoded = encode_frame(frame)?;
    let mut written = 0;

    while written < encoded.len() {
        let result = unsafe {
            libc::write(
                fd,
                encoded[written..].as_ptr().cast(),
                encoded.len() - written,
            )
        };

        if result > 0 {
            written += result as usize;
            continue;
        }

        let error = io::Error::last_os_error();

        if error.kind() == io::ErrorKind::Interrupted {
            continue;
        }

        return Err(ProtocolError::Io(error.to_string()));
    }

    Ok(())
}

pub fn monotonic_ns() -> Result<u64, ProtocolError> {
    let mut time = libc::timespec {
        tv_sec: 0,
        tv_nsec: 0,
    };

    if unsafe { libc::clock_gettime(libc::CLOCK_MONOTONIC, &mut time) } != 0 {
        return Err(ProtocolError::Io(io::Error::last_os_error().to_string()));
    }

    Ok((time.tv_sec as u64)
        .saturating_mul(1_000_000_000)
        .saturating_add(time.tv_nsec as u64))
}

#[cfg(test)]
mod tests {
    use super::*;

    const STARTED: &str = include_str!("../protocol/v1/started.json");
    const STDOUT: &str = include_str!("../protocol/v1/event.json");
    const VALUE: &str = include_str!("../protocol/v1/value.json");
    const FINISHED: &str = include_str!("../protocol/v1/finished.json");
    const INVALID_VERSION: &str = include_str!("../protocol/v1/invalid-version.json");

    fn validator() -> Validator {
        Validator::new(
            "golden-run".into(),
            "task:golden".into(),
            "test".into(),
            "scope:golden".into(),
            7,
        )
    }

    #[test]
    fn golden_frames_are_exact_and_form_a_valid_sequence() {
        let mut validator = validator();

        for json in [STARTED, STDOUT, VALUE, FINISHED] {
            let frame: Frame = serde_json::from_str(json.trim()).unwrap();
            assert_eq!(serde_json::to_string(&frame).unwrap(), json.trim());
            validator.accept(&frame).unwrap();
        }

        assert!(validator.finished);
    }

    #[test]
    fn rejects_an_unknown_protocol_version() {
        let error = validate_standalone(
            INVALID_VERSION,
            "golden-run",
            "task:golden",
            "test",
            "scope:golden",
            7,
            3,
        )
        .unwrap_err();

        assert_eq!(error, ProtocolError::Version(2));
        assert_eq!(error.code(), ERR_VERSION);
    }

    #[test]
    fn decodes_a_frame_across_partial_reads() {
        let frame: Frame = serde_json::from_str(STARTED.trim()).unwrap();
        let encoded = encode_frame(&frame).unwrap();
        let mut buffer = encoded[..3].to_vec();
        let mut validator = validator();

        assert!(decode_available(&mut buffer, &mut validator)
            .unwrap()
            .is_empty());

        buffer.extend_from_slice(&encoded[3..]);
        assert_eq!(
            decode_available(&mut buffer, &mut validator).unwrap(),
            vec![frame]
        );
        assert!(buffer.is_empty());
    }

    #[test]
    fn rejects_an_oversized_frame_from_its_header() {
        let mut buffer = ((MAX_FRAME_BYTES + 1) as u32).to_be_bytes().to_vec();
        let mut validator = validator();

        assert_eq!(
            decode_available(&mut buffer, &mut validator).unwrap_err(),
            ProtocolError::FrameSize(MAX_FRAME_BYTES + 1)
        );
    }

    #[test]
    fn rejects_frames_after_the_terminal_result() {
        let mut validator = validator();

        for json in [STARTED, STDOUT, VALUE, FINISHED] {
            let frame: Frame = serde_json::from_str(json.trim()).unwrap();
            validator.accept(&frame).unwrap();
        }

        let mut late: Frame = serde_json::from_str(STDOUT.trim()).unwrap();
        late.sequence = 4;

        assert_eq!(
            validator.accept(&late).unwrap_err(),
            ProtocolError::Type("Drove received a frame after task.finished.".into())
        );
    }
}
