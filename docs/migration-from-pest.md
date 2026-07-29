# Migrating from Pest to the Drove v0.4 alpha

Drove keeps a deliberately small Pest surface while Drove owns scope planning,
scope lifecycle, native scheduling, result aggregation, and rendering. The
live [GitHub release](https://github.com/oxhq/drove/releases/tag/v0.4.0-alpha.1),
[Packagist package](https://packagist.org/packages/oxhq/drove), and
[hosted workflows](https://github.com/oxhq/drove/actions) are the authorities
for the exact `v0.4.0-alpha.1` artifacts and proof.

## Install

```bash
composer config --no-plugins allow-plugins.pestphp/pest-plugin true
composer require --dev oxhq/drove:^0.4@alpha
vendor/bin/drove-install-native
vendor/bin/drove --version
vendor/bin/drove --compatibility
```

Composer requires that explicit trust decision because this alpha still uses
`pestphp/pest-plugin` for plugin discovery. A consumer's `allow-plugins`
configuration is not inherited from Drove.

The native installer supports GNU/Linux with glibc 2.31 or newer and macOS on
x86_64 and aarch64. It downloads from the exact installed Drove tag, verifies
the published SHA-256 file, and installs the library in the package. It is
never run automatically by Composer. Alpine and other musl-based Linux
distributions are unsupported. PHP 8.4 with FFI, PCNTL, and POSIX extensions
is required; the explicit native installer also requires OpenSSL, Phar, and zlib.
Windows is unsupported.

To build from source, use the tagged repository checkout. Building the library
additionally requires Rust 1.88:

```bash
git clone --branch v0.4.0-alpha.1 --depth 1 \
  https://github.com/oxhq/drove.git
cd drove
composer install
cargo build --manifest-path native/drover/Cargo.toml --release --locked

# Linux:
export DROVER_LIBRARY="$PWD/native/drover/target/release/libdrover.so"

# macOS:
# export DROVER_LIBRARY="$PWD/native/drover/target/release/libdrover.dylib"

php bin/drove
php bin/drove --parallel --processes=8
php bin/drove --filter=Invoice
php bin/drove --group=slow
php bin/drove --exclude-group=integration
php bin/drove --testsuite=Feature
```

When this checkout is installed into a disposable project through a Composer
path repository, invoke `vendor/bin/drove` and point `DROVER_LIBRARY` at the
library built from this checkout. The package is named `oxhq/drove` and
declares that it replaces Pest 5.0.1 for plugin compatibility.

Keep `vendor/bin/pest` in CI while evaluating the alpha. Run both commands
against the same selected suite and treat a semantic difference as a
compatibility bug.

## Declared compatibility

| Surface | Level | Notes |
| --- | --- | --- |
| `test()`, `it()`, `describe()`, expectations | Native | Compiled into Drove Scope IR. |
| `beforeAll`, `beforeEach`, `afterEach`, `afterAll` | Native | Drove owns scope hooks; generated Pest cases preserve per-test hook order. |
| Named and positional datasets | Compatible | Every selected row receives a stable case ID. |
| Custom `TestCase`, `uses()`, `setUp()`, `tearDown()` | Compatible | Instance lifecycle runs in the test child. |
| Generated Pest static class lifecycle | Compatible subset | Conventional `setUpBeforeClass()` / `tearDownAfterClass()` wrap Pest `beforeAll` / `afterAll`; generated-case attribute class hooks are rejected. |
| Skips and todos | Compatible | Status and reason are preserved. |
| `--filter`, `--group`, `--exclude-group`, `--testsuite` | Compatible | PHPUnit selects generated cases before Drove schedules them. |
| `--parallel`, `--processes` | Native | Drover enforces the global limit; C1 and C8 output must match. |
| Ordinary PHPUnit test classes | Compatible | One native `TestCase` class per file; datasets, groups, instance/static and attribute class lifecycle, and mixed Pest/PHPUnit suites are supported. |
| Test dependencies | Unsupported | Rejected with exit 2; result transport is not implemented. |
| Process isolation | Unsupported | CLI, XML, and supported PHPUnit metadata forms are rejected. |
| Orchestra Testbench | Compatible subset | One application profile per selected suite; Testbench attributes and mixed Testbench/application suites are rejected. |
| PHPUnit-enforced time limits | Unsupported | XML enforcement is rejected; Drove's separate `--drove-timeout-ms=N` sets one explicit default per-test deadline. |
| PHPUnit `failOnIncomplete` | Unsupported | XML opt-in is rejected; incomplete tests retain their status under Drove's default policy. |
| PHPUnit useless-test riskiness / XML `failOnRisky` | Compatible | No-assertion metadata and the XML exit policy are preserved; the CLI `--fail-on-risky` option is rejected. |
| Other PHPUnit `failOn*` / all `stopOn*` policies | Unsupported | XML opt-ins are rejected rather than silently changing exit or scheduling semantics. |
| PHPUnit warnings | Compatible exit policy | Planning and per-test warnings are detected; `failOnPhpunitWarning` is preserved, while output is a stable summary rather than PHPUnit's full issue printer. |
| PHPUnit extensions / non-default execution order | Unsupported | XML configuration is rejected before execution. |
| PHPUnit coverage reports | Beta | Fork-local fragments are merged before PHPUnit generates Clover, Cobertura, Crap4J, HTML, PHP, text, or XML reports. PCOV or Xdebug is required. |
| PHPUnit strict global state, strict coverage, and output modes | Unsupported | Strict modes and conflicting coverage metadata are rejected before execution. |
| Profiling, alternate printers, mutation, browser, and watch modes | Unsupported | Recognized CLI modes exit 2. |
| Drove plugin observers | Alpha | `Bootable`, `InspectsPlan`, and `ReportsRun` observe immutable inputs; runner mutation is unsupported. |
| Environment planning | Alpha | Providers declare resource kinds, capabilities, and atomic or best-effort coordination. Only the three Laravel database providers are implemented. |
| GNU/Linux | Alpha | Native release archives target glibc 2.31+ on x86_64/aarch64. musl is unsupported. |
| macOS | Beta | Native release archives target x86_64/aarch64; verify the exact tag's hosted run and checksums. |
| Windows | Unsupported | Drove requires FFI, fork, PCNTL, POSIX process groups, and a Unix native library. |

Higher-order tests, repetitions, architecture tests, snapshots, and third-party
plugins are not part of the declared alpha surface. Detection is not yet
exhaustive, so the dual-run comparison remains required.

## Coverage aggregation

Drove starts PHPUnit's configured coverage driver in each selected test child,
serializes one fragment per case, merges the fragments in the root process, and
then delegates report generation to PHPUnit. Use the ordinary PHPUnit report
options:

```bash
vendor/bin/drove --coverage-text
vendor/bin/drove --coverage-clover=build/coverage.xml
vendor/bin/drove --parallel --processes=8 --coverage-html=build/coverage
```

The report must remain semantically equivalent at concurrency 1 and higher.
The bare `--coverage` switch, strict coverage metadata/contribution modes, and
conflicting coverage declarations are rejected rather than approximated.

## Deadlines, interruption, and diagnostic replay

No timeout is implicit. `--drove-timeout-ms=30000` applies a 30-second default
to every selected test. A timeout terminates the test process group and is
reported as a timeout rather than an assertion failure. SIGINT and SIGTERM
stop new scheduling and are classified separately from a child crash.

Use a replay artifact when a failure is hard to reproduce:

```bash
vendor/bin/drove --parallel --processes=8 \
  --replay-on-failure=build/drove-replay.json
```

The destination directory must already exist, and Drove never overwrites an
existing artifact. The JSON records a plan hash, counts, failure kinds,
completion order, concurrency, platform, and redacted arguments. It omits
outputs, values, environment variables, and failure messages. Inspect it
before sharing because paths and non-secret command arguments remain visible.
This is diagnostic metadata, not an executable replay file.

## Plugin observers and registry

`vendor/bin/drove --compatibility` prints the package's versioned compatibility
registry. A Composer plugin already discoverable through the inherited Pest
plugin loader may opt into these Drove contracts:

- `Drove\Contracts\Plugins\Bootable::bootDrove()`;
- `Drove\Contracts\Plugins\InspectsPlan::inspectDrovePlan()`; and
- `Drove\Contracts\Plugins\ReportsRun::reportDroveRun()`.

All three are observer-only alpha hooks. Arguments and arrays are passed for
inspection; changes do not alter Drove's plan, run, renderer, or exit code. An
observer exception fails the run with exit 1 and is recorded as crash metadata
when replay is enabled. Plugins that intercept Pest/PHPUnit CLI internals remain
unsupported unless the compatibility registry explicitly lists them.

## Lifecycle differences to audit

- `beforeAll` prepares a copy-on-write scope snapshot. Mutations made by one
  test child do not return to its parent or siblings.
- Nested `beforeAll` and `afterAll` are Drove scope hooks.
- Pest runs `beforeEach` after custom `setUp()` and `afterEach` before custom
  `tearDown()`, matching the generated Pest `TestCase` lifecycle.
- A failed `beforeAll` blocks only its subtree. Initialized ancestors still
  unwind and siblings continue.
- Drove preserves a body failure as primary and reports teardown failures
  separately.
- Result order follows the test plan, not process completion order.
- Custom instance lifecycle is supported. Conventional generated-case static
  setup runs before file `beforeAll`; teardown runs after file `afterAll`.
  Attribute class hooks are supported only on ordinary native PHPUnit classes.
- The compatibility CLI exposes only a global `--processes` limit. Scope limits
  remain an internal kernel policy in this alpha.

Tests that depend on mutations leaking between siblings are order-dependent and
must be rewritten before using Drove.

## Laravel and Testbench mode

`DROVE_LARAVEL_RUNTIME=auto` boots `bootstrap/app.php` when present and
otherwise lets the selected Orchestra Testbench case create the prepared
application. Use `application` or `testbench` to force either path; a Testbench
package that ships `bootstrap/app.php` must set `testbench`.

Database state remains explicit:

- `transaction` supports one MySQL connection in a disposable test database;
- `sqlite-copy` gives each descendant a verified file copy; and
- `sqlite-memory` reuses one inherited `:memory:` PDO.

`RefreshDatabase` with `sqlite-memory` requires
`DROVE_LARAVEL_SQLITE_PREPARED_SCHEMA=true`; Drove will not silently mark an
unmigrated schema as prepared. With `sqlite-copy`, each private copy migrates
normally unless the same opt-in declares the parent file already migrated.
For Testbench, built-in adapter state supplied explicitly or through these
environment variables is trait-preflighted before `createApplication()`.
Custom adapters and state defined only during application boot are validated
after boot and therefore must not treat provider bootstrap as disposable.
The selected database connection must equal Laravel's default. An explicit
secondary write can happen before cleanup detects the newly resolved
connection, so every configured database must be disposable.

## Attribution

- The Pest DSL, expectations, PHPUnit integration, and much of the command
  surface are derived from Pest and retain the repository's MIT license and
  notices.
- `src/Drove/Pest` and the modified hook/discovery seams are Drove compatibility
  code.
- `src/Drove/Kernel` is original Drove lifecycle and scheduling code.
- `native/drover` is the original Rust execution engine.

The project does not claim broad Pest parity: the pinned corpus deliberately
excludes higher-order, dependency, snapshot, and runner-internal plugin
surfaces.
