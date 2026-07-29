# Drove Phase 1 execution proofs

This image contains the discovery spike plus the first real kernel execution
contract.

The discovery proof loads a Pest file through the normal PHPUnit/Pest suite
path, prepares Laravel and SQLite once, executes the generated case in a forked
child, and verifies that prepared memory is inherited while child mutation
remains private.

The kernel proof compiles a scalar scope plan and runs the identical
`LifecycleExecutor` fixture through both backends:

- `PcntlScheduler`, the PHP reference implementation;
- `DroverScheduler`, backed by the Rust engine in `native/drover`.

Each backend must preserve the same task IDs and kinds, full scope ancestry,
nested scope behavior, global and scope permits, timeouts, stdout, values,
terminal result shape, failure classes, cleanup, and plan-order projection. The
fixture runs at concurrency 1 and 8 and the two backends must produce the exact
same semantic hash.

Run the complete Phase 1 gate on Linux:

```bash
docker build -f experiments/phase-1/Dockerfile -t drove-phase-one .
docker run --rm drove-phase-one
docker run --rm drove-phase-one php inactive.php
docker run --rm drove-phase-one php kernel.php
docker run --rm drove-phase-one timeout 15 php scheduler.php
docker run --rm drove-phase-one timeout 15 php interruption.php
docker run --rm drove-phase-one php -d ffi.enable=true /pest/native/drover/proof.php
```

The image build itself runs locked Rust tests, Clippy with warnings denied, and
a release build before installing the PHP fixture.

## Boundary

This is a source-built Linux/FFI conformance slice, not a released native
extension or the default Pest runner. It does not yet provide extension
packaging, coverage merging, replay persistence, an output artifact store, or
the final CLI/backend selection surface.
