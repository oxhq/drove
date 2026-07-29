# Drove corpus ladder

The versioned ladder is deliberately sequential:

| Rung | Selection | Status |
| --- | --- | --- |
| Pest | 143 files, 797 cases | Proven: 726 passed, 71 skipped |
| InvoiceShelf | all `tests/Unit` and `tests/Feature/Customer`, 202 cases | Proven |
| Livewire | first 20 sorted `src/**/*UnitTest.php` files, 288 cases | Proven: baseline and Drove semantics match |
| Filament | all `tests/src/Support`, 677 nonserial plus 28 serial cases | Final pending gate |

Every external checkout is fixed to the commit in `manifest.json`. The
manifest also records the exact Drove revision used for proven results; the
containing commit fixes the selectors, dependency overlay, and proof claims. A
rung becomes `SUPPORTED` only when its complete selection preserves the
recorded baseline semantics. An explicit pre-execution rejection is
`EXPLICITLY_UNSUPPORTED`; any other semantic mismatch is an
`UNEXPECTED_DIVERGENCE`, not an accepted result.

Nucleus is deliberately excluded. Its Docker/MySQL suite is not needed for
this portable ladder, whose final challenge is Filament.

## Select cases

`select.sh` prints stable, newline-delimited paths. Run it from this repository
and pass the corpus checkout as its second argument:

```bash
sh benchmarks/corpus/select.sh pest .
sh benchmarks/corpus/select.sh invoiceshelf "$INVOICESHELF"
sh benchmarks/corpus/select.sh livewire "$LIVEWIRE"
sh benchmarks/corpus/select.sh filament "$FILAMENT"
```

For external corpora the selector also requires the pinned Git `HEAD`, clean
selection roots, every required directory, and the manifest's exact file
count. The common corpus image includes Git so those checks still run across a
bind mount. Pest is the inherited source surface in this fork, so it uses the
exact-count guard and the source-difference boundary recorded in the manifest
instead of requiring the fork itself to have Pest's upstream commit ID.

The Pest selection is intentionally conservative. It excludes undeclared
higher-order, dependency, diagnostic-status, and snapshot surfaces. Eight
additional files are excluded because they deliberately assert mutation
leaking from one sibling case into the next; Drove's prepared-state contract
isolates sibling heap state. Those eight are not unsupported syntax. Until
Drove detects and rejects that dependency, they remain divergences rather than
accepted results.

## Dependency overlay

Run `prepare.sh` from a fresh pinned InvoiceShelf, Livewire, or Filament
checkout:

```bash
cd "$CORPUS"
DROVE_SOURCE="$DROVE" "$DROVE/benchmarks/corpus/prepare.sh" invoiceshelf
```

The script installs Drove through non-symlinked Composer path repositories.
InvoiceShelf and Filament replace their Pest root with Drove and pin the direct
Pest 5/PHPUnit 13 dependencies. Livewire keeps its ordinary PHPUnit tests and
installs Drove for the Testbench run. No selected test file or Testbench
bootstrap is changed.

Direct versions and source commits are fixed, but generated corpus lock files
are not committed. Transitive resolution can drift; commit those locks before
making this calibration ladder a required hosted gate.

## Reproduce the proven rungs

Pest runs from the Drove checkout because the corpus is the inherited Pest
v5.0.1 test surface:

```bash
set -- $(sh benchmarks/corpus/select.sh pest .)
php bin/pest --configuration=benchmarks/corpus/phpunit.pest.xml "$@" --compact
php bin/drove --configuration=benchmarks/corpus/phpunit.pest.xml \
  --parallel --processes=8 "$@"
```

The corpus config deliberately omits the root suite's strict-output policy,
which remains an explicit Drove boundary; both baseline and Drove use the same
config.

For InvoiceShelf, build the Laravel image and use a fresh file-backed SQLite
database:

```bash
docker build -f experiments/phase-3-laravel/Dockerfile \
  -t drove-phase-three-laravel .

set -- $(sh "$DROVE/benchmarks/corpus/select.sh" invoiceshelf "$INVOICESHELF")
docker run --rm --tmpfs /drove-work \
  -v "$INVOICESHELF:/app" \
  -v "$DROVE/benchmarks/corpus/phpunit.laravel.xml:/app/phpunit.drove.xml:ro" \
  drove-phase-three-laravel sh -lc \
  'mkdir -p /drove-work/snapshots &&
   touch /drove-work/database.sqlite &&
   php vendor/bin/drove --configuration=phpunit.drove.xml \
     --parallel --processes=8 "$@"' -- "$@"
```

Run the same explicit paths through `php vendor/bin/pest` on the same uplifted
graph for the baseline.

## Testbench rung

Livewire's baseline is PHPUnit 13 over 20 explicit files: 288 tests, 1034
assertions, and 3 incomplete. The complete selection now preserves the same
statuses and assertion total through Drove. It exercises ordinary PHPUnit
cases, one Testbench application profile, and inherited prepared state without
modifying the selected sources.

Use one named vendor volume so the mirrored dependency overlay is identical for
both commands:

