# Drover native scheduler

This directory contains the Linux/macOS native execution boundary used by
`Drove\Kernel\DroverScheduler`. Tagged experimental releases provide
checksummed x86_64 and ARM64 libraries; `drove-install-native` installs the
matching library into the Composer package.

Rust owns:

- a long-lived engine with kernel-backed global and configured scope permit
  pools shared by every descendant;
- atomic acquisition of every task permit before `fork()`;
- a fresh bounded map for each scheduling wave;
- `fork()`, isolated process groups, and nonblocking parent transport;
- monotonic task deadlines and `SIGTERM` to `SIGKILL` group escalation;
- `waitpid()`, result aggregation, interruption, and cancellation cleanup;
- validation of the canonical versioned ChildProtocol frames.

PHP retains the prepared application memory and executes inherited closures.
`DroverScheduler` implements the same kernel `Scheduler` contract as
`PcntlScheduler`: it submits task identity, kind, full scope ancestry, timeout,
and permit policy to Rust; a forked PHP child emits the shared protocol through
`ChildProtocol`; and Rust returns the canonical task result.

Scope hosts do not consume a global test permit. Nested maps use the same
engine-backed permit pools, so concurrency one can enter a scope and still run
its descendants without a self-deadlock.

## Proof from source

The Composer archive includes this runtime note but excludes Rust sources and
development fixtures. Run these commands from the matching tagged
[source checkout](https://github.com/oxhq/drove/tree/v0.4.0-alpha.1).

The standalone ABI smoke is:

```bash
docker build --file native/drover/Dockerfile --tag drove-native-phase-one .
docker run --rm drove-native-phase-one
```

The Docker build runs locked Rust tests, warning-clean Clippy, and a release
build. The PHP smoke then verifies the shared v1 vectors, global and scope
permit reservation, prepared-memory inheritance, ordered result transport, PHP
exception and timeout classification, and cleanup of an exec-style descendant
that ignores `SIGTERM`.

The closure gate is the Phase 1 kernel fixture:

```bash
docker build --file experiments/phase-1/Dockerfile --tag drove-phase-one .
docker run --rm drove-phase-one php kernel.php
```

That command runs the identical `LifecycleExecutor` fixture through
`PcntlScheduler` and the Rust-backed `DroverScheduler`, at concurrency 1 and 8,
then requires exact semantic-projection hashes. It also exercises nested scope
hosts, global and scope limits, output/value framing, failure classes, process
tree cleanup, and native child `_exit` behavior.

## Intentional limits

- Windows is unsupported because Drover requires POSIX `fork()` and process
  groups.
- FFI remains the native bridge; Drove is not a PHP extension.
- The scheduler is single-threaded and uses `poll()`, not pidfds/epoll.
- Bail policy and a general output artifact store remain outside this slice.
- Protocol v1 limits an individual frame to 1 MiB and aggregate stdout,
  stderr, or value data to 64 MiB. `ChildProtocol` chunks normal payloads.
- A Rust panic in the in-process library is not recoverable in this slice.
