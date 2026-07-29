# Drove Phase 3 Laravel package proofs

This Docker-only proof installs both the Drove source checkout and
`oxhq/drove-laravel` into a minimal Laravel 13 application. The default
SQLite proof invokes the public package command:

```bash
php artisan drove -- tests/Feature/PreparedApplicationTest.php --parallel --processes=2
```

The proof requires:

- rejection before boot when the process environment is not `testing`;
- rejection after boot when cached Laravel configuration says `production`;
- a clean paired `afterDispatch` after a failed SQLite `beforeDispatch`;
- exactly one Laravel bootstrap PID;
- an inherited root `Application` bound to both generated Pest cases;
- one prepared SQLite row visible to both descendants;
- isolated alpha and beta writes in verified file copies;
- no child rows in the prepared scope after both tests;
- no schema mutation in the original root database; and
- no remaining Drove SQLite copy artifacts.

Run from the repository root:

```bash
docker build -f experiments/phase-3-laravel/Dockerfile -t drove-phase-three-laravel .
docker run --rm drove-phase-three-laravel
```

This proves one file-backed SQLite path only. It does not prove queues, WAL,
reflinks, multiple databases, Redis, HTTP resources, or production readiness.

## MySQL transaction proof

`proof-mysql.php` exercises the transaction adapter against a real MySQL
server. `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`
must be supplied by the caller. The proof creates and commits one prepared row
in file `beforeAll`, confirms two distinct transactional children each run at
transaction depth one and see only their own write, confirms `afterAll` sees
only the prepared row, confirms paired cleanup after a failed transaction
`beforeDispatch`, rejects Laravel's migration/truncation traits while allowing
`DatabaseTransactions`, and drops the proof table.

For an image named `drove-phase-three-laravel`, run:

```bash
docker run --rm \
  --network your-mysql-network \
  -e DB_HOST=mysql \
  -e DB_PORT=3306 \
  -e DB_DATABASE=drove \
  -e DB_USERNAME=drove \
  -e DB_PASSWORD=secret \
  --entrypoint php \
  drove-phase-three-laravel \
  proof-mysql.php
```

This proof is limited to one resolved MySQL connection in a disposable
database. It does not claim DDL, commits, reconnects, or raw PDO transactions
are rollback-safe, nor does it manage queues, cache, Redis, HTTP resources, or
any secondary database.
