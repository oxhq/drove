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
| Custom static class lifecycle | Compatible | File-scope setup and teardown wrap Pest `beforeAll` and `afterAll`. |
| Skips and todos | Compatible | Status and reason are preserved. |
| `--filter`, `--group`, `--exclude-group`, `--testsuite` | Compatible | PHPUnit selects generated cases before Drove schedules them. |
| `--parallel`, `--processes` | Native | Drover enforces the global limit; C1 and C8 output must match. |
| Ordinary PHPUnit test classes | Unsupported | Rejected with exit 2 instead of being skipped. |
| Test dependencies | Unsupported | Rejected with exit 2; result transport is not implemented. |
| Process isolation | Unsupported | CLI, XML, and supported PHPUnit metadata forms are rejected. |
| PHPUnit-enforced time limits | Unsupported | XML enforcement is rejected; Drove adds no implicit compatibility timeout. |
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
- Custom instance and static class lifecycle are supported. Static setup runs
  before file `beforeAll`; static teardown runs after file `afterAll`.
- The compatibility CLI exposes only a global `--processes` limit. Scope limits
  remain an internal kernel policy in this alpha.

Tests that depend on mutations leaking between siblings are order-dependent and
must be rewritten before using Drove.

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
