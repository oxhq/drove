#!/bin/sh
set -eu

if [ "$#" -lt 7 ]; then
    echo "usage: $0 CORPUS RUNNER COHORT PROCESSES SELECTED_FILES OUTPUT_JSON -- COMMAND..." >&2
    exit 2
fi

corpus=$1
runner=$2
cohort=$3
processes=$4
selected_files=$5
output=$6
shift 6

if [ "${1:-}" != "--" ] || [ "$#" -lt 2 ]; then
    echo "record command must follow --" >&2
    exit 2
fi

shift

drove_source=${DROVE_SOURCE:-/drove}

if git -C "$drove_source" rev-parse HEAD >/dev/null 2>&1; then
    revision=$(git -C "$drove_source" rev-parse HEAD)

    if ! git -C "$drove_source" diff --quiet ||
        ! git -C "$drove_source" diff --cached --quiet; then
        echo "Drove source contains tracked changes" >&2
        exit 2
    fi
elif [ -f /usr/local/share/drove-revision ]; then
    revision=$(cat /usr/local/share/drove-revision)
else
    echo "Drove revision metadata is unavailable" >&2
    exit 2
fi

case "$revision" in
    *[!0-9a-f]*)
        echo "Drove revision is not an exact 40-character Git SHA: $revision" >&2
        exit 2
        ;;
esac

if [ "${#revision}" -ne 40 ]; then
    echo "Drove revision is not an exact 40-character Git SHA: $revision" >&2
    exit 2
fi

if [ -z "${DROVE_EXPECTED_REVISION:-}" ] || [ "$revision" != "$DROVE_EXPECTED_REVISION" ]; then
    echo "Drove revision mismatch: expected ${DROVE_EXPECTED_REVISION:-unset}, found $revision" >&2
    exit 2
fi

mkdir -p "$(dirname "$output")"
raw="${output%.json}.raw.log"

set +e
"$@" >"$raw" 2>&1
status=$?
set -e

cat "$raw"

php "$drove_source/benchmarks/corpus/normalize.php" \
    "$corpus" "$runner" "$cohort" "$processes" "$selected_files" \
    "$revision" "$status" "$raw" "$output"
