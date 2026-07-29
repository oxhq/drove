# Drove Phase 0

This spike tests the first Drove claim without changing Pest's runner:

1. initialize PHPUnit and boot Laravel once;
2. prepare in-memory, SQLite, and Redis state once;
3. disconnect inherited PDO and phpredis handles and purge Laravel's caches;
4. fork two sibling tests;
5. reconnect PDO and Redis in each child;
6. bind a real Laravel `TestCase` to the inherited application;
7. run `assertDatabaseHas()` through PHPUnit's normal test runner;
8. return one JSON result per child through Unix socket pairs;
9. survive an uncaught child error and kill a timed-out process group.

Run it on Linux through Docker with a real Redis server:

```bash
docker build -t drove-phase-zero experiments/phase-0
docker network create drove-phase-zero
docker run --rm -d --name drove-phase-zero-redis --network drove-phase-zero redis:8.2.8-alpine@sha256:3790f265260957949f41e767efd9b66d5e3f277913b63a56c0183d55a47e7347
until [ "$(docker exec drove-phase-zero-redis redis-cli ping)" = PONG ]; do sleep 0.1; done
docker run --rm --network drove-phase-zero -e REDIS_HOST=drove-phase-zero-redis drove-phase-zero
docker run --rm drove-phase-zero php failure.php
docker exec drove-phase-zero-redis redis-cli HGETALL drove:phase-0:child-writes
docker stop drove-phase-zero-redis
docker network rm drove-phase-zero
```

A successful run ends with `"status": "passed"` and shows one Laravel
bootstrap PID, one scope preparation, two distinct child PIDs, three passing
assertions per Laravel `TestCase`, isolated sibling memory mutations,
re-established SQLite and Redis connections, and both child PIDs in Redis's
shared external hash. The failure proof separately reports the uncaught error
and timed-out process group while the root survives.
The spike exits nonzero if setup or any proof check fails.

## Known boundary

This proves a single prepared scope, PDO repair, and phpredis repair against one
shared Redis database. Redis child writes are intentionally external shared
state, not isolated state. It does not yet prove nested scopes, hook unwinding,
concurrent database writes, Redis namespacing or rollback, coverage, or
integration with Pest's discovery and reporters. The reported memory figures
are process peaks, not a full copy-on-write accounting. The `TestCase` binding
is explicit; Pest's generated test-case factory and binding path remain
unproven. Timeout handling is a fixed failure-injection proof, not a scheduler.

The root must remain single-threaded before `pcntl_fork()`. Extensions or
clients that create background threads, cache process IDs, or own native
connections need explicit fork-safety checks before Drove can support them.
