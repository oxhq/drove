<?php

declare(strict_types=1);

if ($argc !== 13) {
    fwrite(
        STDERR,
        "usage: normalize.php CORPUS RUNNER COHORT PROCESSES SELECTED_FILES REVISION EXIT RUN RAW MEASUREMENT REPLAY|- OUTPUT\n",
    );
    exit(2);
}

[
    ,
    $corpus,
    $runner,
    $cohort,
    $processes,
    $selectedFiles,
    $revision,
    $exitCode,
    $run,
    $rawPath,
    $measurementPath,
    $replayPath,
    $outputPath,
] = $argv;

$manifestPath = getenv('CORPUS_MANIFEST') ?: __DIR__.'/manifest.json';
$manifest = decodeFile($manifestPath);
$corpusManifest = null;

foreach ($manifest['corpora'] ?? [] as $candidate) {
    if (($candidate['id'] ?? null) === $corpus) {
        $corpusManifest = $candidate;

        break;
    }
}

if (! is_array($corpusManifest)) {
    throw new RuntimeException("Unknown corpus $corpus");
}

$identity = $corpusManifest['runner_identity'][$runner] ?? null;

if (! is_array($identity)) {
    throw new RuntimeException("Unknown runner identity $corpus/$runner");
}

foreach (['command', 'executable', 'frontend', 'runtime', 'state'] as $field) {
    if (! is_string($identity[$field] ?? null) || $identity[$field] === '') {
        throw new RuntimeException("$corpus/$runner runner identity has invalid $field");
    }
}

$processCount = positiveInteger($processes, 'PROCESSES');
$selectedFileCount = positiveInteger($selectedFiles, 'SELECTED_FILES');
$runIndex = positiveInteger($run, 'RUN');
$commandExit = nonNegativeInteger($exitCode, 'EXIT');
$raw = file_get_contents($rawPath);

if ($raw === false) {
    throw new RuntimeException("Cannot read $rawPath");
}

$measurement = decodeFile($measurementPath);

if (($measurement['schema_version'] ?? null) !== 1
    || ($measurement['clock'] ?? null) !== 'monotonic'
    || ($measurement['memory_source'] ?? null) !== 'cgroup-v2:/sys/fs/cgroup/memory.peak'
    || ($measurement['runner_executable'] ?? null) !== $identity['executable']) {
    throw new RuntimeException('Corpus measurement metadata is invalid.');
}

if (($measurement['exit_code'] ?? null) !== $commandExit) {
    throw new RuntimeException('Corpus measurement exit code does not match the recorded command.');
}

$wallMs = $measurement['wall_ms'] ?? null;
$containerPeak = $measurement['container_peak_memory_bytes'] ?? null;
$platform = $measurement['platform'] ?? null;

if (! is_numeric($wallMs) || ! is_finite((float) $wallMs) || (float) $wallMs <= 0) {
    throw new RuntimeException('Corpus wall_ms must be a positive finite number.');
}

if (! is_int($containerPeak) || $containerPeak < 1) {
    throw new RuntimeException('Corpus container_peak_memory_bytes must be a positive integer.');
}

if (! is_array($platform)) {
    throw new RuntimeException('Corpus platform metadata is missing.');
}

foreach (['os_family', 'os', 'architecture', 'php'] as $field) {
    if (! is_string($platform[$field] ?? null) || $platform[$field] === '') {
        throw new RuntimeException("Corpus platform has invalid $field");
    }
}

$replay = null;
$replayEnvironmentPlan = null;
$observedLanes = 1;
$observedLanesSource = 'single_process_baseline';

