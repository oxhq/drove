# ADR 0001: Pest/PHPUnit bridge coverage aggregation

- Status: Accepted for the experimental Pest/PHPUnit bridge
- Date: 2026-07-29

## Context

Coverage state belongs to the process that executed a test. The Drove
Pest/PHPUnit bridge gives every runnable bridged test its own fork, so a
root-only coverage driver cannot observe the executed lines. Reusing an
executor for several tests would weaken Drove's isolation contract.

The native Drove frontend does not implement or advertise coverage. Its CLI
rejects PHPUnit/Pest coverage options as unknown native options. This ADR and
its evidence therefore do not establish native-frontend coverage.

## Decision

When `drove --pest` selects the Pest/PHPUnit bridge, Drove starts coverage
inside each bridged test fork, writes one atomic fragment after that test
reaches a PHPUnit terminal state, and merges the fragments in the root process
before PHPUnit generates reports.

The bridge release-gated configuration is:

- PCOV 1.0.12, the version pinned by the hosted gate;
- line coverage on Linux;
- PHPUnit's PHP coverage report as the canonical parity artifact;
- C1, C2, C4, C8, C16, and C30.

Failed tests contribute the lines collected before their assertion failure.
Skipped, incomplete, todo, and risky tests do not contribute coverage. A test
killed by a timeout or signal may leave no fragment; completed siblings are
still merged and a valid report is generated with the suite's non-zero exit.
Temporary writes use a separate suffix and are renamed atomically, so an
interrupted write is never read as a fragment.

Bridge coverage requested without an active supported driver fails before
execution with `Drove could not initialize the requested code coverage
driver.` The bare Pest `--coverage` presentation switch and PHPUnit strict
coverage policies remain explicitly unsupported.

Xdebug line, branch, and path coverage are not release-gated by this decision.
The shared PHPUnit integration may accept Xdebug, but it remains experimental
until the same concurrency and fault matrix runs with Xdebug in hosted CI.
Other report formats consume the merged PHPUnit object but remain beta until
they receive their own deterministic artifact checks.

## Consequences

- Bridge coverage preserves one-fork-per-test isolation; there is no batch
  mode.
- A crash can omit only the crashing test's fragment, not corrupt the report.
- The canonical gate compares covered-line data with serial PHPUnit and across
  every declared Drove bridge concurrency.
- PCOV branch and path coverage are outside the supported surface.
- Native coverage remains unsupported until it has its own implementation and
  exact native-frontend gate; this bridge gate cannot satisfy that requirement.
