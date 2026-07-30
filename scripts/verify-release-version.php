<?php

declare(strict_types=1);

use Drove\Version;

$root = dirname(__DIR__);

$readJson = static function (string $path): array {
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException(sprintf('Release version input %s is unreadable.', $path));
    }

    $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($value)) {
        throw new RuntimeException(sprintf('Release version input %s is invalid.', $path));
    }

    return $value;
};

$release = $readJson($root.'/resources/release.json');
$candidate = $release['candidate_version'] ?? null;
$evaluation = $release['evaluation_version'] ?? null;
$candidateRequiresEvidence = $release['candidate_requires_design_partner_evidence'] ?? null;
$evaluationRequiresEvidence = $release['evaluation_requires_design_partner_evidence'] ?? null;

if (($release['schema'] ?? null) !== 1
    || ! is_string($candidate)
    || ! is_string($evaluation)
    || $candidateRequiresEvidence !== true
    || $evaluationRequiresEvidence !== false) {
    throw new RuntimeException('DROVE_RELEASE_VERSION_INVALID: candidate and evaluation versions are inconsistent.');
}

$candidateParts = [];
$evaluationParts = [];

if (preg_match('/^0\.([0-9]+)\.([0-9]+)-alpha\.([0-9]+)$/D', $candidate, $candidateParts) !== 1
    || preg_match('/^0\.([0-9]+)\.([0-9]+)-alpha\.([0-9]+)$/D', $evaluation, $evaluationParts) !== 1
    || array_slice($candidateParts, 1, 2) !== array_slice($evaluationParts, 1, 2)
    || (int) $candidateParts[3] !== (int) $evaluationParts[3] + 1) {
    throw new RuntimeException('DROVE_RELEASE_VERSION_INVALID: candidate and evaluation versions are inconsistent.');
}

$candidateTag = 'v'.$candidate;
$evaluationTag = 'v'.$evaluation;
$requestedTag = $argv[1] ?? $candidateTag;
$outputFormat = $argv[2] ?? 'json';

if ((isset($argv) && count($argv) > 3)
    || ! in_array($outputFormat, ['json', '--github-output'], true)
    || ! in_array($requestedTag, [$evaluationTag, $candidateTag], true)) {
    throw new RuntimeException(sprintf(
        'DROVE_RELEASE_VERSION_MISMATCH: expected %s or %s, received %s.',
        $evaluationTag,
        $candidateTag,
        $requestedTag,
    ));
}

$releaseRole = $requestedTag === $evaluationTag
    ? 'technical-evaluation'
    : 'evidence-gated-candidate';
$requiresDesignPartnerEvidence = $requestedTag === $candidateTag
    ? $candidateRequiresEvidence
    : $evaluationRequiresEvidence;

require_once $root.'/src/Drove/Version.php';

if ($candidate.'-dev' !== Version::FALLBACK) {
    throw new RuntimeException('DROVE_RELEASE_VERSION_MISMATCH: Version fallback is stale.');
}

$corpus = $readJson($root.'/benchmarks/corpus/manifest.json');
$corpusEvaluation = $corpus['evaluation_version'] ?? null;

if (($corpus['candidate_version'] ?? null) !== $evaluation
    || ! is_string($corpusEvaluation)
    || ($corpus['release_overlay_version'] ?? null) !== $corpusEvaluation
    || version_compare($evaluation, $corpusEvaluation, '<=')) {
    throw new RuntimeException('DROVE_RELEASE_VERSION_MISMATCH: corpus does not prove the technical evaluation release.');
}

$registry = $readJson($root.'/resources/drove-compatibility.json');
$proof = $registry['proof'] ?? null;
$releasePolicy = $registry['release_policy'] ?? null;