if ($runner === 'drove') {
    if ($replayPath === '-') {
        throw new RuntimeException('Drove corpus results require replay metadata.');
    }

    $decodedReplay = decodeFile($replayPath);
    $replayDuration = $decodedReplay['duration_ms'] ?? null;
    $replayMemory = $decodedReplay['memory_peak_bytes'] ?? null;
    $replayEnvironmentPlan = $decodedReplay['plan']['environment'] ?? null;
    $observedLanes = $decodedReplay['result']['observed_concurrency']['global'] ?? null;

    if (($decodedReplay['schema'] ?? null) !== 1
        || ($decodedReplay['kind'] ?? null) !== 'run'
        || ($decodedReplay['command']['processes'] ?? null) !== $processCount
        || ($decodedReplay['result']['exit_code'] ?? null) !== $commandExit) {
        throw new RuntimeException('Drove replay identity does not match the recorded command.');
    }

    if (! is_int($observedLanes) || $observedLanes < 1) {
        throw new RuntimeException('Drove replay observed_concurrency.global is invalid.');
    }

    if (! matchesEnvironmentExpectation(
        $replayEnvironmentPlan,
        $corpusManifest['environment_expectation'] ?? null,
    )) {
        throw new RuntimeException('Drove replay environment plan does not match the corpus contract.');
    }

    if (! is_numeric($replayDuration)
        || ! is_finite((float) $replayDuration)
        || (float) $replayDuration < 0
        || ! is_int($replayMemory)
        || $replayMemory < 1) {
        throw new RuntimeException('Drove replay duration or PHP peak memory is invalid.');
    }

    foreach (['os_family', 'os', 'architecture', 'php'] as $field) {
        if (($decodedReplay['platform'][$field] ?? null) !== $platform[$field]) {
            throw new RuntimeException("Drove replay platform mismatch at $field");
        }
    }

    $observedLanesSource = 'drove_replay';
    $replay = [
        'duration_ms' => round((float) $replayDuration, 3),
        'php_peak_memory_bytes' => $replayMemory,
        'sha256' => hash_file('sha256', $replayPath),
    ];
} elseif ($processCount !== 1 || $replayPath !== '-') {
    throw new RuntimeException('Baseline corpus results must be a replay-free single-process run.');
}

$plain = preg_replace('/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $raw);

if (! is_string($plain)) {
    throw new RuntimeException('Cannot normalize ANSI output.');
}

$summary = parseSummary($plain);
$selectionMode = $corpusManifest['selection_mode'] ?? null;
$wholeSuite = $corpusManifest['whole_suite'] ?? null;

if (! is_string($selectionMode) || ! is_bool($wholeSuite)) {
    throw new RuntimeException("$corpus selection metadata is invalid.");
}

$result = [
    'schema_version' => 2,
    'corpus' => $corpus,
    'runner' => $runner,
    'runner_identity' => $identity,
    'selection' => [
        'mode' => $selectionMode,
        'whole_suite' => $wholeSuite,
        'cohort' => $cohort,
        'selected_files' => $selectedFileCount,
    ],
    'requested_processes' => $processCount,
    'observed_lanes' => $observedLanes,
    'observed_lanes_source' => $observedLanesSource,
    'run' => $runIndex,
    'drove_revision' => $revision,
    'environment_plan' => [
        'runtime' => $identity['runtime'],
        'state' => $identity['state'],
        'replay' => $replayEnvironmentPlan,
    ],
    'metrics' => [
        'wall_ms' => round((float) $wallMs, 3),
        'container_peak_memory_bytes' => $containerPeak,
        'replay_duration_ms' => $replay['duration_ms'] ?? null,
        'replay_php_peak_memory_bytes' => $replay['php_peak_memory_bytes'] ?? null,
    ],
    'platform' => $platform,
    'outcome' => [...$summary, 'exit' => $commandExit],
    'artifacts' => [
        'raw_sha256' => hash('sha256', $raw),
        'measurement_sha256' => hash_file('sha256', $measurementPath),
        'replay_sha256' => $replay['sha256'] ?? null,
    ],
];

$encoded = json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;

if (file_put_contents($outputPath, $encoded, LOCK_EX) === false) {
    throw new RuntimeException("Cannot write $outputPath");
}

/**
 * @return array<string, mixed>
 */
function decodeFile(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read $path");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("$path does not contain a JSON object.");
    }

    return $decoded;
}

function positiveInteger(string $value, string $name): int
{
    if (preg_match('/^[1-9]\d*$/', $value) !== 1 || (int) $value < 1) {
        throw new RuntimeException("$name must be a positive integer.");
    }

    return (int) $value;
}

