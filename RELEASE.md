# Experimental release process

Drove `0.x` releases are experimental. Release tags use
`v0.4.0-alpha.N` until the compatibility and distribution contracts stabilize.
The historical Pest tags remain attribution history and are not Drove
releases.

## Required gates

1. The release commit is merged into `develop`, and `origin/develop` points to
   the same commit.
2. Static Analysis, Tests, Drove Phase Gates, Release Gates, and the required
   corpus smoke complete successfully for that commit.
3. Dispatch the External Corpus workflow with `tier=full` against that exact
   `develop` commit and record its successful run ID. The tag workflow refuses
   to publish without its unexpired `corpus-full-<SHA>` artifact.
4. Dispatch the native distribution workflow against that exact `develop`
   commit and verify all four build jobs before tagging.
5. `composer validate --strict`, locked Rust tests, the installed-consumer
   smoke, and the package archive checks pass.
6. Public compatibility and release notes match the supported surface.
7. The GNU/Linux glibc 2.31+ and macOS native archives exist with SHA-256
   checksums and pass their ABI smoke on hosted runners.

## Publish

1. Create an annotated tag from the verified `develop` commit:

   ```bash
   git tag -a v0.4.0-alpha.N -m "Drove v0.4.0-alpha.N"
   git push origin v0.4.0-alpha.N
   ```

2. The tag workflow builds native release assets and creates a prerelease on
   GitHub. Verify every expected archive and checksum before publishing release
   notes.
3. Split `packages/drove-laravel` at that commit into
   `https://github.com/oxhq/drove-laravel`, push its `develop` branch, and add
   the same annotated release tag to the split commit.
4. Submit or update both GitHub repositories on Packagist as `oxhq/drove` and
   `oxhq/drove-laravel`.
5. Install both tagged packages in a disposable consumer, install the matching
   native asset, and execute a passing and failing suite. Begin the clean
   consumer with
   `composer config --no-plugins allow-plugins.pestphp/pest-plugin true`.
6. Record the exact tags, commits, workflow run, Packagist versions, and
   consumer commands in the release notes.

Packagist publication and GitHub artifacts are separate gates. A green source
workflow does not prove either one. If a release is defective, publish a new
prerelease; never move or overwrite an existing tag.
