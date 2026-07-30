<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];

if (count($arguments) !== 9) {
    fwrite(
        STDERR,
        'Usage: php compare.php c1.json c2.json c4.json c8.json c16.json c30.json stress.json comparison.json'.PHP_EOL,
    );
    exit(2);
}

$read = static function (string $path): array {
    $contents = file_get_contents($path);

    if ($contents === false || trim($contents) === '') {
        throw new RuntimeException('Native Phase 3 summary is missing or empty: '.$path);
    }

    $summary = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);

    if (! is_array($summary)) {
        throw new RuntimeException('Native Phase 3 summary must be a JSON object: '.$path);
    }

    return $summary;
};
$hashKeys = [
    'surface_hash',
    'help_hash',
    'diagnostic_hash',
    'plan_hash',
    'case_id_hash',
    'semantic_hash',
    'hook_hash',
    'output_hash',
    'cleanup_hash',
    'selection_hash',
];
$validateHashes = static function (array $summary) use ($hashKeys): void {
    foreach ($hashKeys as $key) {
        $hash = $summary[$key] ?? null;

        if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('Native Phase 3 '.$key.' must be a SHA-256 hash.');
        }
    }
};
$validatePids = static function (array $summary, int $expected): void {
    $pids = $summary['executor_pids'] ?? null;

    if (! is_array($pids)
        || ! array_is_list($pids)
        || count($pids) !== $expected
        || count(array_unique($pids, SORT_REGULAR)) !== $expected
        || array_any($pids, static fn (mixed $pid): bool => ! is_int($pid) || $pid <= 0)) {
        throw new RuntimeException('Native Phase 3 executor PID evidence is invalid.');
    }
};
$validateMetrics = static function (array $summary, int $minimumMemorySamples): void {
    foreach (['planning_ms', 'execution_ms', 'wall_ms', 'proof_wall_ms'] as $field) {
        if (! is_int($summary[$field] ?? null) && ! is_float($summary[$field] ?? null)) {
            throw new RuntimeException('Native Phase 3 '.$field.' is missing.');
        }

        if ($summary[$field] <= 0) {
            throw new RuntimeException('Native Phase 3 '.$field.' must be positive.');
        }
    }

    if (! is_int($summary['parent_peak_memory_bytes'] ?? null)
        || $summary['parent_peak_memory_bytes'] <= 0
        || ! is_string($summary['memory_limit_raw'] ?? null)
        || trim($summary['memory_limit_raw']) === ''
        || ! is_int($summary['memory_limit_bytes'] ?? null)
        || $summary['memory_limit_bytes'] === 0
        || ! is_int($summary['executor_memory_samples'] ?? null)
        || $summary['executor_memory_samples'] < $minimumMemorySamples
        || ! is_int($summary['executor_peak_memory_bytes'] ?? null)
        || $summary['executor_peak_memory_bytes'] <= 0
        || ! is_int($summary['executor_reported_peak_memory_bytes_sum'] ?? null)
        || $summary['executor_reported_peak_memory_bytes_sum'] < $summary['executor_peak_memory_bytes']) {
        throw new RuntimeException('Native Phase 3 memory evidence is invalid.');
    }
};
$validateExecutionIdentity = static function (array $summary): void {
    $revision = $summary['evidence_revision'] ?? null;
    $runtime = $summary['runtime_platform'] ?? null;
    $drover = $summary['drover_identity'] ?? null;

    if (! is_string($revision) || trim($revision) === ''
        || ! is_array($runtime)
        || array_keys($runtime) !== ['os_family', 'os', 'architecture', 'php']
        || ($runtime['os_family'] ?? null) !== 'Linux'
        || ! is_string($runtime['os'] ?? null) || trim($runtime['os']) === ''
        || ! in_array($runtime['architecture'] ?? null, ['amd64', 'x86_64'], true)
        || ! is_string($runtime['php'] ?? null)
        || preg_match('/^8\.4\./D', $runtime['php']) !== 1
        || ! is_array($drover)
        || array_keys($drover) !== [
            'scheduler_class',
            'library_sha256',
            'protocol_version',
            'protocol_max_frame_bytes',
        ]
        || ($drover['scheduler_class'] ?? null) !== 'Drove\\Kernel\\DroverScheduler'
        || ! is_string($drover['library_sha256'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/D', $drover['library_sha256']) !== 1
        || ($drover['protocol_version'] ?? null) !== 1
        || ($drover['protocol_max_frame_bytes'] ?? null) !== 1_048_576) {
        throw new RuntimeException('Native Phase 3 execution identity is invalid.');
    }
};
$expectedProcesses = [1, 2, 4, 8, 16, 30];
$conformance = [];

foreach (array_slice($arguments, 1, 6) as $index => $path) {
    $summary = $read($path);
    $processes = $expectedProcesses[$index];

    if (($summary['schema'] ?? null) !== 1
        || ($summary['ok'] ?? null) !== true
        || ($summary['fixture'] ?? null) !== 'conformance'
        || ($summary['scheduler'] ?? null) !== 'drover'
        || ($summary['processes'] ?? null) !== $processes
        || ($summary['lanes_requested'] ?? null) !== $processes
        || ($summary['lanes_observed'] ?? null) !== $processes
        || ($summary['test_count'] ?? null) !== 49
        || ($summary['runnable_count'] ?? null) !== 47
        || ($summary['terminal_result_count'] ?? null) !== 49
        || ($summary['stress_shard_count'] ?? null) !== 0
        || ($summary['status_counts'] ?? null) !== [
            'failed' => 2,
            'passed' => 45,
            'skipped' => 1,
            'todo' => 1,
        ]
        || ($summary['assertion_count'] ?? null) !== 46
        || ($summary['cleanup_count'] ?? null) !== 35
        || ($summary['reported_cleanup_count'] ?? null) !== 35
        || ($summary['unreported_metric_result_count'] ?? null) !== 1
        || ($summary['executor_pid_assignments'] ?? null) !== 47
        || ($summary['unique_executor_pid_count'] ?? null) !== 47
        || ($summary['one_test_per_executor_checked'] ?? null) !== true
        || ($summary['no_batch_checked'] ?? null) !== true
        || ($summary['fork_isolation_checked'] ?? null) !== true
        || ($summary['source_scanner_checked'] ?? null) !== true
        || ($summary['unsupported_pre_execution_checked'] ?? null) !== true
        || ($summary['native_cli_execution_checked'] ?? null) !== true
        || ($summary['function_alias_scanner_checked'] ?? null) !== true
        || ($summary['duplicate_dataset_rejected_checked'] ?? null) !== true
        || ($summary['dataset_identity_checked'] ?? null) !== true
        || ($summary['selection_precedence_checked'] ?? null) !== true
        || ($summary['scope_hook_body_checked'] ?? null) !== true
        || ($summary['lifo_cleanup_checked'] ?? null) !== true
        || ($summary['dual_failure_preserved_checked'] ?? null) !== true) {
        throw new RuntimeException('Native Phase 3 C'.$processes.' conformance summary failed its gate.');
    }

    $validatePids($summary, 47);
    $validateMetrics($summary, 46);
    $validateHashes($summary);
    $validateExecutionIdentity($summary);
    $conformance[] = $summary;
}

foreach ($hashKeys as $key) {
    if (count(array_unique(array_column($conformance, $key))) !== 1) {
        throw new RuntimeException('Native Phase 3 '.$key.' diverged across process counts.');
    }
}

$stress = $read($arguments[7]);

if (($stress['schema'] ?? null) !== 1
    || ($stress['ok'] ?? null) !== true
    || ($stress['fixture'] ?? null) !== 'stress'
    || ($stress['scheduler'] ?? null) !== 'drover'
    || ($stress['processes'] ?? null) !== 30
    || ($stress['lanes_requested'] ?? null) !== 30
    || ($stress['lanes_observed'] ?? null) !== 30
    || ($stress['test_count'] ?? null) !== 10_000
    || ($stress['runnable_count'] ?? null) !== 10_000
    || ($stress['terminal_result_count'] ?? null) !== 10_000
    || ($stress['stress_shard_count'] ?? null) !== 30
    || ($stress['status_counts'] ?? null) !== ['passed' => 10_000]
    || ($stress['assertion_count'] ?? null) !== 10_000
    || ($stress['cleanup_count'] ?? null) !== 10_000
    || ($stress['reported_cleanup_count'] ?? null) !== 10_000
    || ($stress['unreported_metric_result_count'] ?? null) !== 0
    || ($stress['executor_pid_assignments'] ?? null) !== 10_000
    || ($stress['unique_executor_pid_count'] ?? null) !== 10_000
    || ($stress['one_test_per_executor_checked'] ?? null) !== true
    || ($stress['no_batch_checked'] ?? null) !== true
    || ($stress['fork_isolation_checked'] ?? null) !== true
    || ($stress['source_scanner_checked'] ?? null) !== true
    || ($stress['unsupported_pre_execution_checked'] ?? null) !== true
    || ($stress['native_cli_execution_checked'] ?? null) !== true
    || ($stress['function_alias_scanner_checked'] ?? null) !== true
    || ($stress['duplicate_dataset_rejected_checked'] ?? null) !== true
    || ($stress['scope_hook_body_checked'] ?? null) !== true
    || ($stress['lifo_cleanup_checked'] ?? null) !== true
    || ($stress['dual_failure_preserved_checked'] ?? null) !== true
    || ($stress['memory_limit_raw'] ?? null) !== '512M'
    || ($stress['memory_limit_bytes'] ?? null) !== 512 * 1024 * 1024) {
    throw new RuntimeException('Native Phase 3 10,000-test stress summary failed its gate.');
}

$validatePids($stress, 10_000);
$validateMetrics($stress, 10_000);
$validateHashes($stress);
$validateExecutionIdentity($stress);

foreach (['surface_hash', 'help_hash', 'diagnostic_hash'] as $key) {
    if (! hash_equals($conformance[0][$key], $stress[$key])) {
        throw new RuntimeException('Native Phase 3 stress used a different '.$key.'.');
    }
}

$allSummaries = [...$conformance, $stress];
$revision = $conformance[0]['evidence_revision'];
$expectedRevision = getenv('DROVE_EVIDENCE_REVISION');
$expectedRevision = is_string($expectedRevision) && trim($expectedRevision) !== ''
    ? trim($expectedRevision)
    : null;

if (array_any(
    $allSummaries,
    static fn (array $summary): bool => ($summary['evidence_revision'] ?? null) !== $revision,
)) {
    throw new RuntimeException('Native Phase 3 summaries use different revisions.');
}

if ($expectedRevision !== null && $revision !== $expectedRevision) {
    throw new RuntimeException('Native Phase 3 summaries do not match the expected revision guard.');
}

$executionIdentities = array_map(
    static fn (array $summary): string => json_encode([
        'runtime_platform' => $summary['runtime_platform'],
        'drover_identity' => $summary['drover_identity'],
    ], JSON_THROW_ON_ERROR),
    $allSummaries,
);

if (count(array_unique($executionIdentities)) !== 1) {
    throw new RuntimeException('Native Phase 3 execution identity diverged across summaries.');
}

$runtimePlatform = $conformance[0]['runtime_platform'];
$droverIdentity = $conformance[0]['drover_identity'];

$metrics = static fn (array $summary): array => [
    'fixture' => $summary['fixture'],
    'processes' => $summary['processes'],
    'lanes_requested' => $summary['lanes_requested'],
    'lanes_observed' => $summary['lanes_observed'],
    'test_count' => $summary['test_count'],
    'runnable_count' => $summary['runnable_count'],
    'terminal_result_count' => $summary['terminal_result_count'],
    'stress_shard_count' => $summary['stress_shard_count'],
    'assertion_count' => $summary['assertion_count'],
    'cleanup_count' => $summary['cleanup_count'],
    'reported_cleanup_count' => $summary['reported_cleanup_count'],
    'unreported_metric_result_count' => $summary['unreported_metric_result_count'],
    'unique_executor_pid_count' => $summary['unique_executor_pid_count'],
    'planning_ms' => $summary['planning_ms'],
    'execution_ms' => $summary['execution_ms'],
    'wall_ms' => $summary['wall_ms'],
    'proof_wall_ms' => $summary['proof_wall_ms'],
    'memory_limit_raw' => $summary['memory_limit_raw'],
    'memory_limit_bytes' => $summary['memory_limit_bytes'],
    'parent_peak_memory_bytes' => $summary['parent_peak_memory_bytes'],
    'executor_memory_samples' => $summary['executor_memory_samples'],
    'executor_peak_memory_bytes' => $summary['executor_peak_memory_bytes'],
    'executor_reported_peak_memory_bytes_sum' => $summary['executor_reported_peak_memory_bytes_sum'],
];
$comparison = [
    'schema' => 1,
    'ok' => true,
    'revision' => $revision,
    'platform' => $runtimePlatform,
    'drover_identity' => $droverIdentity,
    'fixture' => 'experiments/native-phase-3',
    'commands' => [
        'conformance' => 'DROVE_NATIVE_FIXTURE=conformance DROVE_NATIVE_SCHEDULER=drover DROVE_NATIVE_PROCESSES=C php -d ffi.enable=true experiments/native-phase-3/proof.php',
        'stress' => 'DROVE_NATIVE_FIXTURE=stress DROVE_NATIVE_SCHEDULER=drover DROVE_NATIVE_PROCESSES=30 php -d ffi.enable=true -d memory_limit=512M experiments/native-phase-3/proof.php',
    ],
    'conformance_hashes' => array_combine(
        $hashKeys,
        array_map(static fn (string $key): string => $conformance[0][$key], $hashKeys),
    ),
    'conformance_runs' => array_map($metrics, $conformance),
    'stress_hashes' => array_combine(
        $hashKeys,
        array_map(static fn (string $key): string => $stress[$key], $hashKeys),
    ),
    'stress_run' => $metrics($stress),
];
$output = json_encode($comparison, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

if (file_put_contents($arguments[8], $output) === false) {
    throw new RuntimeException('Could not write native Phase 3 comparison artifact.');
}

echo $output;
