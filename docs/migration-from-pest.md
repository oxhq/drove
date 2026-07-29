# Migrating from Pest to the Drove compatibility alpha

Drove is currently an experimental Pest fork. Its compatibility frontend keeps
common Pest syntax while Drove owns scope planning, lifecycle order, scheduling,
and result aggregation.

## Install and run

This alpha is source-only and Linux-only:

```bash
composer install
vendor/bin/drove
vendor/bin/drove --filter=Invoice
vendor/bin/drove --group=slow
vendor/bin/drove --testsuite=Feature
```

Keep `vendor/bin/pest` in CI while evaluating Drove. Run both commands against
the same suite and treat a semantic difference as a compatibility bug.

## Compatibility levels

| Surface | Level | Notes |
| --- | --- | --- |
| `test()`, `it()`, `describe()` and expectations | Native | Compiled into Drove Scope IR. |
| `beforeAll`, `beforeEach`, `afterEach`, `afterAll` | Native | Drove owns ordering and partial unwind; hooks run once. |
| Named and positional datasets | Compatible | Each selected dataset row has its own stable case ID. |
| Custom PHPUnit `TestCase` and `uses()` binding | Compatible | Setup, body, teardown, assertions, skips, and todos run through the generated case. |
| `--filter`, `--group`, `--exclude-group`, `--testsuite` | Compatible | PHPUnit selects cases before Drove schedules them. |
| Test dependencies | Unsupported | Dependency result transport is not implemented. |
| Higher-order tests, repetitions, process-isolation attributes | Unsupported | They are rejected before scheduling. |
| Coverage, profiling, snapshots, browser, mutation, architecture, and watch plugins | Unsupported | These need explicit child aggregation or plugin adapters. |
| Windows and macOS snapshot execution | Unsupported | The execution engine requires Linux process semantics. |

Drove does not silently fall back to Pest. Unsupported cases fail with a
diagnostic; use `vendor/bin/pest` explicitly when conventional execution is
required.

## Lifecycle differences to audit

- `beforeAll` prepares a scope snapshot. Mutations made by one test child do not
  return to its parent or siblings.
- Nested `beforeAll` and `afterAll` are valid Drove scope hooks.
- A failed `beforeAll` blocks only its subtree. Initialized ancestors still
  unwind and siblings continue.
- Drove preserves a body failure as primary and reports teardown failures
  separately.
- Result order follows the test plan, not process completion order.
- Global and scope concurrency limits apply before test descendants are forked.

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

The project will not claim broad Pest parity until the published compatibility
matrix and dual-run corpus support it.
