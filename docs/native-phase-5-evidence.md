# Native Phase 5 evidence contract

Phase 5 makes Drove's isolation topology and performance claims falsifiable. It
does not introduce batching: every runnable test still receives one executor
process, emits one terminal result, and exits.

The gate is implemented in `experiments/native-phase-5` and runs in
`.github/workflows/native-topology.yml`. A passing comparison artifact is
required before any Phase 5 performance result is described as hosted
evidence.

## Measurement boundary

The evidence schema is version 1. Every summary records the evidence Git
revision, PHP/platform tuple, Drover target and native-library SHA-256. The
hosted workflow verifies that the checkout is exact and clean before running;
a local artifact from a dirty checkout is development evidence, not hosted
release evidence.

Measurements name their source:

- Drover reports forks, scope workers, executor workers, process anchors,
  peak live PIDs, peak outstanding tasks, and the outstanding-task limit.
- Instrumented fixtures report planning, environment preparation, execution,
  rendering, wall, queue-wait, state-preparation, body, and cleanup time.
- Independent-work timings run without an external observer. Their summaries
  declare `timing_observer=none`, retain kernel topology and harness isolation
  evidence, and deliberately omit aggregate `/proc` memory telemetry.
- A Linux `/proc` monitor reports aggregate and per-process RSS and PSS, swap,
  live PIDs, phase-specific peaks, and cgroup OOM deltas. PSS comes from
  `smaps_rollup` so shared prepared pages are not counted once per fork. Child
  reports retain a PHP peak-memory sample for every executor. A process-tree
  sample is retained only when its canonical phase and complete PID set are
  identical before and after the `/proc` scan; discarded transition samples
  remain counted in telemetry.
- `/proc/<pid>/io` provides root, executor, and aggregate read/write counters.
- Fault fixtures and Drove replay artifacts provide failure, terminal-result,
  schema, redaction, and cleanup evidence.

These sources are kept distinct. A sampled `/proc` interval is not presented
as a kernel high-water mark, and process-local nested-map counters are not
presented as a global scheduler peak.

The configured process count caps active test bodies/executor lanes. It is not
a claim that every OS PID is capped at that number. Stateful scopes add
separately reported, lazily created scope hosts that own their semantic
snapshots; inert scopes add none. Aggregate PID, RSS, and PSS measurements
include both executors and scope hosts. Drover may retain at most `2C` executor
children across active and pre-armed states; that executor window excludes
scope hosts. Scope-host peak is measured independently from scope-worker
started/finished intervals. RSS remains a reported conservative upper bound.
The inactive-scope gate compares raw aggregate execution PSS from the stable
61-PID population at C30 in three paired 10/1,000-scope repetitions. The median
change must remain at most 10% and every pair at most 15%. PHP used-memory,
allocator footprint, and serialized plan bytes are reported separately and are
never subtracted from `/proc` PSS. Exact C1-C30 saturation and aggregate-memory
bounds use the deliberate barrier fixture.

Every executor group created by a nested Drove map is registered with the
original engine before child readiness. The root preserves three identity
states: active, retired-before-reap, and unregistered-after-reap. If a
stateful scope host dies without running PHP cleanup, the root terminates its
active registered subtree and never signals retired tombstones. This guarantee
does not cover user code that deliberately escapes Drove ownership with
`setsid()`, `setpgid()`, or a privilege boundary.

## Gate matrix

The hosted workflow records:

| Gate | Runs | Required result |
|---|---:|---|
| Semantic parity | C1, C2, C4, C8, C16, C30 | Identical semantic hashes and no orphan/state residue |
| Deliberate saturation | C1, C2, C4, C8, C16, C30 | Barrier reaches exactly the requested width |
| Aggregate memory | Saturated C1, C2, C4, C8, C16, C30 | At least three stable full-population samples; `M(C) <= 1.15 * C * M(C1)`; zero observed swap/OOM |
| Cheap C1 | 5 Drove + 5 matched reference samples | Drove median no more than 15% slower |
| Setup dominated | 5 Drove + 5 matched reference samples | Drove median at least 2x faster |
| Independent work | 3 observer-free samples at C1, C16, C30 | At least 20x C1-to-C30; C30 at least 10% faster than C16; idle below 5%; no `/proc` process monitor attached |
| Inactive scopes | 3 paired runs of 10 and 1,000 empty siblings | Every virtual result/event survives; at least three stable 61-PID samples per C30 run; raw full-population PSS median delta at most 10% and each pair at most 15%; zero scope workers/branches |
| Scope behavior | inert and stateful scopes | Inert scopes flatten; stateful `beforeAll`/`afterAll` isolation survives |
| Nested stateful C1 | 1 depth-2 Scope IR run | One active test body, three scope hosts, 63 total forks, semantic parity with C30, and zero orphan/state residue |
| DSL stress | 10,000 flat native cases at C30 | `Runner` path, 256 MiB PHP limit with at least 10% headroom, no shards, no batch |
| Repeated faults | 100 kill + 100 timeout + 100 fatal crash | 300 terminal results, no descendants or state residue |
| Interruption | 30 submitted cases | Every case terminal after `SIGINT`, no descendants |
| Nested host crash | 1 stateful host `SIGKILL` | One terminal result; registered executor and stubborn same-group descendant leave no orphan or sentinel |
| Explicit cancellation | normal + injected lost-waitable-child cases | One stable terminal outcome per request; native failure crosses the ABI; nested executor and stubborn descendant leave no orphan or sentinel |
| Replay | kill, timeout, crash, interruption | Schema 1, private permissions, secrets/output absent |

Ordinary short benchmarks report requested and observed lanes without requiring
incidental saturation. Only the barrier fixture is allowed to claim an exact
observed width.

## References

The cheap threshold uses `Drove\Kernel\PcntlScheduler` as the matched
reference. It exercises the same one-fork-per-test `ChildProtocol` contract,
so the comparison measures native scheduler overhead without silently
weakening isolation.

The setup-dominated reference performs the same deterministic preparation in
each isolated executor; Drove prepares once before forking. This deliberately
measures the product's prepared-state advantage.

Pest, PHPUnit, Laravel, and Testbench are not treated as matched synthetic
references. Their pinned real-suite measurements remain in
`benchmarks/results/2026-07-29-d3dec629.md`. The latest native Laravel prepared
state evidence is recorded separately in
`benchmarks/results/2026-07-30-native-phase-4-a94740e8.md`.

## Reproduction

Build the pinned Drover Linux library, then run:

```bash
DROVE_EVIDENCE_REVISION="$(git rev-parse HEAD)" \
DROVER_LIBRARY="$PWD/native/drover/target/release/libdrover.so" \
DROVE_PHASE5_ARTIFACT_DIR=/tmp/drove-native-phase-5 \
composer test:native-phase-5
```

Run the static gate separately:

```bash
composer test:native-phase-5:static
```

The comparison file is
`native-phase-5-comparison.json`. Raw summaries and metadata-only replay files
are retained beside it so thresholds can be independently recalculated.
