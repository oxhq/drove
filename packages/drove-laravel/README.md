# Drove Laravel

This package is a narrow Laravel 13 bridge for Drove's prepared-process
runtime. It adds:

- a `php artisan drove -- <arguments>` subprocess command;
- one root Laravel or Orchestra Testbench application and `TestCase` binding;
- four lifecycle callbacks matching Drove dispatch boundaries;
- one MySQL transaction adapter;
- one inherited SQLite `:memory:` adapter; and
- one physical file-copy adapter for a single SQLite database.

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

Core integration calls:

```php
$runtime->bindTestCase($testCase, $context); // no-op for non-Laravel cases
$runtime->beforeDispatch($context, $tasks);
$runtime->enterDescendant($context, $task);
$runtime->leaveDescendant($context, $task);
$runtime->afterDispatch($context, $tasks);
```

`leaveDescendant()` and `afterDispatch()` belong in `finally` paths.

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
the prepared PDO open across Linux forks. `RefreshDatabase` is accepted only
with `DROVE_LARAVEL_SQLITE_PREPARED_SCHEMA=true`; without that explicit
contract Drove rejects the suite instead of skipping migrations. Testbench
support is limited to one application profile per selected suite. Testbench
class/method attributes and suites mixing Testbench with Laravel application
cases are rejected before execution.

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
