<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) !== 8) {
    fwrite(STDERR, 'Usage: php compare.php c1.json c2.json c4.json c8.json c16.json c30.json comparison.json'.PHP_EOL);
    exit(2);
}

$expectedProcesses = [1, 2, 4, 8, 16, 30];
$summaries = [];

foreach (array_slice($arguments, 1, 6) as $index => $path) {
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Could not read native Phase 2 summary '.$path.'.');
    }

    $summary = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
    $processes = $expectedProcesses[$index];

    if (! is_array($summary)
        || ($summary['ok'] ?? null) !== true
        || ($summary['scheduler'] ?? null) !== 'pcntl'
        || ($summary['processes'] ?? null) !== $processes
        || ($summary['lanes'] ?? null) !== $processes
        || ($summary['test_count'] ?? null) !== 32
        || ($summary['observed_concurrency'] ?? null) !== min($processes, 32)
        || count($summary['executor_pids'] ?? []) !== 32
        || ($summary['fork_isolation_checked'] ?? null) !== true
        || ($summary['cross_run_leakage_checked'] ?? null) !== true
        || ($summary['pre_autoload_rejection_checked'] ?? null) !== true
        || ($summary['all_configuration_preflight_checked'] ?? null) !== true
        || ($summary['contract_authority_guard_checked'] ?? null) !== true
        || ($summary['fixture_dependency_guard_checked'] ?? null) !== true
        || ($summary['cli_collision_checked'] ?? null) !== true
        || ($summary['cli_front_door_checked'] ?? null) !== true
        || ($summary['reporter_rendering_checked'] ?? null) !== true
        || ($summary['internal_type_error_classification_checked'] ?? null) !== true
        || ($summary['negotiated_api_versions'] ?? null) !== ['proof/alpha' => 1, 'proof/zeta' => 1]
        || ! is_int($summary['executor_peak_memory_bytes'] ?? null)
        || $summary['executor_peak_memory_bytes'] <= 0
        || ! is_int($summary['executor_peak_memory_bytes_sum'] ?? null)
        || $summary['executor_peak_memory_bytes_sum'] < $summary['executor_peak_memory_bytes']) {
        throw new RuntimeException('Native Phase 2 C'.$processes.' summary failed its gate.');
    }

    $summaries[] = $summary;
}

$hashKeys = [
    'manifest_hash',
    'plan_hash',
    'metadata_hash',
    'semantic_hash',
    'report_hash',
    'diagnostic_hash',
    'replay_extensions_hash',
];

foreach ($hashKeys as $key) {
    $hashes = [];

    foreach ($summaries as $summary) {
        $hash = $summary[$key] ?? null;

        if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('Native Phase 2 '.$key.' must be a SHA-256 hash in every summary.');
        }

        $hashes[] = $hash;
    }

    if (count(array_unique($hashes)) !== 1) {
        throw new RuntimeException('Native Phase 2 '.$key.' diverged across process counts.');
    }
}

$revision = getenv('DROVE_EVIDENCE_REVISION');
$runnerOs = getenv('RUNNER_OS');
$runnerArch = getenv('RUNNER_ARCH');

if (! is_string($revision) || trim($revision) === ''
    || ! is_string($runnerOs) || trim($runnerOs) === ''
    || ! is_string($runnerArch) || trim($runnerArch) === '') {
    throw new RuntimeException('Native Phase 2 revision and platform metadata are required.');
}

$comparison = [
    'schema' => 1,
    'ok' => true,
    'revision' => $revision,
    'platform' => [
        'os' => $runnerOs,
        'architecture' => $runnerArch,
        'php' => PHP_VERSION,
    ],
    'fixture' => 'experiments/native-phase-2',
    'command' => 'DROVE_NATIVE_SCHEDULER=pcntl DROVE_NATIVE_PROCESSES=C php experiments/native-phase-2/proof.php',
    'processes' => $expectedProcesses,
    'test_count' => 32,
    'hashes' => array_combine(
        $hashKeys,
        array_map(static fn (string $key): string => $summaries[0][$key], $hashKeys),
    ),
    'runs' => array_map(
        static fn (array $summary): array => [
            'processes' => $summary['processes'],
            'lanes' => $summary['lanes'],
            'observed_concurrency' => $summary['observed_concurrency'],
            'wall_ms' => $summary['wall_ms'],
            'parent_peak_memory_bytes' => $summary['parent_peak_memory_bytes'],
            'executor_peak_memory_bytes' => $summary['executor_peak_memory_bytes'],
            'executor_peak_memory_bytes_sum' => $summary['executor_peak_memory_bytes_sum'],
        ],
        $summaries,
    ),
];
$output = json_encode($comparison, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

if (file_put_contents($arguments[7], $output) === false) {
    throw new RuntimeException('Could not write native Phase 2 comparison artifact.');
}

echo $output;
