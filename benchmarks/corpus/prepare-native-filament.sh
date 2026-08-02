#!/bin/sh
set -eu

drove_source=${DROVE_SOURCE:-}
expected_commit=e9348b2e3792088ee877068116b6c1e1559a7df8

if [ "${CORPUS_DISPOSABLE:-0}" != 1 ]; then
    echo "CORPUS_DISPOSABLE=1 is required because this preparation overlays Composer files." >&2
    exit 2
fi

if [ -z "$drove_source" ] || [ ! -f "$drove_source/composer.json" ]; then
    echo "DROVE_SOURCE must point to a Drove checkout." >&2
    exit 2
fi

if [ "$(git rev-parse HEAD)" != "$expected_commit" ]; then
    echo "native Filament preparation requires commit $expected_commit" >&2
    exit 2
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "Filament checkout must be clean before applying the native dependency overlay." >&2
    exit 2
fi

selection=$(mktemp)
trap 'rm -f -- "$selection"' EXIT HUP INT TERM
sh "$drove_source/benchmarks/corpus/select.sh" filament . >"$selection"

if [ "$(wc -l <"$selection" | tr -d '[:space:]')" != 39 ]; then
    echo "native Filament preparation did not select exactly 39 files." >&2
    exit 2
fi

cp "$drove_source/benchmarks/corpus/locks/filament-native.composer.json" composer.json
cp "$drove_source/benchmarks/corpus/locks/filament-native.lock" composer.lock

composer validate --no-check-publish --no-interaction >&2
php -d auto_prepend_file= \
    "$drove_source/benchmarks/corpus/verify-native-composer-environment.php" \
    filament "$(pwd)" --lock-only >&2

if [ "${CORPUS_VALIDATE_LOCK_ONLY:-0}" = 1 ]; then
    exit 0
fi

composer install --quiet --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader >&2

if [ -n "$(git status --porcelain -- packages tests)" ]; then
    echo "Filament package or selected test sources changed during native installation." >&2
    exit 1
fi

php -d auto_prepend_file= \
    "$drove_source/benchmarks/corpus/verify-native-composer-environment.php" \
    filament "$(pwd)"
