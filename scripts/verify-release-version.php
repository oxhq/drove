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

if (($release['schema'] ?? null) !== 1
    || ! is_string($candidate)
    || ! is_string($evaluation)
    || preg_match('/^0\.[0-9]+\.[0-9]+-alpha\.[0-9]+$/D', $candidate) !== 1
    || preg_match('/^0\.[0-9]+\.[0-9]+-alpha\.[0-9]+$/D', $evaluation) !== 1
    || version_compare($candidate, $evaluation, '<=')) {
    throw new RuntimeException('DROVE_RELEASE_VERSION_INVALID: candidate and evaluation versions are inconsistent.');
}

$candidateTag = 'v'.$candidate;
$requestedTag = $argv[1] ?? $candidateTag;

if ($requestedTag !== $candidateTag) {
    throw new RuntimeException(sprintf(
        'DROVE_RELEASE_VERSION_MISMATCH: expected %s, received %s.',
        $candidateTag,
        $requestedTag,
    ));
}

require_once $root.'/src/Drove/Version.php';

if ($candidate.'-dev' !== Version::FALLBACK) {
    throw new RuntimeException('DROVE_RELEASE_VERSION_MISMATCH: Version fallback is stale.');
}

$corpus = $readJson($root.'/benchmarks/corpus/manifest.json');

if (($corpus['candidate_version'] ?? null) !== $candidate
    || ($corpus['evaluation_version'] ?? null) !== $evaluation
    || ($corpus['release_overlay_version'] ?? null) !== $evaluation) {
    throw new RuntimeException('DROVE_RELEASE_VERSION_MISMATCH: corpus candidate/evaluation identity is stale.');
}

$registry = $readJson($root.'/resources/drove-compatibility.json');
$proof = $registry['proof'] ?? null;

if (! is_array($proof)
    || ($proof['phase_gates'] ?? null)
        !== "https://github.com/oxhq/drove/blob/$candidateTag/.github/workflows/phase-one.yml"
    || ($proof['external_corpus'] ?? null)
        !== "https://github.com/oxhq/drove/blob/$candidateTag/benchmarks/corpus/manifest.json") {
    throw new RuntimeException('DROVE_RELEASE_VERSION_MISMATCH: compatibility proof URLs are stale.');
}

$requiredText = [
    'README.md' => [
        "**Experimental alpha:** `$candidateTag`",
        "https://github.com/oxhq/drove/tree/$candidateTag/benchmarks/corpus",
    ],
    'docs/migration-from-pest.md' => [
        "https://github.com/oxhq/drove/releases/tag/$candidateTag",
        "git clone --branch $candidateTag",
    ],
    'native/drover/README.md' => [
        "https://github.com/oxhq/drove/tree/$candidateTag",
    ],
    'bin/drove-install-native' => [
        "--version=$candidateTag",
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

echo json_encode([
    'schema' => 1,
    'candidate_version' => $candidate,
    'candidate_tag' => $candidateTag,
    'evaluation_version' => $evaluation,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
