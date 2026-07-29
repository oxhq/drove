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

## Evaluation protocol

One completed evaluation records:

1. the Drove tag, native target, PHP version, project revision, and selected
   test paths;
2. the unchanged Pest or PHPUnit baseline command and the Drove command;
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
