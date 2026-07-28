# Drove Phase 1 discovery slice

This spike proves one narrow bridge from the Pest fork into prepared-state
execution:

1. PHPUnit loads a real file containing `it()` through Pest's overridden
   `TestSuiteLoader`;
2. PHPUnit's normal builder and Pest's normal suite filter create and initialize
   the generated `TestCase`;
3. a real Pest `beforeAll` prepares Laravel and SQLite once in the root;
4. that concrete generated case runs in a forked child;
5. the child's memory mutation stays private and its result returns as JSON.

Run it on Linux through Docker:

```bash
docker build -f experiments/phase-1/Dockerfile -t drove-phase-one .
docker run --rm drove-phase-one
```

A successful run ends with `"status": "passed"`, proves the installed
`TestCaseFactory.php` matches this checkout and the Pest override loader is
active, and includes a post-discovery scalar manifest with stable root-relative
file/test IDs and source locations.

## Known boundary

This is a one-test discovery-to-execution bridge, not the Drove runner. It does
not yet provide a pre-generation scope IR, replace PHPUnit's suite runner,
replay child events into Pest reporters, support datasets or nested scopes, or
classify setup failures. Generated PHPUnit identities remain execution evidence
and are not part of the post-discovery scalar manifest.
