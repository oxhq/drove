#!/bin/sh
set -eu

drove_root=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd -P)
pest_input=${1:-${DROVE_PEST_CORPUS:-$drove_root/.temp/native-corpus-pest}}
artifact_input=${2:-}
pest_root=$(realpath "$pest_input" 2>/dev/null || true)

if [ -z "$pest_root" ] || [ ! -d "$pest_root" ] || [ -z "$artifact_input" ]; then
    echo "Usage: baseline.sh <pest-root> <artifact-directory>" >&2
    exit 2
fi

mkdir -p "$artifact_input"
artifact_root=$(realpath "$artifact_input")
baseline_workspace=$(mktemp -d)
baseline_root="$baseline_workspace/pest"

cleanup() {
    case "$baseline_workspace" in
        /*) [ ! -d "$baseline_workspace" ] || rm -rf -- "$baseline_workspace" ;;
    esac
}

trap cleanup EXIT HUP INT TERM

git clone --quiet --no-checkout "$pest_root" "$baseline_root"
git -C "$baseline_root" checkout --quiet --detach 6b2cd358e8a9d6d1abb93804b70e1c659bbc411b
test "$(git -C "$baseline_root" rev-parse HEAD)" = 6b2cd358e8a9d6d1abb93804b70e1c659bbc411b
test "$(sha256sum "$baseline_root/composer.json" | cut -d ' ' -f 1)" = \
    829d94ff9be2d44e8424b30a19d871390686f67e6b878ea06eef24ad3f2a34be
cp "$drove_root/benchmarks/corpus/locks/pest-baseline.lock" "$baseline_root/composer.lock"
lock_before=$(sha256sum "$baseline_root/composer.lock" | cut -d ' ' -f 1)

COMPOSER_ROOT_VERSION=5.0.1 composer install \
    --working-dir="$baseline_root" \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader >&2

test "$lock_before" = "$(sha256sum "$baseline_root/composer.lock" | cut -d ' ' -f 1)"

set -- $(php -r 'foreach (require $argv[1] as $path) { if (preg_match("/\\s/", $path)) { exit(2); } echo $path, " "; }' \
    "$drove_root/experiments/native-corpus-1/cohort.php")

(
    cd "$baseline_root"
    php bin/pest \
        --configuration=phpunit.xml \
        --log-junit="$artifact_root/pest-baseline.xml" \
        "$@"
) >&2

php "$drove_root/experiments/native-corpus-1/generate-baseline.php" \
    "$baseline_root" \
    "$artifact_root/pest-baseline.xml" \
    "$artifact_root/pest-baseline.json"

cmp "$drove_root/experiments/native-corpus-1/baseline.json" \
    "$artifact_root/pest-baseline.json"
git -C "$baseline_root" diff --quiet
