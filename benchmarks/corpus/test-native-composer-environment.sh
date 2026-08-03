#!/bin/sh
set -eu

profile=${1:-}
corpus_root=${2:-}
drove_source=${DROVE_SOURCE:-}

if [ "${CORPUS_DISPOSABLE:-0}" != 1 ]; then
    echo "CORPUS_DISPOSABLE=1 is required for native environment fault injection." >&2
    exit 2
fi

if [ -z "$profile" ] || [ -z "$corpus_root" ] || [ -z "$drove_source" ]; then
    echo "usage: DROVE_SOURCE=/drove CORPUS_DISPOSABLE=1 $0 <profile> <corpus-root>" >&2
    exit 2
fi

case $profile in
    filament|invoiceshelf) ;;
    *)
        echo "unsupported native environment profile: $profile" >&2
        exit 2
        ;;
esac

verifier=$drove_source/benchmarks/corpus/verify-native-composer-environment.php
lock=$corpus_root/composer.lock
backup=$(mktemp)
error=$(mktemp)
poison=$corpus_root/vendor/pestphp/drove-native-poison

cleanup() {
    if [ -f "$backup" ]; then
        cp "$backup" "$lock"
    fi

    rmdir "$poison" 2>/dev/null || true
    rmdir "$corpus_root/vendor/pestphp" 2>/dev/null || true
    rm -f -- "$backup" "$error"
}

trap cleanup EXIT HUP INT TERM
cp "$lock" "$backup"

printf '\n' >>"$lock"

if php -d auto_prepend_file= "$verifier" "$profile" "$corpus_root" --lock-only >"$error" 2>&1; then
    echo "native environment verifier accepted a mutated dependency lock." >&2
    exit 1
fi

grep -F 'the dependency lock diverged' "$error" >/dev/null
cp "$backup" "$lock"

if php -d auto_prepend_file= "$verifier" "$profile" "$corpus_root" --inject-forbidden-symbol >"$error" 2>&1; then
    echo "native environment verifier accepted a loaded bridge symbol." >&2
    exit 1
fi

grep -F 'forbidden_runtime_symbols is not empty' "$error" >/dev/null
mkdir -p "$poison"

if php -d auto_prepend_file= "$verifier" "$profile" "$corpus_root" >"$error" 2>&1; then
    echo "native environment verifier accepted a poisoned vendor tree." >&2
    exit 1
fi

grep -F 'forbidden_vendor_paths is not empty' "$error" >/dev/null
cleanup
trap - EXIT HUP INT TERM

echo "native $profile environment fault injection passed"
