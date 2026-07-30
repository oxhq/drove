<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) !== 3) {
    fwrite(
        STDERR,
        "Usage: php benchmarks/results/render-corpus.php ARTIFACT_DIR EXPECTED_SHA\n",
    );
    exit(2);
}

$artifactDirectory = realpath($arguments[1]);
$expectedRevision = $arguments[2];

if (! is_string($artifactDirectory)
    || ! is_dir($artifactDirectory)
    || preg_match('/^[0-9a-f]{40}$/D', $expectedRevision) !== 1) {
    fail('Artifact directory or expected revision is invalid.');
}

verifySeal($artifactDirectory);

$path = $artifactDirectory.DIRECTORY_SEPARATOR.'benchmark-report.json';
$metadata = jsonFile($artifactDirectory.DIRECTORY_SEPARATOR.'evidence-metadata.json');
$report = jsonFile($path);
$manifest = jsonFile(dirname(__DIR__).'/corpus/manifest.json');
$verifiedReport = verifyCompleteCorpus(completeResultPaths($artifactDirectory, $manifest));
$groups = $report['diagnostic_comparisons']['groups'] ?? null;
$ladder = $manifest['ladder'] ?? null;
$classifications = $report['full_suite_classifications'] ?? null;
$runId = $metadata['run_id'] ?? null;
$runAttempt = $metadata['run_attempt'] ?? null;

if (($metadata['schema_version'] ?? null) !== 1
    || ($metadata['drove_revision'] ?? null) !== $expectedRevision
    || ($metadata['tier'] ?? null) !== 'full'
    || ($metadata['repository'] ?? null) !== 'oxhq/drove'
    || ! is_string($metadata['hosted_image'] ?? null)
    || $metadata['hosted_image'] === ''
    || ! is_int($runId)
    || $runId < 1
    || ! is_int($runAttempt)
    || $runAttempt < 1) {
    fail('Evidence metadata is not an exact full oxhq/drove corpus artifact.');
}

if (($report['verification'] ?? null) !== 'passed'
    || ($report['diagnostic_comparisons']['performance_claim'] ?? null) !== 'none'
    || ($report['diagnostic_comparisons']['thresholds_applied'] ?? null) !== false
    || ! is_array($groups)
    || ! is_array($ladder)
    || ! is_array($classifications)
    || array_column($classifications, 'corpus') !== $ladder
    || ($report['drove_revision'] ?? null) !== $expectedRevision
    || $verifiedReport !== $report) {
    fail('Report is not a passing, complete, diagnostic-only corpus artifact.');
}

$expected = [];

foreach ($manifest['corpora'] ?? [] as $corpus) {
    foreach ($corpus['hosted_gate_matrix'] ?? [] as $cohort => $matrix) {
        foreach (['baseline', 'drove'] as $runner) {
            $expected[$corpus['id'].'/'.$cohort.'/'.$runner] = $matrix[$runner] ?? [];
        }
    }
}

$actual = [];

foreach ($groups as $group) {
    $identity = $group['runner_identity'] ?? null;
    $requested = $group['requested_processes'] ?? null;
    $observed = $group['observed_lanes'] ?? null;
    $runs = $group['runs'] ?? null;

    if (! is_array($identity)
        || ! is_string($group['corpus'] ?? null)
        || ! is_string($group['cohort'] ?? null)
        || ! in_array($group['runner'] ?? null, ['baseline', 'drove'], true)
        || ! is_string($identity['frontend'] ?? null)
        || ! is_string($identity['runtime'] ?? null)
        || ! is_int($requested)
        || $requested < 1
        || ! is_int($observed)
        || $observed < 1
        || $observed > $requested
        || ! is_int($runs)
        || $runs < 1) {
        fail('Report contains an invalid runner, process, lane, or run identity.');
    }

    $actual[implode('/', [$group['corpus'], $group['cohort'], $group['runner']])][] = $requested;
}

array_walk($actual, static function (array &$widths): void {
    sort($widths);
});
ksort($actual);
ksort($expected);

if ($actual !== $expected) {
    fail('Report process matrix does not match the committed corpus manifest.');
}

$revision = $report['drove_revision'] ?? '';
$platform = $report['platform'] ?? [];
$hash = hash_file('sha256', $path);

if (! preg_match('/^[0-9a-f]{40}$/D', (string) $revision)
    || ! is_string($hash)
    || ! is_string($platform['os_family'] ?? null)
    || ! is_string($platform['architecture'] ?? null)
    || ! is_string($platform['php'] ?? null)) {
    fail('Report revision, platform, or source hash is invalid.');
}

