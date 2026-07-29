use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::fmt::{Display, Formatter};
use std::io;

pub const PROTOCOL_NAME: &str = "drover.task";
pub const PROTOCOL_VERSION: u32 = 1;
pub const MAX_FRAME_BYTES: usize = 1_048_576;
pub const MAX_BUFFER_BYTES: usize = MAX_FRAME_BYTES * 2;

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
    pub protocol: String,
    pub version: u32,
    pub run_id: String,
    pub task_id: String,
    pub sequence: u32,
    #[serde(rename = "type")]
    pub event_type: String,
    pub monotonic_ns: u64,
    pub payload: Value,
}

impl Frame {
    pub fn new(
        run_id: String,
        task_id: String,
        sequence: u32,
        event_type: String,
        payload: Value,
    ) -> Result<Self, ProtocolError> {
        if !payload.is_object() {
            return Err(ProtocolError::Payload(
                "Drover frame payloads must be JSON objects.".into(),
            ));
        }

        Ok(Self {
            protocol: PROTOCOL_NAME.into(),
            version: PROTOCOL_VERSION,
            run_id,
            task_id,
            sequence,
            event_type,
            monotonic_ns: monotonic_ns()?,
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
                write!(formatter, "Unsupported Drover protocol version {version}.")
            }
            Self::Sequence { expected, actual } => {
                write!(
                    formatter,
                    "Drover expected frame sequence {expected}, received {actual}."
                )
            }
            Self::FrameSize(size) => {
                write!(
                    formatter,
                    "Drover frame size {size} is outside the v1 limit."
                )
            }
        }
    }
}

#[derive(Debug)]
pub struct Validator {
    run_id: String,
    task_id: String,
    next_sequence: u32,
    finished: bool,
}

impl Validator {
    pub fn new(run_id: String, task_id: String) -> Self {
        Self {
            run_id,
            task_id,
            next_sequence: 0,
            finished: false,
        }
    }

    pub fn accept(&mut self, frame: &Frame) -> Result<(), ProtocolError> {
        validate_envelope(frame, &self.run_id, &self.task_id, self.next_sequence)?;

        if self.finished {
            return Err(ProtocolError::Type(
                "Drover received a frame after task.finished.".into(),
            ));
        }

        if self.next_sequence == 0 {
            if frame.event_type != "task.started" {
                return Err(ProtocolError::Type(
                    "The first Drover frame must be task.started.".into(),
                ));
            }
        } else {
            match frame.event_type.as_str() {
                "task.event" | "task.output" => {}
                "task.finished" => {
                    validate_terminal_payload(&frame.payload)?;
                    self.finished = true;
                }
                event_type => {
                    return Err(ProtocolError::Type(format!(
                        "Unsupported Drover event type {event_type}."
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

pub fn validate_envelope(
    frame: &Frame,
    run_id: &str,
    task_id: &str,
    expected_sequence: u32,
) -> Result<(), ProtocolError> {
    if frame.protocol != PROTOCOL_NAME || frame.run_id != run_id || frame.task_id != task_id {
        return Err(ProtocolError::Envelope(
            "Drover received an inconsistent frame envelope.".into(),
        ));
    }

    if frame.version != PROTOCOL_VERSION {
        return Err(ProtocolError::Version(frame.version));
    }

    if frame.sequence != expected_sequence {
        return Err(ProtocolError::Sequence {
            expected: expected_sequence,
            actual: frame.sequence,
        });
    }

    if !frame.payload.is_object() {
        return Err(ProtocolError::Payload(
            "Drover frame payloads must be JSON objects.".into(),
        ));
    }

    Ok(())
}

pub fn validate_standalone(
    json: &str,
    run_id: &str,
    task_id: &str,
    expected_sequence: u32,
) -> Result<(), ProtocolError> {
    let frame: Frame =
        serde_json::from_str(json).map_err(|error| ProtocolError::Json(error.to_string()))?;
    validate_envelope(&frame, run_id, task_id, expected_sequence)?;

    match frame.event_type.as_str() {
        "task.started" if expected_sequence == 0 => Ok(()),
        "task.event" | "task.output" if expected_sequence > 0 => Ok(()),
        "task.finished" if expected_sequence > 0 => validate_terminal_payload(&frame.payload),
        _ => Err(ProtocolError::Type(
            "Drover received an event in an invalid protocol position.".into(),
        )),
    }
}

pub fn validate_terminal_payload(payload: &Value) -> Result<(), ProtocolError> {
    let status = payload
        .get("status")
        .and_then(Value::as_str)
        .ok_or_else(|| {
            ProtocolError::Payload("A terminal Drover frame requires a status.".into())
        })?;

    if !matches!(status, "passed" | "failed") {
        return Err(ProtocolError::Payload(format!(
            "Unsupported Drover terminal status {status}."
        )));
    }

    if status == "failed" {
        let kind = payload
            .get("failure_kind")
            .and_then(Value::as_str)
            .ok_or_else(|| {
                ProtocolError::Payload("A failed Drover frame requires a failure_kind.".into())
            })?;

        if !known_failure_kind(kind) {
            return Err(ProtocolError::Payload(format!(
                "Unknown Drover failure kind {kind}."
            )));
        }
    }

    Ok(())
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
    const EVENT: &str = include_str!("../protocol/v1/event.json");
    const FINISHED: &str = include_str!("../protocol/v1/finished.json");
    const INVALID_VERSION: &str = include_str!("../protocol/v1/invalid-version.json");

    #[test]
    fn golden_frames_are_exact_and_form_a_valid_sequence() {
        let mut validator = Validator::new("golden-run".into(), "task:golden".into());

        for json in [STARTED, EVENT, FINISHED] {
            let frame: Frame = serde_json::from_str(json.trim()).unwrap();
            assert_eq!(serde_json::to_string(&frame).unwrap(), json.trim());
            validator.accept(&frame).unwrap();
        }

        assert!(validator.finished);
    }

    #[test]
    fn rejects_an_unknown_protocol_version() {
        let error =
            validate_standalone(INVALID_VERSION, "golden-run", "task:golden", 2).unwrap_err();

        assert_eq!(error, ProtocolError::Version(2));
        assert_eq!(error.code(), ERR_VERSION);
    }

    #[test]
    fn decodes_a_frame_across_partial_reads() {
        let frame: Frame = serde_json::from_str(STARTED.trim()).unwrap();
        let encoded = encode_frame(&frame).unwrap();
        let mut buffer = encoded[..3].to_vec();
        let mut validator = Validator::new("golden-run".into(), "task:golden".into());

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
        let mut validator = Validator::new("golden-run".into(), "task:golden".into());

        assert_eq!(
            decode_available(&mut buffer, &mut validator).unwrap_err(),
            ProtocolError::FrameSize(MAX_FRAME_BYTES + 1)
        );
    }

    #[test]
    fn rejects_frames_after_the_terminal_result() {
        let mut validator = Validator::new("golden-run".into(), "task:golden".into());

        for json in [STARTED, EVENT, FINISHED] {
            let frame: Frame = serde_json::from_str(json.trim()).unwrap();
            validator.accept(&frame).unwrap();
        }

        let mut late: Frame = serde_json::from_str(EVENT.trim()).unwrap();
        late.sequence = 3;

        assert_eq!(
            validator.accept(&late).unwrap_err(),
            ProtocolError::Type("Drover received a frame after task.finished.".into())
        );
    }
}
