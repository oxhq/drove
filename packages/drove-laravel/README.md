# Drove Laravel

This package is a narrow Linux/macOS Laravel 13 bridge for Drove's
prepared-process runtime. It adds:

- a `php artisan drove -- <arguments>` subprocess command;
- one root Laravel or Orchestra Testbench application and `TestCase` binding;
- four lifecycle callbacks matching Drove dispatch boundaries;
- one MySQL transaction adapter;
- one inherited SQLite `:memory:` adapter; and
- one physical file-copy adapter for a single SQLite database.

## Install

The alpha depends on Drove's inherited Pest plugin loader, so clean consumers
must explicitly trust that Composer plugin before installing:

```bash
composer config --no-plugins allow-plugins.pestphp/pest-plugin true
composer require --dev oxhq/drove-laravel:^0.4@alpha
vendor/bin/drove-install-native
vendor/bin/drove --version
```

Adapter selection is mandatory. Set `DROVE_LARAVEL_STATE=transaction` or
`DROVE_LARAVEL_STATE=sqlite-memory` or
`DROVE_LARAVEL_STATE=sqlite-copy`; there is no implicit database mode.
The selected connection must be Laravel's default connection.
The Artisan command launches the child process with `APP_ENV=testing`.
Direct runtime callers must do the same: boot is rejected unless both the
process environment and Laravel's booted application environment are
`testing`, including when configuration is cached.

Runtime selection defaults to `DROVE_LARAVEL_RUNTIME=auto`: a root with
`bootstrap/app.php` boots as an application, while a package root defers to the
selected Orchestra Testbench case. Use `application` or `testbench` to force
the mode. A Testbench package that ships `bootstrap/app.php` must opt into
`testbench`.

The runtime entrypoint is:

```php
use Drove\Laravel\LaravelRuntime;

$runtime = LaravelRuntime::boot($projectRoot);
$context = $runtime->scopeContext();
```

Native Drove suites declare the application without a Laravel `TestCase`:

```php
use App\Providers\AppServiceProvider;
use Drove\Laravel\DroveLaravelServiceProvider;
use Illuminate\Foundation\Application;

use function Drove\Laravel\laravel;

laravel(
    $projectRoot,
    ['driver' => 'sqlite-memory', 'prepared_schema' => true],
    [
        AppServiceProvider::class,
        DroveLaravelServiceProvider::class,
    ],
    static function (Application $app): void {
        // Migrate or seed the prepared parent state exactly once.
    },
);
```

This path resolves `ApplicationRuntime` directly and does not enter the
PHPUnit/Testbench bridge. State and the application-provider allowlist are
mandatory data; `bootstrap/providers.php` must be the standard literal
`Provider::class` list and is validated without execution before
`bootstrap/app.php`. Native environments require a branchable,
scope-isolated provider, so the transaction adapter remains bridge-only.

Native projects must disable Composer package discovery explicitly:

```json
{
    "extra": {
        "laravel": {
            "dont-discover": ["*"]
        }
    }
}
```

Cached configuration is rejected, including a custom `APP_CONFIG_CACHE` path.
For Laravel's normal declarative bootstrap, package and service manifests from
fixed paths, process environment, or `.env` are not consumed. Drove gives
Laravel fresh, per-boot temporary `APP_PACKAGES_CACHE` and
`APP_SERVICES_CACHE` paths, validates those paths again immediately before
provider registration, and removes both manifests after boot.

`bootstrap/app.php` is trusted construction-only code. Drove cannot undo
arbitrary I/O performed while that file is required. The returned application
must still be unbootstrapped; Drove then verifies its
`bootstrap/providers.php` path and any `withProviders()` additions against the
allowlist before the console kernel or providers boot. Once Laravel has loaded
configuration, Drove also compares `config('app.providers')` against Laravel's
default providers plus the same allowlist immediately before
`RegisterProviders`. `bootstrap/app.php` and PHP configuration files remain
trusted executable project code. The allowlist closes Laravel's declarative
bootstrap, configuration, package, and service-manifest channels; it cannot
undo arbitrary I/O or direct `app()->register()` calls performed by trusted
code. Trusted code can also mutate or resolve cache paths directly and cause
side effects before Drove rejects the changed path; that behavior is outside
the isolation guarantee. The optional prepare closure runs after Laravel boot
and before the database provider captures its prepared state.

