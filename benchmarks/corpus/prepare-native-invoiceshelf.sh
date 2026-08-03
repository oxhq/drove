#!/bin/sh
set -eu

drove_source=${DROVE_SOURCE:-}
expected_commit=403a4d67225a153838ec126c484339abf60229d1

if [ "${CORPUS_DISPOSABLE:-0}" != 1 ]; then
    echo "CORPUS_DISPOSABLE=1 is required because this preparation overlays Composer files." >&2
    exit 2
fi

if [ -z "$drove_source" ] || [ ! -f "$drove_source/composer.json" ]; then
    echo "DROVE_SOURCE must point to a Drove checkout." >&2
    exit 2
fi

if [ "$(git rev-parse HEAD)" != "$expected_commit" ]; then
    echo "native InvoiceShelf preparation requires commit $expected_commit" >&2
    exit 2
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "InvoiceShelf checkout must be clean before applying the native dependency overlay." >&2
    exit 2
fi

sh "$drove_source/benchmarks/corpus/select.sh" invoiceshelf . >/dev/null

cp "$drove_source/benchmarks/corpus/locks/invoiceshelf-native.composer.json" composer.json
cp "$drove_source/benchmarks/corpus/locks/invoiceshelf-native.lock" composer.lock

composer validate --no-check-publish --no-interaction
php -d auto_prepend_file= \
    "$drove_source/benchmarks/corpus/verify-native-composer-environment.php" \
    invoiceshelf "$(pwd)" --lock-only >&2

if [ "${CORPUS_VALIDATE_LOCK_ONLY:-0}" = 1 ]; then
    exit 0
fi

composer install --quiet --no-dev --no-scripts --no-interaction --no-progress --prefer-dist
composer dump-autoload --quiet --no-dev --no-scripts --no-interaction --optimize
php artisan package:discover --ansi

php -d auto_prepend_file= \
    "$drove_source/benchmarks/corpus/verify-native-composer-environment.php" \
    invoiceshelf "$(pwd)"

for forbidden in \
    vendor/brianium \
    vendor/nunomaduro/collision \
    vendor/orchestra \
    vendor/paratestphp \
    vendor/pestphp \
    vendor/phpunit
do
    if [ -e "$forbidden" ]; then
        echo "native InvoiceShelf runtime contains forbidden dependency: $forbidden" >&2
        exit 1
    fi
done

test -f vendor/oxhq/drove/src/Drove/Native/functions.php
test -f vendor/oxhq/drove-laravel/src/LaravelTestContext.php
