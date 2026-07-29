# Drover Phase 1 native vertical slice

This directory is a source-built Linux proof of the native execution boundary.
It is deliberately not a prebuilt extension distribution.

Rust owns:

- the pending task queue;
- an explicit caller-selected pending queue capacity;
- reservation of a global concurrency slot before `fork()`;
- `fork()` and process-group creation;
- nonblocking parent transport;
- monotonic task deadlines;
- `SIGTERM` to `SIGKILL` descendant-group escalation;
- `waitpid()` and result aggregation;
- versioned, length-prefixed structured frames.

PHP retains prepared application memory and callback execution. A native
`drover_scheduler_step()` call returns `DROVER_ROLE_CHILD` in the forked PHP
process. The PHP child executes its inherited closure and calls
`drover_emit_frame()` for structured events and its terminal result.

## Proof

From the repository root:

```bash
docker build --file native/drover/Dockerfile --tag drove-native-phase-one .
docker run --rm drove-native-phase-one
```

The Docker build runs the Rust protocol and scheduler tests plus warning-clean
Clippy before compiling the release library. The PHP proof then verifies:

- shared PHP/Rust v1 golden vectors and rejection of v2;
- five queued PHP callbacks with native concurrency two;
- rejection of pending work beyond a configured queue capacity in Rust tests;
- no fork beyond the two reserved active slots;
- prepared-memory inheritance and sibling mutation isolation;
- multiple structured frames per task;
- PHP exception transport;
- monotonic timeout classification;
- `SIGKILL` cleanup of a `SIGTERM`-ignoring descendant;
- complete result aggregation after failure.

## Intentional limits

- Linux only.
- FFI is the Phase 1 bridge; packaging as a PHP extension remains separate.
- One global concurrency class; scope/resource permits are not yet modeled.
- The scheduler is single-threaded and uses `poll()`, not pidfds/epoll.
- No signal forwarding, bail policy, replay metadata, coverage, or output
  artifact store.
- Protocol v1 supports event/output streaming, but callers must chunk any
  individual payload larger than 1 MiB.
- A Rust panic in the in-process library is not recoverable in this slice.
