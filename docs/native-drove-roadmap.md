# Native Drove roadmap

This roadmap defines the work required to make Drove a native prepared-state
testing runtime with a Pest-like frontend.

There is no v1 compatibility contract or release compatibility promise yet.
Until Phase 7 passes, every package, CLI surface, extension point, and
compatibility bridge is experimental.

## Product and architecture contract

Drove owns test registration, Scope IR, lifecycle, scheduling, isolation,
result aggregation, and resource coordination. Familiar Pest-like syntax is a
frontend:

```text
Native Pest-like DSL ───────┐
Typed Drove extensions ─────┼─> Scope IR -> LifecycleExecutor -> scheduler
Optional compatibility ─────┘                    |
bridges                                      EnvironmentPlan
                                                  |
                                      prepared resource providers
```

PHPUnit, Pest, and Orchestra Testbench are compatibility bridges. They are not
kernel dependencies and may not define native Drove lifecycle semantics.

The following invariants apply to every feature declared supported:

- Every test executes from its own prepared scope. Sibling tests never share
  mutable runtime or resource state.
- The declared concurrency matrix is C1, C2, C4, C8, C16, and C30. Those
  values have identical test IDs, hook order, datasets, assertions, statuses,
  failures, output semantics, and cleanup behavior. No semantic claim is made
  for an unlisted process count until it is added to the matrix.
- Unsupported syntax, extensions, or resource guarantees are rejected during
  planning with a stable diagnostic. Drove does not silently fall back to a
  weaker isolation mode.
- Batch mode is rejected. Drove will not amortize setup by running multiple
  tests in one mutable executor process.
- Performance claims require repeated controlled fixtures. The existing
  single-sample compatibility corpus is diagnostic evidence, not a speed
  claim.

## Status

| Phase | Status | Exit proof |
| --- | --- | --- |
| 1. Native frontend vertical slice | **IMPLEMENTED — HOSTED GATE PENDING** | Local and Linux C1/C4 proofs pass; required artifacts have not yet been observed and accepted upstream |
| 2. Typed extension front door | **IMPLEMENTED — HOSTED GATE PENDING** | Local Linux C1–C30 parity passes; required artifacts have not yet been observed and accepted upstream |
| 3. Native DSL feature surface | **IMPLEMENTED — HOSTED GATE PENDING** | Local Drover C1–C30 parity and repeated 10,000-case C30 stress pass; required hosted artifact has not yet been observed upstream |
| 4. Native Laravel prepared runtime | **IMPLEMENTED — HOSTED GATE PENDING** | Local Docker C1–C30 matrix, provider faults, preflight, and cleanup proofs pass; exact hosted artifact remains pending |
| 5. Bounded one-fork execution | **IMPLEMENTED — HOSTED GATE PENDING** | Local topology, telemetry, reliability, cancellation, and controlled performance gates pass; the exact hosted artifact has not yet been observed |
| 6. Bridges and migration corpus | **IMPLEMENTED — HOSTED GATE PENDING** | Local bridge, migration, source-identity, and exact pinned Pest proofs pass; the required hosted ladder ending with Filament has not yet been observed |
| 7. Public experimental release | **IMPLEMENTED — PUBLICATION/EVIDENCE GATES PENDING** | The technical evaluation release `v0.4.0-alpha.2` may publish after technical gates; candidate `v0.4.0-alpha.3` still requires 3 design partners and 2 retained migrations |

No phase is complete merely because its code exists. Its exit proof must be
recorded in hosted CI with the fixture, command, revision, and normalized
artifact needed to reproduce the result.

## Phase 1 — Native frontend vertical slice

Build one native path from source syntax to the existing kernel.

Deliverables:

- Native `test()`, `it()`, `describe()`, and lifecycle-hook registration that
  lowers directly into Scope IR.
- An explicit, immutable suite root supplied to the run-scoped declaration
  capture boundary. IDs and source paths are normalized relative to that root;
  outside-root declarations are rejected rather than retaining machine paths.
  Registration never depends on destructors or the current working directory.
- Execution through `LifecycleExecutor` without generated PHPUnit test cases,
  `Pest\Kernel`, PHPUnit `runBare()`, or Testbench lifecycle calls.
