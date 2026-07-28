# Drove Phase 0

This spike tests the first Drove claim without changing Pest's runner:

1. initialize PHPUnit and boot Laravel once;
2. prepare in-memory and SQLite state once;
3. disconnect the inherited PDO handle;
4. fork two sibling tests;
5. reconnect PDO in each child;
6. bind a real Laravel `TestCase` to the inherited application;
7. run `assertDatabaseHas()` through PHPUnit's normal test runner;
8. return one JSON result per child through Unix socket pairs.

Run it on Linux through Docker:

```bash
docker build -t drove-phase-zero-testcase experiments/phase-0
docker run --rm drove-phase-zero-testcase
```

A successful run ends with `"status": "passed"` and shows one Laravel
bootstrap PID, one scope preparation, two distinct child PIDs, three passing
assertions per Laravel `TestCase`, isolated sibling memory mutations, and a
usable SQLite connection in every child.

## Known boundary

This proves a single prepared scope with read-only external state. It does not
yet prove nested scopes, concurrent database writes, Redis repair, timeouts,
coverage, or integration with Pest's discovery and reporters. The reported
memory figures are process peaks, not a full copy-on-write accounting.
The `TestCase` binding is explicit in this spike; Pest's generated test-case
factory and binding path remain unproven.

The root must remain single-threaded before `pcntl_fork()`. Extensions or
clients that create background threads, cache process IDs, or own native
connections need explicit fork-safety checks before Drove can support them.
