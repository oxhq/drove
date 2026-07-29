# Drove

Drove is an experimental independent test runner for hierarchical
prepared-state execution. It began as a Pest hard fork, but the intended
product is a framework with a Pest-compatible frontend—not a permanent
downstream branch.

Supported Pest syntax is compiled into Drove's independent Scope IR, executed
by the lifecycle kernel, and scheduled through the Rust-backed Drover engine.
The Composer package is `oxhq/drove`; it replaces Pest 5.0.1 only to keep the
compatible plugin surface installable during the extraction.

> **Experimental alpha:** `v0.4.0-alpha.1` is not a stable compatibility
> promise. Use the live authorities below to verify package availability,
> native assets, and hosted evidence for an exact release.

| Public artifact | Live authority |
| --- | --- |
| `oxhq/drove` | [Packagist](https://packagist.org/packages/oxhq/drove) |
| `oxhq/drove-laravel` | [Packagist](https://packagist.org/packages/oxhq/drove-laravel) |
| Linux and macOS native archives | [GitHub Releases](https://github.com/oxhq/drove/releases) |
| Hosted external corpus | [External Corpus workflow](https://github.com/oxhq/drove/actions/workflows/corpus.yml) |
| External design partners | [`docs/design-partner-validation.md`](docs/design-partner-validation.md) |

The proven compatibility surface includes:

- `test()`, `it()`, `describe()`, and expectations;
- `beforeAll`, `beforeEach`, `afterEach`, and `afterAll`;
- named and positional datasets;
- custom `TestCase` instance and static lifecycle plus direct `uses()` binding;
- one native PHPUnit `TestCase` class per file, including datasets, groups,
  class lifecycle, and mixed Pest/PHPUnit suites;
- skips, todos, filters, groups, excluded groups, and test suites;
- fork-local code-coverage aggregation into PHPUnit's report generators;
- explicit per-test deadlines, signal/crash classification, and diagnostic
  replay metadata;
- an observer-only Drove plugin API and machine-readable compatibility
  registry;
- a general `EnvironmentPlan` and resource-capability contract, currently
  implemented by the three Laravel database providers;
- deterministic test and scope failure/output rendering;
- identical installed-runner output at concurrency 1 and 8.

## Install the alpha

Install the tagged packages from Packagist:

```bash
composer config --no-plugins allow-plugins.pestphp/pest-plugin true
composer require --dev oxhq/drove:^0.4@alpha
vendor/bin/drove-install-native
vendor/bin/drove --version
vendor/bin/drove --compatibility
```

The explicit Composer opt-in trusts the inherited Pest plugin-discovery
package used by this alpha. Consumer projects do not inherit Drove's own
`allow-plugins` setting, so noninteractive installation fails closed without
that command.

`drove-install-native` is explicit: Composer does not download executable
artifacts during install. It selects GNU/Linux (glibc 2.31 or newer) or macOS
and x86_64 or aarch64, downloads the matching archive from the exact Drove
release tag, verifies its SHA-256 file, and installs the library inside the
package. Alpine and other musl-based Linux distributions are unsupported. Set
`DROVER_LIBRARY=/absolute/path/to/libdrover.so` (or `.dylib`) to use a
source-built or separately managed library instead.

The native installer requires PHP's OpenSSL, Phar, and zlib extensions in
addition to the runtime FFI, PCNTL, and POSIX extensions declared by the
package.

Laravel/Testbench users will also install the matching alpha:

```bash
composer require --dev oxhq/drove-laravel:^0.4@alpha
```

## Run the compatibility gate

Docker is the reproducible path in a tagged source checkout. The Composer
archive intentionally excludes these development fixtures:

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
The pinned ladder covers all four rungs, ending with Filament's 677-case
nonserial cohort at 1, 2, 4, and 8 Drove processes plus its 28-case
filesystem-sensitive cohort at 1. A release requires a successful exact-SHA
hosted full-ladder run; inspect the workflow authority above for its result and
artifacts. Nucleus is deliberately excluded because its Docker/MySQL suite is
not part of this portable corpus. The tagged
[corpus source](https://github.com/oxhq/drove/tree/v0.4.0-alpha.1/benchmarks/corpus)
records revisions, selections, dependency overlays, and normalization rules.

## Coverage, interruption, and replay

Drove can merge coverage collected independently in forked test processes and
then use PHPUnit's standard `--coverage-clover`, `--coverage-cobertura`,
`--coverage-crap4j`, `--coverage-html`, `--coverage-php`, `--coverage-text`,
and `--coverage-xml` report generators. A supported coverage driver such as
PCOV or Xdebug is still required. Strict coverage metadata/contribution modes
and the ambiguous bare `--coverage` option remain explicit rejections.

`--drove-timeout-ms=N` assigns one positive default deadline to every selected
test; omitting it leaves deadlines disabled. On timeout, SIGINT, or SIGTERM,
Drover stops scheduling new work, terminates active process groups, escalates
when needed, and reports a stable failure classification.

`--replay=path.json` writes one no-overwrite diagnostic artifact.
`--replay-on-failure=path.json` writes only for a nonzero run. The artifact
contains version/platform data, redacted arguments, a plan hash, status counts,
failure kinds, completion order, and observed concurrency. It excludes test
output, values, environment variables, and failure messages, but should still
be inspected before sharing. It is metadata for reproducing a run; Drove does
not yet consume it to rerun the suite.

## Plugin and compatibility boundary

`vendor/bin/drove --compatibility` prints the versioned JSON registry shipped
with the package. Drove plugins may implement
`Drove\Contracts\Plugins\Bootable`, `InspectsPlan`, or `ReportsRun`. These alpha
hooks can observe boot, the immutable plan, and the completed run. They cannot
mutate scheduling, results, rendering, or exit policy through the by-value
arguments. Observer exceptions fail the run with exit 1 and crash replay
metadata; Drove does not silently discard broken telemetry. Pest plugins that
depend on runner internals are unsupported unless the registry says otherwise.

## Current boundary

This alpha is not a broad Pest/PHPUnit replacement. It uses FFI plus POSIX
process semantics. Windows is unsupported.
GNU/Linux with glibc 2.31 or newer is the proven platform; musl is unsupported.
macOS x86_64/aarch64 remains a beta distribution target.
Multiple native PHPUnit classes in one file, test
dependencies, process isolation, PHPUnit
`failOnIncomplete`, other unsupported `failOn*` policies, `stopOn*`, strict
global-state/coverage/output modes, conflicting coverage metadata, PHPUnit
extensions, non-default execution order, profiling, alternate printers,
multiple Testbench application profiles, Testbench attributes, multiple
database connections, Redis/queue/object-storage isolation, and plugins that
mutate runner internals are outside the declared alpha surface.

Drove uses exit 1 for any test failure or runtime error and exit 2 for invalid
or explicitly unsupported input. It does not preserve PHPUnit's separate
runtime-error exit code.

Packagist, GitHub Releases, and the hosted workflows above are the live
publication and proof authorities; a source branch alone is not release
evidence.

## Attribution

Drove began as a hard fork of [Pest](https://github.com/pestphp/pest). The Pest
DSL, expectations, PHPUnit integration, and other upstream-derived code retain
their original copyright and the repository's [MIT license](LICENSE.md).
`src/Drove/Pest` contains the modified compatibility frontend, `src/Drove/Kernel`
contains the independent lifecycle kernel, and `native/drover` contains the
original Rust execution engine.
