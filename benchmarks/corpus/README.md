# Drove compatibility corpus

This ladder preserves the Phase 4 calibrated execution cohorts and adds the
Phase 6 full-suite classification preflight. It is deliberately sequential,
and Filament remains the final, non-skippable proof:

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
- **Full ladder** weekly, manually, and for `v0.*` tags: full-suite
  classification followed by the calibrated execution cohorts for Pest,
  InvoiceShelf, Livewire, and finally Filament.

The full Livewire selection retains all 288 calibrated cases at one process.
Its separate 36-case cohort uses seven unchanged Testbench files whose test
bodies do not render Blade views, launch Dusk, or generate application files.
The workflow requests limits of 1/2/4/8/16/30 and records the independently
observed lane high-water mark. A real cohort may finish without saturating a
high limit; exact saturation belongs to the Phase 5 barrier fixture.

Pest's 140 parallel-safe files run at 1/2/4/8/16/30 processes. Three
unchanged filesystem expectation files share `/tmp/fake.file`, so the gate
compares those 12 cases in an explicit one-process cohort instead of accepting
a timing-dependent result. InvoiceShelf's parallel cohort runs at the same
declared matrix.

The calibrated Filament cohort runs its 677 parallel-safe cases at
1/2/4/8/16/30 processes and its 28 filesystem-sensitive cases serially. Every
run starts from a fresh copy of one migrated database and verifies its SHA-256,
an empty snapshot directory, and the absence of transient SQLite files.

The workflow uploads raw logs, measurement JSON, normalized results, replay
metadata, and one aggregate `benchmark-report.json` for 90 days. The gate
rejects:

- a corpus commit or selected-file count mismatch;
- a changed selected source root;
- an unexpected Drove revision;
- a baseline/Drove status, assertion, or exit-code difference;
- a discovery/baseline/Drove canonical case-ID difference;
- observed concurrency outside the range from one through the requested
  process limit;
- a missing or changed dependency lock;
- an unclassified full-suite case or extension surface;
- scanner coverage below 100%;
- exact-source bridge execution below 80% of a calibrated cohort, or below
  95% after an explicitly recorded idempotent codemod;
- a manifest that does not keep Filament in the final position.

Passing local scripts is local proof. The workflow is only hosted proof after
GitHub Actions completes successfully. `manifest.json` therefore keeps every
rung at `CURATED_PROVEN_FULL_SUITE_PENDING`; this change does not fabricate a
hosted Phase 6 result.

## Full-suite classification boundary

Before each full-tier execution rung, `classify.php` runs the pinned baseline
frontend with PHPUnit's `--list-tests-xml` over the repository's complete
testsuite configuration. It resolves testsuite paths relative to that
configuration file, scans every PHP input under the versioned suite-support
roots, the configured bootstrap, root Composer `autoload.files` and
`autoload-dev.files`, and deterministic local `require`/`include` descendants.
It fails on a dynamic bootstrap include or when any discovered case cannot be
mapped back to one scanned source file. Installed vendor behavior is
classified separately from the committed dependency lock and extension map.

Every case, source finding, Composer/Pest plugin, Testbench package, and
configured PHPUnit extension is assigned exactly one or more registry surfaces
whose public status is `supported`, `unsupported`, or `bridge-only`. An
extension that has no versioned mapping fails the preflight.

The artifact keeps two axes separate. Runtime compatibility records whether a
case is native, requires the declared bridge, or is rejected. Exact files in a
calibrated execution cohort are `bridge-only` under the
`exact-source-execution-contract`; the normalized baseline/Drove results prove
whether those unchanged files actually loaded. Static native migration is a
separate diagnostic. The scanner and idempotent codemod run twice against
every input and report how much source could move to the native frontend, but
that ratio is not treated as runtime incompatibility and has no Phase 6
threshold.

The classification artifact says `whole_suite=true` only for discovery and
classification, and explicitly says `execution.performed=false`. Every
execution result remains `selection.mode=curated` and
`selection.whole_suite=false`. In particular:

- Pest discovery uses a separate clean checkout of the exact v5.0.1 commit and
  its committed corpus lock. Baseline runs there; Drove bind-mounts that
  checkout's complete `tests`, `tests-external`, and `phpunit.xml` surfaces
  over a fresh Drove runtime container, so both runners execute identical
  pinned test sources and support configuration.
- InvoiceShelf, Livewire, and Filament discovery use their exact pinned
  checkouts after installing the committed compatibility locks.
- A green Pest, InvoiceShelf, or Livewire rung cannot substitute for Filament.
  `verify.php --complete` requires all four classification artifacts in ladder
  order and Filament's complete serial/parallel cohort evidence.

