# Drove

Drove is an experimental independent test runner for hierarchical
prepared-state execution. It began as a Pest hard fork, but the intended
product is a framework with a Pest-compatible frontend—not a permanent
downstream branch.

This repository currently contains a source-built Linux compatibility alpha.
Supported Pest syntax is compiled into Drove's independent Scope IR, executed
by the lifecycle kernel, and scheduled through the Rust-backed Drover engine.
The Composer package is now `oxhq/drove`; it replaces Pest 5.0.1 only to keep
the compatible plugin surface installable during the extraction.

The proven compatibility surface includes:

- `test()`, `it()`, `describe()`, and expectations;
- `beforeAll`, `beforeEach`, `afterEach`, and `afterAll`;
- named and positional datasets;
- custom `TestCase` instance and static lifecycle plus direct `uses()` binding;
- one native PHPUnit `TestCase` class per file, including datasets, groups,
  class lifecycle, and mixed Pest/PHPUnit suites;
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

docker build --file experiments/phase-3-laravel/Dockerfile \
  --tag drove-phase-three-laravel .
docker run --rm drove-phase-three-laravel
docker run --rm drove-phase-three-laravel php proof-testbench-guards.php
docker run --rm drove-phase-three-laravel php proof-testbench.php
docker run --rm drove-phase-three-laravel php proof-data-provider.php
```

The installed-project proof runs the real `drove` executable through Drover,
checks path/filter/group/suite selection, and requires exit codes 0, 1, and 2
for passing, failing, and explicitly unsupported input.

See [`docs/migration-from-pest.md`](docs/migration-from-pest.md) for source
installation, the compatibility matrix, and declared limitations.

## Laravel alpha

`packages/drove-laravel` adds an Artisan subprocess command, one prepared
Laravel or Orchestra Testbench application, Laravel-aware file `beforeAll`,
and explicit database state adapters:

- verified file copies for one file-backed SQLite database;
- one inherited in-memory SQLite connection with an explicit prepared-schema
  contract; and
- rollback isolation for one MySQL connection and InnoDB tables in a disposable
  test database.

`DROVE_LARAVEL_RUNTIME=auto` selects a normal application when
`bootstrap/app.php` exists and otherwise defers to Testbench discovery.
`application` and `testbench` force either mode. A Testbench project that also
ships `bootstrap/app.php` must select `testbench` explicitly.

The installed proof requires a real `testing` environment, one bootstrap PID,
isolated sibling writes, paired cleanup after failures, and no remaining
SQLite artifacts. MySQL migration/truncation traits are rejected before test
execution because they can commit schema changes outside the adapter
transaction.

The external correctness ladder is Pest, InvoiceShelf, Livewire, then Filament.
All four rungs are proven, ending with Filament's 677-case nonserial cohort at
1, 2, 4, and 8 Drove processes plus its 28-case filesystem-sensitive cohort at
1. Nucleus is deliberately excluded because its Docker/MySQL suite is not part
of this portable corpus. See
[`benchmarks/corpus`](benchmarks/corpus/README.md) for revisions, selections,
dependency overlays, and observed results.

## Current boundary

This branch is not a released package or a broad Pest/PHPUnit replacement. It
is Linux only and uses FFI as the native bridge. Multiple native PHPUnit
classes in one file, test dependencies, process isolation, PHPUnit
`failOnIncomplete`, other unsupported `failOn*` policies, `stopOn*`, strict
global-state/coverage/output modes, conflicting coverage metadata, PHPUnit
extensions, non-default execution order, profiling, multiple Testbench application
profiles, Testbench attributes, multiple database connections, Redis/queue
isolation, and the wider plugin ecosystem are outside the declared alpha
surface.

Drove uses exit 1 for any test failure or runtime error and exit 2 for invalid
or explicitly unsupported input. It does not preserve PHPUnit's separate
runtime-error exit code.

Neither Drove package has been published to Packagist. Installation is
source/path based until the native bridge is distributed and a tagged
experimental release exists.

## Attribution

Drove began as a hard fork of [Pest](https://github.com/pestphp/pest). The Pest
DSL, expectations, PHPUnit integration, and other upstream-derived code retain
their original copyright and the repository's [MIT license](LICENSE.md).
`src/Drove/Pest` contains the modified compatibility frontend, `src/Drove/Kernel`
contains the independent lifecycle kernel, and `native/drover` contains the
original Rust execution engine.
