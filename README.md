# Drove

Drove is an experimental fork of Pest exploring hierarchical prepared-state
snapshots and native parallel execution.

The current `refactor/phase-1` branch is a source-built Linux kernel proof. It
defines a framework-independent Scope IR, deterministic lifecycle semantics,
structured child events and failures, bounded global and scope concurrency,
and two conforming schedulers:

- `PcntlScheduler`, the PHP reference backend;
- `DroverScheduler`, backed by the Rust engine in `native/drover`.

The same lifecycle fixture runs at concurrency 1 and 8 through both backends
and must produce the same semantic projection. Drover owns process creation,
permit acquisition, polling, timeouts, process-group cleanup, protocol frame
collection, and canonical result delivery.

## Run the Phase 1 gate

Docker is the supported reproducible path:

```bash
docker build --file experiments/phase-1/Dockerfile --tag drove-phase-one .
docker run --rm drove-phase-one
docker run --rm drove-phase-one php inactive.php
docker run --rm drove-phase-one php kernel.php
docker run --rm drove-phase-one timeout 15 php scheduler.php
docker run --rm drove-phase-one timeout 15 php interruption.php
docker run --rm drove-phase-one php -d ffi.enable=true /pest/native/drover/proof.php
```

The image build also runs the locked Rust tests, warning-clean Clippy, and a
release build. See
[`experiments/phase-1/README.md`](experiments/phase-1/README.md) and
[`native/drover/README.md`](native/drover/README.md) for the proof contracts.

## Current boundary

This branch is not a released package or a drop-in Pest runner. It is Linux
only, uses FFI as the native bridge, and does not provide coverage merging,
plugin parity, native extension packaging, or a stable public API. The Pest
compatibility runner belongs to Phase 2.

## Attribution

Drove began as a hard fork of [Pest](https://github.com/pestphp/pest). The Pest
DSL, expectations, PHPUnit integration, and other upstream-derived code retain
their original copyright and the repository's [MIT license](LICENSE.md).
`src/Drove` contains the independent kernel and compatibility work;
`native/drover` contains the original Rust execution engine.
