# Drove compatibility corpus

This ladder turns the Phase 4 calibration runs into a repeatable compatibility
gate. It is deliberately sequential, and Filament remains the final proof:

| Rung | Selection | Recorded calibration |
| --- | --- | --- |
| Pest 5.0.1 | 143 files, 797 cases | 726 passed, 71 skipped |
| InvoiceShelf | 47 files, 202 cases | 202 passed |
| Livewire | 20-file serial and 7-file parallel cohorts | 285 passed + 3 incomplete; 36 parallel-safe passed |
| Filament | 39 files, 705 cases | 677 parallel-safe and 28 serial |

The recorded numbers above are historical evidence from the revision named by
`initial_proof_revision` in `manifest.json`. The hosted workflow must pass
before a later revision can claim the same evidence.

Nucleus is intentionally excluded. The portable ladder uses Pest,
InvoiceShelf, Livewire, and finally Filament.

## Hosted tiers

`.github/workflows/corpus.yml` runs:

- **Required smoke** on pull requests and `develop`: Pest plus InvoiceShelf.
- **Full ladder** weekly, manually, and for `v0.*` tags: smoke, Livewire
  full parity serially plus its filesystem-neutral cohort at 1/2/4/8
  processes, then Filament.

The full Livewire selection retains all 288 calibrated cases at one process.
Its separate 36-case cohort uses seven unchanged Testbench files whose test
bodies do not render Blade views, launch Dusk, or generate application files.
Replay metadata must report observed concurrency of exactly 1/2/4/8, preventing
a serialized run from satisfying the parallel gate.

Pest's 140 parallel-safe files run at eight processes. Three unchanged
filesystem expectation files share `/tmp/fake.file`, so the gate compares
those 12 cases in a separate one-process cohort instead of accepting a
timing-dependent result.

The full Filament cohort runs its 677 parallel-safe cases at 1/2/4/8 processes
and its 28 filesystem-sensitive cases serially. Every run starts from a fresh
copy of one migrated database and verifies its SHA-256, an empty snapshot
directory, and the absence of transient SQLite files.

The workflow uploads raw logs, measurement JSON, normalized results, replay
metadata, and one aggregate `benchmark-report.json` for 90 days. The gate
rejects:

- a corpus commit or selected-file count mismatch;
- a changed selected source root;
- an unexpected Drove revision;
- a baseline/Drove status, assertion, or exit-code difference;
- observed concurrency that differs from the requested process count;
- a missing or changed dependency lock;
- a manifest that does not keep Filament in the final position.

Passing local scripts is local proof. The workflow is only hosted proof after
GitHub Actions completes successfully.

## Pinned dependencies

Each external checkout is fixed to the commit in `manifest.json`. Its
compatibility overlay installs exact `0.4.0-alpha.1` Drove packages and uses
the committed lock in `locks/`. Normal gates call `composer install`; they do
not resolve transitive dependencies.

To intentionally recalibrate one lock from a clean pinned checkout:

```bash
CORPUS_UPDATE_LOCK=1 \
CORPUS_LOCK_ROOT=/output \
DROVE_SOURCE=/drove \
sh /drove/benchmarks/corpus/prepare.sh livewire
```

Review the dependency diff, copy the generated lock into `locks/`, and update
its SHA-256 in `manifest.json`. Then verify:

```bash
php benchmarks/corpus/verify.php --manifest-only
```

`prepare.sh` refuses a dirty corpus checkout. The overlay changes dependency
metadata only; `select.sh` continues to require the exact commit, clean
selection roots, required directories, and exact file counts.

## Result contract

`record.sh` captures one runner invocation and calls `normalize.php`. Its v2
shape is shown below with illustrative metric values:

```json
{
  "schema_version": 2,
  "corpus": "livewire",
  "runner": "drove",
  "runner_identity": {
    "command": "drove",
    "executable": "vendor/bin/drove",
    "frontend": "phpunit",
    "runtime": "testbench",
    "state": "sqlite-memory"
  },
  "selection": {
    "mode": "curated",
    "whole_suite": false,
    "cohort": "parallel",
    "selected_files": 7
  },
  "requested_processes": 8,
  "observed_lanes": 8,
  "observed_lanes_source": "drove_replay",
  "run": 1,
  "drove_revision": "40-character Git SHA",
  "environment_plan": {
    "runtime": "testbench",
    "state": "sqlite-memory",
    "replay": {
      "schema": 1,
      "coordination": "best-effort",
      "resources": {
        "database": {
          "kind": "database",
          "provider": "sqlite-memory",
          "capabilities": ["branchable", "scope-isolated"]
        },
        "filesystem": {
          "kind": "filesystem",
          "provider": null,
          "capabilities": []
        },
        "cache": {
          "kind": "cache",
          "provider": null,
          "capabilities": []
        },
        "queue": {
          "kind": "queue",
          "provider": null,
          "capabilities": []
        },
        "object-storage": {
          "kind": "object-storage",
          "provider": null,
          "capabilities": []
        }
      }
    }
  },
  "metrics": {
    "wall_ms": 1409.61,
    "container_peak_memory_bytes": 640000000,
    "replay_duration_ms": 1375.42,
    "replay_php_peak_memory_bytes": 52000000
  },
  "platform": {
    "os_family": "Linux",
    "os": "Linux",
    "architecture": "x86_64",
    "php": "8.4.0"
  },
  "outcome": {
    "tests": 36,
    "passed": 36,
    "failed": 0,
    "errors": 0,
    "skipped": 0,
    "incomplete": 0,
    "risky": 0,
    "warnings": 0,
    "assertions": 68,
    "exit": 0
  },
  "artifacts": {
    "raw_sha256": "64-character SHA-256",
    "measurement_sha256": "64-character SHA-256",
    "replay_sha256": "64-character SHA-256"
  }
}
```

`command` is the runner identity, while `executable` is the allowlisted path
observed by the measurement wrapper. Pest and PHPUnit are frontends; Testbench
is a runtime, not a third runner. Process counts are requested configuration.
Drove's observed lanes come from replay metadata and must match; a baseline is
explicitly identified as an assumed one-process lane. Drove environment
providers and capabilities also come from replay and must match the manifest
expectation; a baseline has no replay plan.

`wall_ms` uses a monotonic clock around only the test command.
`container_peak_memory_bytes` is the aggregate cgroup-v2 peak for that fresh
lane container. The cgroup peak cannot be reset without privilege, so it also
includes container and selection work before the timed command. It is not PSS,
USS, or a claim about copy-on-write savings. Replay duration and sampled PHP
peak memory are secondary Drove-only diagnostics; PHP peak is the maximum root
or descendant process sample, not their sum.

`run` is a one-based sample ordinal. The verifier accepts contiguous repeated
runs and reports min/median/max plus the run count. Hosted lanes currently
record one sample, so their three summaries are identical. No threshold is
applied and the report explicitly records `performance_claim: none`; the
numbers are diagnostic until repeated, controlled fixtures prove a claim. The
standalone report retains the Drove revision, platform, selection, outcome,
runner identity, and environment plan for each group.

Every current rung declares `selection.mode=curated` and
`selection.whole_suite=false`. These results do not satisfy the proposed v1
whole-suite gate.

The conservative Pest selection still excludes undeclared higher-order,
dependency, snapshot, and diagnostic surfaces. Eight additional files assert
shared in-memory mutation between sibling cases; they remain known divergences
until Drove can reject that dependency before execution.