- A Drove-only proof bootstrap that does not load the root Composer vendor tree,
  Pest frontend files, or Pest/PHPUnit runtime classes.
- Native declarations without a dataset accept zero-parameter closures. Test
  bodies, `beforeEach`, and `afterEach` execute with `$this` bound to one
  Drove-owned `TestContext` per case. `beforeAll` and `afterAll` are scope hooks
  and receive neither `$this`, `TestContext`, nor a kernel context.
- Drove-owned identity and equality assertions whose failures are classified
  by the kernel without a PHPUnit assertion object.
- A dependency guard that fails when `src/Drove/Native` imports or invokes
  Pest, PHPUnit, or Testbench runtime names. Stable scanner diagnostics may
  name rejected bridge syntax as inert data. The production package does not
  require or autoload compatibility dependencies; the explicit Pest bridge
  remains co-located during the alpha and is selected with `--pest`.

Exit gates:

- The committed fixture covers a file scope, a nested scope, all four hook
  phases, per-case fixtures, passes, and a native assertion failure.
- Repeated capture from at least two working directories, using the same
  explicit suite root, emits identical Scope IR, portable plan IDs, source
  paths, and source lines.
- The shared declaration guard rejects parameters on native closures without
  a dataset before execution. Per-case closures prove their `$this` binding,
  while scope hooks run through an unbound, zero-argument wrapper.
- The native proof process loads only Drove source plus its fixture; bridge
  frontend/bootstrap files and project vendor code remain absent.
- Linux forked runs at C1 and C4 have identical plan and semantic hashes. The
  C4 fixture keeps at least four independent tests runnable long enough to
  observe all four lanes; the proof asserts observed concurrency rather than
  echoing requested configuration.
- Every runnable test reports one executor PID that is unique within the run
  and differs from the parent PID. Each child mutates prepared static state,
  every sibling observes the original value, and the parent remains unchanged.
- The proof asserts exact nested hook-phase order, scope-hook multiplicity,
  native assertion classification, aggregate failed/exit-1 semantics, and both
  teardown levels after the injected failure.
- `composer test:native-phase-1`, targeted lint/static analysis, and the native
  dependency guard pass locally.
- The required Tests workflow uploads both run summaries and a normalized
  comparison artifact containing revision/platform metadata, plan and semantic
  hashes, lifecycle proof, executor PID/concurrency telemetry, and guard
  results.
- The same C1/C4 comparison passes in the required Tests workflow. Until those
  hosted artifacts are green and reviewed, Phase 1 remains in progress.

## Phase 2 — Typed extension front door

Give native features one constrained extension boundary before adding DSL
breadth.

Deliverables:

- A data-only discovery manifest containing extension identity, package
  version, supported Drove extension-API range, contribution kinds, and
  configuration schema. Discovery reads the manifest without executing
  extension code.
- Explicit API-version negotiation before an extension entrypoint is loaded.
- A typed extension registry initialized before source compilation, with
  separate matcher, context, planner, resource-provider, reporter, and CLI
  contribution contracts.
- Deterministic contribution ordering, duplicate-key rejection, and stable
  diagnostic ownership for each extension.
- CLI contributions consume typed parsed options and return typed results.
  The API exposes neither raw `argv`, process-exit policy, nor autoload
  callbacks. Discovery rejects extension candidates declared as Composer
  plugins or with `autoload.files` before loading their entrypoint.
- No arbitrary event interception or mutation of kernel-owned lifecycle state.
- Installed extension code is trusted PHP, not a sandbox. Drove constrains the
  authority exposed by its API; it cannot prevent arbitrary package code from
  calling global PHP functions outside that contract.

Exit gates:

- Fixture extensions exercise one matcher, context field, planner annotation,
  resource provider, reporter, and CLI option without importing kernel
  internals.
- Discovery rejects an incompatible API range before loading its entrypoint;
  accepted negotiation records the chosen API version in the plan and replay
  artifact.
- Registration order produces the same plan hash and semantic result at every
  declared concurrency.
