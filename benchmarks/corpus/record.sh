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
php "$drove_source/benchmarks/corpus/validate-command.php" \
    "$runner" -- "$@"

replay=

for argument in "$@"; do
    case "$argument" in
        --replay=*)
            if [ -n "$replay" ]; then
                echo "Drove corpus records require exactly one --replay= artifact" >&2
                exit 2
            fi

            replay=${argument#--replay=}
            ;;
    esac
done

if [ "$runner" = drove ] && [ -z "$replay" ]; then
    echo "Drove corpus records require --replay= metadata" >&2
    exit 2
fi

revision_file=${DROVE_REVISION_FILE:-}
source_root=${CORPUS_SOURCE_ROOT:-}
selection_file=${CORPUS_SELECTION_FILE:-}
configuration=${CORPUS_CONFIGURATION:-}
classification=${CORPUS_CLASSIFICATION_FILE:-}

if [ -z "$source_root" ] || [ -z "$selection_file" ] ||
    [ -z "$configuration" ] || [ -z "$classification" ]; then
    echo "CORPUS_SOURCE_ROOT, CORPUS_SELECTION_FILE, CORPUS_CONFIGURATION, and CORPUS_CLASSIFICATION_FILE are required" >&2
    exit 2
fi

if [ -n "$revision_file" ]; then
    if [ ! -f "$revision_file" ] || [ -L "$revision_file" ]; then
        echo "DROVE_REVISION_FILE must name a regular, non-symlinked file" >&2
        exit 2
    fi

    revision=$(cat "$revision_file")
elif git -C "$drove_source" rev-parse HEAD >/dev/null 2>&1; then
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
measurement="${output%.json}.measurement.json"
source_identity="${output%.json}.source.json"
source_identity_after="${output%.json}.source-after.json"
case_evidence="${output%.json}.cases.xml"
run=${CORPUS_RUN:-1}

case "$run" in
    ''|*[!0-9]*|0)
        echo "CORPUS_RUN must be a positive integer" >&2
        exit 2
        ;;
esac

php "$drove_source/benchmarks/corpus/source-identity.php" \
    "$corpus" "$source_root" "$selection_file" "$configuration" \
    "$source_identity"

set +e
if [ "$runner" = baseline ]; then
    php "$drove_source/benchmarks/corpus/measure.php" "$measurement" "$raw" -- \
        "$@" "--log-junit=$case_evidence"
else
    php "$drove_source/benchmarks/corpus/measure.php" "$measurement" "$raw" -- "$@"
fi
status=$?
set -e

php "$drove_source/benchmarks/corpus/source-identity.php" \
    "$corpus" "$source_root" "$selection_file" "$configuration" \
    "$source_identity_after"

if ! cmp -s "$source_identity" "$source_identity_after"; then
    echo "Corpus source identity changed during execution" >&2
    exit 2
fi

rm -f "$source_identity_after"

if [ -f "$raw" ]; then
    cat "$raw"
fi

if [ "$runner" = drove ] && [ ! -f "$replay" ]; then
    echo "Drove replay artifact was not created: $replay" >&2
    exit 2
fi

if [ "$runner" = baseline ] && [ ! -f "$case_evidence" ]; then
    echo "Baseline JUnit case evidence was not created: $case_evidence" >&2
    exit 2
fi

case_evidence_arg=-

if [ "$runner" = baseline ]; then
    case_evidence_arg=$case_evidence
fi

php "$drove_source/benchmarks/corpus/normalize.php" \
    "$corpus" "$runner" "$cohort" "$processes" "$selected_files" \
    "$revision" "$status" "$run" "$raw" "$measurement" \
    "${replay:--}" "$source_identity" "$classification" \
    "$case_evidence_arg" "$output"
