# Drove Phase 2 compatibility proof

This experiment exercises the Pest compatibility frontend against generated
PHPUnit cases. It proves:

- filtered PHPUnit discovery before scheduling;
- stable per-dataset IDs and scalar Scope IR;
- custom `TestCase` and `uses()` binding;
- Drove-owned hooks running exactly once;
- passed, failed, skipped, and todo outcomes;
- original PHPUnit failure transport and output-once rendering;
- pruning of fully filtered scope subtrees.

Run the frontend, kernel seam, and renderer proofs on Linux:

```bash
docker build -f experiments/phase-2/Dockerfile -t drove-phase-two .
docker run --rm drove-phase-two
docker run --rm drove-phase-two php kernel-seam.php
docker run --rm drove-phase-two php renderer.php
```

The separate installed-project proof covers the `drove` executable, path and
suite selection, filters, groups, parallel process selection, and exit codes:

```bash
docker build -f experiments/phase-2-cli/Dockerfile -t drove-phase-two-cli .
docker run --rm drove-phase-two-cli
```

This remains a source-built Linux alpha. See
[`docs/migration-from-pest.md`](../../docs/migration-from-pest.md) for the
supported and rejected Pest surfaces.
