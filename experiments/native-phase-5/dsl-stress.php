<?php

declare(strict_types=1);

use Drove\Kernel\DroverScheduler;
use Drove\Native\Declarations;
use Drove\Native\Runner;

use function Drove\Native\expect;
use function Drove\Native\test;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/support.php';

final class NativePhaseFiveDslHeap
{
    public static int $value = 73;
}

try {
    $proofStartedNs = hrtime(true);
    $identity = nativePhaseFiveIdentity();
    $processes = (int) (getenv('DROVE_PHASE5_PROCESSES') ?: 30);
    nativePhaseFiveAssert(
        $processes === 30,
        'The native Phase 5 DSL stress gate runs at C30.',
    );
    nativePhaseFiveAssert(
        ini_get('memory_limit') === '256M',
        'The native Phase 5 DSL stress gate must run under memory_limit=256M.',
    );
    nativePhaseFivePhase('planning');
    $planningStartedNs = hrtime(true);
    $registry = Declarations::capture(
        static function (): void {
            for ($index = 0; $index < 10_000; $index++) {
                test(sprintf('flat stress %05d', $index), static function () use ($index): void {
                    expect(NativePhaseFiveDslHeap::$value)->toBe(73);
                    expect($index)->toBe($index);
                });
            }
        },
        dirname(__DIR__, 2),
        'Drove native Phase 5 flat stress',
    );
    $plan = $registry->plan();
    $planJson = json_encode($plan, JSON_THROW_ON_ERROR);
    $planMetadataBytes = strlen($planJson);
    $root = $plan['root'] ?? null;
    nativePhaseFiveAssert(
        is_array($root)
            && is_array($root['children'] ?? null)
            && count($root['children']) === 1
            && is_array($root['children'][0] ?? null)
            && ($root['children'][0]['type'] ?? null) === 'file'
            && ($root['children'][0]['children'] ?? null) === []
            && is_array($root['children'][0]['tests'] ?? null)
            && count($root['children'][0]['tests']) === 10_000,
        'The native Phase 5 stress plan must be one flat, unsharded file scope.',
    );
    $planningMs = round((hrtime(true) - $planningStartedNs) / 1_000_000, 3);
    nativePhaseFivePhase('environment_prepare');
    $environmentStartedNs = hrtime(true);
    $environmentPrepareMs = round(
        (hrtime(true) - $environmentStartedNs) / 1_000_000,
        3,
    );
    $scheduler = new DroverScheduler(
        'native-phase-5-dsl-stress-'.bin2hex(random_bytes(6)),
        $processes,
        defaultTimeoutMs: 5_000,
        termGraceMs: 50,
    );
    nativePhaseFivePhase('execution');
    $executionStartedNs = hrtime(true);
    $run = new Runner($scheduler)->run($registry);
    $executionMs = round((hrtime(true) - $executionStartedNs) / 1_000_000, 3);
    $tests = $run['tests'] ?? null;
    nativePhaseFiveAssert(
        ($run['status'] ?? null) === 'passed'
            && ($run['exit_code'] ?? null) === 0
            && is_array($tests)
            && array_is_list($tests)
            && count($tests) === 10_000
            && array_all(
                $tests,
                static fn (array $result): bool => ($result['status'] ?? null) === 'passed'
                    && ($result['assertions'] ?? null) === 2
                    && ($result['cleanups'] ?? null) === 0,
            ),
        'The native Phase 5 DSL stress run lost semantic terminal results.',
    );
    $executorPids = [];
    $executorMemory = [];
    $semanticProjection = [];

    foreach ($tests as $result) {
        $telemetry = $result['telemetry'] ?? null;
        $pid = is_array($telemetry) ? ($telemetry['pid'] ?? null) : null;
        $memory = is_array($telemetry) ? ($telemetry['memory_peak_bytes'] ?? null) : null;
        nativePhaseFiveAssert(
            is_int($pid)
                && $pid > 0
                && is_int($memory)
                && $memory > 0
                && ($telemetry['forks'] ?? null) === 1
                && ($telemetry['scope_workers'] ?? null) === 0
                && ($telemetry['executor_workers'] ?? null) === 1
                && ($telemetry['process_anchors'] ?? null) === 0,
            'A native Phase 5 DSL executor lost topology or memory telemetry.',
        );
        $executorPids[] = $pid;
        $executorMemory[] = ['pid' => $pid, 'peak_bytes' => $memory];
        $semanticProjection[] = [
            'id' => $result['id'] ?? null,
            'status' => $result['status'] ?? null,
            'assertions' => $result['assertions'] ?? null,
            'cleanups' => $result['cleanups'] ?? null,
        ];
    }

    $uniquePids = array_values(array_unique($executorPids, SORT_REGULAR));
    sort($uniquePids, SORT_NUMERIC);
    nativePhaseFiveAssert(
        count($uniquePids) === 10_000,
        'Native Phase 5 DSL stress observed batching or executor PID reuse.',
    );
    $topology = $scheduler->topologyTelemetry();
    nativePhaseFiveAssertTopology($topology);
    nativePhaseFiveAssert(
        $topology['forks'] === 10_000
            && $topology['scope_workers'] === 0
            && $topology['executor_workers'] === 10_000
            && $topology['process_anchors'] === 0
            && $topology['peak_live_pids'] >= 2
            && $topology['peak_live_pids'] <= 30
            && $topology['peak_outstanding_tasks'] <= 60
            && $topology['outstanding_task_limit'] === 60,
        'Native Phase 5 DSL stress violated its exact flat C30 topology.',
    );
    nativePhaseFiveAssert(
        NativePhaseFiveDslHeap::$value === 73,
        'Native Phase 5 DSL stress mutated the prepared parent heap.',
    );
    $rootPeakMemoryBytes = memory_get_peak_usage(true);
    $memoryLimitBytes = 256 * 1024 * 1024;
    $memoryHeadroomRatio = 1 - ($rootPeakMemoryBytes / $memoryLimitBytes);
    nativePhaseFiveAssert(
        $rootPeakMemoryBytes <= (int) floor(0.90 * $memoryLimitBytes),
        sprintf(
            'Native Phase 5 DSL stress left only %.2f%% PHP memory headroom.',
            100 * $memoryHeadroomRatio,
        ),
    );
    $remainingChildren = nativePhaseFiveWaitForExit(nativePhaseFiveChildPids(), 2_000);
    nativePhaseFiveAssert(
        $remainingChildren === [],
        'Native Phase 5 DSL stress left child processes alive.',
    );
    nativePhaseFivePhase('rendering');
    $renderingStartedNs = hrtime(true);
    $semanticHash = hash(
        'sha256',
        json_encode($semanticProjection, JSON_THROW_ON_ERROR),
    );
    $renderingMs = round((hrtime(true) - $renderingStartedNs) / 1_000_000, 3);
    $summary = [
        'schema' => 1,
        'ok' => true,
        ...$identity,
        'fixture' => 'dsl-stress',
        'runner' => 'native-dsl',
        'processes' => $processes,
        'test_count' => count($tests),
        'runnable_count' => 10_000,
        'terminal_result_count' => count($tests),
        'status_counts' => ['passed' => 10_000],
        'assertion_count' => 20_000,
        'cleanup_count' => 0,
        'semantic_hash' => $semanticHash,
        'plan' => [
            'scope_count' => 2,
            'stress_shard_count' => 0,
            'metadata_bytes' => $planMetadataBytes,
        ],
        'isolation' => [
            'one_test_per_executor' => true,
            'no_batch' => true,
            'unique_executor_pid_count' => count($uniquePids),
            'executor_pids' => $uniquePids,
            'parent_heap_unchanged' => true,
            'orphan_pids' => [],
            'artifact_residue_count' => 0,
        ],
        'telemetry' => [
            'schema' => 1,
            'measurement_sources' => [
                'topology' => 'kernel',
                'timings' => 'harness',
                'php_memory' => 'php',
            ],
            'timings_ms' => [
                'planning' => $planningMs,
                'environment_prepare' => $environmentPrepareMs,
                'execution' => $executionMs,
                'rendering' => $renderingMs,
                'wall' => round((hrtime(true) - $proofStartedNs) / 1_000_000, 3),
            ],
            'scheduler' => [
                'requested_processes' => 30,
                'observed_process_lanes' => $topology['peak_live_pids'],
            ],
            'topology' => $topology,
            'memory' => [
                'memory_limit' => ini_get('memory_limit'),
                'root_php_peak_bytes' => $rootPeakMemoryBytes,
                'limit_bytes' => $memoryLimitBytes,
                'headroom_ratio' => round($memoryHeadroomRatio, 6),
                'minimum_headroom_ratio' => 0.10,
                'executor_sample_count' => count($executorMemory),
                'executor_php_peak_bytes' => max([
                    1,
                    ...array_column($executorMemory, 'peak_bytes'),
                ]),
                'per_process_php_peak_bytes' => $executorMemory,
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
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
