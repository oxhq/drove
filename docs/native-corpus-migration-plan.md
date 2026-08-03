# Native corpus migration plan

This plan gates the removal of Pest, PHPUnit, and Orchestra Testbench bridges
from Drove's evaluated corpus paths. It does not promise source compatibility
with every upstream feature. It covers the concrete semantics required by the
pinned Pest, InvoiceShelf, Livewire, and Filament corpus cohorts.

## Completion contract

A corpus case is native only when:

- its source, or an explicitly recorded idempotent codemod result, declares a
  Drove-owned frontend that lowers directly to Scope IR;
- execution does not boot `Pest\Kernel`, construct a PHPUnit test suite, call
  `PHPUnit\Framework\TestCase::runBare()`, or invoke Testbench test-case
  lifecycle methods;
- no compatibility bridge entrypoint is loaded;
- the native result matches the pinned independent baseline for case identity,
  status, assertions, output, hook order, cleanup, and exit semantics; and
- every declared parallel-safe cohort retains the same native semantic hash at
  C1, C2, C4, C8, C16, and C30 without batching or shared mutable sibling
  state; resource-sensitive cohorts remain named C1-only cohorts rather than
  being silently serialized inside a parallel run.

The compatibility bridge may remain available as a separately selected
migration aid while this plan is in progress. A bridge result never satisfies
a native corpus gate.

## Ordering rule

C1 timings continue to be recorded, but Drove will not add an inline,
single-process, no-isolation C1 path or optimize around current bridge timings
until all four native corpus gates below pass. Prepared state remains part of
the execution contract whenever the EnvironmentPlan declares it.

## Phase N1 — Executable native corpus contract

Deliverables:

- Versioned native cohorts for every pinned corpus, expressed as exact source
  paths and case IDs rather than copied fixtures.
- A guard that fails if a native cohort loads Pest frontend/runtime classes,
  PHPUnit execution classes, Testbench lifecycle classes, or a registered
  compatibility bridge. A corpus may load a namespace as production code under
  test; for example, Pest's own corpus necessarily loads `Pest\Support`.
- Normalized baseline/native artifacts with dependency-lock, source-tree,
  codemod, plan, semantic, timing, lane, fork, and memory identity.
- Comparable phase telemetry uses four canonical boundaries: `preparation`
  (discovery, codemod, and environment preparation as applicable), `planning`,
  `execution` (dispatch, test bodies, and lifecycle cleanup), and `verification`.
  End-to-end wall time additionally includes final cleanup and JSON rendering;
  those costs remain visible in wall time but are not separate comparable phases.

Exit gate:

- At least one pinned Pest source file executes through the native command and
  the forbidden-runtime guard, with exact baseline parity.

## Phase N2 — Pest-like native frontend

Deliverables:

- Portable declarations, hooks, datasets, groups, skips, todo, timeouts, and
  expected exceptions needed by the pinned cohorts.
- A general expectation catalog covering the common value, type, collection,
  containment, count, key, comparison, exception, and negation assertions used
  by those cohorts.
- Domain matchers enter through the typed extension API. Higher-order syntax is
  expanded by an idempotent codemod; it does not gain implicit runtime magic.
- `uses()` becomes an explicit native environment/context declaration.
- Snapshots, when required by a selected native cohort, use a typed filesystem
  provider and never mutate files outside its managed workspace.

Exit gate:

- The pinned Pest native cohort passes without Pest's frontend/runtime or
  PHPUnit execution lifecycle loaded and every remaining full-suite construct
  is either assigned to a later native provider/frontend or rejected with a
  stable diagnostic.

## Phase N3 — Drove-owned class frontend

Deliverables:

- Native class and method discovery for the lifecycle, datasets, groups,
  statuses, and metadata used by the pinned Livewire cohort.
- Drove-owned attributes and context lower directly to Scope IR. Native test
  classes neither extend PHPUnit TestCase nor call PHPUnit assertions.
- An idempotent migration path converts the selected PHPUnit-shaped sources to
  the native class frontend without carrying `runBare()` semantics forward.

Exit gate:

- The pinned Livewire native cohort passes without PHPUnit or Testbench
  lifecycle classes loaded and matches its independent PHPUnit baseline.

## Phase N4 — Native Laravel and Testbench-shaped environments

Deliverables:

- A typed Laravel testing context for the HTTP, response, database, container,
  filesystem, event, queue, and application helpers exercised by the native
  cohorts.
- `RefreshDatabase` intent becomes explicit prepared-schema/resource data; it
  is not inherited from a TestCase trait.
- A Drove-owned package-test environment replaces Testbench TestCase profiles,
  callbacks, and reflection over internal lifecycle fields.
- InvoiceShelf completes before Filament. Filament remains the final and
  broadest proof.

Exit gates:

- InvoiceShelf passes natively from one verified prepared database master.
- Livewire package state passes through the native environment.
- Filament's selected nonserial and serial-resource cohorts pass natively with
  no unmanaged state residue.

## Phase N5 — Bridge retirement from evaluated paths

Deliverables:

- Native corpus installations exclude Pest, PHPUnit, and Testbench runtime
  packages unless application production code independently requires one.
- Native corpus commands contain no `--pest`, bridge bootstrap, PHPUnit suite
  builder, generated Pest TestCase, `runBare()`, or Testbench lifecycle route.
  Separate reference-runner jobs remain mandatory for baseline parity.
- Compatibility packages and commands are marked migration-only and are not
  used by native release claims.

Exit gate:

- The complete pinned ladder passes Pest, InvoiceShelf, Livewire, and Filament
  in that order, and a static plus runtime guard proves the bridge-free path.

## Phase N6 — Performance decision after native parity

Deliverables:

- Correct independent baselines use separate locked dependency trees.
- InvoiceShelf prepares and hashes its schema once; baseline and native runs
  start from equivalent database state.
- C1, C2, C4, C8, C16, and C30 run at least five times in randomized order on
  recorded CPU and memory quotas. Reports include median, p95, dispersion,
  observed lanes, wall time, phase time, forks, PSS, RSS, and cgroup peak.
- Observed lanes preserve actual corpus overlap within the requested cap; the
  deliberate N5 barrier, not incidental short-test duration, proves full lane capacity.

Exit gate:

- Only after N1-N5 pass may Drove decide whether C1 needs another topology or
  whether C8 is a default, resource-specific cap, or merely a property of the
  former bridge path.
