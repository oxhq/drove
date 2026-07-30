<?php

declare(strict_types=1);

use Drove\Kernel\DroverScheduler;
use Drove\Replay\Artifact;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/support.php';

try {
    $proofStartedNs = hrtime(true);
    $identity = nativePhaseFiveIdentity();
    $processes = (int) (getenv('DROVE_PHASE5_PROCESSES') ?: 30);
    $replayDirectory = getenv('DROVE_PHASE5_REPLAY_DIR');
    $replayDirectory = is_string($replayDirectory) && $replayDirectory !== ''
        ? $replayDirectory
        : sys_get_temp_dir().'/drove-native-phase-5-replays-'.bin2hex(random_bytes(8));
    $workspace = sys_get_temp_dir().'/drove-native-phase-5-faults-'.bin2hex(random_bytes(8));
    nativePhaseFiveAssert(
        $processes === 30,
        'Native Phase 5 repeated faults run at C30.',
    );
    nativePhaseFiveAssert(
        (is_dir($replayDirectory)
            || (mkdir($replayDirectory, 0700, true) && is_dir($replayDirectory)))
            && ! file_exists($workspace)
            && mkdir($workspace, 0700, true),
        'Could not create the native Phase 5 fault workspaces.',
    );
    $scheduler = new DroverScheduler(
        'native-phase-5-faults-'.bin2hex(random_bytes(6)),
        $processes,
        defaultTimeoutMs: 100,
        termGraceMs: 25,
    );
    $cases = [];
    $replayArtifacts = [];

    nativePhaseFivePhase('execution');

    foreach (['kill', 'timeout', 'crash'] as $kind) {
        $tasks = [];

        for ($index = 0; $index < 100; $index++) {
            $tasks[] = [
                'id' => sprintf('%s-%03d', $kind, $index),
                'kind' => 'test',
                'scope_id' => 'phase5-faults',
                'scopes' => ['phase5-faults'],
                'timeout_ms' => $kind === 'timeout' ? 50 : 2_000,
                'permit' => true,
            ];
        }

        $startedNs = hrtime(true);
        $mapped = $scheduler->map(
            $tasks,
            static function (array $task) use ($kind, $workspace): never {
                $readyPath = $workspace.'/'.$task['id'].'.ready';
                $escapedPath = $workspace.'/'.$task['id'].'.escaped';
                $descendant = pcntl_fork();
                nativePhaseFiveAssert(
                    $descendant !== -1,
                    'Could not fork a native Phase 5 fault descendant.',
                );

                if ($descendant === 0) {
                    pcntl_async_signals(true);
                    pcntl_signal(SIGTERM, SIG_IGN);
                    $state = json_encode([
                        'pid' => getmypid(),
                        'ppid' => posix_getppid(),
                        'pgid' => posix_getpgrp(),
                    ], JSON_THROW_ON_ERROR);
                    file_put_contents($readyPath, $state, LOCK_EX);
                    usleep(1_000_000);
                    file_put_contents($escapedPath, 'escaped', LOCK_EX);
                    exit(0);
                }

                $deadline = hrtime(true) + 1_000_000_000;

                while (! is_file($readyPath) && hrtime(true) < $deadline) {
                    usleep(500);
                }

                nativePhaseFiveAssert(
                    is_file($readyPath),
                    'A native Phase 5 fault descendant did not become ready.',
                );

                if ($kind === 'kill') {
                    $executorPid = getmypid();
                    nativePhaseFiveAssert(
                        is_int($executorPid),
                        'Could not resolve the native Phase 5 executor PID.',
                    );
                    posix_kill($executorPid, SIGKILL);
                }

                if ($kind === 'timeout') {
                    usleep(1_000_000);
                }

                trigger_error('native-phase-5 deliberate fatal crash', E_USER_ERROR);
            },
        );
        $durationMs = round((hrtime(true) - $startedNs) / 1_000_000, 3);
        $results = $mapped['results'];
        nativePhaseFiveAssert(
            count($results) === 100,
            'Native Phase 5 fault injection lost terminal results.',
        );
        $expectedFailure = match ($kind) {
            'kill' => 'signal_termination',
            'timeout' => 'timeout',
            'crash' => 'php_fatal_error',
        };
        $executorPids = [];
        $executorIntervals = [];
        $descendantPids = [];
        $failureKinds = [];

        foreach ($results as &$result) {
            $telemetry = $result['telemetry'] ?? null;
            $executorStartedNs = is_array($telemetry) ? ($telemetry['started_ns'] ?? null) : null;
            $executorFinishedNs = is_array($telemetry) ? ($telemetry['finished_ns'] ?? null) : null;
            nativePhaseFiveAssert(
                ($result['status'] ?? null) === 'failed'
                    && ($result['failure']['kind'] ?? null) === $expectedFailure
                    && is_array($telemetry)
                    && is_int($telemetry['pid'] ?? null)
                    && $telemetry['pid'] > 0
                    && ($telemetry['forks'] ?? null) === 1
                    && ($telemetry['scope_workers'] ?? null) === 0
                    && ($telemetry['executor_workers'] ?? null) === 1
                    && ($telemetry['process_anchors'] ?? null) === 0
                    && is_int($executorStartedNs)
                    && is_int($executorFinishedNs)
                    && $executorFinishedNs >= $executorStartedNs,
                'Native Phase 5 fault result lost failure or topology identity.',
            );

            if ($kind === 'kill') {
                nativePhaseFiveAssert(
                    ($telemetry['signal'] ?? null) === SIGKILL,
                    'Native Phase 5 kill injection lost SIGKILL identity.',
                );
            }

            $executorPids[] = $telemetry['pid'];
            $executorIntervals[] = [
                'started_ns' => $executorStartedNs,
                'finished_ns' => $executorFinishedNs,
            ];
            $failureKinds[] = $result['failure']['kind'];
            $readyPath = $workspace.'/'.$result['id'].'.ready';
            $escapedPath = $workspace.'/'.$result['id'].'.escaped';
            $ready = file_get_contents($readyPath);
            $state = is_string($ready)
                ? json_decode($ready, true, 8, JSON_THROW_ON_ERROR)
                : null;
            nativePhaseFiveAssert(
                is_array($state)
                    && is_int($state['pid'] ?? null)
                    && is_int($state['pgid'] ?? null)
                    && $state['pid'] > 0
                    && $state['pgid'] === $telemetry['pgid']
                    && $telemetry['pid'] === $telemetry['pgid']
                    && ! is_file($escapedPath),
                'A native Phase 5 fault descendant escaped its executor process group.',
            );
            $descendantPids[] = $state['pid'];
            nativePhaseFiveAssert(
                unlink($readyPath),
                'Could not remove a native Phase 5 fault sentinel.',
            );
            $memory = $result['memory_peak_bytes'] ?? null;

            if (is_int($memory) && $memory > 0) {
                $result['telemetry']['memory_peak_bytes'] = $memory;
            }
        }

        unset($result);
        $observedProcessLanes = nativePhaseFivePeakConcurrency($executorIntervals);
        nativePhaseFiveAssert(
            $observedProcessLanes >= 1 && $observedProcessLanes <= $processes,
            'Native Phase 5 fault injection exceeded declared active lanes.',
        );
        $orphanPids = nativePhaseFiveWaitForExit($descendantPids, 2_000);
        nativePhaseFiveAssert(
            $orphanPids === []
                && count(array_unique($executorPids, SORT_REGULAR)) === 100
                && count(array_unique($failureKinds, SORT_STRING)) === 1,
            'Native Phase 5 fault injection leaked a process or reused an executor.',
        );
        $topology = $mapped['telemetry']['topology'];
        nativePhaseFiveAssertTopology($topology);
        nativePhaseFiveAssert(
            $topology['forks'] === 100
                && $topology['scope_workers'] === 0
                && $topology['executor_workers'] === 100
                && $topology['process_anchors'] === 0
                && $topology['peak_live_pids'] >= 1
                && $topology['peak_live_pids'] <= $topology['outstanding_task_limit']
                && $topology['peak_outstanding_tasks'] <= 2 * $processes
                && $topology['outstanding_task_limit'] === 2 * $processes,
            'Native Phase 5 fault map violated bounded one-executor topology.',
        );
        $plan = [
            'schema' => 1,
            'root' => [
                'id' => 'suite:phase5-faults',
                'type' => 'suite',
                'metadata' => [],
                'tests' => array_map(
                    static fn (array $task): array => ['id' => $task['id']],
                    $tasks,
                ),
                'children' => [],
            ],
        ];
        $replayPath = $replayDirectory.'/native-phase-5-'.$kind.'.replay.json';
        nativePhaseFiveAssert(
            ! file_exists($replayPath),
            'Native Phase 5 will not overwrite a replay artifact.',
        );
        $replay = Artifact::create(
            dirname(__DIR__, 2),
            $replayPath,
            false,
            [
                'bin/drove',
                '--api-token=native-phase-5-private-token',
                '--password',
                'native-phase-5-private-password',
                '--replay='.$replayPath,
            ],
            $processes,
            $kind === 'timeout' ? 50 : 2_000,
        );
        $replay->recordPlan($plan);
        $replay->writeRun([
            'exit_code' => 1,
            'status' => 'failed',
            'scopes' => [],
            'tests' => $results,
            'completion_order' => $mapped['completion_order'],
            'observed_concurrency' => ['global' => $observedProcessLanes],
        ]);
        $replayContents = file_get_contents($replayPath);
        $replayPayload = is_string($replayContents)
            ? json_decode($replayContents, true, 64, JSON_THROW_ON_ERROR)
            : null;
        nativePhaseFiveAssert(
            is_string($replayContents)
                && is_array($replayPayload)
                && ($replayPayload['schema'] ?? null) === 1
                && ($replayPayload['kind'] ?? null) === 'run'
                && ($replayPayload['command']['arguments'][1] ?? null)
                    === '--api-token=[REDACTED]'
                && ($replayPayload['command']['arguments'][3] ?? null)
                    === '[REDACTED]'
                && ! str_contains($replayContents, 'native-phase-5-private-token')
                && ! str_contains($replayContents, 'native-phase-5-private-password')
                && ! str_contains($replayContents, 'native-phase-5 deliberate fatal crash')
                && ! str_contains($replayContents, '"stdout"')
                && ! str_contains($replayContents, '"stderr"')
                && ($replayPayload['result']['counts'] ?? null) === ['failed' => 100]
                && count($replayPayload['result']['failures'] ?? []) === 100
                && array_all(
                    $replayPayload['result']['failures'] ?? [],
                    static fn (array $failure): bool => ($failure['kind'] ?? null)
                        === $expectedFailure,
                ),
            'Native Phase 5 replay schema or redaction validation failed.',
        );
        $permissions = fileperms($replayPath);
        nativePhaseFiveAssert(
            is_int($permissions) && ($permissions & 0777) === 0600,
            'Native Phase 5 replay artifact permissions are not private.',
        );
        $replayHash = hash_file('sha256', $replayPath);
        nativePhaseFiveAssert(
            is_string($replayHash),
            'Could not hash a native Phase 5 replay artifact.',
        );
        $replayArtifacts[] = [
            'kind' => $kind,
            'schema' => 1,
            'path' => basename($replayPath),
            'sha256' => $replayHash,
            'redaction_checked' => true,
            'permissions' => '0600',
        ];
        $cases[] = [
            'kind' => $kind,
            'injection_count' => 100,
            'terminal_result_count' => count($results),
            'failure_kind' => $expectedFailure,
            'duration_ms' => $durationMs,
            'unique_executor_pid_count' => count(array_unique($executorPids, SORT_REGULAR)),
            'descendant_count' => count($descendantPids),
            'orphan_pids' => [],
            'escaped_artifact_count' => 0,
            'observed_process_lanes' => $observedProcessLanes,
            'topology' => $topology,
            'replay_sha256' => $replayHash,
        ];
    }

    nativePhaseFiveAssert(
        nativePhaseFiveChildPids() === [],
        'Native Phase 5 repeated faults left scheduler children alive.',
    );
    $workspaceEntries = glob($workspace.'/*');
    nativePhaseFiveAssert(
        is_array($workspaceEntries) && $workspaceEntries === [],
        'Native Phase 5 repeated faults left state artifacts.',
    );
    nativePhaseFiveRemove($workspace);
    nativePhaseFivePhase('rendering');
    $summary = [
        'schema' => 1,
        'ok' => true,
        ...$identity,
        'fixture' => 'repeated-faults',
        'processes' => $processes,
        'injections_per_kind' => 100,
        'submitted' => 300,
        'terminal_result_count' => 300,
        'missing_terminal_result_count' => 0,
        'orphan_pids' => [],
        'artifact_residue_count' => 0,
        'replay_artifacts' => $replayArtifacts,
        'cases' => $cases,
        'telemetry' => [
            'schema' => 1,
            'measurement_sources' => [
                'topology' => 'kernel',
                'faults' => 'harness',
                'orphan_cleanup' => 'procfs',
                'replay' => 'drove-replay-artifact',
            ],
            'timings_ms' => [
                'wall' => round((hrtime(true) - $proofStartedNs) / 1_000_000, 3),
            ],
            'topology' => $scheduler->topologyTelemetry(),
            'faults' => [
                'submitted' => 300,
                'terminal' => 300,
                'kinds' => [
                    'kill' => 100,
                    'timeout' => 100,
                    'crash' => 100,
                ],
            ],
        ],
    ];
    echo json_encode(
        $summary,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
} catch (Throwable $throwable) {
    if (isset($workspace) && is_string($workspace) && str_contains($workspace, 'drove-native-phase-5')) {
        try {
            nativePhaseFiveRemove($workspace);
        } catch (Throwable) {
            //
        }
    }

    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
