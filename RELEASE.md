# Experimental release process

Drove `0.x` releases are experimental. Release tags use
`v0.4.0-alpha.N` until the compatibility and distribution contracts stabilize.
The historical Pest tags remain attribution history and are not Drove
releases.

## Required gates

1. The release commit is merged into `develop`, and `origin/develop` points to
   the same commit.
2. Static Analysis, Tests, Drove Phase Gates, Release Gates, Native DSL,
   Native Extensions, Native Laravel, Native Topology, Bridge Coverage, and
   the bridge-free Native Corpus Ladder complete successfully for that commit.
3. Dispatch the Compatibility Corpus workflow against that exact `develop`
   commit and record its successful full-ladder run ID. It has no smoke tier.
   Both release runs refuse to promote or publish without its unexpired
   `corpus-full-<SHA>-attempt-<N>` artifact.
4. Dispatch the native distribution workflow from that exact `develop` commit
   with the intended version and exact split Laravel commit. Its branch run must
   build and attest all four targets, rerun the release policy and exact-SHA
   hosted gates, verify exact root-subtree/split-tree equality and branch-ref
   attestations, and authorize the exact commit for manual tag creation.
5. `composer validate --strict`, locked Rust tests, the installed-consumer
   smoke, and the package archive checks pass.
6. Public compatibility and release notes match the supported surface.
7. Two clean GNU/Linux glibc 2.31+ or macOS target builds reproduce
   byte-for-byte inside the same hosted job and environment. Linux base images
   are digest-pinned and dependencies are lock-pinned. Deterministic archives
   have SHA-256 checksums and provenance manifests/attestations and pass the ABI
   smoke. Hosted runner labels still roll, so this is not cross-host native
   reproducibility proof.
8. When `resources/release.json` marks the version as an evidence-gated
   candidate, the design-partner evidence required by
   `docs/design-partner-validation.md` and the native roadmap is complete.

## Hosted trust boundary

Protect root `v0.*` release tags with an active repository ruleset that
restricts tag creation, update, and deletion. Repository administrators are the
explicit bypass and trust root for the one manual tag-creation operation after
the authorized branch run; GitHub Actions has no ruleset bypass.

The only job with `contents: write` must use the protected `native-release`
environment with an explicit human reviewer. Builds may finish before approval,
but neither root tag promotion nor GitHub release publication may begin without
that environment approval.

An administrator bypass is an actor-level repository trust boundary, not a
grant scoped to this procedure. Repository administrators and applications
with repository-administration authority can change or bypass the ruleset and
must be treated as trusted release actors. Keep the repository default token at
read-only, grant write permission only to the protected release job, and
protect changes to `develop` and `.github/workflows/native-release.yml` through
repository review policy. Source checks inside the workflow are defense in
depth; they do not replace the hosted ruleset.

The root `GITHUB_TOKEN` has no authority in `oxhq/drove-laravel`. Strict,
atomic cross-repository promotion remains unavailable without a separately
authorized split-repository mechanism; this process does not claim it. The root
workflow only proves that the recorded split `develop` commit exists and has
the exact `packages/drove-laravel` tree before authorizing the manual root tag.

Protect split `v0.*` tags against update and deletion without a bypass. Initial
split-tag creation remains the separately authorized manual operation described
below; after creation the tag is immutable unless a repository administrator
changes the ruleset.

The `push.tags` trigger is the normal publication path after the administrator
creates the authorized root tag. It still requires an annotated tag at the
exact `develop` SHA, an already annotated exact-tree split tag, the same hosted
gates, release policy, and tag-ref attestations.

## Publish

1. Split `packages/drove-laravel` from the exact root `develop` commit, push
   that tree to `https://github.com/oxhq/drove-laravel` `develop`, and record
   its 40-character commit as `LARAVEL_COMMIT`. Do this before creating either
   release tag.
2. Dispatch the root release promotion from the exact `develop` commit:

   ```bash
   gh workflow run native-release.yml \
     --ref develop \
     -f version="$RELEASE_TAG" \
     -f laravel_commit="$LARAVEL_COMMIT"
   ```

3. The branch run builds and attests the exact root `develop` SHA, applies the
   release policy and existing hosted gates, proves that `LARAVEL_COMMIT` is
   the current split `develop` commit with the exact root subtree, verifies
   attestations bound to `refs/heads/develop`, waits for approval of the
   protected `native-release` environment, and authorizes that exact version,
   root commit, and split commit for manual tagging. It does not create a tag.
4. Pause the Packagist push webhooks for both repositories. Confirm that the
   intended version is still absent from both Packagist package APIs. Keep the
   hooks paused if any later publication gate fails.
5. Create the annotated tag first at `LARAVEL_COMMIT` in
   `oxhq/drove-laravel`. Then, as a root-repository administrator, create the
   same annotated root tag at the authorized root `develop` commit. The root
   tag push starts the immutable publication workflow. Do not move or delete
   either tag after creation.
6. If the root tag push did not start the workflow, dispatch the immutable root
   tag manually:

   ```bash
   gh workflow run native-release.yml \
     --ref "$RELEASE_TAG" \
     -f version="$RELEASE_TAG" \
     -f laravel_commit="$LARAVEL_COMMIT"
   ```

   The root tag run (automatic or manual) rebuilds the four native archives,
   requires the identically named annotated split tag at `LARAVEL_COMMIT`,
   produces attestations bound to the exact tag ref, verifies them, waits for
   the protected-environment approval, and creates the immutable GitHub
   prerelease. The tagged commit must remain an ancestor of root `develop`, so
   later development does not strand an already verified immutable tag. Verify
   every expected archive, checksum, provenance manifest, and GitHub
   attestation. Verification must bind
   `.github/workflows/native-release.yml`, the release commit and tag ref, and
   reject self-hosted provenance.
7. After the GitHub prerelease and all 13 assets are verified, reactivate both
   Packagist hooks and ping each repository webhook through GitHub's hook API.
   Packagist treats that ping as an update request and rescans the repository's
   current tags. Require HTTP 202 from both deliveries, then verify that both
   package APIs resolve the intended tag to the exact root and split commits.
8. Dispatch the Published package workflow with the exact tag plus the root and
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
9. Record both native-release run IDs (branch promotion and tag publication),
   the exact tags, root and split commits, and published-package workflow
   runs, Packagist versions, and normalized consumer artifacts in the release
   notes. A technical evaluation release must also link directly to the
   design-partner guide and submission form, including the native-library step
   required before its evaluator runs.

Packagist publication and GitHub artifacts are separate gates. A green source
workflow does not prove either one. The temporary webhook pause prevents a
normal push-triggered crawl from exposing a mismatched or assetless alpha; it
does not disable Packagist's independent fallback crawls or make the two
repositories atomic. Verify the package APIs before and after tagging. If a
release is defective, publish a new prerelease; never move or overwrite an
existing tag.