- Duplicate, late, untyped, and incompatible extensions fail before execution
  with stable diagnostic codes.
- A guarded proof shows discovery reads package metadata without loading
  entrypoints, rejects forbidden Composer/autoload surfaces, and leaves raw
  `argv` and process-exit policy outside every contribution contract.
- Removing an extension leaves no hidden global state in the following run.

## Phase 3 — Native DSL feature surface

Complete the initial source surface without promising Pest runtime parity.

Deliverables:

- Closure tests and nested scopes with `beforeAll`, `beforeEach`, `afterEach`,
  `afterAll`, and deferred cleanup.
- Inline and named datasets with deterministic expansion and stable case IDs.
- Groups, filtering, skip, todo, per-test timeout, source locations, and
  structured output capture.
- A documented native expectation catalog and extension mechanism.
- A machine-readable supported-surface manifest and scanner diagnostics for
  unsupported constructs.

Exit gates:

- Every positive and negative case in the declared conformance matrix passes;
  unsupported cases are rejected before any test body runs.
- Dataset IDs, hook traces, assertion counts, output, and exit codes are
  identical through the product Drover scheduler at C1, C2, C4, C8, C16, and
  C30.
- A 10,000-test stress fixture completes without lost results, duplicate IDs,
  leaked deferred cleanup, or cross-test memory mutation.
- CLI help and native documentation contain only behavior covered by the
  conformance matrix.

Current local evidence is diagnostic until the hosted gate runs. At exact
revision `2fb74fb404a331f61f3f9dcd32041b682c5b7fd4`, the conformance fixture
produced 49 terminal results, 47 runnable cases, 46 assertions, and 35 cleanups
with identical semantic hashes at C1–C30. Two Drover C30 stress runs each
produced 10,000 passes, assertions, cleanups, and unique executor PIDs with no
batching. Their wall times were 13,837.722 ms and 14,012.134 ms; parent peaks
were 370,257,920 and 370,405,376 bytes, and the maximum executor sample was
71,303,168 bytes under the explicit 512 MiB stress limit. The complete
platform, lane, timing, memory, command, and artifact-hash record is stored in
[`benchmarks/results/2026-07-29-native-phase-3-2fb74fb4.md`](../benchmarks/results/2026-07-29-native-phase-3-2fb74fb4.md).

The same large fixture is not a supported `PcntlScheduler` claim. A flat
PCNTL run lost three leaf executors to signal 11, and a later frozen sharded
run still produced a terminal failure. Small PCNTL conformance remains useful
diagnostic coverage, but Drover's native child `_exit` boundary is the product
gate. Bounded plan/result memory and PCNTL large-suite teardown remain Phase 5
work.

## Phase 4 — Native Laravel prepared runtime

Provide Laravel behavior through native Drove contracts rather than a Laravel
`TestCase` or Testbench bridge.

Deliverables:

- A native Laravel environment declaration and Laravel-aware `TestContext`.
- One prepared application and container state per compatible environment plan,
  with explicit rules for when a new prepared scope is required.
- Typed database resource providers with declared capabilities such as
  branchable, scope-isolated, leaf-isolated, resettable, and shared-read-only.
- Initial SQLite memory and SQLite copy providers. Any transactional SQL
  provider must advertise its weaker scope guarantees instead of masquerading
  as branchable state.
- Preflight rejection for declared incompatible traits, providers, and
  resources, plus runtime rejection for reconnects the selected provider can
  observe. `bootstrap/app.php` and `config/*.php` remain trusted executable
  construction/configuration code; arbitrary I/O or direct provider
  registration performed there is outside the isolation guarantee.

Exit gates:

- A native Laravel fixture with at least 300 tests in 30 files has identical
  semantics and assertion counts at C1, C2, C4, C8, C16, and C30.
- Instrumentation proves the application preparation count matches the
  EnvironmentPlan and is not repeated once per test.
- Sibling writes are invisible across prepared scopes, the prepared source
  hash remains unchanged, and no database or filesystem artifacts remain.
- Every provider has fault-injection coverage for prepare, enter, leave, and
  cleanup failures.

## Phase 5 — One-executor-fork topology and declared-concurrency proof

