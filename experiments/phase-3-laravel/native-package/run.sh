#!/bin/sh
set -eu

cd "$(dirname "$0")"

vendor="${DROVE_NATIVE_VENDOR:-}"
storage="${DROVE_NATIVE_PACKAGE_STORAGE:-}"
remove_vendor=0
remove_storage=0

if [ -z "$vendor" ]; then
    vendor="$(mktemp -d)"
    remove_vendor=1
else
    mkdir -p "$vendor"
fi

if [ -z "$storage" ]; then
    storage="$(mktemp -d)"
    remove_storage=1
else
    mkdir -p "$storage"
fi

cleanup() {
    if [ "$remove_vendor" -eq 1 ]; then
        rm -rf "$vendor"
    fi

    if [ "$remove_storage" -eq 1 ]; then
        rm -rf "$storage"
    fi
}

trap cleanup EXIT

cmp composer.json ../../../benchmarks/corpus/locks/livewire-native.composer.json
cp ../../../benchmarks/corpus/locks/livewire-native.lock composer.lock

COMPOSER_VENDOR_DIR="$vendor" composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

DROVE_NATIVE_PACKAGE_STORAGE="$storage" \
DROVE_NATIVE_VENDOR="$vendor" \
php "${1:-proof.php}"
