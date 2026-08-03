# syntax=docker/dockerfile:1.7
ARG DROVE_NATIVE_BENCHMARK_BASE_IMAGE=scratch
FROM ${DROVE_NATIVE_BENCHMARK_BASE_IMAGE}

ARG DROVE_NATIVE_BENCHMARK_CORPUS
ARG DROVE_NATIVE_BENCHMARK_RUNNER
ARG DROVE_NATIVE_BENCHMARK_LOCK_SHA256

LABEL org.oxhq.drove.native-benchmark.corpus="$DROVE_NATIVE_BENCHMARK_CORPUS" \
      org.oxhq.drove.native-benchmark.runner="$DROVE_NATIVE_BENCHMARK_RUNNER" \
      org.oxhq.drove.native-benchmark.lock-sha256="$DROVE_NATIVE_BENCHMARK_LOCK_SHA256"

COPY --from=corpus-source /corpus/ /corpus/

RUN git config --system --add safe.directory /corpus

RUN --mount=type=cache,target=/root/.composer/cache <<'SH'
set -eu

case "$DROVE_NATIVE_BENCHMARK_CORPUS/$DROVE_NATIVE_BENCHMARK_RUNNER" in
    pest/baseline)
        cp /drove/benchmarks/corpus/locks/pest-baseline.lock /corpus/composer.lock
        COMPOSER_ROOT_VERSION=5.0.1 composer --working-dir=/corpus install \
            --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader
        ;;
    pest/native)
        mkdir /native-deps
        cd /native-deps
        DROVE_SOURCE=/drove sh /drove/benchmarks/corpus/prepare-native-pest.sh
        ;;
    invoiceshelf/baseline)
        cd /corpus
        composer install --no-interaction --no-progress --no-scripts --prefer-dist --quiet
        git checkout -- composer.lock
        composer dump-autoload --no-interaction --no-scripts --optimize --quiet
        php artisan package:discover --ansi >/dev/null
        git checkout -- composer.lock
        ;;
    invoiceshelf/native)
        cd /corpus
        CORPUS_DISPOSABLE=1 DROVE_SOURCE=/drove \
            sh /drove/benchmarks/corpus/prepare-native-invoiceshelf.sh
        ;;
    livewire/baseline)
        cp /drove/benchmarks/corpus/locks/livewire-baseline.composer.json /corpus/composer.json
        cp /drove/benchmarks/corpus/locks/livewire-baseline.lock /corpus/composer.lock
        composer --working-dir=/corpus install \
            --no-interaction --no-progress --no-scripts --prefer-dist --quiet
        ;;
    livewire/native)
        cd /drove/experiments/phase-3-laravel/native-package
        cmp composer.json /drove/benchmarks/corpus/locks/livewire-native.composer.json
        cp /drove/benchmarks/corpus/locks/livewire-native.lock composer.lock
        COMPOSER_VENDOR_DIR=/native-vendor composer install \
            --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --quiet
        ;;
    filament/baseline)
        composer --working-dir=/corpus install \
            --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader
        ;;
    filament/native)
        cd /corpus
        CORPUS_DISPOSABLE=1 DROVE_SOURCE=/drove \
            sh /drove/benchmarks/corpus/prepare-native-filament.sh
        ;;
    *)
        echo "Unknown native benchmark image $DROVE_NATIVE_BENCHMARK_CORPUS/$DROVE_NATIVE_BENCHMARK_RUNNER" >&2
        exit 2
        ;;
esac

case "$DROVE_NATIVE_BENCHMARK_CORPUS/$DROVE_NATIVE_BENCHMARK_RUNNER" in
    pest/native)
        installed_lock=/native-deps/composer.lock
        ;;
    livewire/native)
        installed_lock=/drove/experiments/phase-3-laravel/native-package/composer.lock
        ;;
    *)
        installed_lock=/corpus/composer.lock
        ;;
esac

printf '%s  %s\n' "$DROVE_NATIVE_BENCHMARK_LOCK_SHA256" "$installed_lock" | sha256sum --check --status

if [ "$DROVE_NATIVE_BENCHMARK_RUNNER" = baseline ]; then
    test ! -e /corpus/vendor/oxhq

    case "$DROVE_NATIVE_BENCHMARK_CORPUS" in
        pest)
            test -f /corpus/bin/pest
            ;;
        invoiceshelf|filament)
            test -f /corpus/vendor/bin/pest
            ;;
        livewire)
            test -f /corpus/vendor/bin/phpunit
            ;;
    esac
fi
SH

WORKDIR /drove
