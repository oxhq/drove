#!/bin/sh
set -eu

drove_source=${DROVE_SOURCE:-}

if [ -z "$drove_source" ] || [ ! -f "$drove_source/composer.json" ]; then
    echo "DROVE_SOURCE must point to a Drove checkout." >&2
    exit 2
fi

manifest="$drove_source/benchmarks/corpus/locks/pest-native.composer.json"
lock="$drove_source/benchmarks/corpus/locks/pest-native.lock"

if [ ! -f "$manifest" ] || [ ! -f "$lock" ]; then
    echo "The locked native Pest dependency overlay is incomplete." >&2
    exit 2
fi

for file in composer.json composer.lock
do
    source=$manifest
    if [ "$file" = composer.lock ]; then
        source=$lock
    fi

    if [ -e "$file" ] && ! cmp -s "$source" "$file"; then
        echo "Refusing to overwrite divergent $file in the native Pest dependency workspace." >&2
        exit 2
    fi

    cp "$source" "$file"
done

composer validate --no-check-publish --no-interaction >&2

if [ "${CORPUS_VALIDATE_LOCK_ONLY:-0}" = 1 ]; then
    php -d auto_prepend_file= \
        "$drove_source/benchmarks/corpus/verify-native-pest-environment.php" \
        "$(pwd)" --lock-only
    exit 0
fi

php -d auto_prepend_file= \
    "$drove_source/benchmarks/corpus/verify-native-pest-environment.php" \
    "$(pwd)" --lock-only >&2
composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader >&2
php -d auto_prepend_file= \
    "$drove_source/benchmarks/corpus/verify-native-pest-environment.php" \
    "$(pwd)"
