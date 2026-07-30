<?php

declare(strict_types=1);

use Drove\Kernel\DroverScheduler;
use Drove\Kernel\PcntlScheduler;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/support.php';

try {
    $proofStartedNs = hrtime(true);
    $identity = nativePhaseFiveIdentity();
    $fixture = getenv('DROVE_PHASE5_FIXTURE') ?: '';
    $runner = getenv('DROVE_PHASE5_RUNNER') ?: 'drover';
    $processes = (int) (getenv('DROVE_PHASE5_PROCESSES') ?: 1);
    $repetition = (int) (getenv('DROVE_PHASE5_REPETITION') ?: 1);
    $workspace = getenv('DROVE_PHASE5_WORKSPACE');
    $workspace = is_string($workspace) && $workspace !== ''
        ? $workspace
        : sys_get_temp_dir().'/drove-native-phase-5-'.bin2hex(random_bytes(8));

    nativePhaseFiveAssert(
        in_array(
            $fixture,
            ['parity', 'saturation', 'cheap', 'setup', 'independent', 'stress'],
            true,
        ),
        'Unknown native Phase 5 fixture.',
    );
    nativePhaseFiveAssert(
        in_array($runner, ['drover', 'pcntl'], true),
        'DROVE_PHASE5_RUNNER must be drover or pcntl.',
    );
    nativePhaseFiveAssert(
        $processes >= 1 && $processes <= 30,
        'DROVE_PHASE5_PROCESSES must be between 1 and 30.',
    );
    nativePhaseFiveAssert($repetition >= 1, 'DROVE_PHASE5_REPETITION must be positive.');
    nativePhaseFiveAssert(
        ! file_exists($workspace) && mkdir($workspace, 0700, true),
        'Could not create the native Phase 5 proof workspace.',
    );

    $configuration = match ($fixture) {
        'parity' => [
            'tests' => 300,
            'state_prepare_us' => 250,
            'body_us' => 2_000,
            'cleanup_us' => 250,
            'timeout_ms' => 5_000,
        ],
        'saturation' => [
            'tests' => 60,
            'state_prepare_us' => 0,
            'body_us' => 100_000,
            'cleanup_us' => 0,
            'timeout_ms' => 10_000,
        ],
        'cheap' => [
            'tests' => 300,
            'state_prepare_us' => 0,
            'body_us' => 0,
            'cleanup_us' => 0,
            'timeout_ms' => 5_000,
        ],
        'setup' => [
            'tests' => 300,
            'state_prepare_us' => $runner === 'pcntl' ? 20_000 : 0,
            'body_us' => 0,
            'cleanup_us' => 0,
            'timeout_ms' => 5_000,
        ],
        'independent' => [
            'tests' => 300,
            'state_prepare_us' => 0,
            'body_us' => 100_000,
            'cleanup_us' => 0,
            'timeout_ms' => 5_000,
        ],
        'stress' => [
            'tests' => 10_000,
            'state_prepare_us' => 0,
            'body_us' => 0,
            'cleanup_us' => 0,
            'timeout_ms' => 5_000,
        ],
    };
    $testCount = $configuration['tests'];
    $barrierPath = $workspace.'/saturation.ready';
    $expectedPopulation = 1 + min($testCount, 2 * $processes);
    $memoryLimit = ini_get('memory_limit');

    if ($fixture === 'stress') {
        nativePhaseFiveAssert(
            $memoryLimit === '256M',
            'The 10,000-case Phase 5 stress gate must run under memory_limit=256M.',
        );
    }

    nativePhaseFivePhase('planning');
    $planningStartedNs = hrtime(true);
    $tasks = [];

    for ($index = 0; $index < $testCount; $index++) {
        $tasks[] = [
            'id' => sprintf('phase5-%05d', $index),
            'kind' => 'test',
            'scope_id' => 'phase5-root',
            'scopes' => ['phase5-root'],
            'timeout_ms' => $configuration['timeout_ms'],
            'permit' => true,
        ];
    }

    $planMetadataBytes = strlen(json_encode($tasks, JSON_THROW_ON_ERROR));
    $planningMs = round((hrtime(true) - $planningStartedNs) / 1_000_000, 3);

    nativePhaseFivePhase('environment_prepare');
    $environmentStartedNs = hrtime(true);
    $preparedDigest = null;

    if ($fixture === 'setup' && $runner === 'drover') {
        usleep(20_000);
        $preparedDigest = hash('sha256', str_repeat('prepared-state:', 8_192));
    }

    $environmentPrepareMs = round(
        (hrtime(true) - $environmentStartedNs) / 1_000_000,
        3,
    );
    $scheduler = $runner === 'drover'
        ? new DroverScheduler(
            'native-phase-5-'.$fixture.'-'.$processes.'-'.bin2hex(random_bytes(6)),
            $processes,
            defaultTimeoutMs: 5_000,
            termGraceMs: 50,
        )
        : new PcntlScheduler(
            'native-phase-5-reference-'.$fixture.'-'.$processes.'-'.bin2hex(random_bytes(6)),
            $processes,
            defaultTimeoutMs: 5_000,
            termGraceMs: 50,
        );
    $rootIoBefore = nativePhaseFiveIoSnapshot();

    nativePhaseFivePhase('execution');
    $executionStartedNs = hrtime(true);
    $mapped = $scheduler->map(
        $tasks,
        static function (array $task) use (
            $barrierPath,
            $configuration,
            $expectedPopulation,
            $fixture,
            $preparedDigest,
            $processes,
            $runner,
        ): array {
            $index = (int) substr((string) $task['id'], -5);
            $ioBefore = nativePhaseFiveIoSnapshot();
            $stateStartedNs = hrtime(true);
            $digest = $preparedDigest;

            if ($fixture === 'setup' && $runner === 'pcntl') {
                usleep($configuration['state_prepare_us']);
                $digest = hash('sha256', str_repeat('prepared-state:', 8_192));
            } elseif ($configuration['state_prepare_us'] > 0) {
                usleep($configuration['state_prepare_us']);
            }

            $stateFinishedNs = hrtime(true);

            if ($fixture === 'saturation') {
                nativePhaseFiveAssert(
                    file_put_contents(
                        $barrierPath,
                        getmypid().PHP_EOL,
                        FILE_APPEND | LOCK_EX,
                    ) !== false,
                    'Could not enter the native Phase 5 saturation barrier.',
                );
                $barrierDeadline = hrtime(true) + 5_000_000_000;

                do {
                    clearstatcache(true, $barrierPath);
                    $ready = file($barrierPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $readyCount = is_array($ready)
                        ? count(array_unique($ready, SORT_STRING))
                        : 0;

                    if ($readyCount >= $processes) {
                        break;
                    }

                    usleep(500);
                } while (hrtime(true) < $barrierDeadline);

                nativePhaseFiveAssert(
                    $readyCount >= $processes,
                    'The native Phase 5 saturation barrier did not fill.',
                );
                nativePhaseFiveWaitForPopulationAck($expectedPopulation);
            }

            $bodyStartedNs = hrtime(true);

            if ($configuration['body_us'] > 0) {
                usleep($configuration['body_us']);
            }

            $semantic = hash(
                'sha256',
                implode(':', [
                    $fixture,
                    (string) $index,
                    $fixture === 'setup' ? (string) $digest : 'native',
                ]),
            );
            $bodyFinishedNs = hrtime(true);
            $cleanupStartedNs = hrtime(true);

            if ($configuration['cleanup_us'] > 0) {
                usleep($configuration['cleanup_us']);
            }

            $cleanupFinishedNs = hrtime(true);

            return [
                'id' => $task['id'],
                'semantic' => $semantic,
                'pid' => getmypid(),
                'state_prepare' => [
                    'started_ns' => $stateStartedNs,
                    'finished_ns' => $stateFinishedNs,
                    'duration_ms' => round(
                        ($stateFinishedNs - $stateStartedNs) / 1_000_000,
                        3,
                    ),
                ],
                'body' => [
                    'started_ns' => $bodyStartedNs,
                    'finished_ns' => $bodyFinishedNs,
                    'duration_ms' => round(
                        ($bodyFinishedNs - $bodyStartedNs) / 1_000_000,
                        3,
                    ),
                ],
                'cleanup' => [
                    'started_ns' => $cleanupStartedNs,
                    'finished_ns' => $cleanupFinishedNs,
                    'duration_ms' => round(
                        ($cleanupFinishedNs - $cleanupStartedNs) / 1_000_000,
                        3,
                    ),
                ],
                'io' => nativePhaseFiveIoDelta(
                    $ioBefore,
                    nativePhaseFiveIoSnapshot(),
                ),
                'php_peak_memory_bytes' => memory_get_peak_usage(true),
            ];
        },
    );
    $executionFinishedNs = hrtime(true);
    $executionMs = round(($executionFinishedNs - $executionStartedNs) / 1_000_000, 3);
    $rootIo = nativePhaseFiveIoDelta($rootIoBefore, nativePhaseFiveIoSnapshot());
    $results = $mapped['results'];
    nativePhaseFiveAssert(
        count($results) === $testCount,
        'Native Phase 5 lost terminal scheduler results.',
    );

    $values = [];
    $executorPids = [];
    $processIntervals = [];
    $bodyIntervals = [];
    $queueWaitMs = [];
    $statePrepareMs = [];
    $bodyMs = [];
    $cleanupMs = [];
    $executorMemory = [];
    $executorIo = [
        'read_bytes' => 0,
        'write_bytes' => 0,
        'cancelled_write_bytes' => 0,
    ];

    foreach ($results as $result) {
        $resultIdentity = [
            'id' => $result['id'] ?? null,
            'status' => $result['status'] ?? null,
            'failure' => $result['failure'] ?? null,
            'telemetry' => $result['telemetry'] ?? null,
        ];
        nativePhaseFiveAssert(
            ($result['status'] ?? null) === 'passed'
                && is_array($result['value'] ?? null)
                && is_array($result['telemetry'] ?? null),
            'Native Phase 5 received a non-passing terminal result: '
                .json_encode($resultIdentity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $value = $result['value'];
        $telemetry = $result['telemetry'];
        $pid = $telemetry['pid'] ?? null;
        $startedNs = $telemetry['started_ns'] ?? null;
        $finishedNs = $telemetry['finished_ns'] ?? null;
        $memory = $telemetry['memory_peak_bytes']
            ?? $result['memory_peak_bytes']
            ?? $value['php_peak_memory_bytes']
            ?? null;

        nativePhaseFiveAssert(
            is_int($pid)
                && $pid > 0
                && is_int($startedNs)
                && is_int($finishedNs)
                && $finishedNs >= $startedNs
                && is_int($memory)
                && $memory > 0,
            'Native Phase 5 executor telemetry is incomplete.',
        );
        nativePhaseFiveAssert(
            ($value['id'] ?? null) === ($result['id'] ?? null)
                && ($value['pid'] ?? null) === $pid
                && is_array($value['state_prepare'] ?? null)
                && is_array($value['body'] ?? null)
                && is_array($value['cleanup'] ?? null),
            'Native Phase 5 executor value telemetry diverged.',
        );
        $body = $value['body'];
        $state = $value['state_prepare'];
        $cleanup = $value['cleanup'];
        $bodyStartedNs = $body['started_ns'] ?? null;
        $bodyFinishedNs = $body['finished_ns'] ?? null;
        nativePhaseFiveAssert(
            is_int($bodyStartedNs)
                && is_int($bodyFinishedNs)
                && $bodyFinishedNs >= $bodyStartedNs,
            'Native Phase 5 body interval is invalid.',
        );
        $values[(string) $result['id']] = [
            'id' => $result['id'],
            'semantic' => $value['semantic'],
        ];
        $executorPids[] = $pid;
        $processIntervals[] = [
            'started_ns' => $startedNs,
            'finished_ns' => $finishedNs,
        ];
        $bodyIntervals[] = [
            'started_ns' => $bodyStartedNs,
            'finished_ns' => $bodyFinishedNs,
        ];
        $queueWaitMs[] = max(0.0, ($startedNs - $executionStartedNs) / 1_000_000);
        $statePrepareMs[] = (float) $state['duration_ms'];
        $bodyMs[] = (float) $body['duration_ms'];
        $cleanupMs[] = (float) $cleanup['duration_ms'];
        $executorMemory[] = ['pid' => $pid, 'peak_bytes' => $memory];
        $io = $value['io'] ?? null;

        if (is_array($io)) {
            foreach (array_keys($executorIo) as $field) {
                $executorIo[$field] += (int) ($io[$field] ?? 0);
            }
        }
    }

    ksort($values, SORT_STRING);
    $uniqueExecutorPids = array_values(array_unique($executorPids, SORT_REGULAR));
    sort($uniqueExecutorPids, SORT_NUMERIC);
    nativePhaseFiveAssert(
        count($executorPids) === $testCount
            && count($uniqueExecutorPids) === $testCount,
        'Native Phase 5 batching or executor PID reuse was observed.',
    );
    $observedProcesses = nativePhaseFivePeakConcurrency($processIntervals);
    $observedBodies = nativePhaseFivePeakConcurrency($bodyIntervals);
    nativePhaseFiveAssert(
        $observedBodies <= $processes && $observedProcesses <= $processes,
        'Native Phase 5 exceeded declared concurrency.',
    );

    if ($fixture === 'saturation') {
        nativePhaseFiveAssert(
            $observedBodies === $processes && $observedProcesses === $processes,
            'The deliberate Phase 5 saturation fixture did not reach its declared width.',
        );
    } elseif ($processes === 1) {
        nativePhaseFiveAssert(
            $observedBodies === 1 && $observedProcesses === 1,
            'A C1 native Phase 5 fixture did not observe exactly one lane.',
        );
    } elseif ($runner === 'drover'
        && in_array($fixture, ['parity', 'independent'], true)) {
        nativePhaseFiveAssert(
            $observedProcesses >= 2,
            'A parallel native Phase 5 fixture observed fewer than two executor lanes.',
        );
    }

    $mapTopology = $mapped['telemetry']['topology'] ?? null;

    if ($runner === 'drover') {
        nativePhaseFiveAssert(
            is_array($mapTopology),
            'Drover did not publish versioned map topology telemetry.',
        );
        $topology = ['scope_workers' => 0] + $mapTopology;
        nativePhaseFiveAssertTopology($topology);
        nativePhaseFiveAssert(
            $topology['forks'] === $testCount
                && $topology['executor_workers'] === $testCount
                && $topology['process_anchors'] === 0
                && $topology['peak_live_pids'] >= 1
                && $topology['peak_live_pids'] <= $topology['outstanding_task_limit']
                && $topology['peak_outstanding_tasks'] <= $topology['outstanding_task_limit']
                && $topology['outstanding_task_limit'] <= 2 * $processes,
            'Drover topology violated the Phase 5 one-fork or bounded-window contract.',
        );
        nativePhaseFiveAssert(
            $fixture !== 'saturation'
                || $topology['peak_live_pids'] === min($testCount, 2 * $processes),
            'Drover did not fill its bounded pre-armed executor window.',
        );
        $topologySource = 'kernel';
    } else {
        $topology = [
            'schema' => 1,
            'forks' => $testCount,
            'scope_workers' => 0,
            'executor_workers' => $testCount,
            'process_anchors' => 0,
            'peak_live_pids' => $observedProcesses,
            'peak_outstanding_tasks' => $observedProcesses,
            'outstanding_task_limit' => $processes,
        ];
        nativePhaseFiveAssertTopology($topology);
        $topologySource = 'harness';
    }

    $remainingChildren = nativePhaseFiveWaitForExit(nativePhaseFiveChildPids(), 2_000);
    nativePhaseFiveAssert(
        $remainingChildren === [],
        'Native Phase 5 left child processes alive after the scheduler map.',
    );
    nativePhaseFivePhase('rendering');
    $renderingStartedNs = hrtime(true);
    $semanticHash = hash(
        'sha256',
        json_encode(array_values($values), JSON_THROW_ON_ERROR),
    );
    $renderProbe = json_encode([
        'fixture' => $fixture,
        'runner' => $runner,
        'values' => array_values($values),
    ], JSON_THROW_ON_ERROR);
    $renderingMs = round((hrtime(true) - $renderingStartedNs) / 1_000_000, 3);
    nativePhaseFiveRemove($workspace);
    $proofWallMs = round((hrtime(true) - $proofStartedNs) / 1_000_000, 3);
    $rootIo ??= [
        'read_bytes' => 0,
        'write_bytes' => 0,
        'cancelled_write_bytes' => 0,
    ];
    $totalIo = [];

    foreach (array_keys($executorIo) as $field) {
        $totalIo[$field] = $rootIo[$field] + $executorIo[$field];
    }

    $summary = [
        'schema' => 1,
        'ok' => true,
        ...$identity,
        'fixture' => $fixture,
        'runner' => $runner,
        'runner_class' => $scheduler::class,
        'repetition' => $repetition,
        'processes' => $processes,
        'test_count' => $testCount,
        'runnable_count' => $testCount,
        'terminal_result_count' => count($results),
        'status_counts' => ['passed' => $testCount],
        'semantic_hash' => $semanticHash,
        'plan' => [
            'scope_count' => 1,
            'stress_shard_count' => 0,
            'metadata_bytes' => $planMetadataBytes,
        ],
        'isolation' => [
            'one_test_per_executor' => true,
            'no_batch' => true,
            'unique_executor_pid_count' => count($uniqueExecutorPids),
            'executor_pids' => $uniqueExecutorPids,
            'orphan_pids' => [],
            'artifact_residue_count' => 0,
        ],
        'telemetry' => [
            'schema' => 1,
            'measurement_sources' => [
                'topology' => $topologySource,
                'timings' => 'harness',
                'timing_observer' => getenv('DROVE_PHASE5_PHASE_FILE') === false
                    ? 'none'
                    : 'procfs-smaps-rollup',
                'queue_wait' => 'harness',
                'phase_timings' => 'harness',
                'php_memory' => 'php',
                'io' => PHP_OS_FAMILY === 'Linux' ? 'procfs' : 'unavailable',
            ],
            'timings_ms' => [
                'planning' => $planningMs,
                'environment_prepare' => $environmentPrepareMs,
                'execution' => $executionMs,
                'rendering' => $renderingMs,
                'wall' => $proofWallMs,
            ],
            'scheduler' => [
                'requested_processes' => $processes,
                'observed_process_lanes' => $observedProcesses,
                'observed_interval_lanes' => $observedProcesses,
                'observed_body_lanes' => $observedBodies,
                'scheduler_idle_lane_ratio' => nativePhaseFiveIdleRatio(
                    $bodyIntervals,
                    $processes,
                ),
                'scheduler_steady_state_idle_lane_ratio' => nativePhaseFiveSteadyStateIdleRatio(
                    $bodyIntervals,
                    $processes,
                ),
                'queue_wait_ms' => [
                    'total' => round(array_sum($queueWaitMs), 3),
                    'median' => round(nativePhaseFiveMedian($queueWaitMs), 3),
                    'max' => round(max($queueWaitMs), 3),
                ],
            ],
            'topology' => $topology,
            'phases_ms' => [
                'state_prepare' => [
                    'total' => round(array_sum($statePrepareMs), 3),
                    'median' => round(nativePhaseFiveMedian($statePrepareMs), 3),
                    'max' => round(max($statePrepareMs), 3),
                ],
                'body' => [
                    'total' => round(array_sum($bodyMs), 3),
                    'median' => round(nativePhaseFiveMedian($bodyMs), 3),
                    'max' => round(max($bodyMs), 3),
                ],
                'cleanup' => [
                    'total' => round(array_sum($cleanupMs), 3),
                    'median' => round(nativePhaseFiveMedian($cleanupMs), 3),
                    'max' => round(max($cleanupMs), 3),
                ],
            ],
            'memory' => [
                'memory_limit' => $memoryLimit,
                'root_php_peak_bytes' => memory_get_peak_usage(true),
                'executor_sample_count' => count($executorMemory),
                'executor_php_peak_bytes' => max(array_column($executorMemory, 'peak_bytes')),
                'per_process_php_peak_bytes' => $executorMemory,
            ],
            'io' => [
                'root' => $rootIo,
                'executors' => $executorIo,
                'aggregate' => $totalIo,
            ],
            'faults' => [
                'submitted' => 0,
                'terminal' => 0,
                'kinds' => [],
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