Make isolation affordable without weakening it.

Deliverables:

- Exactly one executor fork for each runnable test. Scope traversal does not
  create resident PHP worker processes solely to coordinate descendants.
- Lazy, bounded scheduling: inactive sibling scopes own no executor, process
  anchor, or prepared resource branch; prepared-but-not-running branches are
  capped at twice the requested test processes.
- The 10,000-case gate must no longer require a 512 MiB PHP limit: plan and
  result retention must fit the declared bound without relying on fixture
  sharding to protect PHP request teardown.
- No batch mode. Each executor receives one test, emits one terminal result,
  and exits.
- Versioned telemetry for planning, environment preparation, execution,
  rendering, wall time, queue wait, state preparation, test-body time, cleanup,
  requested processes, observed lanes, forks, scope workers, executor workers,
  process anchors, peak PIDs, aggregate memory, per-process memory, faults, and
  I/O.
- Signal, timeout, fatal-error, descendant-crash, cancellation, orphan cleanup,
  and replay hardening.

Exit gates:

- C1, C2, C4, C8, C16, and C30 produce identical semantic hashes and complete
  without orphaned processes or state artifacts.
- Active test bodies never exceed the requested process count. With runnable
  work and no resource limit, scheduler-caused idle-lane time remains below
  5%.
- Increasing inactive sibling scopes from 10 to 1,000 at fixed test count and
  concurrency preserves the exact full-lane PID population and changes raw
  execution PSS by no more than a 10% median across three paired repetitions;
  no individual pair may exceed 15%. PHP plan memory is reported separately
  and is never subtracted from `/proc` PSS.
- Aggregate execution memory stays within
  `M(C) <= 1.15 * C * M(C1)` at every declared concurrency, with no swap or
  OOM event.
- On the canonical cheap fixture, C1 is no more than 15% slower than the
  matched reference. On the setup-dominated fixture, Drove is at least 2x
  faster. A 300-test, 100 ms independent-work fixture reaches at least 20x
  C1-to-C30 speedup, and C30 is at least 10% faster than C16.
- At least 100 repeated kill, timeout, and crash injections lose no terminal
  result, leak no descendant, and generate replay artifacts that pass schema
  and redaction validation.

## Phase 6 — Compatibility bridges, migration, and external corpus

Keep migration paths optional and below the native product boundary.

Deliverables:

- Separately loadable Pest, PHPUnit, and Testbench bridges that lower supported
  cases into Scope IR without changing kernel contracts.
- A versioned compatibility registry that classifies each surface as supported,
  explicitly unsupported, or bridge-only.
- A migration scanner and idempotent codemods for portable Pest-like syntax,
  `uses()`, custom expectations, and environment declarations.
- Pinned repositories, commits, dependency locks, prepared images, normalized
  baselines, and hosted artifacts for the external corpus.
- Full-suite discovery and classification for every pinned corpus. Execution
  may use versioned supported, serial-resource, and diagnostic cohorts, but a
  cohort is never represented as full-suite execution.
- The corpus ladder remains Pest, InvoiceShelf, Livewire, and finally Filament.

Exit gates:

- The native dependency guard still passes when all bridges are installed.
- Every case and discovered extension surface in each pinned full suite is
  classified before cohort execution. Supported executed cases have matching
  IDs, statuses, assertions, and exit semantics, explicitly unsupported cases
  have stable diagnostics, and no case remains an unclassified divergence.
- The pinned ladder covers at least the calibrated selections: 797 Pest cases,
  202 InvoiceShelf cases, 288 Livewire cases, and 705 Filament cases.
- Parallel-safe cohorts pass at C1, C2, C4, C8, C16, and C30. Serial resource
  cohorts remain explicit rather than being silently serialized.
- The scanner classifies 100% of full-suite inputs. Across target execution
  cohorts, normalized exact-source baseline/Drove artifacts prove that at least
  80% of cases load unchanged and at least 95% load unchanged or after an
  explicitly recorded idempotent codemod. Static native-migration ratios remain
  diagnostic and cannot be substituted for execution evidence.
- Filament is the last hosted proof and cannot be skipped by an earlier green
  rung.

