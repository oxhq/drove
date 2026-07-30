#!/bin/sh
set -eu

target=${1:-}
drove_source=${DROVE_SOURCE:-}
lock_root=${CORPUS_LOCK_ROOT:-$drove_source/benchmarks/corpus/locks}

case "$target" in
    pest|invoiceshelf|livewire|filament) ;;
    *) echo "usage: DROVE_SOURCE=/absolute/drove/path $0 pest|invoiceshelf|livewire|filament" >&2; exit 2 ;;
esac

if [ -z "$drove_source" ] || [ ! -f "$drove_source/composer.json" ]; then
    echo "DROVE_SOURCE must point to a Drove checkout." >&2
    exit 2
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "corpus checkout must be clean before applying the dependency overlay" >&2
    exit 2
fi

if [ "$target" != pest ]; then
    sh "$drove_source/benchmarks/corpus/select.sh" "$target" . >/dev/null
fi

lock="$lock_root/$target.lock"

if [ "$target" = pest ]; then
    if [ ! -f "$lock" ]; then
        echo "missing pinned corpus lock: $lock" >&2
        exit 2
    fi

    cp "$lock" composer.lock
    COMPOSER_ROOT_VERSION=5.0.1 \
        composer validate --no-check-publish --no-interaction

    if [ "${CORPUS_VALIDATE_LOCK_ONLY:-0}" != 1 ]; then
        COMPOSER_ROOT_VERSION=5.0.1 \
            composer install --no-scripts --no-interaction --no-progress --prefer-dist
        composer dump-autoload --no-interaction --optimize
    fi

    exit 0
fi

drove_repository=$(printf '{"type":"path","url":"%s","options":{"symlink":false,"reference":"none","versions":{"oxhq/drove":"0.4.0-alpha.1"}}}' "$drove_source")
laravel_repository=$(printf '{"type":"path","url":"%s/packages/drove-laravel","options":{"symlink":false,"reference":"none","versions":{"oxhq/drove-laravel":"0.4.0-alpha.1"}}}' "$drove_source")

composer config --json repositories.drove "$drove_repository"
composer config --json repositories.drove-laravel "$laravel_repository"
composer config allow-plugins.pestphp/pest-plugin true

if [ "$target" = livewire ]; then
    composer require --dev --no-update --no-interaction \
        laravel/framework:'13.23.0' \
        oxhq/drove:'0.4.0-alpha.1' \
        oxhq/drove-laravel:'0.4.0-alpha.1' \
        phpunit/phpunit:'13.2.4'
else
    composer remove --dev pestphp/pest --no-update --no-interaction
    composer require --dev --no-update --no-interaction \
        oxhq/drove:'0.4.0-alpha.1' \
        oxhq/drove-laravel:'0.4.0-alpha.1' \
        pestphp/pest-plugin-laravel:'5.0.0' \
        phpunit/phpunit:'13.2.4'

    case "$target" in
        invoiceshelf)
            composer require --no-update --no-interaction laravel/framework:'13.23.0'
            composer require --dev --no-update --no-interaction pestphp/pest-plugin-faker:'5.0.0'
            ;;
        filament)
            support_repository='{"type":"path","url":"packages/support","options":{"symlink":true,"versions":{"filament/support":"4.x-dev"}}}'
            composer config --json repositories.filament-support "$support_repository"
            composer require --dev --no-update --no-interaction pestphp/pest-plugin-browser:'5.0.0'
            composer require --dev --no-update --no-interaction filament/support:'4.x-dev'
            ;;
    esac
fi

if [ "${CORPUS_UPDATE_LOCK:-0}" = 1 ]; then
    case "$target" in
        invoiceshelf)
            set -- oxhq/drove oxhq/drove-laravel laravel/framework \
                pestphp/pest-plugin-laravel pestphp/pest-plugin-faker phpunit/phpunit
            ;;
        livewire)
            # Livewire does not commit a root lock, so the calibration refresh
            # must resolve the complete graph once. Normal gates install our lock.
            set --
            ;;
        filament)
            set -- filament/support oxhq/drove oxhq/drove-laravel \
                orchestra/testbench orchestra/testbench-core \
                pestphp/pest-plugin-browser pestphp/pest-plugin-laravel phpunit/phpunit
            ;;
    esac

    composer update "$@" \
        --with-all-dependencies --no-install --no-scripts --no-interaction --no-progress
    mkdir -p "$(dirname "$lock")"
    cp composer.lock "$lock"
    exit 0
else
    if [ ! -f "$lock" ]; then
        echo "missing pinned corpus lock: $lock" >&2
        exit 2
    fi

    cp "$lock" composer.lock
    composer validate --no-check-publish --no-interaction

    if [ "${CORPUS_VALIDATE_LOCK_ONLY:-0}" = 1 ]; then
        exit 0
    fi

    composer install --no-scripts --no-interaction --no-progress --prefer-dist
fi

composer dump-autoload --no-interaction --optimize