echo '# Compatibility benchmark artifact — `'.substr($revision, 0, 8)."`\n\n";
echo "This is a diagnostic snapshot, not a performance claim.\n\n";
echo "- Drove revision: `$revision`\n";
echo "- Source run: [GitHub Actions #$runId](https://github.com/oxhq/drove/actions/runs/$runId), attempt $runAttempt\n";
echo "- Evidence tier/image: `full` / `{$metadata['hosted_image']}`\n";
echo "- Source `benchmark-report.json` SHA-256: `$hash`\n";
echo "- Platform: {$platform['os_family']} {$platform['architecture']}, PHP {$platform['php']}\n";
echo "- Baselines run at C1; parallel-safe Drove cohorts run at C1/C2/C4/C8/C16/C30; serial cohorts remain C1.\n";
echo "- Observed Drove lanes come from replay metadata; the baseline lane is an explicit single-process assumption.\n";
echo "- `wall_ms` covers only the timed command. Container peak memory is the fresh container's aggregate cgroup-v2 peak and includes earlier container setup. Replay PHP peak is the maximum sampled process, not aggregate memory.\n";
echo "- `Runs` is the sample count; metric columns report min/median/max. No performance threshold is applied.\n\n";
echo "| Corpus | Cohort | Runner | Frontend/runtime | Requested | Observed lanes | Lane source | Runs | Tests/assertions | Wall ms min/median/max | Container peak bytes min/median/max | Replay ms min/median/max | Replay PHP peak bytes min/median/max |\n";
echo "|---|---|---|---|---:|---:|---|---:|---:|---:|---:|---:|---:|\n";

foreach ($groups as $group) {
    $outcome = $group['outcome'] ?? null;
    $identity = $group['runner_identity'];
    $baseline = $group['runner'] === 'baseline';

    if (! is_array($outcome)
        || ! is_int($outcome['tests'] ?? null)
        || ! is_int($outcome['assertions'] ?? null)) {
        fail('Report outcome is invalid.');
    }

    echo '| '.implode(' | ', [
        cell($group['corpus']),
        cell($group['cohort']),
        cell($group['runner']),
        cell($identity['frontend'].'/'.$identity['runtime']),
        (string) $group['requested_processes'],
        (string) $group['observed_lanes'],
        $baseline ? 'single-process assumption' : 'Drove replay',
        (string) $group['runs'],
        $outcome['tests'].'/'.$outcome['assertions'],
        triple($group['wall_ms'] ?? null),
        triple($group['container_peak_memory_bytes'] ?? null, decimals: 0),
        triple($group['replay_duration_ms'] ?? null, nullable: $baseline),
        triple($group['replay_php_peak_memory_bytes'] ?? null, decimals: 0, nullable: $baseline),
    ])." |\n";
}

/**
 * @return array<string, mixed>
 */
function jsonFile(string $path): array
{
    if (! is_file($path)) {
        fail("Cannot read $path.");
    }

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : fail("$path is not a JSON object.");
}

