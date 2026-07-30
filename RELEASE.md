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
   `develop` commit and record its successful run ID. Both release runs refuse
   to promote or publish without its unexpired `corpus-full-<SHA>` artifact.
4. Dispatch the native distribution workflow from that exact `develop` commit
   with the intended version and exact split Laravel commit. Its branch run must
   build and attest all four targets, rerun the release policy and exact-SHA
   hosted gates, verify exact root-subtree/split-tree equality and branch-ref
   attestations, and only then promote the root commit to a tag.
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

Protect `v0.*` release tags with an active repository ruleset that restricts tag
creation, update, and deletion. The GitHub Actions app may bypass that ruleset
for the release workflow; human and team bypass should remain absent if direct
tags are meant to be prohibited. No custom GitHub App is required for this root
repository promotion.

That bypass is an actor-level repository trust boundary, not a grant scoped to
one workflow file. Any workflow that receives a `GITHUB_TOKEN` with
`contents: write` can act as GitHub Actions. Keep the repository default at
read-only, grant write permission only to the release job, and protect changes
to `develop` and `.github/workflows/native-release.yml` through repository
review policy. Source checks inside the workflow are defense in depth; they do
not replace the hosted ruleset.

The root `GITHUB_TOKEN` has no authority in `oxhq/drove-laravel`. Strict,
atomic cross-repository promotion remains unavailable without a separately
authorized split-repository mechanism; this process does not claim it. The root
workflow only proves that the recorded split `develop` commit exists and has
the exact `packages/drove-laravel` tree before creating the root tag.

The `push.tags` trigger remains a defensive path for a tag created outside the
promotion flow: it still requires an annotated tag at the exact `develop` SHA,
an already annotated exact-tree split tag, the same hosted gates, release
policy, and tag-ref attestations. It is not the normal authorization path.

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
   attestations bound to `refs/heads/develop`, and creates an annotated root tag
   with its `GITHUB_TOKEN`. A tag created by `GITHUB_TOKEN` does not start the
   tag-push workflow. Do not rerun the branch promotion or move/delete the tag
   after it succeeds.
4. Immediately create and push the same annotated tag at `LARAVEL_COMMIT` in
   `oxhq/drove-laravel`. This remains a separately authorized action; do not
   move either tag after creation.
5. Dispatch the immutable root tag only after the split tag exists:

   ```bash
   gh workflow run native-release.yml \
     --ref "$RELEASE_TAG" \
     -f version="$RELEASE_TAG" \
     -f laravel_commit="$LARAVEL_COMMIT"
   ```

   The root tag run rebuilds the four native archives, requires the identically
   named annotated split tag at `LARAVEL_COMMIT`, produces attestations bound
   to the exact tag ref, verifies them, and creates the immutable GitHub
   prerelease. The tagged commit must remain an ancestor of root `develop`, so
   later development does not strand an already verified immutable tag. Verify
   every expected archive, checksum, provenance manifest, and GitHub
   attestation. Verification must bind
   `.github/workflows/native-release.yml`, the release commit and tag ref, and
   reject self-hosted provenance.
6. Submit or update both GitHub repositories on Packagist as `oxhq/drove` and
   `oxhq/drove-laravel`.
7. Dispatch the Published package workflow with the exact tag plus the root and
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
8. Record both native-release run IDs (branch promotion and tag publication),
   the exact tags, root and split commits, and published-package workflow
   runs, Packagist versions, and normalized consumer artifacts in the release
   notes.

Packagist publication and GitHub artifacts are separate gates. A green source
workflow does not prove either one. If a release is defective, publish a new
prerelease; never move or overwrite an existing tag.
