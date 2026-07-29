# Drove Laravel

This package is a narrow Laravel 13 bridge for Drove's prepared-process
runtime. It adds:

- a `php artisan drove -- <arguments>` subprocess command;
- one root Laravel application and `TestCase` binding;
- four lifecycle callbacks matching Drove dispatch boundaries;
- one MySQL transaction adapter; and
- one physical file-copy adapter for a single SQLite database.

Adapter selection is mandatory. Set `DROVE_LARAVEL_STATE=transaction` or
`DROVE_LARAVEL_STATE=sqlite-copy`; there is no implicit database mode.
The Artisan command launches the child process with `APP_ENV=testing`.
Direct runtime callers must do the same: boot is rejected unless both the
process environment and Laravel's booted application environment are
`testing`, including when configuration is cached.

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
`RefreshDatabase`, `LazilyRefreshDatabase`, `DatabaseMigrations`, or
`DatabaseTruncation` are rejected recursively before execution;
`DatabaseTransactions` remains supported on the selected connection.

The SQLite adapter accepts one absolute, file-backed database in `DELETE`
journal mode. It rejects in-memory or URI databases, attached databases,
WAL/SHM/journal sidecars, open transactions, and secondary resolved
connections. Descendant state uses verified PHP `copy()` output. It makes no
reflink claim.

Neither adapter manages queue workers, cache, Redis, HTTP clients, sockets, or
other external resources. Multi-database applications are outside this slice.