The hosted runner is pinned to `ubuntu-24.04`. The uploaded artifact name
includes the exact Drove commit; `evidence-metadata.json` repeats that identity
and `SHA256SUMS` seals every uploaded file.

Selected hosted reports are promoted permanently under
[`benchmarks/results/`](../results/); each snapshot links its source workflow,
artifact, revision, and report hash.

## Pinned dependencies

Each checkout is fixed to the commit in `manifest.json`. Pest uses its
committed classification lock without a Drove overlay. The three application
corpora use a compatibility overlay with exact `0.4.0-alpha.1` Drove packages
and their committed locks in `locks/`. Normal gates call `composer install`;
they do not resolve transitive dependencies.

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

`record.sh` captures one runner invocation and calls `normalize.php`. Its v3
shape is shown below with illustrative metric values:

```json
{
  "schema_version": 3,
  "corpus": "livewire",
  "runner": "drove",
  "runner_identity": {
    "command": "drove",
    "executable": "vendor/bin/drove",
    "arguments": ["--pest"],
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
  "source": {
    "schema_version": 1,
    "corpus": "livewire",
    "source_commit": "40-character pinned Git SHA",
    "source_tree": "40-character Git tree SHA",
    "tracked_source_clean": true,
    "tracked_dependency_overlays": [
      {
        "path": "composer.json",
        "sha256": "64-character SHA-256"
      }
    ],
    "composer_json_sha256": "64-character SHA-256",
    "selection": {
      "files": 7,
      "sha256": "64-character SHA-256"
    },
    "configuration": {
      "path": "phpunit.xml.dist",
      "sha256": "64-character SHA-256"
    },
    "dependency_lock_sha256": "64-character SHA-256",
    "source_sha256": "64-character composite SHA-256"
  },
  "execution_identity": {
    "schema": 1,
    "source": "drove-replay",
    "case_ids": ["canonical frontend case IDs, sorted"],
    "case_ids_sha256": "64-character canonical case-ID-set SHA-256",
    "plan_sha256": "64-character Drove plan SHA-256",
    "planned_tests": 36,
    "terminal_case_ids": 36,
    "terminal_case_ids_sha256": "64-character sorted case-ID-set SHA-256"
  },
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
    "replay_sha256": "64-character SHA-256",
    "case_evidence_sha256": null,
    "source_identity_sha256": "64-character SHA-256"
  }
}
```

`command` is the runner identity, while `executable` is the allowlisted path
observed by the measurement wrapper. `arguments` seals the frontend selector:
every Drove corpus identity is exactly `["--pest"]`, and `record.sh` rejects an
actual Drove command that omits or duplicates it, selects `--native`, or does
not invoke `bin/drove`. Pest and PHPUnit are frontends; Testbench is a runtime,
not a third runner. Process counts are requested configuration.
Drove's observed lanes come from replay metadata and cannot exceed the
requested limit; a baseline is explicitly identified as an assumed
one-process lane. Drove environment providers and capabilities also come from
replay and must match the manifest expectation; a baseline has no replay plan.

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

Every execution rung declares `selection.mode=curated` and
`selection.whole_suite=false`. The separate full-suite artifacts classify the
whole pinned source surface; they do not claim that Drove executed every
discovered test.

Before and after each runner invocation, `record.sh` rebuilds the source
identity from the Git checkout. It binds the exact commit and tree, sorted
selected paths and their bytes, PHPUnit configuration, committed dependency
lock, and the versioned Composer overlay. An undeclared tracked change, a
selection/configuration mismatch, or any before/after identity drift aborts
normalization. The verifier additionally requires baseline and Drove results
to carry the same `source_commit` and composite `source_sha256`.

The manifest pins separate hashes when discovery uses the repository's
configuration but execution uses Drove's versioned Laravel configuration.
Neither path can be substituted while retaining a valid classification or
execution artifact.

Every classification cohort publishes its sorted canonical frontend IDs.
Baseline commands emit JUnit case evidence; Drove replay maps each internal
execution ID to that same frontend identity. Normalization requires the
observed list to equal discovery exactly, and the verifier repeats that
discovery/baseline/C1-C30 equality before calculating bridge load. Drove also
binds the replay plan and terminal internal IDs; there must be exactly one
unique terminal ID per planned and normalized test.

The complete pinned Pest suite is still classified conservatively outside the
selected execution contract. Higher-order, dependency, snapshot, diagnostic,
and implicit shared-state surfaces can remain explicitly unsupported there.
That does not reduce the exact bridge-load result for the 797 selected cases:
the unchanged 785-case parallel cohort and 12-case serial cohort must load and
match their baseline artifacts. The static native-migration percentage is
reported separately as a migration-planning diagnostic.
