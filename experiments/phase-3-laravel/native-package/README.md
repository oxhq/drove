# Native Livewire package gate

This gate proves that Drove can migrate and execute a pinned, real Laravel
package cohort without installing or loading Pest, PHPUnit, or Orchestra
Testbench. It is meant to close the native package-runtime boundary, not to
claim that every Livewire test is supported.

Run the complete gate from the repository root:

```console
composer test:native-livewire-package
```

The command builds the pinned Linux image, reconstructs an independent PHPUnit
12.5.33/Testbench 11.1.0 baseline in an ephemeral vendor, creates a separate
runtime-only native vendor, clones Livewire at commit
`9c1450739d30c9b0b223ad6512be2a33f8f62f96`, and mounts that checkout read-only.
The proof rejects a dirty checkout, the wrong commit, or a source file whose
Git blob differs from the committed baseline. Both Composer manifests and locks
are committed under `benchmarks/corpus/locks`: the baseline contains 121
packages, the native graph contains 77, and their 74 shared production packages
must match by version, source reference, and dist reference. The native graph
must contain no PHPUnit, Testbench, Pest, or ParaTest package.

The baseline extraction and native source stage normalize source-file mtimes to
the pinned commit timestamp recorded in both manifests. This keeps Livewire's
compiler-expiry tests independent of checkout and migration duration without a
sleep or a test-specific source rewrite; the gate rejects a manifest clock that
does not equal the pinned Git commit timestamp.

The gate requires all of the following:

- the authoritative 20-file cohort produces 288 cases, 285 passes, three
  incompletes, and 1,034 assertions at C1;
- every case runs in its own child PID and the scheduler reports exactly one
  fork per case, with every topology counter checked exactly;
- a task-aware post-lifecycle audit binds every scheduler task ID, kind, scope,
  and PID to its result telemetry, and rejects the entire `Drove\Bridge\`
  namespace and source tree in addition to Pest, PHPUnit, and Testbench;
- the migrated source, staged application, storage tree, prepared in-memory
  SQLite state, and route definitions remain unchanged;
- injected post-boot failures exit with stable diagnostics and leave no staged
  source, evidence file, application mutation, storage mutation, or checkout
  mutation;
- real body, deferred-cleanup, and stateful `afterAll` fault probes prove that
  forbidden runtimes loaded late in the lifecycle are attributed to the exact
  test or scope worker with stable diagnostics;
- the parallel-safe seven-file cohort preserves the same 36 cases, 68
  assertions, and semantic hash at C1, C2, C4, C8, C16, and C30, while a
  filesystem barrier deterministically saturates every requested lane; its
  exact pre-armed PID window is `min(2 * processes, 36)`, while body concurrency
  remains exactly the requested process count; and
- level-eight PHPStan passes while loading the clean runtime vendor.

The independent baseline and native execution must expose the same 288 exact
case rows: ID, status, per-case assertion count, stdout, and stderr. The
committed schema-2 rows contain 285 passes, three incompletes, 1,034 assertions,
and empty output streams; each gate reconstructs the real PHPUnit baseline and
requires its row hash to match both the committed rows and the native result.
Runtime inspection covers the root plus all 288 descendants after each complete
worker lifecycle. Its class/file self-tests and lifecycle fault probes must
reject injected bridge runtimes. A failure to remove an ephemeral checkout,
vendor volume, or artifact workspace is a gate failure rather than a warning.

The full cohort remains C1 because some selected Livewire tests intentionally
exercise shared filesystem paths. The seven-file matrix is the explicit
parallel-safe proof; neither path batches test bodies.

For a prebuilt image or an existing checkout, set
`DROVE_NATIVE_LIVEWIRE_SKIP_BUILD=1`, `DROVE_NATIVE_LIVEWIRE_IMAGE`, and
`DROVE_NATIVE_LIVEWIRE_CHECKOUT`. Set
`DROVE_NATIVE_LIVEWIRE_ARTIFACT_DIR` to retain the normalized JSON summaries.