Compatibility-bridge integration calls:

```php
$runtime->assertPlanSupported($scopeIr);
$runtime->bindTestCase($testCase, $context); // no-op for non-Laravel cases
$runtime->beforeDispatch($context, $tasks);
$runtime->enterDescendant($context, $task);
$runtime->leaveDescendant($context, $task);
$runtime->afterDispatch($context, $tasks);
```

`leaveDescendant()` and `afterDispatch()` belong in `finally` paths.
`assertPlanSupported()` belongs after Scope IR compilation and before lifecycle
execution; unsupported topology is input rejection rather than a failed scope.

Each provider contributes a core `ResourcePlan` to an `EnvironmentPlan`.
Resource plans identify database, filesystem, cache, queue, or object-storage
resources and declare explicit `Branchable`, `LeafIsolated`, `ScopeIsolated`,
`RollbackIsolated`, `Resettable`, and `SharedReadOnly` capabilities. The
environment also declares whether a multi-resource lifecycle is atomic or
best-effort.

The current Laravel environment has one managed database resource and declares
best-effort coordination because filesystem, cache, queue, and object-storage
are explicitly unmanaged. The SQLite providers are
`Branchable + ScopeIsolated`; the transaction provider is `LeafIsolated`.
Planning and runtime dispatch both reject sibling or mixed scopes when any
managed resource lacks scope isolation. The four unmanaged resource kinds have
no provider in this alpha.

## Deliberate limits

The transaction adapter accepts one resolved MySQL connection in a disposable
test database. Every existing table must use InnoDB; other engines are rejected
before dispatch. It wraps test descendants in a transaction and rolls them back.
Scope hooks are not transaction-isolated, a dispatch containing a scope must
contain only that scope, and DDL, explicit or implicit commits, connection
purges/reconnects, and raw PDO transactions are unsupported. Those operations
can persist writes before Drove detects transaction-depth drift. Test cases using
`RefreshDatabase`, `LazilyRefreshDatabase`, `DatabaseMigrations`,
`DatabaseTransactions`, or `DatabaseTruncation` are rejected recursively
before execution.

The SQLite adapter accepts one absolute, file-backed database in `DELETE`
journal mode. It rejects in-memory or URI databases, attached databases,
WAL/SHM/journal sidecars, open transactions, and already-resolved secondary
connections. Descendant state uses verified PHP `copy()` output. It makes no
reflink claim. `RefreshDatabase` migrates each private copy unless
`DROVE_LARAVEL_SQLITE_PREPARED_SCHEMA=true` declares that the parent file is
already migrated.

The `sqlite-memory` adapter accepts one literal `:memory:` database and keeps
the prepared PDO open across POSIX forks. `RefreshDatabase` is accepted only
with `DROVE_LARAVEL_SQLITE_PREPARED_SCHEMA=true`; without that explicit
contract Drove rejects the suite instead of skipping migrations. Testbench
support is limited to one application profile per selected suite. Testbench
class/method attributes and suites mixing Testbench with Laravel application
cases are rejected before execution.

Orchestra Testbench is optional for Laravel application consumers. Package
suites are tested against `orchestra/testbench:^11.1`; Drove checks the
reflected Testbench application/binding contract before it creates an
application.

All configured databases must be disposable. If test code explicitly resolves
and writes a secondary connection, Drove detects it only during descendant
cleanup, after that external write may already have happened. This late
detection applies to both SQLite modes and the transaction adapter.

For the built-in adapters, state passed to `bootForSuite()` or configured with
the documented environment variables is trait-preflighted before Testbench
calls `createApplication()`. Custom adapters and state configured only inside
the Testbench application are necessarily validated after application boot;
provider bootstrap must therefore be safe to run once in the parent.

Neither adapter manages queue workers, cache, Redis, HTTP clients, sockets, or
other external resources. Multi-database applications are outside this slice.
