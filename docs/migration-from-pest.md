# Migrating from Pest to the Drove compatibility alpha

Drove is a source-only Linux alpha. It keeps a deliberately small Pest
surface while Drove owns scope planning, scope lifecycle, native scheduling,
result aggregation, and rendering.

## Build and run from source

The current branch requires PHP 8.4 with FFI and PCNTL, Composer 2, Rust 1.88,
and Linux:

```bash
composer install
cargo build --manifest-path native/drover/Cargo.toml --release --locked

export DROVER_LIBRARY="$PWD/native/drover/target/release/libdrover.so"
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
declares that it replaces Pest 5.0.1 for plugin compatibility. Neither Drove
package is published yet.

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
| PHPUnit-enforced time limits | Unsupported | XML enforcement is rejected; Drove adds no implicit compatibility timeout. |
| PHPUnit `failOnIncomplete` | Unsupported | XML opt-in is rejected; incomplete tests retain their status under Drove's default policy. |
| PHPUnit useless-test riskiness / XML `failOnRisky` | Compatible | No-assertion metadata and the XML exit policy are preserved; the CLI `--fail-on-risky` option is rejected. |
| Other PHPUnit `failOn*` / all `stopOn*` policies | Unsupported | XML opt-ins are rejected rather than silently changing exit or scheduling semantics. |
| PHPUnit warnings | Compatible exit policy | Planning and per-test warnings are detected; `failOnPhpunitWarning` is preserved, while output is a stable summary rather than PHPUnit's full issue printer. |
| PHPUnit extensions / non-default execution order | Unsupported | XML configuration is rejected before execution. |
| PHPUnit strict global state, coverage, and output modes | Unsupported | XML strict modes and conflicting coverage metadata are rejected before execution; ordinary metadata remains available for dual-run compatibility. |
| Coverage, profiling, alternate printers, mutation, browser, and watch modes | Unsupported | Recognized CLI modes exit 2; plugin discovery is not exhaustive. |
| Windows and macOS execution | Unsupported | The execution engine requires Linux process semantics. |

Higher-order tests, repetitions, architecture tests, snapshots, and third-party
plugins are not part of the declared alpha surface. Detection is not yet
exhaustive, so the dual-run comparison remains required.

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

The project will not claim broad Pest parity until a larger dual-run corpus
supports it.
