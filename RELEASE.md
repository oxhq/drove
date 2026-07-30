# Experimental release process

Drove `0.x` releases are experimental. Release tags use
`v0.4.0-alpha.N` until the compatibility and distribution contracts stabilize.
The historical Pest tags remain attribution history and are not Drove
releases.

## Required gates

1. The release commit is merged into `develop`, and `origin/develop` points to
   the same commit.
2. Static Analysis, Tests, Drove Phase Gates, Release Gates, Native DSL,
   Native Extensions, Native Laravel, Native Topology, and Bridge Coverage
   complete successfully for that commit.
3. Dispatch the External Corpus workflow with `tier=full` against that exact
   `develop` commit and record its successful run ID. The tag workflow refuses
   to publish without its unexpired `corpus-full-<SHA>` artifact.
4. Dispatch the native distribution workflow against that exact `develop`
   commit and verify all four build jobs before tagging.
5. `composer validate --strict`, locked Rust tests, the installed-consumer
   smoke, and the package archive checks pass.
6. Public compatibility and release notes match the supported surface.
7. Two clean GNU/Linux glibc 2.31+ or macOS target builds reproduce
   byte-for-byte inside the same hosted job and environment. Linux base images
   are digest-pinned and dependencies are lock-pinned. Deterministic archives
   have SHA-256 checksums and provenance manifests/attestations and pass the ABI
   smoke. Hosted runner labels still roll, so this is not cross-host native
   reproducibility proof.
8. The design-partner evidence required by
   `docs/design-partner-validation.md` and the native roadmap is complete.

## Publish

1. Create an annotated tag from the verified `develop` commit:

   ```bash
   git tag -a v0.4.0-alpha.N -m "Drove v0.4.0-alpha.N"
   git push origin v0.4.0-alpha.N
   ```

2. The tag workflow builds native release assets and creates a prerelease on
   GitHub. Verify every expected archive, checksum, provenance manifest, and
   GitHub attestation before publishing release notes. Attestation verification
   must bind `.github/workflows/native-release.yml`, the release commit and tag
   ref, and reject self-hosted provenance.
3. Split `packages/drove-laravel` at that commit into
   `https://github.com/oxhq/drove-laravel`, push its `develop` branch, and add
   the same annotated release tag to the split commit.
4. Submit or update both GitHub repositories on Packagist as `oxhq/drove` and
   `oxhq/drove-laravel`.
5. Dispatch the Published package workflow with the exact tag plus the root and
   split Laravel commits. All four pinned Linux/macOS runner families must
   resolve both Packagist dists without path repositories, verify the native
   checksum and attestation, install the matching asset, reject incompatible
   platforms, and execute passing and failing suites.

   ```bash
   gh workflow run published-package.yml \
     --ref "$RELEASE_TAG" \
     -f version="$RELEASE_TAG" \
     -f commit="$RELEASE_COMMIT" \
     -f laravel_commit="$LARAVEL_COMMIT"
   ```

   `--ref` must be the exact annotated tag; dispatching the workflow from a
   branch or another tag fails before package resolution.
6. Record the exact tags, commits, native-release and published-package workflow
   runs, Packagist versions, and normalized consumer artifacts in the release
   notes.

Packagist publication and GitHub artifacts are separate gates. A green source
workflow does not prove either one. If a release is defective, publish a new
prerelease; never move or overwrite an existing tag.
