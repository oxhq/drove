#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

: "${DROVE_EVIDENCE_REVISION:?DROVE_EVIDENCE_REVISION is required}"
: "${DROVER_LIBRARY:?DROVER_LIBRARY is required}"

test -f "$DROVER_LIBRARY"
artifact_dir="${DROVE_PHASE5_ARTIFACT_DIR:-}"

if [[ -z "$artifact_dir" ]]; then
    artifact_dir="$(mktemp -d "${RUNNER_TEMP:-/tmp}/drove-native-phase-5-artifacts.XXXXXX")"
fi

mkdir -p "$artifact_dir"
work_root="$(mktemp -d "${RUNNER_TEMP:-/tmp}/drove-native-phase-5-work.XXXXXX")"
trap 'rm -rf -- "$work_root"' EXIT

run_direct() {
    local name="$1"
    local fixture="$2"
    local runner="$3"
    local processes="$4"
    local repetition="$5"
    local workspace="$work_root/$name"

    DROVE_PHASE5_FIXTURE="$fixture" \
    DROVE_PHASE5_RUNNER="$runner" \
    DROVE_PHASE5_PROCESSES="$processes" \
    DROVE_PHASE5_REPETITION="$repetition" \
    DROVE_PHASE5_WORKSPACE="$workspace" \
        php experiments/native-phase-5/monitor.php \
        "$artifact_dir/$name.json" \
        -- php -d ffi.enable=true experiments/native-phase-5/proof.php \
        >/dev/null
}

for processes in 1 2 4 8 16 30; do
    run_direct "parity-c$processes" parity drover "$processes" 1
    run_direct "saturation-c$processes" saturation drover "$processes" 1
done

for fixture in cheap setup; do
    for runner in drover pcntl; do
        for repetition in 1 2 3 4 5; do
            run_direct \
                "$fixture-$runner-r$repetition" \
                "$fixture" \
                "$runner" \
                1 \
                "$repetition"
        done
    done
done

for processes in 1 16 30; do
    for repetition in 1 2 3; do
        run_direct \
            "independent-c$processes-r$repetition" \
            independent \
            drover \
            "$processes" \
            "$repetition"
    done
done

for repetition in 1 2 3; do
    for inactive in 10 1000; do
        name="inactive-$inactive-r$repetition"
        DROVE_PHASE5_SCOPE_FIXTURE=inactive \
        DROVE_PHASE5_INACTIVE_SCOPES="$inactive" \
        DROVE_PHASE5_REPETITION="$repetition" \
        DROVE_PHASE5_PROCESSES=30 \
        DROVE_PHASE5_WORKSPACE="$work_root/$name" \
            php experiments/native-phase-5/monitor.php \
            "$artifact_dir/$name.json" \
            -- php -d ffi.enable=true experiments/native-phase-5/scopes.php \
            >/dev/null
    done
done

for fixture in inert stateful; do
    DROVE_PHASE5_SCOPE_FIXTURE="$fixture" \
    DROVE_PHASE5_PROCESSES=30 \
    DROVE_PHASE5_WORKSPACE="$work_root/$fixture" \
        php experiments/native-phase-5/monitor.php \
        "$artifact_dir/$fixture.json" \
        -- php -d ffi.enable=true experiments/native-phase-5/scopes.php \
        >/dev/null
done

DROVE_PHASE5_PROCESSES=30 \
    php experiments/native-phase-5/monitor.php \
    "$artifact_dir/dsl-stress-c30.json" \
    -- php -d ffi.enable=true -d memory_limit=256M \
        experiments/native-phase-5/dsl-stress.php \
    >/dev/null

DROVE_PHASE5_PROCESSES=30 \
DROVE_PHASE5_REPLAY_DIR="$artifact_dir" \
    php experiments/native-phase-5/monitor.php \
    "$artifact_dir/faults.json" \
    -- php -d ffi.enable=true experiments/native-phase-5/faults.php \
    >/dev/null

DROVE_PHASE5_REPLAY_DIR="$artifact_dir" \
    php -d ffi.enable=true experiments/native-phase-5/interrupt.php \
    >"$artifact_dir/interruption.json"

php -d ffi.enable=true experiments/native-phase-5/nested-host-crash.php \
    >"$artifact_dir/nested-host-crash.json"

php -d ffi.enable=true experiments/native-phase-5/cancel.php \
    >"$artifact_dir/explicit-cancellation.json"

php experiments/native-phase-5/compare.php \
    "$artifact_dir" \
    "$artifact_dir/native-phase-5-comparison.json"