if (! is_array($proof)
    || $releasePolicy !== [
        'evaluation_tag' => $evaluationTag,
        'candidate_tag' => $candidateTag,
        'evaluation_requires_design_partner_evidence' => false,
        'candidate_requires_design_partner_evidence' => true,
    ]
    || ($proof['phase_gates'] ?? null)
        !== "https://github.com/oxhq/drove/blob/$evaluationTag/.github/workflows/phase-one.yml"
    || ($proof['external_corpus'] ?? null)
        !== "https://github.com/oxhq/drove/blob/$evaluationTag/benchmarks/corpus/manifest.json") {
    throw new RuntimeException('DROVE_RELEASE_VERSION_MISMATCH: compatibility proof URLs are stale.');
}

$requiredText = [
    'README.md' => [
        "**Experimental alpha:** `$evaluationTag`",
        "candidate `$candidateTag`",
        "https://github.com/oxhq/drove/tree/$evaluationTag/benchmarks/corpus",
    ],
    'docs/migration-from-pest.md' => [
        "https://github.com/oxhq/drove/releases/tag/$evaluationTag",
        "git clone --branch $evaluationTag",
    ],
    'docs/native-drove-roadmap.md' => [
        "technical evaluation release `$evaluationTag`",
        "candidate `$candidateTag`",
    ],
    'docs/design-partner-validation.md' => [
        "`$evaluationTag` is the installable technical evaluation release",
        "`$candidateTag` is the evidence-gated candidate",
    ],
    '.github/ISSUE_TEMPLATE/design_partner.yml' => [
        $evaluationTag,
    ],
    '.github/ISSUE_TEMPLATE/bug_report.yml' => [
        $evaluationTag,
    ],
    '.github/workflows/native-release.yml' => [
        '"$GITHUB_REF_NAME"',
        'REQUESTED_VERSION: ${{ inputs.version }}',
        'actions: read',
        'source_ref=refs/heads/develop',
        '--source-ref "$RELEASE_SOURCE_REF"',
        'LARAVEL_COMMIT: ${{ inputs.laravel_commit }}',
        '- name: Promote exact develop SHA to annotated tag',
        'git merge-base --is-ancestor HEAD origin/develop',
        'test "$(git rev-parse origin/develop)" = "$RELEASE_SHA"',
        'split_ref="refs/drove-laravel/tags/$RELEASE_VERSION"',
        'test "$laravel_commit" = "$LARAVEL_COMMIT"',
        '--github-output',
        "if: steps.release_policy.outputs.requires_design_partner_evidence == 'true'",
    ],
    '.github/workflows/published-package.yml' => [
        '"$RELEASE_VERSION"',
        '--github-output',
        "if: steps.release_policy.outputs.requires_design_partner_evidence == 'true'",
    ],
    'native/drover/README.md' => [
        "https://github.com/oxhq/drove/tree/$evaluationTag",
    ],
    'bin/drove-install-native' => [
        "--version=$evaluationTag",
    ],
];

foreach ($requiredText as $path => $needles) {
    $contents = file_get_contents($root.'/'.$path);

    foreach ($needles as $needle) {
        if (! is_string($contents) || ! str_contains($contents, $needle)) {
            throw new RuntimeException(sprintf(
                'DROVE_RELEASE_VERSION_MISMATCH: %s does not reference %s.',
                $path,
                $needle,
            ));
        }
    }
}

$identity = [
    'schema' => 1,
    'candidate_version' => $candidate,
    'candidate_tag' => $candidateTag,
    'evaluation_version' => $evaluation,
    'evaluation_tag' => $evaluationTag,
    'requested_tag' => $requestedTag,
    'release_role' => $releaseRole,
    'requires_design_partner_evidence' => $requiresDesignPartnerEvidence,
];

if ($outputFormat === '--github-output') {
    echo sprintf(
        "release_role=%s\nrequires_design_partner_evidence=%s\n",
        $releaseRole,
        $requiresDesignPartnerEvidence ? 'true' : 'false',
    );

    exit(0);
}

echo json_encode(
    $identity,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
