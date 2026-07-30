<?php

declare(strict_types=1);

require __DIR__.'/support.php';

try {
    $arguments = $_SERVER['argv'] ?? [];
    nativePhaseFiveAssert(
        count($arguments) === 3,
        'Usage: php compare.php artifact-directory comparison.json',
    );
    $directory = $arguments[1];
    $outputPath = $arguments[2];
    nativePhaseFiveAssert(
        is_dir($directory),
        'Native Phase 5 artifact directory does not exist.',
    );
    $read = static fn (string $name): array => nativePhaseFiveReadJson(
        $directory.'/'.$name.'.json',
    );
    $processes = [1, 2, 4, 8, 16, 30];
    $all = [];
    $parity = [];
    $saturation = [];

    /**
     * @param  array<string, mixed>  $summary
     */
    $validateIdentity = static function (array $summary): void {
        nativePhaseFiveAssert(
            ($summary['schema'] ?? null) === 1
                && ($summary['ok'] ?? null) === true
                && is_string($summary['evidence_revision'] ?? null)
                && preg_match('/\A[a-f0-9]{40}\z/D', $summary['evidence_revision']) === 1
                && ($summary['runtime_platform']['os_family'] ?? null) === 'Linux'
                && preg_match(
                    '/\A8\.4\./D',
                    (string) ($summary['runtime_platform']['php'] ?? ''),
                ) === 1
                && is_string($summary['drover_identity']['library_sha256'] ?? null)
                && preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    $summary['drover_identity']['library_sha256'],
                ) === 1,
            'Native Phase 5 artifact identity is invalid.',
        );
    };

    /**
     * @param  array<string, mixed>  $summary
     */
    $validateMonitor = static function (array $summary): void {
        $monitor = $summary['telemetry']['process_monitor'] ?? null;
        $global = is_array($monitor) ? ($monitor['global'] ?? null) : null;
        $phases = is_array($monitor) ? ($monitor['phases'] ?? null) : null;
        nativePhaseFiveAssert(
            is_array($monitor)
                && ($monitor['schema'] ?? null) === 1
                && ($monitor['measurement_source'] ?? null) === 'procfs'
                && is_array($global)
                && is_int($global['sample_count'] ?? null)
                && $global['sample_count'] > 0
                && is_int($global['discarded_unstable_sample_count'] ?? null)
                && $global['discarded_unstable_sample_count'] >= 0
                && is_int($global['aggregate_peak_rss_bytes'] ?? null)
                && $global['aggregate_peak_rss_bytes'] > 0
                && is_int($global['aggregate_peak_pss_bytes'] ?? null)
                && $global['aggregate_peak_pss_bytes'] > 0
                && ($global['aggregate_peak_swap_bytes'] ?? null) === 0
                && is_int($global['peak_live_pids'] ?? null)
                && $global['peak_live_pids'] > 0
                && is_int($global['peak_live_pid_sample_count'] ?? null)
                && $global['peak_live_pid_sample_count'] > 0
                && $global['peak_live_pid_sample_count'] <= $global['sample_count']
                && is_int($global['peak_live_pid_aggregate_peak_rss_bytes'] ?? null)
                && $global['peak_live_pid_aggregate_peak_rss_bytes'] > 0
                && $global['peak_live_pid_aggregate_peak_rss_bytes']
                    <= $global['aggregate_peak_rss_bytes']
                && is_int($global['peak_live_pid_aggregate_peak_pss_bytes'] ?? null)
                && $global['peak_live_pid_aggregate_peak_pss_bytes'] > 0
                && $global['peak_live_pid_aggregate_peak_pss_bytes']
                    <= $global['aggregate_peak_pss_bytes']
                && ($global['peak_live_pid_aggregate_peak_swap_bytes'] ?? null) === 0
                && is_array($phases)
                && array_diff(
                    array_keys($phases),
                    ['bootstrap', 'planning', 'environment_prepare', 'execution', 'rendering'],
                ) === [],
            'Native Phase 5 procfs evidence is invalid.',
        );
        $events = $monitor['cgroup_memory_events_delta'] ?? null;

        if (is_array($events)) {
            nativePhaseFiveAssert(
                ($events['oom'] ?? 0) === 0
                    && ($events['oom_kill'] ?? 0) === 0,
                'Native Phase 5 observed a cgroup OOM event.',
            );
        }
    };

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, int>
     */
    $validatePeakExecution = static function (
        array $summary,
        int $expectedPids,
    ): array {
        $execution = $summary['telemetry']['process_monitor']['phases']['execution'] ?? null;
        nativePhaseFiveAssert(
            is_array($execution)
                && is_int($execution['sample_count'] ?? null)
                && $execution['sample_count'] >= 3
                && ($execution['peak_live_pids'] ?? null) === $expectedPids
                && is_int($execution['peak_live_pid_sample_count'] ?? null)
                && $execution['peak_live_pid_sample_count'] >= 3
                && $execution['peak_live_pid_sample_count'] <= $execution['sample_count']
                && is_int($execution['peak_live_pid_aggregate_peak_rss_bytes'] ?? null)
                && $execution['peak_live_pid_aggregate_peak_rss_bytes'] > 0
                && is_int($execution['aggregate_peak_rss_bytes'] ?? null)
                && $execution['peak_live_pid_aggregate_peak_rss_bytes']
                    <= $execution['aggregate_peak_rss_bytes']
                && is_int($execution['peak_live_pid_aggregate_peak_pss_bytes'] ?? null)
                && $execution['peak_live_pid_aggregate_peak_pss_bytes'] > 0
                && is_int($execution['aggregate_peak_pss_bytes'] ?? null)
                && $execution['peak_live_pid_aggregate_peak_pss_bytes']
                    <= $execution['aggregate_peak_pss_bytes']
                && ($execution['peak_live_pid_aggregate_peak_swap_bytes'] ?? null) === 0,
            'Native Phase 5 did not capture the expected pre-armed execution-phase process population.',
        );

        return $execution;
    };

    /**
     * @param  array<string, mixed>  $summary
     */
    $validateDirect = static function (array $summary) use (
        $validateIdentity,
        $validateMonitor,
    ): void {
        $validateIdentity($summary);
        $validateMonitor($summary);
        $tests = $summary['test_count'] ?? null;
        $topology = $summary['telemetry']['topology'] ?? null;
        nativePhaseFiveAssert(
            ($summary['runner'] ?? null) === 'drover'
                && is_int($tests)
                && $tests > 0
                && ($summary['terminal_result_count'] ?? null) === $tests
                && ($summary['status_counts'] ?? null) === ['passed' => $tests]
                && ($summary['isolation']['one_test_per_executor'] ?? null) === true
                && ($summary['isolation']['no_batch'] ?? null) === true
                && ($summary['isolation']['unique_executor_pid_count'] ?? null) === $tests
                && ($summary['isolation']['orphan_pids'] ?? null) === []
                && ($summary['isolation']['artifact_residue_count'] ?? null) === 0
                && is_array($topology),
            'Native Phase 5 direct proof lost terminal or isolation evidence.',
        );
        nativePhaseFiveAssertTopology($topology);
        nativePhaseFiveAssert(
            $topology['forks'] === $tests
                && $topology['scope_workers'] === 0
                && $topology['executor_workers'] === $tests
                && $topology['process_anchors'] === 0
                && $topology['peak_live_pids'] >= 1
                && $topology['peak_live_pids'] <= $topology['outstanding_task_limit']
                && $topology['peak_outstanding_tasks'] <= $topology['outstanding_task_limit']
                && $topology['outstanding_task_limit'] <= 2 * $summary['processes'],
            'Native Phase 5 direct topology is invalid.',
        );
    };

    foreach ($processes as $processCount) {
        $paritySummary = $read('parity-c'.$processCount);
        $saturationSummary = $read('saturation-c'.$processCount);
        $validateDirect($paritySummary);
        $validateDirect($saturationSummary);
        $expectedLiveExecutors = min(60, 2 * $processCount);
        $validatePeakExecution($saturationSummary, $expectedLiveExecutors + 1);
        nativePhaseFiveAssert(
            ($paritySummary['fixture'] ?? null) === 'parity'
                && ($paritySummary['processes'] ?? null) === $processCount
                && ($paritySummary['test_count'] ?? null) === 300
                && ($saturationSummary['fixture'] ?? null) === 'saturation'
                && ($saturationSummary['processes'] ?? null) === $processCount
                && ($saturationSummary['test_count'] ?? null) === 60
                && ($saturationSummary['telemetry']['topology']['peak_live_pids'] ?? null)
                    === $expectedLiveExecutors
                && ($saturationSummary['telemetry']['scheduler']['observed_process_lanes'] ?? null)
                    === $processCount
                && ($saturationSummary['telemetry']['scheduler']['observed_body_lanes'] ?? null)
                    === $processCount,
            'Native Phase 5 declared-concurrency saturation gate failed.',
        );
        $parity[] = $paritySummary;
        $saturation[] = $saturationSummary;
        $all[] = $paritySummary;
        $all[] = $saturationSummary;
    }

    nativePhaseFiveAssert(
        count(array_unique(array_column($parity, 'semantic_hash'))) === 1
            && count(array_unique(array_column($saturation, 'semantic_hash'))) === 1,
        'Native Phase 5 C1-C30 semantic hashes diverged.',
    );
    $c1Execution = $saturation[0]['telemetry']['process_monitor']['phases']['execution'];

    foreach ($saturation as $summary) {
        $execution = $summary['telemetry']['process_monitor']['phases']['execution'];
        nativePhaseFiveAssert(
            $execution['peak_live_pid_aggregate_peak_rss_bytes']
                <= 1.15 * $summary['processes']
                    * $c1Execution['peak_live_pid_aggregate_peak_rss_bytes']
                && $execution['peak_live_pid_aggregate_peak_pss_bytes']
                    <= 1.15 * $summary['processes']
                        * $c1Execution['peak_live_pid_aggregate_peak_pss_bytes'],
            'Native Phase 5 aggregate execution memory exceeded its C1-derived bound.',
        );
    }

    $cheap = ['drover' => [], 'pcntl' => []];
    $setup = ['drover' => [], 'pcntl' => []];

    foreach (['cheap' => &$cheap, 'setup' => &$setup] as $fixture => &$samples) {
        foreach (['drover', 'pcntl'] as $runner) {
            for ($repetition = 1; $repetition <= 5; $repetition++) {
                $summary = $read($fixture.'-'.$runner.'-r'.$repetition);
                $validateIdentity($summary);
                $validateMonitor($summary);
                nativePhaseFiveAssert(
                    ($summary['fixture'] ?? null) === $fixture
                        && ($summary['runner'] ?? null) === $runner
                        && ($summary['processes'] ?? null) === 1
                        && ($summary['repetition'] ?? null) === $repetition
                        && ($summary['test_count'] ?? null) === 300
                        && ($summary['terminal_result_count'] ?? null) === 300
                        && ($summary['isolation']['no_batch'] ?? null) === true
                        && ($summary['isolation']['unique_executor_pid_count'] ?? null) === 300,
                    'Native Phase 5 matched-reference sample is invalid.',
                );
                $samples[$runner][] = $summary;
                $all[] = $summary;
            }
        }
    }

    unset($samples);
    $cheapDroverMedian = nativePhaseFiveMedian(array_map(
        static fn (array $summary): float => (float) $summary['telemetry']['timings_ms']['wall'],
        $cheap['drover'],
    ));
    $cheapReferenceMedian = nativePhaseFiveMedian(array_map(
        static fn (array $summary): float => (float) $summary['telemetry']['timings_ms']['wall'],
        $cheap['pcntl'],
    ));
    nativePhaseFiveAssert(
        $cheapDroverMedian <= 1.15 * $cheapReferenceMedian,
        sprintf(
            'Native Phase 5 cheap C1 median %.3f ms exceeds 115%% of matched PCNTL %.3f ms.',
            $cheapDroverMedian,
            $cheapReferenceMedian,
        ),
    );
    $setupDroverMedian = nativePhaseFiveMedian(array_map(
        static fn (array $summary): float => (float) $summary['telemetry']['timings_ms']['wall'],
        $setup['drover'],
    ));
    $setupReferenceMedian = nativePhaseFiveMedian(array_map(
        static fn (array $summary): float => (float) $summary['telemetry']['timings_ms']['wall'],
        $setup['pcntl'],
    ));
    nativePhaseFiveAssert(
        $setupReferenceMedian >= 2 * $setupDroverMedian,
        sprintf(
            'Native Phase 5 setup fixture is only %.3fx faster than its matched reference.',
            $setupReferenceMedian / max(0.001, $setupDroverMedian),
        ),
    );

    $independent = [];

    foreach ([1, 16, 30] as $processCount) {
        for ($repetition = 1; $repetition <= 3; $repetition++) {
            $summary = $read('independent-c'.$processCount.'-r'.$repetition);
            $validateDirect($summary);
            nativePhaseFiveAssert(
                ($summary['fixture'] ?? null) === 'independent'
                    && ($summary['processes'] ?? null) === $processCount
                    && ($summary['test_count'] ?? null) === 300
                    && ($summary['telemetry']['scheduler']['scheduler_idle_lane_ratio'] ?? 1) < 0.05,
                'Native Phase 5 independent-work utilization gate failed.',
            );
            $independent[$processCount][] = $summary;
            $all[] = $summary;
        }
    }

    $independentMedians = [];

    foreach ($independent as $processCount => $samples) {
        $independentMedians[$processCount] = nativePhaseFiveMedian(array_map(
            static fn (array $summary): float => (float) $summary['telemetry']['timings_ms']['execution'],
            $samples,
        ));
    }

    $speedup = $independentMedians[1] / $independentMedians[30];
    nativePhaseFiveAssert(
        $speedup >= 20
            && $independentMedians[30] <= 0.9 * $independentMedians[16],
        sprintf(
            'Native Phase 5 independent work reached %.3fx C1-C30 and C30/C16 %.3f.',
            $speedup,
            $independentMedians[30] / $independentMedians[16],
        ),
    );

    $inactive = [10 => [], 1_000 => []];
    $inactiveArtifactNames = [];

    for ($repetition = 1; $repetition <= 3; $repetition++) {
        foreach ([10, 1_000] as $count) {
            $name = 'inactive-'.$count.'-r'.$repetition;
            $inactive[$count][$repetition] = $read($name);
            $inactiveArtifactNames[] = $name.'.json';
        }
    }

    $inert = $read('inert');
    $stateful = $read('stateful');
    $inactiveSummaries = [
        ...array_values($inactive[10]),
        ...array_values($inactive[1_000]),
    ];

    foreach ([...$inactiveSummaries, $inert, $stateful] as $scopeSummary) {
        $validateIdentity($scopeSummary);
        $validateMonitor($scopeSummary);
        $expectedScopeHostPeak = ($scopeSummary['fixture'] ?? null) === 'stateful' ? 3 : 0;
        nativePhaseFiveAssert(
            ($scopeSummary['processes'] ?? null) === 30
                && ($scopeSummary['test_count'] ?? null) === 60
                && ($scopeSummary['terminal_result_count'] ?? null) === 60
                && ($scopeSummary['plan']['reported_scope_count'] ?? null)
                    === ($scopeSummary['plan']['scope_count'] ?? null)
                && ($scopeSummary['plan']['scope_started_event_count'] ?? null)
                    === ($scopeSummary['plan']['scope_count'] ?? null)
                && ($scopeSummary['plan']['scope_finished_event_count'] ?? null)
                    === ($scopeSummary['plan']['scope_count'] ?? null)
                && ($scopeSummary['telemetry']['scheduler']['requested_test_body_lanes'] ?? null)
                    === 30
                && is_int(
                    $scopeSummary['telemetry']['scheduler']['observed_test_body_lanes'] ?? null,
                )
                && $scopeSummary['telemetry']['scheduler']['observed_test_body_lanes'] >= 1
                && $scopeSummary['telemetry']['scheduler']['observed_test_body_lanes'] <= 30
                && ($scopeSummary['telemetry']['scheduler']['observed_active_process_lanes'] ?? null)
                    === ($scopeSummary['telemetry']['topology']['peak_live_pids'] ?? null)
                && ($scopeSummary['telemetry']['scheduler']['scope_host_peak'] ?? null)
                    === $expectedScopeHostPeak
                && ($scopeSummary['telemetry']['scheduler']['executor_peak'] ?? null)
                    === ($scopeSummary['telemetry']['scheduler']['observed_test_body_lanes'] ?? null)
                && ($scopeSummary['telemetry']['scheduler']['observed_active_process_lanes'] ?? null)
                    === ($scopeSummary['telemetry']['scheduler']['executor_peak'] ?? 0)
                        + ($scopeSummary['telemetry']['scheduler']['scope_host_peak'] ?? 0)
                && ($scopeSummary['isolation']['orphan_pids'] ?? null) === []
                && ($scopeSummary['isolation']['artifact_residue_count'] ?? null) === 0
                && ($scopeSummary['telemetry']['prepared_branches']['current_after_run'] ?? null) === 0
                && ($scopeSummary['telemetry']['prepared_branches']['peak'] ?? PHP_INT_MAX)
                    <= ($scopeSummary['telemetry']['prepared_branches']['limit'] ?? 0),
            'Native Phase 5 scope fixture lost cleanup or branch bounds.',
        );
        $all[] = $scopeSummary;
    }

    foreach ($inactive as $count => $samples) {
        foreach ($samples as $repetition => $summary) {
            $validatePeakExecution($summary, 1 + min(60, 2 * 30));
            nativePhaseFiveAssert(
                ($summary['fixture'] ?? null) === 'inactive'
                    && ($summary['inactive_scope_count'] ?? null) === $count
                    && ($summary['repetition'] ?? null) === $repetition
                    && ($summary['telemetry']['topology']['scope_workers'] ?? null) === 0
                    && ($summary['telemetry']['prepared_branches']['enters_by_kind']['scope'] ?? null) === 0
                    && ($summary['telemetry']['scheduler']['observed_test_body_lanes'] ?? null) === 30,
                'Native Phase 5 inactive scope sample is invalid.',
            );
        }
    }

    nativePhaseFiveAssert(
        count(array_unique(array_column($inactiveSummaries, 'semantic_hash'))) === 1
            && ($inert['fixture'] ?? null) === 'inert'
            && ($inert['repetition'] ?? null) === 0
            && ($inert['telemetry']['topology']['scope_workers'] ?? null) === 0
            && ($inert['telemetry']['prepared_branches']['enters_by_kind']['scope'] ?? null) === 0
            && ($stateful['fixture'] ?? null) === 'stateful'
            && ($stateful['repetition'] ?? null) === 0
            && ($stateful['telemetry']['topology']['scope_workers'] ?? null) === 3
            && ($stateful['telemetry']['prepared_branches']['enters_by_kind']['scope'] ?? null) === 3
            && ($stateful['isolation']['parent_heap_unchanged'] ?? null) === true
            && ($stateful['isolation']['stateful_scope_checked'] ?? null) === true
            && ($stateful['stateful_c1_depth']['processes'] ?? null) === 1
            && ($stateful['stateful_c1_depth']['scope_ir_depth'] ?? null) === 2
            && ($stateful['stateful_c1_depth']['process_descendant_depth'] ?? null) === 3
            && ($stateful['stateful_c1_depth']['test_count'] ?? null) === 60
            && ($stateful['stateful_c1_depth']['terminal_result_count'] ?? null) === 60
            && ($stateful['stateful_c1_depth']['semantic_hash'] ?? null)
                === ($stateful['semantic_hash'] ?? null)
            && ($stateful['stateful_c1_depth']['forks'] ?? null) === 63
            && ($stateful['stateful_c1_depth']['scope_workers'] ?? null) === 3
            && ($stateful['stateful_c1_depth']['executor_workers'] ?? null) === 60
            && ($stateful['stateful_c1_depth']['scope_host_peak'] ?? null) === 3
            && ($stateful['stateful_c1_depth']['executor_peak'] ?? null) === 1
            && ($stateful['stateful_c1_depth']['active_process_peak'] ?? null) === 4
            && ($stateful['stateful_c1_depth']['prepared_branch_peak'] ?? null) === 4
            && ($stateful['stateful_c1_depth']['parent_heap_unchanged'] ?? null) === true
            && ($stateful['stateful_c1_depth']['orphan_pids'] ?? null) === []
            && ($stateful['stateful_c1_depth']['artifact_residue_count'] ?? null) === 0,
        'Native Phase 5 inert/stateful scope topology gate failed.',
    );

    $inactivePairs = [];
    $inactiveMemoryChanges = [];
    $inactivePidChanges = [];

    for ($repetition = 1; $repetition <= 3; $repetition++) {
        $pair = [];

        foreach ([10, 1_000] as $count) {
            $summary = $inactive[$count][$repetition];
            $execution = $summary['telemetry']['process_monitor']['phases']['execution'];
            $planMemory = $summary['plan']['memory_footprint_delta_bytes'] ?? null;
            $planAllocator = $summary['plan']['allocator_footprint_delta_bytes'] ?? null;
            nativePhaseFiveAssert(
                is_int($planMemory)
                    && $planMemory >= 0
                    && is_int($planAllocator)
                    && $planAllocator >= 0,
                'Inactive scope plan memory footprint is invalid.',
            );
            $pair[$count] = [
                'raw_peak_population_rss_bytes' => $execution['peak_live_pid_aggregate_peak_rss_bytes'],
                'raw_peak_population_pss_bytes' => $execution['peak_live_pid_aggregate_peak_pss_bytes'],
                'plan_php_used_memory_delta_bytes' => $planMemory,
                'plan_php_allocator_delta_bytes' => $planAllocator,
                'plan_metadata_json_bytes' => $summary['plan']['metadata_bytes'],
                'peak_live_pids' => $execution['peak_live_pids'],
                'peak_live_pid_sample_count' => $execution['peak_live_pid_sample_count'],
            ];
        }

        $memoryChange = abs(
            $pair[1_000]['raw_peak_population_pss_bytes']
                - $pair[10]['raw_peak_population_pss_bytes'],
        ) / $pair[10]['raw_peak_population_pss_bytes'];
        $pidChange = abs(
            $pair[1_000]['peak_live_pids'] - $pair[10]['peak_live_pids'],
        ) / $pair[10]['peak_live_pids'];
        $inactiveMemoryChanges[] = $memoryChange;
        $inactivePidChanges[] = $pidChange;
        $inactivePairs[] = [
            'repetition' => $repetition,
            'scopes' => $pair,
            'raw_pss_change_ratio' => round($memoryChange, 6),
            'pid_change_ratio' => round($pidChange, 6),
        ];
    }

    $inactiveMemoryMedianChange = nativePhaseFiveMedian($inactiveMemoryChanges);
    $inactiveMemoryMaxChange = max($inactiveMemoryChanges);
    $inactivePidMaxChange = max($inactivePidChanges);
    nativePhaseFiveAssert(
        $inactiveMemoryMedianChange <= 0.10
            && $inactiveMemoryMaxChange <= 0.15
            && $inactivePidMaxChange <= 0.10,
        sprintf(
            'Inactive raw PSS delta exceeded median/max bounds (median %.4f; max %.4f; pids %.4f).',
            $inactiveMemoryMedianChange,
            $inactiveMemoryMaxChange,
            $inactivePidMaxChange,
        ),
    );

    $dslStress = $read('dsl-stress-c30');
    $validateIdentity($dslStress);
    $validateMonitor($dslStress);
    nativePhaseFiveAssert(
        ($dslStress['fixture'] ?? null) === 'dsl-stress'
            && ($dslStress['runner'] ?? null) === 'native-dsl'
            && ($dslStress['processes'] ?? null) === 30
            && ($dslStress['test_count'] ?? null) === 10_000
            && ($dslStress['terminal_result_count'] ?? null) === 10_000
            && ($dslStress['plan']['stress_shard_count'] ?? null) === 0
            && ($dslStress['plan']['scope_count'] ?? null) === 2
            && ($dslStress['telemetry']['memory']['memory_limit'] ?? null) === '256M'
            && ($dslStress['telemetry']['memory']['root_php_peak_bytes'] ?? PHP_INT_MAX)
                <= (int) floor(0.90 * 256 * 1024 * 1024)
            && ($dslStress['telemetry']['memory']['limit_bytes'] ?? null)
                === 256 * 1024 * 1024
            && ($dslStress['telemetry']['memory']['headroom_ratio'] ?? 0) >= 0.10
            && ($dslStress['telemetry']['memory']['minimum_headroom_ratio'] ?? null) === 0.10
            && ($dslStress['telemetry']['topology']['schema'] ?? null) === 1
            && ($dslStress['telemetry']['topology']['forks'] ?? null) === 10_000
            && ($dslStress['telemetry']['topology']['scope_workers'] ?? null) === 0
            && ($dslStress['telemetry']['topology']['executor_workers'] ?? null) === 10_000
            && ($dslStress['telemetry']['topology']['process_anchors'] ?? null) === 0
            && ($dslStress['telemetry']['topology']['peak_live_pids'] ?? 0) >= 2
            && ($dslStress['telemetry']['topology']['peak_live_pids'] ?? PHP_INT_MAX) <= 60
            && ($dslStress['telemetry']['topology']['peak_outstanding_tasks'] ?? PHP_INT_MAX)
                <= 60
            && ($dslStress['telemetry']['topology']['outstanding_task_limit'] ?? null) === 60
            && ($dslStress['telemetry']['scheduler']['observed_process_lanes'] ?? 0) >= 2
            && ($dslStress['telemetry']['scheduler']['observed_process_lanes'] ?? PHP_INT_MAX)
                <= 30
            && ($dslStress['isolation']['no_batch'] ?? null) === true
            && ($dslStress['isolation']['unique_executor_pid_count'] ?? null) === 10_000
            && ($dslStress['isolation']['orphan_pids'] ?? null) === []
            && ($dslStress['isolation']['artifact_residue_count'] ?? null) === 0,
        'Native Phase 5 unsharded 10,000-case DSL stress gate failed.',
    );
    $all[] = $dslStress;

    $faults = $read('faults');
    $validateIdentity($faults);
    $validateMonitor($faults);
    nativePhaseFiveAssert(
        ($faults['fixture'] ?? null) === 'repeated-faults'
            && ($faults['injections_per_kind'] ?? null) === 100
            && ($faults['submitted'] ?? null) === 300
            && ($faults['terminal_result_count'] ?? null) === 300
            && ($faults['missing_terminal_result_count'] ?? null) === 0
            && ($faults['orphan_pids'] ?? null) === []
            && ($faults['artifact_residue_count'] ?? null) === 0
            && ($faults['telemetry']['faults']['kinds'] ?? null) === [
                'kill' => 100,
                'timeout' => 100,
                'crash' => 100,
            ]
            && count($faults['replay_artifacts'] ?? []) === 3
            && array_all(
                $faults['replay_artifacts'] ?? [],
                static fn (array $artifact): bool => ($artifact['schema'] ?? null) === 1
                    && ($artifact['redaction_checked'] ?? null) === true
                    && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($artifact['sha256'] ?? '')) === 1,
            ),
        'Native Phase 5 repeated fault and replay gate failed.',
    );
    $all[] = $faults;
    $interruption = $read('interruption');
    $validateIdentity($interruption);
    $forkedExecutorCount = $interruption['forked_executor_count'] ?? null;
    $startedExecutorCount = $interruption['started_executor_count'] ?? null;
    $interruptionProcessLanes = $interruption['observed_process_lanes'] ?? null;
    nativePhaseFiveAssert(
        ($interruption['fixture'] ?? null) === 'interruption'
            && ($interruption['signal'] ?? null) === SIGINT
            && ($interruption['submitted'] ?? null) === 30
            && ($interruption['terminal_result_count'] ?? null) === 30
            && is_int($forkedExecutorCount)
            && $forkedExecutorCount >= 1
            && $forkedExecutorCount <= 16
            && is_int($startedExecutorCount)
            && $startedExecutorCount >= 1
            && $startedExecutorCount <= 8
            && $startedExecutorCount <= $forkedExecutorCount
            && is_int($interruptionProcessLanes)
            && $interruptionProcessLanes >= 1
            && $interruptionProcessLanes <= 8
            && $interruptionProcessLanes <= $startedExecutorCount
            && ($interruption['orphan_pids'] ?? null) === []
            && ($interruption['artifact_residue_count'] ?? null) === 0
            && ($interruption['replay']['schema'] ?? null) === 1
            && ($interruption['replay']['redaction_checked'] ?? null) === true,
        'Native Phase 5 signal interruption gate failed.',
    );
    $all[] = $interruption;
    $nestedHostCrash = $read('nested-host-crash');
    $validateIdentity($nestedHostCrash);
    nativePhaseFiveAssert(
        ($nestedHostCrash['fixture'] ?? null) === 'nested-host-crash'
            && ($nestedHostCrash['terminal_result_count'] ?? null) === 1
            && ($nestedHostCrash['root_result']['status'] ?? null) === 'failed'
            && ($nestedHostCrash['root_result']['failure_kind'] ?? null)
                === 'signal_termination'
            && ($nestedHostCrash['root_result']['signal'] ?? null) === SIGKILL
            && ($nestedHostCrash['processes']['executor_pid'] ?? null)
                === ($nestedHostCrash['processes']['executor_pgid'] ?? null)
            && ($nestedHostCrash['observed_before_harness_cleanup'] ?? null) === [
                'executor_alive' => false,
                'descendant_alive' => false,
                'executor_escaped' => false,
                'descendant_escaped' => false,
            ]
            && ($nestedHostCrash['harness_cleanup']['orphan_pids'] ?? null) === []
            && ($nestedHostCrash['harness_cleanup']['artifact_residue_count'] ?? null) === 0,
        'Native Phase 5 nested scope-host crash leaked a registered process group.',
    );
    $all[] = $nestedHostCrash;
    $cancellation = $read('explicit-cancellation');
    $validateIdentity($cancellation);
    $cancellationCases = $cancellation['cases'] ?? null;
    nativePhaseFiveAssert(
        ($cancellation['fixture'] ?? null) === 'explicit-cancellation'
            && ($cancellation['terminal_outcome_count'] ?? null) === 2
            && is_array($cancellationCases)
            && array_is_list($cancellationCases)
            && count($cancellationCases) === 2
            && ($cancellationCases[0]['kind'] ?? null) === 'explicit'
            && ($cancellationCases[0]['terminal_outcome_count'] ?? null) === 1
            && ($cancellationCases[0]['terminal_outcome']['kind'] ?? null)
                === 'explicit_cancellation'
            && ($cancellationCases[1]['kind'] ?? null) === 'injected-failure'
            && ($cancellationCases[1]['terminal_outcome_count'] ?? null) === 1
            && ($cancellationCases[1]['terminal_outcome']['kind'] ?? null)
                === 'native_cancellation_failure'
            && ($cancellationCases[1]['injected_failure_count'] ?? null) === 1
            && ($cancellationCases[1]['cancellation_error_observed'] ?? null) === true
            && array_all(
                $cancellationCases,
                static fn (array $case): bool => ($case['ok'] ?? null) === true
                    && ($case['handler_call_count'] ?? null) === 1
                    && ($case['observed_before_harness_cleanup'] ?? null) === [
                        'executor_alive' => false,
                        'descendant_alive' => false,
                        'executor_escaped' => false,
                        'descendant_escaped' => false,
                    ]
                    && ($case['harness_cleanup']['orphan_pids'] ?? null) === []
                    && ($case['harness_cleanup']['artifact_residue_count'] ?? null) === 0,
            )
            && ($cancellation['harness_cleanup']['artifact_residue_count'] ?? null) === 0,
        'Native Phase 5 cancellation was not terminal, observable, and leak-free.',
    );
    $all[] = $cancellation;

    $revisions = array_unique(array_column($all, 'evidence_revision'));
    $platforms = array_unique(array_map(
        static fn (array $summary): string => json_encode(
            $summary['runtime_platform'],
            JSON_THROW_ON_ERROR,
        ),
        $all,
    ));
    $libraries = array_unique(array_column(array_column($all, 'drover_identity'), 'library_sha256'));
    nativePhaseFiveAssert(
        count($revisions) === 1
            && count($platforms) === 1
            && count($libraries) === 1,
        'Native Phase 5 evidence mixed revisions, platforms, or native libraries.',
    );
    $expectedRevision = getenv('DROVE_EVIDENCE_REVISION');

    if (is_string($expectedRevision) && $expectedRevision !== '') {
        nativePhaseFiveAssert(
            $revisions === [$expectedRevision],
            'Native Phase 5 evidence does not match the expected revision.',
        );
    }

    $artifactHashes = [];

    foreach (glob($directory.'/*.json') ?: [] as $path) {
        if (realpath($path) === realpath($outputPath)) {
            continue;
        }

        $hash = hash_file('sha256', $path);

        if (is_string($hash)) {
            $artifactHashes[basename($path)] = $hash;
        }
    }

    ksort($artifactHashes, SORT_STRING);
    $comparison = [
        'schema' => 1,
        'ok' => true,
        'revision' => array_values($revisions)[0],
        'platform' => $all[0]['runtime_platform'],
        'drover_identity' => $all[0]['drover_identity'],
        'fixture' => 'experiments/native-phase-5',
        'measurement_contract' => [
            'kernel_executor_population' => 'executor-pids-including-armed-excludes-root-and-grandchildren',
            'active_process_lanes' => 'started-finished-intervals-excludes-armed',
            'scope_host_peak' => 'scope-worker-started-finished-intervals',
            'procfs_process_population' => 'root-plus-all-descendants',
            'phase_timings' => 'instrumented-harness',
            'aggregate_memory' => 'linux-procfs-rss-and-smaps-rollup-pss',
            'stable_process_snapshot' => 'shared-phase-lock-and-identical-pid-set-before-after',
            'inactive_scope_memory_gate' => 'three-pair-median-raw-peak-population-pss',
            'plan_memory' => 'reported-php-used-and-allocator-bytes-never-subtracted-from-procfs',
            'per_process_memory' => 'php-child-report-plus-procfs-sample',
            'io' => 'linux-procfs',
            'matched_reference' => 'Drove PcntlScheduler with identical one-fork-per-test protocol',
            'external_frontend_reference' => 'benchmarks/results/2026-07-29-d3dec629.md',
        ],
        'gates' => [
            'semantic_parity_c1_c30' => true,
            'deliberate_saturation_c1_c30' => true,
            'one_executor_fork_per_test' => true,
            'no_batch' => true,
            'executor_window_at_most_2c' => true,
            'stateful_scope_host_peak' => $stateful['telemetry']['scheduler']['scope_host_peak'],
            'stateful_c1_nested_scope_depth' => $stateful['stateful_c1_depth'],
            'inactive_scope_artifacts' => $inactiveArtifactNames,
            'inactive_scope_pairs' => $inactivePairs,
            'inactive_scope_raw_pss_median_change_ratio' => round(
                $inactiveMemoryMedianChange,
                6,
            ),
            'inactive_scope_raw_pss_max_change_ratio' => round(
                $inactiveMemoryMaxChange,
                6,
            ),
            'inactive_scope_pid_max_change_ratio' => round($inactivePidMaxChange, 6),
            'aggregate_memory_c1_bound' => true,
            'cheap_c1_drover_median_ms' => round($cheapDroverMedian, 3),
            'cheap_c1_reference_median_ms' => round($cheapReferenceMedian, 3),
            'cheap_c1_ratio' => round($cheapDroverMedian / $cheapReferenceMedian, 6),
            'setup_drove_median_ms' => round($setupDroverMedian, 3),
            'setup_reference_median_ms' => round($setupReferenceMedian, 3),
            'setup_speedup' => round($setupReferenceMedian / $setupDroverMedian, 6),
            'independent_median_ms' => $independentMedians,
            'independent_c1_c30_speedup' => round($speedup, 6),
            'dsl_stress_10000_256m_unsharded' => true,
            'fault_injections' => 300,
            'signal_interruption_checked' => true,
            'nested_scope_host_sigkill_cleanup_checked' => true,
            'explicit_cancellation_cleanup_checked' => true,
            'injected_cancellation_failure_observed' => true,
            'replay_schema_and_redaction_checked' => true,
            'no_orphans_or_state_artifacts' => true,
        ],
        'artifact_sha256' => $artifactHashes,
    ];
    nativePhaseFiveWriteJson($outputPath, $comparison);
    echo json_encode(
        $comparison,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
