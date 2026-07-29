# Drove compatibility corpus

This ladder turns the Phase 4 calibration runs into a repeatable compatibility
gate. It is deliberately sequential, and Filament remains the final proof:

| Rung | Selection | Recorded calibration |
| --- | --- | --- |
| Pest 5.0.1 | 143 files, 797 cases | 726 passed, 71 skipped |
| InvoiceShelf | 47 files, 202 cases | 202 passed |
| Livewire | 20 files, 288 cases | 285 passed, 3 incomplete |
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
  sqlite-memory at 1/2/4/8 processes, then Filament.

The full Filament cohort runs its 677 parallel-safe cases at 1/2/4/8 processes
and its 28 filesystem-sensitive cases serially. Every run starts from a fresh
copy of one migrated database and verifies its SHA-256, an empty snapshot
directory, and the absence of transient SQLite files.

The workflow uploads raw logs and normalized JSON. `verify.php` rejects:

- a corpus commit or selected-file count mismatch;
- a changed selected source root;
- an unexpected Drove revision;
- a baseline/Drove status, assertion, or exit-code difference;
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

`record.sh` captures one runner invocation and calls `normalize.php`. A result
contains:

```json
{
  "schema_version": 1,
  "corpus": "livewire",
  "runner": "drove",
  "cohort": "full",
  "processes": 8,
  "selected_files": 20,
  "drove_revision": "40-character Git SHA",
  "outcome": {
    "tests": 288,
    "passed": 285,
    "failed": 0,
    "errors": 0,
    "skipped": 0,
    "incomplete": 3,
    "risky": 0,
    "warnings": 0,
    "assertions": 1034,
    "exit": 0
  },
  "raw_sha256": "SHA-256 of the retained raw log"
}
```

The conservative Pest selection still excludes undeclared higher-order,
dependency, snapshot, and diagnostic surfaces. Eight additional files assert
shared mutation between sibling cases; they remain known divergences until
Drove can reject that dependency before execution.
