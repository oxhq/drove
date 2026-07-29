# Drove

Drove is an experimental hard fork of Pest used to validate hierarchical
prepared-state testing and native parallel execution. Its intended destination
is an independent framework with a Pest-compatible frontend, not a permanent
downstream fork.

This repository currently contains a source-built Linux compatibility alpha.
Supported Pest syntax is compiled into Drove's independent Scope IR, executed
by the lifecycle kernel, and scheduled through the Rust-backed Drover engine.

The proven compatibility surface includes:

- `test()`, `it()`, `describe()`, and expectations;
- `beforeAll`, `beforeEach`, `afterEach`, and `afterAll`;
- named and positional datasets;
- custom `TestCase` instance setup/teardown and direct `uses()` binding;
- skips, todos, filters, groups, excluded groups, and test suites;
- deterministic test and scope failure/output rendering;
- identical installed-runner output at concurrency 1 and 8.

## Run the compatibility gate

Docker is the reproducible path:

```bash
docker build --file experiments/phase-2/Dockerfile --tag drove-phase-two .
docker run --rm drove-phase-two
docker run --rm drove-phase-two php kernel-seam.php
docker run --rm drove-phase-two php renderer.php

docker build --file experiments/phase-2-cli/Dockerfile --tag drove-phase-two-cli .
docker run --rm drove-phase-two-cli
```

The installed-project proof runs the real `drove` executable through Drover,
checks path/filter/group/suite selection, and requires exit codes 0, 1, and 2
for passing, failing, and explicitly unsupported input.

See [`docs/migration-from-pest.md`](docs/migration-from-pest.md) for source
installation, the compatibility matrix, and declared limitations.

## Current boundary

This branch is not a released package or a broad Pest/PHPUnit replacement. It
is Linux only and uses FFI as the native bridge. Ordinary PHPUnit classes, test
dependencies, process isolation, custom static `TestCase` lifecycle, coverage,
profiling, and the wider plugin ecosystem are outside the declared alpha
surface.

The Composer package name remains `pestphp/pest` during the hard-fork stage for
plugin and installer compatibility. No Drove package has been published to
Packagist.

## Attribution

Drove began as a hard fork of [Pest](https://github.com/pestphp/pest). The Pest
DSL, expectations, PHPUnit integration, and other upstream-derived code retain
their original copyright and the repository's [MIT license](LICENSE.md).
`src/Drove/Pest` contains the modified compatibility frontend, `src/Drove/Kernel`
contains the independent lifecycle kernel, and `native/drover` contains the
original Rust execution engine.
