# External design-partner validation

Drove's public repositories in the compatibility corpus are test inputs, not
design partners. A partner counts only when an unaffiliated team evaluates a
tagged Drove build in its own suite and reports the outcome.

`v0.4.0-alpha.3` is the installable technical evaluation release; it does not
require or claim external evidence about itself. Its schema-2 collector
reconstructs an independent baseline from the recorded original revision.
`v0.4.0-alpha.4` is the evidence-gated candidate; only it requires three
completed external evaluations plus two meaningful migrations retained in CI
for at least 14 days.

The evaluation release itself has hosted technical proof:
[full corpus #30596079973](https://github.com/oxhq/drove/actions/runs/30596079973),
[native publication #30597049719](https://github.com/oxhq/drove/actions/runs/30597049719),
and
[installed packages #30597292729](https://github.com/oxhq/drove/actions/runs/30597292729).
Those runs make the evaluator installable and reproducible; they do not count
as external partner submissions.

The historical `alpha.2` schema-1 collector used one dependency tree and is
superseded. Its evaluator output does not count toward the external gate.

## Ledger

Status as of 2026-07-30:

| Measure | Count |
| --- | ---: |
| Target | 10 |
| Recruited | 0 |
| Completed evaluations | 0 |
| Retained migrations (14+ days) | 0 |
| Public evidence links | 0 |

No adoption, compatibility, or performance claim is inferred from this empty
ledger. The candidate gate is currently 0 of 3 completed evaluations and 0 of
2 retained migrations.

The machine-readable source of truth is
[`design-partner-evidence.json`](design-partner-evidence.json). Counts in this
page are informational and never satisfy the `v0.4.0-alpha.4` candidate
release gate. For the annotated candidate tag,
`scripts/verify-design-partners.php` requires canonical public GitHub
repositories under distinct non-OxHQ owners, with scanner and benchmark
artifacts bound to the exact Drove evaluation tag and revision. Three different
owners are the machine-enforced minimum; maintainers must still review whether
the named teams are actually unaffiliated because repository metadata cannot
prove organizational independence.
`resources/release.json` fixes `v0.4.0-alpha.3` as the evaluation tag for that
candidate; every entry must use it.
The evaluation tag must be annotated, its recorded revision must match the
repository tag, and that revision must be a strict ancestor of the release
candidate. The gate also requires two meaningful migrations whose
artifact-backed CI timestamps span at least 14 complete days. Every evidence
artifact is downloaded without redirects and checked against its recorded
SHA-256 hash.

Partner evaluation therefore happens against an earlier installable alpha and
gates a later release candidate. Requiring evidence for the not-yet-published
candidate itself would create an impossible publication cycle.

Each schema-2 ledger entry contains `id`, `team`, `repository`, `drove`,
`project_revision`, `evaluation_revision`, `evidence_revision`, and an
`evidence` object with a lowercase `sha256`. `project_revision` is the
unchanged baseline revision **R**. `evaluation_revision` is a strict descendant
**E** containing only the permitted Drove evaluation changes. The distinct
`evidence_revision` is a later commit containing the generated artifact.
GitHub's compare API must prove the strict chain **R < E < evidence revision**.
The artifact URL must be the immutable
`https://raw.githubusercontent.com/<external-owner>/<repo>/<40-sha>/<path>`
form, and its owner, repository, and revision must match the ledger's
`evidence_revision`. The
downloaded evidence is normalized JSON that repeats the entry identity and
records:

- a scanner result where `supported + bridge_only + rejected = discovered`;
- SHA-256 hashes for the baseline and evaluation lockfiles plus the exact
  baseline runner package, version, and Composer source revision;
- a sorted, unique `case_ids` list whose count equals `selected` and whose
  canonical JSON SHA-256 is recomputed by the gate, measured by the matching
  Pest or PHPUnit baseline at C1, Drove at C1, and Drove at one concurrency
  from C2 through C30; at least two cases must be selected and the parallel
  Drove run must observe at least two lanes;
- one supported Linux/macOS native target bound to its OS and architecture,
  an exact PHP `8.4.x`, and the evaluated Drove package version and revision;
- runner, frontend, PHP/Laravel/Testbench runtime identity, a non-secret
  `command_argv`, zero exit code, process count, observed Drove lanes, wall
  milliseconds, peak memory bytes, and its lowercase `rss` source; and
- identical outcomes and assertion counts for that supported selection across
  the baseline and Drove runs.

This separation avoids an impossible commit-hash self-reference: an artifact
cannot name the SHA of the commit that will only exist after the artifact is
written. The gate caps each evidence artifact at 1 MiB, rejects cross-partner artifact
reuse, and rejects a hash or normalized identity mismatch. For a meaningful
migration, its start and verification URLs must be distinct GitHub Actions runs
in the same external repository. The gate checks both through the GitHub API
and requires completed successful `push` or `pull_request` runs using the same
event, exact head revisions and creation timestamps, the same workflow path,
and at least 14 complete days between them.

The benchmark and retention topologies are separate. Benchmark evidence proves
**R < E < F**. Retention starts at **S**, where **S** may equal **E** or strictly
descend from it, and ends at a strict descendant **V** after at least 14 days:
**E <= S < V**. GitHub comparisons prove the strict edges. The `composer.lock`
files at **S** and **V** must both contain the same exact `oxhq/drove`
`0.4.0-alpha.3` package revision; a compatible range or changed package
reference does not prove retention.

Each migration records one stable `drove_step_name`; the immutable workflow
must bind that exact step to one direct, non-informational `vendor/bin/drove`
command under an explicit `shell: bash`. The literal command must include
`--fail-on-empty-test-suite` and cannot use shell variables, substitutions,
globs, redirections, or command separators. The Actions jobs API must prove the
uniquely named step actually completed successfully in both runs. A comment,
inline comment, custom shell, disabled or failure-masking step,
help/version/listing command, empty suite, manual dispatch, successful job
without the named step, or Composer script name does not count as execution.

This is Phase 7's only irreducibly external gate. Code, fixtures, or corpus runs
owned by Drove cannot manufacture it. Until the ledger contains reviewable
partner evidence, candidate tag publication is intentionally blocked. The
technical evaluation release is governed by technical release gates and must
not claim that this external validation already exists. The older `alpha.2`
schema-1 evaluator is superseded rather than treated as qualifying evidence.

`php scripts/verify-design-partners-self-test.php` exercises valid and
adversarial fixtures without network access. Release verification uses the real
immutable raw files and GitHub Actions API instead.

During intake, `php scripts/verify-design-partners.php --partial <ledger.json>
<release-tag> <release-revision>` validates every entry currently present but
does not enforce the 3-evaluation/2-migration minimum. It reports
`release_gate_satisfied: false` and can never stand in for the strict command
used by release workflows. Both modes reject unknown ledger and artifact
fields; the published schema remains the artifact contract.

## Evaluation protocol for `v0.4.0-alpha.3`

The tagged package includes schema-2 `vendor/bin/drove-evaluate`. Schema 1 and
the historical `alpha.2` collector are not accepted by the candidate gate.

### Record the original revision

Start from a clean original revision **R** in the canonical public GitHub
repository. Its tracked `composer.lock` must install without dependency
updates, and it must not contain tracked `vendor` files. Record the full SHA,
then create evaluation branch **E**:

```bash
R="$(git rev-parse HEAD)"
git switch -c drove-evaluation
```

Only these baseline runners are accepted:

| Frontend | Exact package in baseline lock and installed metadata |
| --- | --- |
| Pest | `pestphp/pest` `5.0.1` |
| PHPUnit | `phpunit/phpunit` `13.2.4` |

Install the exact `v0.4.0-alpha.3` Drove tag only in **E**. Its
`composer.lock` must bind `oxhq/drove` to the installed tag and source
revision. The Pest bridge also requires
`brianium/paratest ^7.23.0`, `nunomaduro/collision ^8.9.5`,
`nunomaduro/termwind ^2.4.0`, `pestphp/pest-plugin ^5.0.0`,
`phpunit/phpunit 13.2.4`, and `symfony/process ^8.1.0`.

Across **R..E**, where **E** is the committed evaluation revision,
`composer.json`, `composer.lock`, and `.drove/evaluation-config.json` must each
be changed and must not be removed or renamed. The only additional permitted
paths are optional direct workflow files:

- `composer.json`;
- `composer.lock`;
- `.drove/evaluation-config.json`; and
- optional direct `.github/workflows/*.yml` or
  `.github/workflows/*.yaml` files.

Application source, tests, fixtures, and existing configuration must remain
unchanged. **E** must strictly descend from **R**. A private repository,
anonymized project identity, or evaluation needing any other changed path can
inform engineering but cannot satisfy the release gate.

### Configure and collect

In **E**, create `.drove/evaluation-config.json`.
`baseline_revision` is the full 40-character SHA for **R**; command arrays are
relative and execute directly without a shell:

```json
{
  "schema": 2,
  "evaluation_id": "acme-billing",
  "team": "Acme",
  "repository": "https://github.com/acme/billing",
  "frontend": "pest",
  "runtime": "laravel",
  "parallel_processes": 8,
  "baseline_revision": "0123456789abcdef0123456789abcdef01234567",
  "baseline_command_argv": ["vendor/bin/pest", "--colors=never", "tests/Feature"],
  "drove_command_argv": ["vendor/bin/drove", "--pest", "tests/Feature"]
}
```

Commit the Composer changes and config so **E** is a clean strict descendant of
**R**, install the tagged native library, then run the two-step flow. The
explicit absolute `DROVER_LIBRARY` keeps the immutable `alpha.3` collector's
detached evaluation worktree on the verified package-local library:

```bash
git add composer.json composer.lock .drove/evaluation-config.json
git commit -m "test: configure Drove evaluation"
vendor/bin/drove-install-native
DROVER_LIBRARY="$(php -r 'require "vendor/autoload.php"; echo Drove\Kernel\NativeLibrary::bundledPath(Drove\Kernel\NativeLibrary::target());')" \
  vendor/bin/drove-evaluate collect
git add .drove/evaluation.json
git commit -m "test: record Drove evaluation"
vendor/bin/drove-evaluate seal > /tmp/drove-ledger-entry.json
```

`collect` verifies the canonical origin, the strict **R < E** ancestry, the
changed-path allow-list, and the evaluation dependency tree. It creates fresh
detached worktrees at **R** and **E**, runs `composer install` from each
unchanged tracked lock, and validates the exact package and installed metadata.
Discovery and the baseline C1 run execute in the disposable **R** worktree;
Drove runs at C1 plus the configured parallel count in the disposable **E**
worktree. Every recorded Drove command contains one `--pest` bridge selector
followed by the collector-owned `--parallel`, matching `--processes=N`, and
deterministic `--replay` suffix. The hosted gate revalidates that exact contract
and rejects instrumentation on the baseline command. The collector removes both
worktrees after success or failure.

`DROVER_LIBRARY` is a non-secret local path and is not written to evidence.

The artifact records **R**, **E**, both lockfile hashes, the baseline runner
package identity, exact case IDs, outcomes, assertions, wall milliseconds,
observed lanes, and sampled root-runner RSS. It never stores runner output or
environment variables. **E** must be clean, and non-ignored untracked project
files are forbidden. Every measured run must exit successfully with no failed
tests. Temporary logs and replays are removed. The non-secret evaluation config
is committed first so its selection is part of **E**.

The baseline argv defines the evaluated scanner and benchmark selection; it is
not silently widened to the full suite. If any selected source has a
`bridge-only` or `unsupported` scanner finding, collection stops and the team
must choose a smaller honest selection. A generated artifact therefore reports
that exact selection as `supported`; it does not relabel bridge-only cases or
claim unselected cases were classified or executed.

`seal` refuses uncommitted or changed artifact bytes, verifies that **E** is a
strict ancestor of the current artifact commit, and emits the complete ledger
entry with immutable raw URL and SHA-256. Copy that entry to Drove's ledger; do
not edit measured values. The artifact shape is published in
[`design-partner-evidence.schema.json`](design-partner-evidence.schema.json).

Migration retention is not part of the config or measured artifact: the later
verification revision and run do not exist when the benchmark is collected.
After at least 14 complete days, release maintainers may add the optional
`migration` object only to the Drove ledger entry. The hosted verifier binds
that later metadata to **E <= S < V**, both Actions runs, both workflows, and
the exact Drove lock at S and V; the measured artifact remains independently
bound to **R < E < F**.

One completed evaluation records:

1. the Drove tag, native target, PHP version, baseline revision **R**,
   evaluation revision **E**, evidence revision, and sorted selected case IDs;
2. both lockfile hashes, the exact baseline runner package identity, and the
   unchanged Pest or PHPUnit baseline `command_argv` plus each Drove
   `command_argv`, without environment values, tokens, or other secrets;
3. discovered, passed, failed, skipped, incomplete, risky, rejected, and
   assertion counts at concurrency 1 and one realistic parallel setting;
4. any unexpected divergence, explicit rejection, crash, timeout, leaked
   process, or leftover state artifact; and
5. permission to publish the canonical repository identity and normalized
   result.

The team may redact application output and private local paths. Repository
identity, revisions, dependency identities, commands, normalized counts, and
the sanitized artifact remain public and reviewable. Private feedback can
inform engineering but never counts toward this release gate.

For a retained migration, the later ledger metadata identifies **S**, **V**,
and both immutable CI runs. The start and verification runs must use the same
workflow path, event, stable Drove step name, and exact `alpha.3` lock. **S**
equals or descends from **E**; **V** strictly descends from **S**. Sealing the
separate benchmark artifact creates **F**.

Use the repository's **Design partner evaluation** issue form to volunteer.
An evaluation is not an endorsement, and a divergence is a useful result.
