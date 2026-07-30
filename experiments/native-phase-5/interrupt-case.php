<?php

declare(strict_types=1);

use Drove\Kernel\DroverScheduler;
use Drove\Replay\Artifact;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/support.php';

try {
    $identity = nativePhaseFiveIdentity();
    $workspace = getenv('DROVE_PHASE5_INTERRUPT_WORKSPACE');
    $replayPath = getenv('DROVE_PHASE5_INTERRUPT_REPLAY');
    nativePhaseFiveAssert(
        is_string($workspace)
            && is_dir($workspace)
            && is_string($replayPath)
            && $replayPath !== ''
            && ! file_exists($replayPath),
        'Native Phase 5 interruption paths are invalid.',
    );
    $tasks = [];

    for ($index = 0; $index < 30; $index++) {
        $tasks[] = [
            'id' => sprintf('interrupt-%02d', $index),
            'kind' => 'test',
            'scope_id' => 'phase5-interrupt',
            'scopes' => ['phase5-interrupt'],
            'timeout_ms' => 0,
            'permit' => true,
        ];
    }

    $plan = [
        'schema' => 1,
        'root' => [
            'id' => 'suite:phase5-interrupt',
            'type' => 'suite',
            'metadata' => [],
            'tests' => array_map(
                static fn (array $task): array => ['id' => $task['id']],
                $tasks,
            ),
            'children' => [],
        ],
    ];
    $replay = Artifact::create(
        dirname(__DIR__, 2),
        $replayPath,
        false,
        ['bin/drove', '--secret=interrupt-private', '--replay='.$replayPath],
        8,
        0,
    );
    $replay->recordPlan($plan);
    $scheduler = new DroverScheduler(
        'native-phase-5-interruption-'.bin2hex(random_bytes(6)),
        8,
        defaultTimeoutMs: 5_000,
        termGraceMs: 25,
    );
    $mapped = $scheduler->map(
        $tasks,
        static function (array $task) use ($workspace): never {
            $readyPath = $workspace.'/'.$task['id'].'.ready';
            $escapedPath = $workspace.'/'.$task['id'].'.escaped';
            $descendant = pcntl_fork();
            nativePhaseFiveAssert(
                $descendant !== -1,
                'Could not fork a native Phase 5 interruption descendant.',
            );

            if ($descendant === 0) {
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM, SIG_IGN);
                file_put_contents($readyPath, json_encode([
                    'pid' => getmypid(),
                    'pgid' => posix_getpgrp(),
                ], JSON_THROW_ON_ERROR), LOCK_EX);
                usleep(1_000_000);
                file_put_contents($escapedPath, 'escaped', LOCK_EX);
                exit(0);
            }

            while (true) {
                usleep(10_000);
            }
        },
    );
    $results = $mapped['results'];
    nativePhaseFiveAssert(
        count($results) === 30
            && array_all(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === 'failed'
                    && ($result['failure']['kind'] ?? null) === 'user_interruption'
                    && ($result['telemetry']['interrupted_signal'] ?? null) === SIGINT,
            ),
        'Native Phase 5 interruption lost terminal cancellation results: '.json_encode(
            array_map(
                static fn (array $result): array => [
                    'id' => $result['id'] ?? null,
                    'status' => $result['status'] ?? null,
                    'failure_kind' => $result['failure']['kind'] ?? null,
                    'failure_message' => $result['failure']['message'] ?? null,
                    'signal' => $result['telemetry']['signal'] ?? null,
                    'interrupted_signal' => $result['telemetry']['interrupted_signal'] ?? null,
                ],
                $results,
            ),
            JSON_THROW_ON_ERROR,
        ),
    );
    $descendantPids = [];

    foreach (glob($workspace.'/*.ready') ?: [] as $readyPath) {
        $contents = file_get_contents($readyPath);
        $state = is_string($contents)
            ? json_decode($contents, true, 8, JSON_THROW_ON_ERROR)
            : null;
        nativePhaseFiveAssert(
            is_array($state)
                && is_int($state['pid'] ?? null)
                && $state['pid'] > 0
                && ! is_file(substr($readyPath, 0, -6).'.escaped'),
            'A native Phase 5 interruption descendant escaped cleanup.',
        );
        $descendantPids[] = $state['pid'];
    }

    nativePhaseFiveAssert(
        count($descendantPids) >= 1
            && nativePhaseFiveWaitForExit($descendantPids, 2_000) === [],
        'Native Phase 5 interruption leaked a descendant.',
    );

    foreach ($results as &$result) {
        $memory = $result['memory_peak_bytes'] ?? null;

        if (is_int($memory) && $memory > 0) {
            $result['telemetry']['memory_peak_bytes'] = $memory;
        }
    }

    unset($result);
    $replay->writeRun([
        'exit_code' => 1,
        'status' => 'failed',
        'scopes' => [],
        'tests' => $results,
        'completion_order' => $mapped['completion_order'],
        'observed_concurrency' => [
            'global' => $mapped['telemetry']['topology']['peak_live_pids'],
        ],
    ]);
    $replayContents = file_get_contents($replayPath);
    $replayPayload = is_string($replayContents)
        ? json_decode($replayContents, true, 64, JSON_THROW_ON_ERROR)
        : null;
    nativePhaseFiveAssert(
        is_string($replayContents)
            && is_array($replayPayload)
            && ($replayPayload['schema'] ?? null) === 1
            && ($replayPayload['command']['arguments'][1] ?? null)
                === '--secret=[REDACTED]'
            && ! str_contains($replayContents, 'interrupt-private')
            && count($replayPayload['result']['failures'] ?? []) === 30,
        'Native Phase 5 interruption replay failed schema or redaction validation.',
    );
    $replayHash = hash_file('sha256', $replayPath);
    nativePhaseFiveAssert(
        is_string($replayHash),
        'Could not hash the native Phase 5 interruption replay.',
    );

    foreach (glob($workspace.'/*') ?: [] as $path) {
        nativePhaseFiveAssert(
            is_file($path) && unlink($path),
            'Could not remove a native Phase 5 interruption sentinel.',
        );
    }

    $topology = $mapped['telemetry']['topology'];
    nativePhaseFiveAssertTopology($topology);
    echo json_encode([
        'schema' => 1,
        'ok' => true,
        ...$identity,
        'fixture' => 'interruption',
        'signal' => SIGINT,
        'submitted' => 30,
        'terminal_result_count' => 30,
        'active_executor_count' => count(array_filter(
            $results,
            static fn (array $result): bool => is_int($result['telemetry']['pid'] ?? null),
        )),
        'descendant_count' => count($descendantPids),
        'orphan_pids' => [],
        'artifact_residue_count' => 0,
        'replay' => [
            'schema' => 1,
            'path' => basename($replayPath),
            'sha256' => $replayHash,
            'redaction_checked' => true,
        ],
        'telemetry' => [
            'schema' => 1,
            'measurement_sources' => [
                'topology' => 'kernel',
                'faults' => 'kernel',
                'orphan_cleanup' => 'procfs',
                'replay' => 'drove-replay-artifact',
            ],
            'topology' => $topology,
            'faults' => [
                'submitted' => 30,
                'terminal' => 30,
                'kinds' => ['user_interruption' => 30],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