Current local evidence is implementation proof, not hosted completion. The
exact pinned Pest checkout discovers and classifies its complete configured
suite while preserving runtime compatibility and native-migration status as
separate axes. Its calibrated 785-case parallel and 12-case serial selections
load unchanged through the Pest bridge and match baseline statuses, assertions,
and exits locally. Every normalized result is bound to the same commit, tree,
selected-file bytes, configuration, dependency lock, and prepared Composer
overlay. The all-corpus hosted ladder remains the exit gate.

## Phase 7 — Packaging and public experimental release

Publish only what can be installed and independently exercised.

Deliverables:

- Reproducible prebuilt native binaries for the declared Linux and macOS
  targets, plus installable Composer packages with checksum and provenance
  metadata.
- A tagged experimental release and Packagist publication. The release remains
  pre-v1 and links the exact native support surface and bridge registry.
- A Pest/PHPUnit bridge coverage decision recorded as an ADR and an
  implementation for every bridge driver declared supported; unsupported
  bridge drivers fail explicitly. Native coverage remains undeclared and its
  frontend rejects coverage options.
- Installation, migration, troubleshooting, benchmark, security, attribution,
  and rollback documentation.
- A design-partner program using external Laravel/Pest repositories rather than
  repository-owned fixtures alone.

Exit gates:

- Each published archive installs in a clean supported Linux or macOS image,
  verifies its checksum, runs the native smoke suite, and rejects an
  incompatible platform clearly.
- Packagist installs resolve the tagged package without source-tree paths or
  uncommitted artifacts.
- PCOV 1.0.12 line coverage through `drove --pest` has identical covered-line
  data at C1, C2, C4, C8, C16, and C30 and survives failed, skipped,
  timed-out, and crashed tests without corrupting the report. This bridge gate
  is not native-frontend coverage evidence.
- At least three external design partners run the scanner and benchmark. At
  least two migrate a meaningful suite, retain Drove in CI for two weeks, and
  report reproducible before/after artifacts.
- Public release notes distinguish native guarantees, bridge compatibility,
  known unsupported surfaces, and evidence that remains local or experimental.

The technical evaluation release `v0.4.0-alpha.2` is not yet proven published.
Its local archive consumers and PCOV bridge matrix pass, so it may publish
after the technical release gates without claiming external validation. It is
the installable build that unaffiliated teams evaluate.

The candidate `v0.4.0-alpha.3` remains blocked: the external ledger currently
records 0 of 3 design partners and 0 of 2 retained migrations. Its release path
requires two artifact-backed migrations retained for at least 14 days. Drove
does not manufacture repository-owned evidence or apply that irreducibly
external gate to the build needed to collect it.

An alpha tag can become visible to Packagist before its tag-ref rebuild
finishes. Root promotion therefore builds and verifies branch-ref provenance,
runs the exact-SHA hosted gates and, when required, the design-partner verifier,
and proves that the recorded split `develop` commit has the exact Laravel
subtree before GitHub Actions creates the annotated root tag. A second run at
the tag requires the identically named annotated split tag, then rebuilds and
verifies tag-ref provenance before creating the GitHub release. The split
commit must be prepared first and tagged immediately after the root tag, but
the tags remain separate promotions; this is not atomic cross-repository
enforcement. Source code cannot prove or replace the hosted tag-protection
rules or their actor-level GitHub Actions bypass.

## Non-goals

- A drop-in replacement for every Pest project, plugin, CLI option, or internal
  API.
- PHPUnit, Pest, or Testbench compatibility as a dependency or identity of the
  kernel.
- Running multiple tests in one mutable process, even when batching would
  improve a benchmark.
- Preserving tests that rely on shared mutable state between sibling cases.
- Implicit support for snapshots, architecture tests, test dependencies,
  mutation testing, browser mode, watch mode, alternate printers, or arbitrary
  PHPUnit extensions.
- Treating transaction rollback as hierarchical prepared-state isolation.
- A stable v1 API, semantic-version compatibility promise, or general release
  claim before the roadmap gates pass.
- Windows native binaries in the first public experimental packaging gate.