```bash
docker build -f experiments/phase-3-laravel/Dockerfile \
  -t drove-phase-three-laravel .
docker build -f benchmarks/corpus/Dockerfile -t drove-corpus .
docker volume create drove-livewire-vendor

docker run --rm -v "$LIVEWIRE:/app" -v "$DROVE:/drove:ro" \
  -v drove-livewire-vendor:/app/vendor -w /app --entrypoint sh drove-corpus -lc \
  'DROVE_SOURCE=/drove sh /drove/benchmarks/corpus/prepare.sh livewire'

docker run --rm -v "$LIVEWIRE:/app" -v "$DROVE:/drove:ro" \
  -v drove-livewire-vendor:/app/vendor -w /app --entrypoint sh drove-corpus -lc \
  'set -- $(sh /drove/benchmarks/corpus/select.sh livewire /app);
   exec php vendor/bin/phpunit --configuration=phpunit.xml.dist \
     --do-not-cache-result "$@"'

docker run --rm -e APP_ENV=testing -e DB_CONNECTION=testbench \
  -e DB_DATABASE=:memory: -e DROVE_LARAVEL_RUNTIME=testbench \
  -e DROVE_LARAVEL_STATE=sqlite-memory \
  -e DROVE_LARAVEL_DB_CONNECTION=testbench \
  -e DROVE_LARAVEL_SQLITE_PREPARED_SCHEMA=true \
  -v "$LIVEWIRE:/app" -v "$DROVE:/drove:ro" \
  -v drove-livewire-vendor:/app/vendor -w /app --entrypoint sh drove-corpus -lc \
  'set -- $(sh /drove/benchmarks/corpus/select.sh livewire /app);
   exec php vendor/bin/drove --parallel --processes=1 "$@"'
```

## Final pending rung

Filament is last. Apply the pinned dependency overlay, build the common corpus
image, and prepare one migrated SQLite master with
`vendor/bin/testbench migrate:fresh`. Copy that master into a fresh
`/drove-work/database.sqlite` (plus an empty `/drove-work/snapshots`) before
each command below:

```bash
DROVE_SOURCE="$DROVE" "$DROVE/benchmarks/corpus/prepare.sh" filament
docker build -f benchmarks/corpus/Dockerfile -t drove-corpus .

# Each directory below is a separate fresh copy of the migrated master.
BASELINE_NONSERIAL=/absolute/artifacts/baseline-nonserial
BASELINE_SERIAL=/absolute/artifacts/baseline-serial
DROVE_NONSERIAL=/absolute/artifacts/drove-nonserial
DROVE_SERIAL=/absolute/artifacts/drove-serial

docker run --rm -v "$FILAMENT:/app" -v "$DROVE:/drove:ro" \
  -v "$DROVE/benchmarks/corpus/phpunit.laravel.xml:/app/phpunit.drove.xml:ro" \
  -v "$BASELINE_NONSERIAL:/drove-work" -w /app --entrypoint sh drove-corpus -lc \
  'set -- $(sh /drove/benchmarks/corpus/select.sh filament /app);
   exec php vendor/bin/pest --configuration=phpunit.drove.xml --exclude-group=serial "$@"'

docker run --rm -v "$FILAMENT:/app" -v "$DROVE:/drove:ro" \
  -v "$DROVE/benchmarks/corpus/phpunit.laravel.xml:/app/phpunit.drove.xml:ro" \
  -v "$BASELINE_SERIAL:/drove-work" -w /app --entrypoint sh drove-corpus -lc \
  'set -- $(sh /drove/benchmarks/corpus/select.sh filament /app);
   exec php vendor/bin/pest --configuration=phpunit.drove.xml --group=serial "$@"'

docker run --rm -e DROVE_LARAVEL_RUNTIME=testbench -e DROVE_LARAVEL_SQLITE_PREPARED_SCHEMA=true \
  -v "$FILAMENT:/app" -v "$DROVE:/drove:ro" \
  -v "$DROVE/benchmarks/corpus/phpunit.laravel.xml:/app/phpunit.drove.xml:ro" \
  -v "$DROVE_NONSERIAL:/drove-work" -w /app --entrypoint sh drove-corpus -lc \
  'set -- $(sh /drove/benchmarks/corpus/select.sh filament /app);
   exec php vendor/bin/drove --configuration=phpunit.drove.xml --parallel \
     --processes=8 --exclude-group=serial "$@"'

docker run --rm -e DROVE_LARAVEL_RUNTIME=testbench -e DROVE_LARAVEL_SQLITE_PREPARED_SCHEMA=true \
  -v "$FILAMENT:/app" -v "$DROVE:/drove:ro" \
  -v "$DROVE/benchmarks/corpus/phpunit.laravel.xml:/app/phpunit.drove.xml:ro" \
  -v "$DROVE_SERIAL:/drove-work" -w /app --entrypoint sh drove-corpus -lc \
  'set -- $(sh /drove/benchmarks/corpus/select.sh filament /app);
   exec php vendor/bin/drove --configuration=phpunit.drove.xml --parallel \
     --processes=1 --group=serial "$@"'
```

The nonserial baseline is 677 passing cases and 1016 assertions. The serial
baseline is 28 passing cases and 34 assertions. Filament remains pending until
all 705 cases execute through Drove with the same semantics; partial folders
or bypassed guards do not satisfy this gate.

These are correctness results, not speed claims. Shared-host wall times and
Docker bind-mount runs are not a publishable performance methodology.