function verifySeal(string $directory): void
{
    $checksumPath = $directory.DIRECTORY_SEPARATOR.'SHA256SUMS';
    $lines = is_file($checksumPath)
        ? file($checksumPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : false;

    if (! is_array($lines)) {
        fail('Artifact does not contain a readable SHA256SUMS seal.');
    }

    $sealed = [];

    foreach ($lines as $line) {
        if (preg_match('/^([0-9a-f]{64})  \.\/([^\/\\\\\r\n]+)$/D', $line, $match) !== 1
            || isset($sealed[$match[2]])) {
            fail('Artifact contains an invalid or duplicate checksum entry.');
        }

        $file = $directory.DIRECTORY_SEPARATOR.$match[2];

        if (! is_file($file)
            || is_link($file)
            || hash_file('sha256', $file) !== $match[1]) {
            fail("Artifact checksum failed for {$match[2]}.");
        }

        $sealed[$match[2]] = true;
    }

    $entries = scandir($directory);

    if (! is_array($entries)) {
        fail('Cannot enumerate artifact directory.');
    }

    $actual = [];

    foreach ($entries as $entry) {
        if (in_array($entry, ['.', '..', 'SHA256SUMS'], true)) {
            continue;
        }

        $file = $directory.DIRECTORY_SEPARATOR.$entry;

        if (! is_file($file) || is_link($file)) {
            fail('Artifact must be a flat directory of regular sealed files.');
        }

        $actual[$entry] = true;
    }

    ksort($actual);
    ksort($sealed);

    if ($actual !== $sealed
        || ! isset($sealed['benchmark-report.json'], $sealed['evidence-metadata.json'])) {
        fail('Artifact seal does not cover the exact evidence bundle.');
    }
}

/**
 * @param  array<string, mixed>  $manifest
 * @return list<string>
 */
function completeResultPaths(string $directory, array $manifest): array
{
    $classifications = [];
    $results = [];
    $resultCount = 0;
    $paths = glob($directory.DIRECTORY_SEPARATOR.'*.json');

    if (! is_array($paths)) {
        fail('Cannot enumerate corpus JSON artifacts.');
    }

    foreach ($paths as $path) {
        $document = jsonFile($path);

        if (($document['kind'] ?? null) === 'full-suite-classification') {
            $corpus = $document['corpus'] ?? null;

            if (! is_string($corpus) || isset($classifications[$corpus])) {
                fail('Artifact contains an invalid or duplicate full-suite classification.');
            }

            $classifications[$corpus] = $path;

            continue;
        }

        if (($document['schema_version'] ?? null) !== 3) {
            continue;
        }

        $corpus = $document['corpus'] ?? null;
        $cohort = $document['selection']['cohort'] ?? null;
        $runner = $document['runner'] ?? null;
        $processes = $document['requested_processes'] ?? null;
        $run = $document['run'] ?? null;

        if (! is_string($corpus)
            || ! is_string($cohort)
            || ! in_array($runner, ['baseline', 'drove'], true)
            || ! is_int($processes)
            || $processes < 1
            || ! is_int($run)
            || $run < 1
            || isset($results[$corpus][$cohort][$runner][$processes][$run])) {
            fail('Artifact contains an invalid or duplicate normalized corpus result.');
        }

        $results[$corpus][$cohort][$runner][$processes][$run] = $path;
        $resultCount++;
    }

    $ordered = [];
    $ladder = $manifest['ladder'] ?? null;

    if (! is_array($ladder)) {
        fail('Corpus manifest ladder is invalid.');
    }

    foreach ($ladder as $corpus) {
        if (! is_string($corpus) || ! isset($classifications[$corpus])) {
            fail('Artifact is missing a ladder classification.');
        }

        $ordered[] = $classifications[$corpus];
    }

    if (count($classifications) !== count($ordered)) {
        fail('Artifact contains a classification outside the committed ladder.');
    }

    $orderedResultCount = 0;

    foreach ($manifest['corpora'] ?? [] as $corpus) {
        $id = $corpus['id'] ?? null;

        if (! is_string($id)) {
            fail('Corpus manifest identity is invalid.');
        }

        foreach ($corpus['hosted_gate_matrix'] ?? [] as $cohort => $matrix) {
            foreach (['baseline', 'drove'] as $runner) {
                foreach ($matrix[$runner] ?? [] as $processes) {
                    $runs = $results[$id][$cohort][$runner][$processes] ?? [];

                    if ($runs === []) {
                        fail("Artifact is missing $id/$cohort/$runner/C$processes.");
                    }

                    ksort($runs, SORT_NUMERIC);

                    foreach ($runs as $path) {
                        $ordered[] = $path;
                        $orderedResultCount++;
                    }
                }
            }
        }
    }

    if ($orderedResultCount !== $resultCount) {
        fail('Artifact contains a normalized result outside the committed process matrix.');
    }

    return $ordered;
}

/**
 * @param  list<string>  $paths
 * @return array<string, mixed>
 */
function verifyCompleteCorpus(array $paths): array
{
    $command = [
        PHP_BINARY,
        dirname(__DIR__).'/corpus/verify.php',
        '--complete',
        ...$paths,
    ];
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2),
        options: ['bypass_shell' => true],
    );

    if (! is_resource($process)
        || ! is_resource($pipes[0] ?? null)
        || ! is_resource($pipes[1] ?? null)
        || ! is_resource($pipes[2] ?? null)) {
        fail('Cannot start the complete corpus verifier.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0 || ! is_string($stdout)) {
        $details = trim((string) $stderr);

        if ($details === '') {
            $failed = json_decode((string) $stdout, true);
            $details = is_array($failed) && is_array($failed['errors'] ?? null)
                ? json_encode($failed['errors'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : trim((string) $stdout);
        }

        fail('Complete corpus verification failed: '.$details);
    }

    $decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : fail('Complete verifier did not emit a JSON object.');
}

function triple(mixed $summary, int $decimals = 3, bool $nullable = false): string
{
    if ($summary === null && $nullable) {
        return '—';
    }

    if (! is_array($summary)
        || array_filter(
            [$summary['min'] ?? null, $summary['median'] ?? null, $summary['max'] ?? null],
            static fn (mixed $value): bool => ! is_int($value) && ! is_float($value),
        ) !== []
        || $summary['min'] > $summary['median']
        || $summary['median'] > $summary['max']) {
        fail('Metric summary is invalid.');
    }

    return implode('/', array_map(
        static fn (mixed $value): string => number_format((float) $value, $decimals, '.', ''),
        [$summary['min'], $summary['median'], $summary['max']],
    ));
}

function cell(mixed $value): string
{
    return str_replace('|', '\|', (string) $value);
}

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}
