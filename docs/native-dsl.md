# Native Drove DSL

The native DSL is Drove's product surface. It lowers declarations directly to
Scope IR and does not create Pest or PHPUnit test cases.

## Run

Import the namespaced functions in a PHP test file:

```php
<?php

use function Drove\Native\beforeEach;
use function Drove\Native\dataset;
use function Drove\Native\expect;
use function Drove\Native\test;

beforeEach(function (): void {
    $this->answer = 42;
});

dataset('answers', [
    'integer' => [42],
    'string' => ['42'],
]);

test('has the expected answer', function (int|string $expected): void {
    expect($this->answer)->toEqual($expected);
    $this->defer(static function (): void {
        // Runs once for this case, in LIFO order.
    });
})->with('answers')->group('fast')->timeout(500);
```

Run explicit files or directories:

```bash
vendor/bin/drove --processes=8 tests
```

Native execution accepts only the concurrency values proven by the conformance
matrix: `1`, `2`, `4`, `8`, `16`, and `30`.
Execution uses Drove's Rust-backed Drover scheduler so each selected runnable
case terminates through the native `_exit` boundary instead of PHP request
shutdown.

## Supported declarations

- `test()`, `it()`, and nested `describe()`;
- `beforeAll()`, `beforeEach()`, `afterEach()`, and `afterAll()`;
- `$this->defer(Closure)` for per-case LIFO cleanup;
- inline `->with(iterable)` and named `dataset()` values;
- `->group()`, `->skip()`, `->todo()`, and `->timeout()`.

One optional `environment()` declaration may return a Drove
`EnvironmentRuntime`. Its zero-argument factory resolves once after planning,
before dispatch. Tests access the prepared application through
`$this->app()`. Laravel projects should use the typed `Drove\Laravel\laravel()`
frontend documented in
[`packages/drove-laravel`](../packages/drove-laravel/README.md).

Dataset cases receive stable suffixes:

- integer key: `::dataset:index:N`;
- string key: `::dataset:name:<urlencoded-key>`.

Duplicate case IDs, empty datasets, missing named datasets, and argument-count
mismatches fail during planning before any test body runs.

## Selection

```bash
vendor/bin/drove \
  --filter="invoice total" \
  --group=fast \
  --group=database \
  --exclude-group=network \
  tests
```

`--filter` is a case-sensitive name substring. Repeated include groups use
any-match semantics. Any excluded group wins over an included group.

Skipped and todo cases remain explicit results and their bodies do not run.
Each selected runnable case receives its own executor process; Drove does not
batch cases in one mutable executor.

## Expectations and output

The initial built-in catalog is deliberately small:

- `expect($actual)->toBe($expected)` for strict identity;
- `expect($actual)->toEqual($expected)` for loose equality.

Both increment the case assertion count. Typed extensions register matchers
through the Drove extension API; a test invokes them through
`$this->assertWith($extensionId, $matcher, $actual, ...$arguments)`.

Standard output is captured per case and returned as structured `stdout` data.
Source path, source line, dataset identity, groups, status,
assertion count, failures, and cleanup events remain attached to the result.
Direct `STDERR` capture is not part of the Phase 3 surface.

Installed extensions are discovered from Composer metadata before declaration
capture. Project configuration maps extension package IDs to their typed
configuration in `composer.json`:

```json
{
  "extra": {
    "drove": {
      "extensions": {
        "vendor/extension": {
          "option": "value"
        }
      }
    }
  }
}
```

Extension boolean options use a bare `--flag`; string and integer options use
`--name=value`. Installed options appear under `Extension options` in native
`--help`. Successful CLI contribution output and reporter output are rendered
in separate, owner-labelled sections. They remain presentation data and do not
participate in test-status or assertion aggregation.

## Unsupported surface

[`supported-surface.json`](../src/Drove/Native/Surface/supported-surface.json)
is the machine-readable authority. Native source is scanned before declaration
capture. Known unsupported constructs such as dependencies, repetitions,
snapshots, architecture tests, `uses()`, and higher-order chains produce stable
`DROVE_NATIVE_*` diagnostics instead of falling through to Pest behavior.
Bare Pest-style functions and functions imported from `Pest` are rejected at
the same boundary; native files must import the corresponding
`Drove\Native` functions explicitly.

The current support boundary is intentionally narrower than Pest. Additions
must first define lifecycle and isolation semantics, then pass the same
conformance matrix at every declared process count.

The [native Drove roadmap](native-drove-roadmap.md) records the remaining
runtime, Laravel, bridge, performance, packaging, and release gates.
