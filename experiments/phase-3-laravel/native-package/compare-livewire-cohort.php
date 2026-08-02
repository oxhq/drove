<?php

declare(strict_types=1);

function matrixAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    $expectedProcesses = [1, 2, 4, 8, 16, 30];
    $arguments = $_SERVER['argv'] ?? [];
    matrixAssert(count($arguments) === count($expectedProcesses) + 1, 'Livewire matrix requires six summary files.');
    $summaries = [];

    foreach (array_slice($arguments, 1) as $path) {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Could not read Livewire matrix summary '.$path.'.');
        }

        $summary = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        matrixAssert(is_array($summary), 'Livewire matrix summary is invalid: '.$path.'.');
        $processes = $summary['processes'] ?? null;
        matrixAssert(is_int($processes) && in_array($processes, $expectedProcesses, true), 'Livewire matrix process count drifted.');
        matrixAssert(! isset($summaries[$processes]), 'Livewire matrix contains a duplicate process count.');
        matrixAssert(
            ($summary['ok'] ?? null) === true
                && ($summary['cases'] ?? null) === 36
                && ($summary['assertions'] ?? null) === 68
                && ($summary['child_pids'] ?? null) === 36
                && ($summary['forks'] ?? null) === 36
                && ($summary['observed_lanes'] ?? null) === $processes
                && ($summary['runtime_audit'] ?? null) === [
                    'rows' => 37,
                    'test_tasks' => 36,
                    'root_tasks' => 1,
                    'task_ids_bound_to_telemetry' => 36,
                ]
                && ($summary['saturation_barrier'] ?? null) === [
                    'requested_lanes' => $processes,
                    'executor_pids' => 36,
                    'pid_set_matches_telemetry' => true,
                ]
                && ($summary['topology'] ?? null) === [
                    'schema' => 1,
                    'forks' => 36,
                    'scope_workers' => 0,
                    'executor_workers' => 36,
                    'process_anchors' => 0,
                    'peak_live_pids' => min(2 * $processes, 36),
                    'peak_outstanding_tasks' => min(2 * $processes, 36),
                    'outstanding_task_limit' => 2 * $processes,
                ]
                && ($summary['source_mode'] ?? null) === 'read-only-git-checkout'
                && ($summary['staging_source_mtime_epoch'] ?? null) === 1784995076
                && ($summary['external_test_runtime_packages'] ?? null) === 0
                && ($summary['external_test_runtime_files'] ?? null) === 0,
            'Livewire matrix summary violated its semantic or topology contract at C'.$processes.'.',
        );
        $summaries[$processes] = $summary;
    }

    ksort($summaries, SORT_NUMERIC);
    matrixAssert(array_keys($summaries) === $expectedProcesses, 'Livewire matrix process set drifted.');
    matrixAssert(
        count(array_unique(array_column($summaries, 'semantic_hash'))) === 1,
        'Livewire matrix semantic hashes diverged.',
    );
    $first = reset($summaries);
    matrixAssert(
        is_array($first) && is_string($first['semantic_hash'] ?? null),
        'Livewire matrix semantic hash is unavailable.',
    );

    echo json_encode([
        'ok' => true,
        'corpus' => 'livewire/livewire',
        'scope' => 'parallel-safe-7-file-cohort',
        'semantic_hash' => $first['semantic_hash'],
        'matrix' => array_map(
            static fn (array $summary): array => [
                'processes' => $summary['processes'],
                'observed_lanes' => $summary['observed_lanes'],
                'cases' => $summary['cases'],
                'assertions' => $summary['assertions'],
                'child_pids' => $summary['child_pids'],
                'forks' => $summary['forks'],
            ],
            array_values($summaries),
        ),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, 'DROVE_NATIVE_LIVEWIRE_MATRIX_FAILED '.$throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
