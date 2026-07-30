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
docker run --rm drove-phase-three-laravel php proof-state-capabilities.php
docker run --rm drove-phase-three-laravel php proof-native-runtime.php
docker run --rm drove-phase-three-laravel php proof-testbench-guards.php
docker run --rm drove-phase-three-laravel php proof-testbench.php
docker run --rm drove-phase-three-laravel php proof-data-provider.php
```

The native proof uses an inherited SQLite `:memory:` connection without
loading PHPUnit or Testbench. It proves state, package-discovery, provider,
prepare-signature, fixed and custom config-cache, and hidden-bootstrap guards.
It treats `bootstrap/app.php` and `config/*.php` as trusted executable project
code, verifies declarative provider configuration before provider bootstrap,
rejects an unapproved `config/app.php` provider after configuration load but
before provider registration, invokes the prepare closure exactly once before
the selected provider captures state, and boots twice in separate PHP
processes so fresh isolated package and service manifests cannot poison a
later run. The
proof also keeps malicious fixed and custom cache sentinels cold, verifies
their hashes remain unchanged, and leaves no temporary manifest behind. A
separate-process proof repeats those checks for cache paths declared through
`.env`. Direct cache-path mutation or manifest resolution from trusted
`bootstrap/app.php` or `config/*.php` code is deliberately outside this claim.

The default bridge proof covers one file-backed SQLite default connection only. The configured
selected connection must equal Laravel's default. It does not prove queues,
WAL, reflinks, multiple databases, Redis, HTTP resources, or production
readiness. Explicit secondary writes may happen before cleanup rejects the
newly resolved connection, so every configured database must be disposable.

The three focused commands prove the Testbench profile and attribute guards, a
single prepared Testbench application over inherited in-memory SQLite, the
required `prepared_schema=true` contract for `RefreshDatabase`, and application
data-provider expansion after Laravel boot. `DROVE_LARAVEL_RUNTIME=auto`
selects a normal application when `bootstrap/app.php` exists and otherwise
defers to Testbench; `application` and `testbench` force either mode.

## MySQL transaction proof

`proof-mysql.php` exercises the transaction adapter against a real MySQL
server. `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`
must be supplied by the caller. The proof creates and commits one prepared row
in file `beforeAll`, confirms two distinct transactional children each run at
transaction depth one and see only their own write, confirms `afterAll` sees
only the prepared row, confirms paired cleanup after a failed transaction
`beforeDispatch`, rejects Laravel's migration/transaction/truncation traits,
and drops the proof table.

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
