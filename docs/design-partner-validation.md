# External design-partner validation

Drove's public repositories in the compatibility corpus are test inputs, not
design partners. A partner counts only when an unaffiliated team evaluates a
tagged Drove build in its own suite and reports the outcome.

## Ledger

Status as of 2026-07-29:

| Measure | Count |
| --- | ---: |
| Target | 10 |
| Recruited | 0 |
| Completed evaluations | 0 |
| Public evidence links | 0 |

No adoption, compatibility, or performance claim is inferred from this empty
ledger.

The machine-readable source of truth is
[`design-partner-evidence.json`](design-partner-evidence.json). Counts in this
page are informational and never satisfy the release gate. For an annotated
release tag, `scripts/verify-design-partners.php` requires three unique external
teams and repositories with scanner and benchmark artifacts bound to that exact
Drove evaluation tag and revision. `resources/release.json` fixes that
evaluation tag for the candidate; every entry must use it. The evaluation tag
must be annotated, its recorded revision must match the repository tag, and
that revision must be a strict ancestor of the release candidate. It also
requires two meaningful migrations whose artifact-backed CI timestamps span at
least 14 complete days. Every evidence artifact is downloaded without redirects
and checked against its recorded SHA-256 hash.

Partner evaluation therefore happens against an earlier installable alpha and
gates a later release candidate. Requiring evidence for the not-yet-published
candidate itself would create an impossible publication cycle.

Each ledger entry contains `id`, `team`, `repository`, `drove`,
`project_revision`, and an `evidence` object with a lowercase `sha256`. Its URL
must be the immutable
`https://raw.githubusercontent.com/<external-owner>/<repo>/<40-sha>/<path>`
form, and its owner, repository, and revision must match the ledger. The
downloaded evidence is normalized JSON that repeats the entry identity and
records:

- a scanner result where `supported + bridge_only + rejected = discovered`;
- a sorted, unique `case_ids` list whose count equals `selected` and whose
  canonical JSON SHA-256 is recomputed by the gate, measured by the matching
  Pest or PHPUnit baseline at C1, Drove at C1, and Drove at one concurrency
  from C2 through C30;
- one supported Linux/macOS native target bound to its OS and architecture,
  an exact PHP `8.4.x`, and the evaluated Drove package version and revision;
- runner, frontend, PHP/Laravel/Testbench runtime identity, a non-secret
  `command_argv`, process count, observed Drove lanes, wall milliseconds, peak
  memory bytes, and its RSS/PSS/cgroup source; and
- identical outcomes and assertion counts for that supported selection across
  the baseline and Drove runs.

The gate caps each evidence artifact at 1 MiB, rejects cross-partner artifact
reuse, and rejects a hash or normalized identity mismatch. For a meaningful
migration, its start and verification URLs must be distinct GitHub Actions runs
in the same external repository. The gate checks both through the GitHub API
and requires completed successful `push` or `pull_request` runs using the same
event, exact head revisions and creation timestamps, the same workflow path,
and at least 14 complete days between them. GitHub's compare API must prove the
start revision is a strict ancestor of the verified revision. Each migration
records one stable `drove_step_name`; the immutable workflow must bind that
exact step to one direct, non-informational `vendor/bin/drove` command under an
explicit `shell: bash`, and the Actions jobs API must prove the uniquely named
step actually completed successfully in both runs. A comment, inline comment,
custom shell, disabled or failure-masking step, help/version/listing command,
manual dispatch, successful job without the named step, or Composer script name
does not count as execution.

This is Phase 7's only irreducibly external gate. Code, fixtures, or corpus runs
owned by Drove cannot manufacture it. Until the ledger contains reviewable
partner evidence, tag publication is intentionally blocked while ordinary
workflow-dispatch native builds remain available.

`php scripts/verify-design-partners-self-test.php` exercises valid and
adversarial fixtures without network access. Release verification uses the real
immutable raw files and GitHub Actions API instead.

## Evaluation protocol

One completed evaluation records:

1. the Drove tag, native target, PHP version, project revision, and sorted
   selected case IDs;
2. the unchanged Pest or PHPUnit baseline `command_argv` and each Drove
   `command_argv` without environment values, tokens, or other secrets;
3. discovered, passed, failed, skipped, incomplete, risky, rejected, and
   assertion counts at concurrency 1 and one realistic parallel setting;
4. any unexpected divergence, explicit rejection, crash, timeout, leaked
   process, or leftover state artifact; and
5. permission to publish either the project name or an anonymized result.

The team may redact application output and paths. A private evaluation can
inform engineering but does not become public evidence without a reviewable,
sanitized artifact.

Use the repository's **Design partner evaluation** issue form to volunteer.
An evaluation is not an endorsement, and a divergence is a useful result.