function nonNegativeInteger(string $value, string $name): int
{
    if (preg_match('/^\d+$/', $value) !== 1) {
        throw new RuntimeException("$name must be a non-negative integer.");
    }

    return (int) $value;
}

function matchesEnvironmentExpectation(mixed $plan, mixed $expectation): bool
{
    if ($expectation === null) {
        return $plan === null;
    }

    if (! is_array($plan)
        || ! is_array($expectation)
        || ($plan['schema'] ?? null) !== 1
        || ($plan['coordination'] ?? null) !== ($expectation['coordination'] ?? null)
        || ! is_array($plan['resources']['database'] ?? null)
        || ! is_array($expectation['database'] ?? null)) {
        return false;
    }

    $database = $plan['resources']['database'];

    return ($database['kind'] ?? null) === 'database'
        && ($database['provider'] ?? null) === ($expectation['database']['provider'] ?? null)
        && ($database['capabilities'] ?? null) === ($expectation['database']['capabilities'] ?? null);
}

/**
 * @return array<string, int|string>
 */
function parseSummary(string $plain): array
{
    $summary = [
        'tests' => 0,
        'passed' => 0,
        'failed' => 0,
        'errors' => 0,
        'skipped' => 0,
        'incomplete' => 0,
        'risky' => 0,
        'warnings' => 0,
        'assertions' => 0,
    ];

    preg_match_all('/^\s*Tests:\s*(.+)$/mi', $plain, $matches);
    $line = trim((string) end($matches[1]));

    if ($line === ''
        && preg_match('/\bOK \((\d+) tests?,\s*(\d+) assertions?\)/i', $plain, $ok) === 1) {
        $summary['tests'] = (int) $ok[1];
        $summary['passed'] = (int) $ok[1];
        $summary['assertions'] = (int) $ok[2];
    } elseif ($line === '') {
        $summary['parse_error'] = 'No Tests summary was found.';
    } elseif (preg_match('/\bAssertions:\s*\d+/i', $line) === 1) {
        if (preg_match('/^(\d+)\s*,/', $line, $tests) === 1) {
            $summary['tests'] = (int) $tests[1];
        }

        $labels = [
            'assertions' => 'assertions',
            'failures' => 'failed',
            'errors' => 'errors',
            'skipped' => 'skipped',
            'incomplete' => 'incomplete',
            'risky' => 'risky',
            'warnings' => 'warnings',
        ];

        foreach ($labels as $label => $key) {
            if (preg_match('/\b'.preg_quote($label, '/').':\s*(\d+)/i', $line, $value) === 1) {
                $summary[$key] = (int) $value[1];
            }
        }

        $summary['passed'] = max(
            0,
            $summary['tests'] - $summary['failed'] - $summary['errors']
                - $summary['skipped'] - $summary['incomplete'] - $summary['risky'],
        );
    } else {
        $labels = [
            'passed' => 'passed',
            'failed' => 'failed',
            'error' => 'errors',
            'errors' => 'errors',
            'skipped' => 'skipped',
            'incomplete' => 'incomplete',
            'risky' => 'risky',
            'warning' => 'warnings',
            'warnings' => 'warnings',
        ];

        preg_match_all('/(\d+)\s+([a-z]+)/i', $line, $counts, PREG_SET_ORDER);

        foreach ($counts as $count) {
            $label = strtolower($count[2]);

            if (isset($labels[$label])) {
                $summary[$labels[$label]] += (int) $count[1];
            }
        }

        if (preg_match('/\((\d+)\s+assertions?\)/i', $line, $assertions) === 1) {
            $summary['assertions'] = (int) $assertions[1];
        }

        $summary['tests'] = array_sum(array_intersect_key(
            $summary,
            array_flip(['passed', 'failed', 'errors', 'skipped', 'incomplete', 'risky']),
        ));
    }

    preg_match_all('/^\s*Assertions:\s*(\d+)\s*$/mi', $plain, $assertionMatches);
    $assertions = end($assertionMatches[1]);

    if ($assertions !== false) {
        $summary['assertions'] = (int) $assertions;
    }

    return $summary;
}
