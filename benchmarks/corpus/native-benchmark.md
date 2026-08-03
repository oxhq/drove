# Native N6 benchmark harness

`native-benchmark.php` will not create or run a plan until a pinned JSON
artifact proves N1-N5 passed Pest, InvoiceShelf, Livewire, and Filament in that
order. It then keeps each real upstream baseline on a lock file separate from
the native dependency tree.

The compared cohorts must be identical on both runners:

| Corpus | Parallel comparison | C1 supplemental |
|---|---:|---:|
| Pest | 686 portable cases | — |
| InvoiceShelf | 202 cases | — |
| Livewire | 36 parallel-safe cases | full 288 cases |
| Filament | 677 nonserial cases | 28 serial-resource cases |

Every cohort config pins one `expected` object containing `cases`,
`assertions`, `statuses`, and a normalized `semantic_hash`. Both runners must
match it. A `parallel` cohort schedules five or more randomized repetitions of
the baseline at C1 and native Drove at C1/C2/C4/C8/C16/C30. A
`serial-resource` cohort schedules both runners at C1 and requires a named
`c1_only_reason`.

## Commands

```text
php benchmarks/corpus/native-benchmark-evidence.php PEST_ARTIFACTS INVOICESHELF_ARTIFACTS LIVEWIRE_ARTIFACTS FILAMENT_ARTIFACTS N1-N5.json
php benchmarks/corpus/native-benchmark-prepare.php N1-N5.json CONFIG.json PEST_CHECKOUT INVOICESHELF_CHECKOUT LIVEWIRE_CHECKOUT FILAMENT_CHECKOUT [CPU_CORES] [MEMORY_BYTES]
php benchmarks/corpus/native-benchmark.php plan CONFIG.json PLAN.json [SEED]
php benchmarks/corpus/native-benchmark.php run PLAN.json ARTIFACT_DIR
php benchmarks/corpus/native-benchmark.php report PLAN.json ARTIFACT_DIR REPORT.json
```

Evidence generation validates and hashes the final N1-N5 gate artifacts; each
gate must carry the same `DROVE_EXPECTED_REVISION`, so it does not accept a
handwritten phase marker or mixed Drove revisions. Preparation requires that
revision to equal the clean Drove HEAD, requires clean exact-revision checkouts
of all four corpora, and builds separate
upstream/native dependency images, pins their content-addressed image IDs, and
binds every image to the planned Composer lock hash. Baseline images also fail
if Drove is installed or the expected upstream Pest/PHPUnit executable is
missing. Before it starts the timed child, the measurement wrapper requires the
image's embedded Drove revision marker to be a full SHA equal to the revision
carried by the job. Preparation writes the real Docker command arrays to `CONFIG.json`.
Keep generated inputs outside the Drove checkout so its clean-tree check
remains meaningful. The default container quota is 30 CPU cores and 16 GiB;
the optional final two arguments override those finite quotas.

The evidence also pins every cohort's mode, C1-only reason, and exact expected
outcome. Every run/report re-derives the complete plan from the immutable
config and recorded random seed, so editing a cohort or its coherently rebuilt
schedule after planning is rejected.

Runner commands are argument arrays, never shell strings. The plan accepts only
the exact content-addressed Docker template written by preparation: a fresh
`--rm`, `--network=none`, CPU- and memory-limited container that mounts the
artifact directory and invokes:

```text
php benchmarks/corpus/native-benchmark-measure.php JOB OBSERVATION RAW -- COMMAND...
```

`COMMAND` prints one JSON proof object:

```json
{
  "schema_version": 1,
  "outcome": {
    "cases": 686,
    "assertions": 1675,
    "statuses": {"passed": 683, "skipped": 3},
    "semantic_hash": "64 lowercase hex characters"
  },
  "timing": {
    "phases_ms": {
      "preparation": 1.0,
      "planning": 2.0,
      "execution": 3.0,
      "verification": 1.0
    }
  },
  "topology": {
    "observed_lanes": 8,
    "runnable_cases": 683,
    "forks": 683,
    "strategy": "isolated-per-test",
    "fork_semantics": "one-fork-per-test"
  }
}
```

Upstream baselines expose `execution` and `verification` phases and use topology
identity `upstream` / `runner-native`. Native Drove must expose exactly
`preparation`, `planning`, `execution`, and `verification`, report the actual
observed lanes within the requested/runnable bound, and satisfy
`forks === runnable_cases` at every width, including C1. Short real workloads
need not incidentally saturate the requested cap; the N5 barrier fixture proves
exact C1-C30 capacity separately. Skipped declarations remain in the outcome
but do not fork; there is no batch or alternate C1 mode.

The measurement wrapper adds monotonic wall milliseconds, aggregate process
tree RSS/PSS peaks, fresh-container cgroup peak, OOM counters, platform,
container identity, and observed finite CPU/memory quotas. Each observation is
also bound to the content-addressed image and a hash of its fully expanded
Docker invocation, including the concrete artifact paths, so a result from an
older image or another run cannot be resumed silently. The report retains
those image/command identities and artifact hashes, and reports min, median,
nearest-rank p95, max, median absolute deviation, relative MAD, phase
statistics, lanes, forks, and separate native/baseline median ratios for
execution, the native runner path, and end-to-end wall time. Those upstream
ratios are contextual, not equal-boundary claims: upstream `execution` is its
whole Pest/PHPUnit subprocess, native `execution` starts after explicit
preparation and planning, native `runner_path_ms` sums those three phases but
still excludes PHP process startup, and the two sides do different verification
work around the end-to-end wall. The report therefore makes the same-boundary
native C1-to-C30 ratios and speedups explicit; those are the primary evidence
for a later C1/default decision. It encodes no threshold or C1 decision.

Run the schema and false-green checks with:

```text
composer test:native-benchmark
composer test:native-benchmark:static
```
