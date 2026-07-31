# Drove

Drove is an experimental independent test runner for hierarchical
prepared-state execution. It began as a Pest hard fork, but the intended
product is a framework with a Pest-compatible frontend—not a permanent
downstream branch.

Supported Pest syntax is compiled into Drove's independent Scope IR, executed
by the lifecycle kernel, and scheduled through the Rust-backed Drover engine.
The Composer package is `oxhq/drove`; it replaces Pest 5.0.1 only to keep the
compatible plugin surface installable during the extraction.

> **Experimental alpha:** `v0.4.0-alpha.3` is the technical evaluation release,
> not a stable compatibility promise. It does not claim external validation.
> Its schema-2 evidence will gate candidate `v0.4.0-alpha.4`. Use the live
> authorities below to verify package availability, native assets, and hosted
> evidence for an exact release.

| Public artifact | Live authority |
| --- | --- |
| `oxhq/drove` | [Packagist](https://packagist.org/packages/oxhq/drove) |
| `oxhq/drove-laravel` | [Packagist](https://packagist.org/packages/oxhq/drove-laravel) |
| Linux and macOS native archives | [`v0.4.0-alpha.3` release](https://github.com/oxhq/drove/releases/tag/v0.4.0-alpha.3) |
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
- fork-local PCOV line coverage for the Pest/PHPUnit bridge;
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
composer require --dev oxhq/drove:0.4.0-alpha.3
vendor/bin/drove-install-native
vendor/bin/drove --version
vendor/bin/drove --compatibility
```

The historical `v0.4.0-alpha.2`
[published-package gate #30589230018](https://github.com/oxhq/drove/actions/runs/30589230018)
resolved both tagged packages from Packagist on Linux and macOS, x86_64 and
ARM64. It is not hosted proof for `v0.4.0-alpha.3`; use the live authorities
above for the exact current tag.

The native install does not install or trust Pest, PHPUnit, ParaTest,
Collision, Termwind, Symfony Process, or the Pest Composer plugin.

The Pest compatibility bridge is a separate opt-in inside this alpha:

```bash
composer config --no-plugins allow-plugins.pestphp/pest-plugin true
composer require --dev \
  brianium/paratest:^7.23.0 \
  nunomaduro/collision:^8.9.5 \
  nunomaduro/termwind:^2.4.0 \
  pestphp/pest-plugin:^5.0.0 \
  phpunit/phpunit:13.2.4 \
  symfony/process:^8.1.0
vendor/bin/drove --pest --version
```

`vendor/bin/drove --pest` verifies that complete compatible set before loading
Pest code. Missing or incompatible dependencies fail explicitly.

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
composer require --dev oxhq/drove-laravel:0.4.0-alpha.3
```

Drove's packaged `vendor/bin/pest` is a bridge alias, not an independent Pest
baseline. Gate-counting comparisons use the `v0.4.0-alpha.3` schema-2 collector,
which reconstructs the original dependency tree from a recorded revision.
`alpha.2` evaluator output does not count. See the
[migration, troubleshooting, and rollback guide](docs/migration-from-pest.md).

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
The independent [`native DSL`](docs/native-dsl.md) is the forward product
surface; it lowers directly to Scope IR without generated Pest or PHPUnit
cases.

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
Each rung is a curated compatibility cohort, not whole-suite adoption proof.
The pinned ladder covers all four rungs, ending with Filament's 677-case
nonserial cohort at 1, 2, 4, 8, 16, and 30 Drove processes plus its 28-case
filesystem-sensitive cohort at 1. The historical `alpha.2` release-SHA full
ladder was accepted in
[External Corpus #30587352130](https://github.com/oxhq/drove/actions/runs/30587352130);
the stored
[compatibility benchmark](benchmarks/results/2026-07-30-corpus-2146dd4b.md)
retains requested/observed lanes, counts, milliseconds, and memory sources as a
single-sample diagnostic rather than a performance claim. Nucleus is
deliberately excluded because its Docker/MySQL suite is not part of this
portable corpus. The linked run proves `alpha.2` only; it is not hosted
`alpha.3` evidence. The tagged
[corpus source](https://github.com/oxhq/drove/tree/v0.4.0-alpha.3/benchmarks/corpus)
records revisions, selections, dependency overlays, and normalization rules.

## Bridge coverage, interruption, and replay

`drove --pest` can merge coverage collected independently in forked
Pest/PHPUnit bridge processes. The bridge-gated configuration is PCOV 1.0.12
line coverage with PHPUnit's `--coverage-php` report. Xdebug and the other
PHPUnit report formats remain experimental until they pass the same
concurrency and fault matrix. Strict coverage metadata/contribution modes and
the ambiguous bare `--coverage` option remain explicit bridge rejections.

The native Drove frontend does not implement or advertise coverage. Native
coverage options are rejected, and the Bridge Coverage gate is not evidence of
native-frontend coverage.

`--drove-timeout-ms=N` assigns one positive default deadline to every selected
test; omitting it leaves deadlines disabled. On timeout, SIGINT, or SIGTERM,
Drover stops scheduling new work, terminates active process groups, escalates
when needed, and reports a stable failure classification. Each task uses one
fork: the PHP executor is also its process-group leader, and no inert anchor is
created. Drover observes executor exit with `waitid(..., WNOWAIT)`, completes
same-group descendant cleanup, and only then reaps the executor. Native runs
require the default `SIGCHLD` disposition so executor children remain waitable.
Nested Drove maps register each executor group with the original engine before
child readiness. The root cleans that ownership graph transitively if a
stateful scope host crashes, including when PHP shutdown handlers cannot run.
An explicit `DroverScheduler::requestCancellation()` is latched and executed
at the next safe PHP/native boundary. Native cancellation is fallible: cleanup
continues across every active map, then any drain, signal, reap, registry, or
permit-release failure is propagated with a stable diagnostic. Destructors and
fatal shutdown handlers use the same cleanup path best-effort, report failures
to stderr, and never throw from teardown.
The configured process count is a hard cap on active test bodies/executor
lanes, not on every OS process. Stateful scopes add separately reported,
lazily created scope hosts that own their semantic snapshots; inert scopes add
none. Aggregate PID and memory telemetry includes both executors and scope
hosts.

`--replay=path.json` writes one no-overwrite diagnostic artifact.
`--replay-on-failure=path.json` writes only for a nonzero run. The artifact
contains version/platform data, redacted arguments, a plan hash, status counts,
failure kinds, completion order, observed concurrency, sampled PHP memory, and
a whitelist-only projection of environment providers and capabilities. It
excludes test output, values, environment variables, failure messages, and
unknown environment fields, but should still be inspected before sharing. It
records the maximum of the root and available descendant PHP-process peaks,
not aggregate RSS. It is metadata for reproducing a run; Drove does not yet
consume it to rerun the suite.

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
runtime-error exit code. Native cleanup covers descendants that remain in the
task process group and Drove-owned nested groups in the root ownership
registry. User code that deliberately escapes with `setsid()`, `setpgid()`, or
privilege changes requires an OS sandbox and is outside this alpha.

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
