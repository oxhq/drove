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
| 3. Native DSL feature surface | PENDING | Declared DSL conformance matrix passes at every declared concurrency |
| 4. Native Laravel prepared runtime | PENDING | Laravel fixture proves prepared boot and sibling resource isolation |
| 5. Bounded one-fork execution | PENDING | Declared-concurrency topology, telemetry, reliability, and performance gates pass |
| 6. Bridges and migration corpus | PENDING | Classified bridge parity ladder passes, ending with Filament |
| 7. Public experimental release | PENDING | Installable artifacts, publication, coverage, and design-partner gates pass |

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
- Native declarations accept zero-parameter closures only. Test bodies,
  `beforeEach`, and `afterEach` execute with `$this` bound to one Drove-owned
  `TestContext` per case. `beforeAll` and `afterAll` are scope hooks and receive
  neither `$this`, `TestContext`, nor a kernel context.
- Drove-owned identity and equality assertions whose failures are classified
  by the kernel without a PHPUnit assertion object.
- A dependency guard that fails when `src/Drove/Native` references Pest,
  PHPUnit, or Testbench. Package extraction and removal of compatibility
  dependencies from the root manifest remain later work.

Exit gates:

- The committed fixture covers a file scope, a nested scope, all four hook
  phases, per-case fixtures, passes, and a native assertion failure.
- Repeated capture from at least two working directories, using the same
  explicit suite root, emits identical Scope IR, portable plan IDs, source
  paths, and source lines.
- The shared declaration guard rejects parameters on native closures before
  execution. Per-case closures prove their `$this` binding, while scope hooks
  run through an unbound, zero-argument wrapper.
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
  identical at C1, C2, C4, C8, C16, and C30.
- A 10,000-test stress fixture completes without lost results, duplicate IDs,
  leaked deferred cleanup, or cross-test memory mutation.
- CLI help and native documentation contain only behavior covered by the
  conformance matrix.

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
- Preflight rejection for reconnects, traits, providers, or external resources
  that invalidate the selected prepared-state guarantee.

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
  concurrency changes execution peak PIDs and execution memory by no more than
  10% after subtracting plan metadata.
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
  cohorts, at least 80% of cases load unchanged and at least 95% load unchanged
  or after an idempotent codemod.
- Filament is the last hosted proof and cannot be skipped by an earlier green
  rung.

## Phase 7 — Packaging and public experimental release

Publish only what can be installed and independently exercised.

Deliverables:

- Reproducible prebuilt native binaries for the declared Linux and macOS
  targets, plus installable Composer packages with checksum and provenance
  metadata.
- A tagged experimental release and Packagist publication. The release remains
  pre-v1 and links the exact native support surface and bridge registry.
- A coverage aggregation decision recorded as an ADR and an implementation for
  every driver declared supported; unsupported drivers fail explicitly.
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
- Supported coverage output has identical covered-line data at C1, C2, C4, C8,
  C16, and C30 and survives failed, skipped, timed-out, and crashed tests
  without corrupting the report.
- At least three external design partners run the scanner and benchmark. At
  least two migrate a meaningful suite, retain Drove in CI for two weeks, and
  report reproducible before/after artifacts.
- Public release notes distinguish native guarantees, bridge compatibility,
  known unsupported surfaces, and evidence that remains local or experimental.

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
