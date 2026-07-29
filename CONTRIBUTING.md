# Contributing to Drove

Drove accepts focused pull requests against `develop`.

## Setup

Drove requires PHP 8.4+, Composer 2, Rust, FFI, PCNTL, POSIX, and a Unix
process model. Windows is supported as a development host through Docker, not
as an execution target.

```bash
composer install
cargo test --manifest-path native/drover/Cargo.toml --locked -- --test-threads=1
```

## Checks

Run the narrowest relevant check first, then the full gate for changes that
touch shared execution behavior:

```bash
composer validate
composer test:lint
composer test:type:check
composer test:unit
composer test:parallel
composer test:integration

docker build --tag drove-phase-one --file experiments/phase-1/Dockerfile .
docker run --rm drove-phase-one
```

The Docker phase gates are the reproducible path for native and fork-sensitive
behavior. External corpus results have a higher integration cost and are
documented under `benchmarks/corpus`.

## Pull requests

Describe:

- the behavior or compatibility boundary changed;
- whether Pest, PHPUnit, Laravel, Testbench, or the native ABI is affected;
- the exact checks that passed;
- any unsupported case that remains.

Do not present local checks as hosted, corpus, consumer, or release proof.
