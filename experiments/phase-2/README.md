# Drove Phase 2 compatibility proof

This experiment exercises the Pest compatibility frontend against generated
PHPUnit cases. It proves:

- filtered PHPUnit discovery before scheduling;
- stable per-dataset IDs and scalar Scope IR;
- custom `TestCase` and `uses()` binding;
- Drove-owned hooks running exactly once;
- passed, failed, skipped, and todo outcomes;
- original PHPUnit failure transport and output-once rendering;
- pruning of fully filtered files and scope subtrees;
- no implicit file or test timeout.

Run the frontend, kernel seam, and renderer proofs on Linux:

```bash
docker build -f experiments/phase-2/Dockerfile -t drove-phase-two .
docker run --rm drove-phase-two
docker run --rm drove-phase-two php kernel-seam.php
docker run --rm drove-phase-two php renderer.php
```

The separate installed-project proof builds Drover and covers the real `drove`
executable, path and suite selection, filters, groups, named and positional
datasets, direct `uses()` binding, instance lifecycle, deterministic output at
concurrency 1 and 8, and exit codes:

```bash
docker build -f experiments/phase-2-cli/Dockerfile -t drove-phase-two-cli .
docker run --rm drove-phase-two-cli
```

It also requires explicit exit-2 diagnostics for ordinary PHPUnit cases,
dependencies, process isolation, custom static class lifecycle, and XML time
limits. This remains a source-built Linux alpha. See
[`docs/migration-from-pest.md`](../../docs/migration-from-pest.md) for the
supported and rejected Pest surfaces.
