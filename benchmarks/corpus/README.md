# Drove Laravel corpus

This directory records the Phase 3 compatibility corpus. Every source checkout
is pinned in `manifest.json`; the containing Drove commit pins the runner.
Corpus source and credentials are never vendored here.

Results use three classifications:

- `SUPPORTED`: the selected Pest cases must preserve pass/fail semantics;
- `EXPLICITLY_UNSUPPORTED`: Drove must reject before a selected test body runs
  or persistent application state is mutated; and
- `UNEXPECTED_DIVERGENCE`: a Drove bug, never an accepted result.

## Dependency overlay

Run `prepare.sh` inside each pinned disposable checkout. It uses path
repositories for this repository and `packages/drove-laravel`, with symlinks
disabled and these development versions:

```json
{
  "oxhq/drove": "0.1.x-dev",
  "oxhq/drove-laravel": "1.0.x-dev"
}
```

The script replaces the checkout's Pest 4 root requirement with `oxhq/drove`,
pins Laravel 13.23.0, the relevant Pest plugins to 5.0.0, and PHPUnit 13.2.4,
then updates only that dependency set and its dependencies. InvoiceShelf also
pins the Faker plugin to 5.0.0. Larastreamers sets Composer's PHP platform to
8.4.1. No selected test file is changed. Filament needs the committed provider
patch because its Testbench bootstrap deliberately disables package discovery.

The direct uplift and source revisions are fixed, but generated corpus lock
files are not committed here. Transitive resolution can therefore drift; add
those locks when this calibration corpus becomes a required CI gate.

## Reproduction

Build the committed test image:

```bash
docker build -f experiments/phase-3-laravel/Dockerfile \
  -t drove-phase-three-laravel .
```

Clone the exact corpus revision, apply the dependency overlay above, then
mount `phpunit.laravel.xml` as `/app/phpunit.drove.xml`. A supported run uses a
fresh, file-backed database and workspace:

```bash
cd "$CORPUS"
DROVE_SOURCE="$DROVE" "$DROVE/benchmarks/corpus/prepare.sh" invoiceshelf

docker run --rm --tmpfs /drove-work \
  -v "$CORPUS:/app" \
  -v "$DROVE/benchmarks/corpus/phpunit.laravel.xml:/app/phpunit.drove.xml:ro" \
  drove-phase-three-laravel sh -lc \
  'mkdir -p /drove-work/snapshots &&
   touch /drove-work/database.sqlite &&
   php vendor/bin/drove --configuration=phpunit.drove.xml \
     --parallel --processes=2 TEST_PATHS'
```

Run the same paths through `php vendor/bin/pest` with the corpus's original
PHPUnit XML for the conventional baseline. The committed manifest contains the
exact selections and observed counts.

Filament is a negative safety gate. Its TestCase extends Orchestra Testbench,
not Laravel's foundation TestCase. Drove must emit the declared diagnostic for
all eight selected cases while leaving the original database hash, size, and
copy workspace unchanged. Build its additional `intl`/`sockets` image with:

```bash
docker build -f benchmarks/corpus/Dockerfile.filament \
  -t drove-filament-smoke .
```

These are correctness results, not speed claims. The dependency uplift and
Docker bind mounts are controlled for semantics but are not a publishable
performance methodology. The recorded 1/2/4/8 wall times are single-run
scheduler calibration samples; they must not be compared to the conventional
baseline as a product claim. A separate repository is unnecessary until the
corpus has an independently versioned harness and representative timing gate.
