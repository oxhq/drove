# Migrating from Pest to the Drove v0.4 alpha

Drove keeps a deliberately small Pest surface while Drove owns scope planning,
scope lifecycle, native scheduling, result aggregation, and rendering. The
technical evaluation [GitHub release](https://github.com/oxhq/drove/releases/tag/v0.4.0-alpha.2),
[Packagist package](https://packagist.org/packages/oxhq/drove), and
[hosted workflows](https://github.com/oxhq/drove/actions) are the live
authorities for the exact `v0.4.0-alpha.2` artifacts.
[Native release #30588903021](https://github.com/oxhq/drove/actions/runs/30588903021)
published the four native targets, and
[Published package #30589230018](https://github.com/oxhq/drove/actions/runs/30589230018)
resolved both tagged packages from Packagist on all four targets. This release
intentionally precedes external validation; evidence collected with it gates
candidate `v0.4.0-alpha.3`.

## Install

```bash
composer require --dev oxhq/drove:^0.4@alpha
vendor/bin/drove-install-native
vendor/bin/drove --version
vendor/bin/drove --compatibility
```

This native installation neither installs nor trusts Pest's Composer plugin.

To run unchanged Pest/PHPUnit sources through the explicit bridge:

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

The bridge checks this compatible dependency set before loading Pest code.

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
git clone --branch v0.4.0-alpha.2 --depth 1 \
  https://github.com/oxhq/drove.git
cd drove
composer install
cargo build --manifest-path native/drover/Cargo.toml --release --locked

# Linux:
export DROVER_LIBRARY="$PWD/native/drover/target/release/libdrover.so"

# macOS:
# export DROVER_LIBRARY="$PWD/native/drover/target/release/libdrover.dylib"

php bin/drove --pest
php bin/drove --pest --parallel --processes=8
php bin/drove --pest --filter=Invoice
php bin/drove --pest --group=slow
php bin/drove --pest --exclude-group=integration
php bin/drove --pest --testsuite=Feature
```

When this checkout is installed into a disposable project through a Composer
path repository, invoke `vendor/bin/drove --pest` and point `DROVER_LIBRARY` at
the library built from this checkout. The package is named `oxhq/drove` and
declares that it replaces Pest 5.0.1 for plugin compatibility.

### Compare against an honest baseline

`oxhq/drove` replaces `pestphp/pest` in Composer, and Drove's packaged
`vendor/bin/pest` is a compatibility alias. It is not an independent Pest
baseline. Run Pest and Drove from separate clean worktrees or containers bound
to the same project revision and selected case IDs. Preserve the original
Pest `composer.json` and lock for the baseline; install Drove only in the
evaluation checkout. A semantic or assertion difference is a compatibility
bug.

### Troubleshoot an installation

```bash
composer show oxhq/drove
php -r 'foreach (["ffi", "pcntl", "posix", "openssl", "Phar", "zlib"] as $extension) { printf("%s=%s\n", $extension, extension_loaded($extension) ? "yes" : "no"); }'
php -r 'printf("ffi.enable=%s\n", ini_get("ffi.enable"));'
vendor/bin/drove --version
vendor/bin/drove --compatibility
```

FFI must be enabled for the CLI that runs Drove. GNU/Linux must use glibc
2.31+; Windows and musl are unsupported. If `DROVER_LIBRARY` is set, verify
that it names the library built for the current OS and architecture, or unset
it and rerun `vendor/bin/drove-install-native`. Preserve a failing run with
`--replay=/private/path/run.json`; replay artifacts omit test output and use
private permissions, but should still be reviewed before sharing.

### Roll back to Pest

The safest rollback is restoring the pre-evaluation Composer files from the
project's own version control and reinstalling them:

```bash
git restore -- composer.json composer.lock
composer install
vendor/bin/pest --version
```

Alternatively, remove `oxhq/drove-laravel` when installed, remove
`oxhq/drove`, and require the project's intended Pest version with dependency
updates. Remove Drove-only CI commands, `DROVE_*` variables, and native
extension manifests. `drove-install-native` writes only inside the installed
Drove package, so removing that package removes its downloaded library; unset
an external `DROVER_LIBRARY` separately.

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
| PHPUnit coverage reports | Beta bridge-only | `drove --pest` merges fork-local fragments before PHPUnit generates reports. Only PCOV 1.0.12 line coverage with `--coverage-php` is release-gated. |
| PHPUnit strict global state and strict coverage modes | Unsupported | Strict modes and conflicting coverage metadata are rejected before execution. |
| PHPUnit `disallowTestOutput` / `--disallow-test-output` | Compatible | Unexpected output marks an otherwise successful case risky; expected output is accepted and `failOnRisky` preserves the exit policy. |
| Profiling, alternate printers, mutation, browser, and watch modes | Unsupported | Recognized CLI modes exit 2. |
| Legacy Pest-bridge plugin observers | Alpha bridge-only | `Bootable`, `InspectsPlan`, and `ReportsRun` observe immutable inputs for packages discovered by the inherited Pest plugin loader. They are not Drove's native extension API. |
| Typed native extensions | Alpha | `Drove\Extension` manifests and typed contributions are the native front door; extensions cannot intercept raw runner internals. |
| Environment planning | Alpha | Providers declare resource kinds, capabilities, and atomic or best-effort coordination. Only the three Laravel database providers are implemented. |
| GNU/Linux | Alpha | Native release archives target glibc 2.31+ on x86_64/aarch64. musl is unsupported. |
| macOS | Beta | Native release archives target x86_64/aarch64; verify the exact tag's hosted run and checksums. |
| Windows | Unsupported | Drove requires FFI, fork, PCNTL, POSIX process groups, and a Unix native library. |

Higher-order tests, repetitions, architecture tests, snapshots, and third-party
plugins are not part of the declared alpha surface. Detection is not yet
exhaustive, so the dual-run comparison remains required.

## Phase 6 bridge and migration boundary

The compatibility output now embeds `surface_registry`, a versioned data-only
registry whose public status vocabulary is exactly `supported`, `unsupported`,
or `bridge-only`. Maturity labels in the older release registry remain release
metadata; they are not semantic compatibility guarantees.

Pest, PHPUnit, and Orchestra Testbench have separate, explicitly loaded bridge
entrypoints. Loading an entrypoint checks identity and Scope IR schema without
registering hooks or activating a compiler. Pest and PHPUnit are frontend
bridges over the existing `ScopeCompiler` and `TestCaseRuntime`; Testbench is
an optional environment bridge in `oxhq/drove-laravel`. These adapters do not
own kernel lifecycle or scheduling.

The migration API scans source with PHP tokens and never executes the file:

```php
use Drove\Bridge\CompatibilityRegistry;
use Drove\Migration\CodemodOptions;
use Drove\Migration\Migrator;
use Drove\Migration\Scanner;

$scanner = new Scanner(CompatibilityRegistry::load());
$migrator = new Migrator($scanner);
$result = $migrator->migrate(
    file_get_contents('tests/Feature/InvoiceTest.php'),
    'tests/Feature/InvoiceTest.php',
    new CodemodOptions(
        environments: [
            'Tests\\TestCase' => [
                'name' => 'laravel',
                'factory' => 'static fn () => NativeLaravel::environment()',
                'declaration_path' => 'tests/Feature/InvoiceTest.php',
            ],
        ],
        matchers: [
            'toBeUuid' => ['owner' => 'acme.uuid', 'matcher' => 'uuid'],
        ],
    ),
);
```

Portable unqualified or explicitly `Pest`-qualified declarations, lifecycle
hooks, datasets, and `toBe`/`toEqual` expectations are qualified to
`Drove\Native`. A bare `uses(TestCase::class)` changes only when the caller
provides one exact suite-environment factory and the one source path that owns
the declaration. Class imports and `as` aliases are resolved before that exact
mapping is selected. A custom expectation call changes only when its method has
an explicit typed-extension owner and matcher key.

Those constraints are intentional:

- local function declarations and function imports are ambiguous and are not
  rewritten;
- files with multiple namespace declarations keep `uses()` bridge-only because
  class imports cannot be resolved safely without a position-aware import map;
- `uses()->in()` is directory-scoped and remains bridge-only because a native
  environment is suite-wide;
- `expect()->extend()` definitions remain bridge-only and must become typed
  matcher entrypoints before their mapped call sites are usable;
- unsupported or later chained modifiers prevent partial conversion of the
  owning call; and
- applying the same codemod twice produces the same bytes and no second edit.

The deterministic local proof is:

```bash
php experiments/native-phase-6-bridges/proof.php
```

It loads all three entrypoints, lowers one ordinary PHPUnit case through Scope
IR, checks installed and missing Testbench dependency states, reruns the native
dependency guard after bridge loading, and proves migration idempotence plus
stable blockers. It is a bridge/migration proof, not the external corpus exit
gate; the full Phase 6 gate still ends with the pinned Filament run.

## Pest/PHPUnit bridge coverage

With the Pest/PHPUnit bridge selected, Drove starts PHPUnit's configured
coverage driver in each selected test child, serializes one fragment per case,
merges the fragments in the root process, and then delegates report generation
to PHPUnit. Use `--pest` with the ordinary PHPUnit report options:

```bash
vendor/bin/drove --pest --coverage-text
vendor/bin/drove --pest --coverage-clover=build/coverage.xml
vendor/bin/drove --pest --parallel --processes=8 --coverage-html=build/coverage
```

Only PCOV 1.0.12 line coverage with PHPUnit's `--coverage-php` artifact is
release-gated at C1, C2, C4, C8, C16, and C30. The other bridge report formats
and Xdebug remain experimental. The bare `--coverage` switch, strict coverage
metadata/contribution modes, and conflicting coverage declarations are
rejected rather than approximated.

The native frontend has no coverage implementation or declared coverage
surface. It rejects PHPUnit/Pest coverage flags as unknown native options; the
bridge gate does not prove native coverage.

## Deadlines, interruption, and diagnostic replay

No timeout is implicit. `--drove-timeout-ms=30000` applies a 30-second default
to every selected test. A timeout terminates the test process group and is
reported as a timeout rather than an assertion failure. SIGINT and SIGTERM
stop new scheduling and are classified separately from a child crash.

Use a replay artifact when a failure is hard to reproduce:

```bash
vendor/bin/drove --pest --parallel --processes=8 \
  --replay-on-failure=build/drove-replay.json
```

The destination directory must already exist, and Drove never overwrites an
existing artifact. The JSON records a plan hash, counts, failure kinds,
completion order, concurrency, sampled PHP memory, platform, redacted
arguments, and a whitelist-only projection of environment providers and
capabilities. It omits outputs, values, environment variables, failure
messages, and unknown environment fields. Inspect it before sharing because
paths and non-secret command arguments remain visible. PHP memory is the
maximum of the root and available descendant process peaks, not aggregate RSS.
This is diagnostic metadata, not an executable replay file.

## Native extensions, legacy bridge observers, and registry

`vendor/bin/drove --compatibility` prints the package's versioned compatibility
registry. Native integrations enter through `Drove\Extension`: discovery reads
data-only manifests before loading code, negotiates an API version, then
registers typed matcher, context, planner, resource-provider, reporter, or CLI
contributions. The API does not expose raw `argv`, lifecycle interception,
autoload callbacks, renderer mutation, or exit-policy control.

For migration only, a Composer plugin already discoverable through the
inherited Pest plugin loader may opt into these legacy bridge contracts:

- `Drove\Contracts\Plugins\Bootable::bootDrove()`;
- `Drove\Contracts\Plugins\InspectsPlan::inspectDrovePlan()`; and
- `Drove\Contracts\Plugins\ReportsRun::reportDroveRun()`.

All three are observer-only alpha hooks. They belong to
`Drove\Plugins\Manager`, the legacy Pest compatibility bridge, not the native
plugin API. Arguments and arrays are passed for inspection; changes do not
alter Drove's plan, run, renderer, or exit code. An observer exception fails
the run with exit 1 and is recorded as crash metadata when replay is enabled.
Plugins that intercept Pest/PHPUnit CLI internals remain unsupported unless
the compatibility registry explicitly lists them.

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
