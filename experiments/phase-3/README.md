# Drove Phase 3 hierarchical hook-IR slice

This experiment proves that explicit Pest hooks compile before test-case
generation into a scalar hierarchy:

1. file hooks remain on the file scope;
2. nested `beforeEach` and `afterEach` hooks remain on their describe scopes;
3. sibling describes with the same label receive distinct stable scope IDs;
4. each test receives outer-to-inner setup IDs and inner-to-outer teardown IDs;
5. hook IDs resolve to their original PHP closures without serializing them.

Run it on Linux through Docker:

```bash
docker build -f experiments/phase-3/Dockerfile -t drove-phase-three .
docker run --rm drove-phase-three
```

## Known boundary

This is hook IR and planning only. The proof covers explicit, file-local hooks
passed directly to Pest's hook functions. It does not execute hook or test
bodies or prove lifecycle execution, partial-failure unwind, nested
`beforeAll`, imported, global, or higher-order hooks, `->after()`, datasets,
Laravel integration, or a Drove scheduler.
