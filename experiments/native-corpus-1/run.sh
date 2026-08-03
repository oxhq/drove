#!/bin/sh
set -eu

drove_root=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd -P)
lock_only=0

if [ "${1:-}" = --lock-only ]; then
    lock_only=1
    shift
fi

pest_root=

if [ "$lock_only" = 0 ]; then
    pest_input=${1:-${DROVE_PEST_CORPUS:-$drove_root/.temp/native-corpus-pest}}
    pest_root=$(realpath "$pest_input" 2>/dev/null || true)

    if [ -z "$pest_root" ] || [ ! -d "$pest_root" ]; then
        echo "The pinned Pest checkout does not exist." >&2
        exit 2
    fi
fi

use_docker=0

if ! command -v php >/dev/null 2>&1 || ! command -v composer >/dev/null 2>&1; then
    use_docker=1
elif [ "$lock_only" = 0 ] && ! php -r '
    require $argv[1]."/src/Drove/Kernel/NativeLibrary.php";
    try {
        $library = Drove\Kernel\NativeLibrary::resolve();
    } catch (Throwable) {
        exit(1);
    }
    exit(
        ! in_array(PHP_OS_FAMILY, ["Linux", "Darwin"], true)
        || ! class_exists("FFI")
        || ! function_exists("pcntl_async_signals")
        || ! function_exists("pcntl_signal")
        || ! function_exists("pcntl_signal_get_handler")
        || ! is_file($library)
    );
' "$drove_root"; then
    use_docker=1
fi

if [ "$use_docker" = 1 ]; then
    if [ "${DROVE_NATIVE_CORPUS_CONTAINER:-0}" = 1 ]; then
        echo "The native Pest container is missing a usable Drover runtime." >&2
        exit 2
    fi

    if ! command -v docker >/dev/null 2>&1; then
        echo "The native Pest wrapper requires a Unix Drover runtime or Docker." >&2
        exit 2
    fi

    if [ "$lock_only" = 1 ]; then
        exec docker run --rm \
            --env DROVE_NATIVE_CORPUS_CONTAINER=1 \
            --volume "$drove_root:/workspace/drove:ro" \
            --workdir /workspace/drove \
            --entrypoint sh drove-corpus -lc \
            'sh experiments/native-corpus-1/run.sh --lock-only'
    fi

    exec docker run --rm \
        --env DROVE_NATIVE_CORPUS_CONTAINER=1 \
        --env DROVE_EXPECTED_REVISION \
        --env DROVE_NATIVE_CORPUS_PROCESSES \
        --env DROVE_NATIVE_CORPUS_SCHEDULER=drover \
        --volume "$drove_root:/workspace/drove:ro" \
        --volume "$pest_root:/workspace/pest:ro" \
        --workdir /workspace/drove \
        --entrypoint sh drove-corpus -lc \
        '
          set -eu
          git config --global --add safe.directory /workspace/pest
          git config --global --add safe.directory /workspace/drove
          git config --global --add safe.directory /workspace/drove/.git
          mounted_commit=$(git -C /workspace/pest rev-parse HEAD 2>/dev/null || true)

          if [ -n "$mounted_commit" ]; then
              if [ "$mounted_commit" != 6b2cd358e8a9d6d1abb93804b70e1c659bbc411b ]; then
                  echo "The mounted Pest checkout is not at the pinned v5.0.1 commit." >&2
                  exit 2
              fi

              exec sh experiments/native-corpus-1/run.sh /workspace/pest
          fi

          if ! git --git-dir=/workspace/drove/.git cat-file -e \
              6b2cd358e8a9d6d1abb93804b70e1c659bbc411b^{commit} 2>/dev/null; then
              echo "The mounted Pest checkout metadata is not portable and the pinned commit is unavailable locally." >&2
              exit 2
          fi

          git init /tmp/pest >/dev/null
          git -C /tmp/pest fetch --no-tags /workspace/drove/.git \
              6b2cd358e8a9d6d1abb93804b70e1c659bbc411b >/dev/null
          git -C /tmp/pest checkout --detach FETCH_HEAD >/dev/null
          exec sh experiments/native-corpus-1/run.sh /tmp/pest
        '
fi

DROVE_NATIVE_CORPUS_SCHEDULER=drover
export DROVE_NATIVE_CORPUS_SCHEDULER

dependency_root=$(mktemp -d)

case "$dependency_root" in
    /*) ;;
    *) echo "mktemp did not return an absolute dependency workspace." >&2; exit 2 ;;
esac

cleanup() {
    if [ -n "$dependency_root" ] && [ "$dependency_root" != / ] && [ -d "$dependency_root" ]; then
        rm -rf -- "$dependency_root"
    fi
}

trap cleanup EXIT HUP INT TERM

(
    cd "$dependency_root"
    CORPUS_VALIDATE_LOCK_ONLY="$lock_only" DROVE_SOURCE="$drove_root" \
        sh "$drove_root/benchmarks/corpus/prepare-native-pest.sh"
) >&2

if [ "$lock_only" = 1 ]; then
    exit 0
fi

DROVE_NATIVE_CORPUS_VENDOR="$dependency_root/vendor" \
    php "$drove_root/experiments/native-corpus-1/proof.php" "$pest_root"
