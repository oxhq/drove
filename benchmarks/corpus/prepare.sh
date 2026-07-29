#!/bin/sh
set -eu

target=${1:-}
drove_source=${DROVE_SOURCE:-}

case "$target" in
    invoiceshelf|larastreamers|phpreleases|filament) ;;
    *) echo "usage: DROVE_SOURCE=/absolute/drove/path $0 invoiceshelf|larastreamers|phpreleases|filament" >&2; exit 2 ;;
esac

if [ -z "$drove_source" ] || [ ! -f "$drove_source/composer.json" ]; then
    echo "DROVE_SOURCE must point to a Drove checkout." >&2
    exit 2
fi

drove_repository=$(printf '{"type":"path","url":"%s","options":{"symlink":false,"versions":{"oxhq/drove":"0.1.x-dev"}}}' "$drove_source")
laravel_repository=$(printf '{"type":"path","url":"%s/packages/drove-laravel","options":{"symlink":false,"versions":{"oxhq/drove-laravel":"1.0.x-dev"}}}' "$drove_source")

composer config --json repositories.drove "$drove_repository"
composer config --json repositories.drove-laravel "$laravel_repository"
composer remove --dev pestphp/pest --no-update --no-interaction
composer require --dev --no-update --no-interaction \
    oxhq/drove:'0.1.x-dev' \
    oxhq/drove-laravel:'1.0.x-dev' \
    pestphp/pest-plugin-laravel:'5.0.0' \
    phpunit/phpunit:'13.2.4'

case "$target" in
    invoiceshelf)
        composer require --no-update --no-interaction laravel/framework:'13.23.0'
        composer require --dev --no-update --no-interaction pestphp/pest-plugin-faker:'5.0.0'
        composer update oxhq/drove oxhq/drove-laravel laravel/framework \
            pestphp/pest-plugin-laravel pestphp/pest-plugin-faker phpunit/phpunit \
            --with-all-dependencies --no-scripts --no-interaction --no-progress
        ;;
    larastreamers)
        composer config platform.php 8.4.1
        composer require --no-update --no-interaction laravel/framework:'13.23.0'
        composer update oxhq/drove oxhq/drove-laravel laravel/framework \
            pestphp/pest-plugin-laravel phpunit/phpunit \
            --with-all-dependencies --no-scripts --no-interaction --no-progress
        ;;
    phpreleases)
        composer require --no-update --no-interaction laravel/framework:'13.23.0'
        composer update oxhq/drove oxhq/drove-laravel laravel/framework \
            pestphp/pest-plugin-laravel phpunit/phpunit \
            --with-all-dependencies --no-scripts --no-interaction --no-progress
        ;;
    filament)
        composer require --dev --no-update --no-interaction pestphp/pest-plugin-browser:'5.0.0'
        git apply "$drove_source/benchmarks/corpus/filament-provider.patch"
        composer update oxhq/drove oxhq/drove-laravel pestphp/pest-plugin-browser \
            pestphp/pest-plugin-laravel phpunit/phpunit \
            --with-all-dependencies --no-scripts --no-interaction --no-progress
        ;;
esac

composer dump-autoload --no-interaction --optimize
