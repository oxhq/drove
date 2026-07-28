# Drove Phase 2 file-plan slice

This experiment proves one pre-generation Pest compatibility path:

1. Pest's overridden loader includes one test file.
2. After `include_once` and before `makeIfNeeded()` or `eval()`, it captures an
   ordered, scalar file plan with a stable root-relative file ID, test IDs, and
   describe ancestry.
3. A PHP registry maps those IDs to closures and later resolves the generated
   Pest test cases without using discovery position.
4. One file-level `beforeAll` prepares the root snapshot once.
5. Two sibling tests fork from that snapshot, and the root aggregates their
   results in plan order.

Run it on Linux through Docker:

```bash
docker build -f experiments/phase-2/Dockerfile -t drove-phase-two .
docker run --rm drove-phase-two
```

## Known boundary

This is a file-level execution proof, not a complete `SuitePlan` or hierarchical
hook planner. Only explicit, file-local, argument-free test closures are
accepted. Pest does not expose nested `beforeAll`; datasets, inherited `uses()`
configuration, reporters, a native scheduler, Laravel integration, external-state
isolation, and structured setup-failure unwinding are not included. It makes no
performance or full-IR claim.
