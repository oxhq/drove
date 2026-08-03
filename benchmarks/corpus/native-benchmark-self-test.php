<?php

declare(strict_types=1);

require __DIR__.'/native-benchmark-prepare.php';

$temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drove-native-benchmark-'.bin2hex(random_bytes(8));

try {
    nativeBenchmarkRequire(mkdir($temporary, 0700, true), 'Cannot create native benchmark self-test directory.');
    $cleanupParent = $temporary.DIRECTORY_SEPARATOR.'cleanup-parent';
    $cleanupFixture = $cleanupParent.DIRECTORY_SEPARATOR.'cleanup-fixture';
    $cleanupSibling = $cleanupParent.DIRECTORY_SEPARATOR.'sibling.txt';
    nativeBenchmarkRequire(
        mkdir($cleanupFixture.DIRECTORY_SEPARATOR.'nested', 0700, true)
            && file_put_contents($cleanupFixture.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'proof.txt', 'cleanup') === 7,
        'Could not create the native benchmark cleanup fixture.',
    );
    nativeBenchmarkRequire(
        file_put_contents($cleanupSibling, 'sibling') === 7,
        'Could not create the native benchmark cleanup sibling.',
    );
    nativeBenchmarkRunnerRemoveTree($cleanupFixture);
    nativeBenchmarkRequire(
        ! file_exists($cleanupFixture) && file_get_contents($cleanupSibling) === 'sibling',
        'Native benchmark cleanup escaped its exact target or left fixture residue.',
    );

    if (PHP_OS_FAMILY === 'Windows') {
        $lockedFixture = $cleanupParent.DIRECTORY_SEPARATOR.'locked-fixture';
        $lockedFile = $lockedFixture.DIRECTORY_SEPARATOR.'pack.idx';
        $lockReady = $cleanupParent.DIRECTORY_SEPARATOR.'lock-ready';
        nativeBenchmarkRequire(
            mkdir($lockedFixture, 0700) && file_put_contents($lockedFile, 'locked') === 6,
            'Could not create the Windows cleanup lock fixture.',
        );
        $lockScript = sprintf(
            '$stream = [IO.File]::Open(\'%s\', \'Open\', \'Read\', \'None\'); [IO.File]::WriteAllText(\'%s\', \'ready\'); Start-Sleep -Milliseconds 750; $stream.Dispose()',
            str_replace("'", "''", $lockedFile),
            str_replace("'", "''", $lockReady),
        );
        $lockProcess = proc_open(
            [
                'powershell.exe',
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                $lockScript,
            ],
            [
                0 => ['file', nativeBenchmarkNullDevice(), 'r'],
                1 => ['file', nativeBenchmarkNullDevice(), 'w'],
                2 => ['file', nativeBenchmarkNullDevice(), 'w'],
            ],
            $lockPipes,
            options: ['bypass_shell' => true],
        );

        if (! is_resource($lockProcess)) {
            throw new RuntimeException('Could not start the Windows cleanup lock fixture.');
        }

        $lockDeadline = hrtime(true) + 5_000_000_000;

        while (! file_exists($lockReady) && hrtime(true) < $lockDeadline) {
            clearstatcache(true, $lockReady);
            usleep(10_000);
        }

        nativeBenchmarkRequire(file_exists($lockReady), 'The Windows cleanup lock fixture did not become ready.');
        $cleanupStarted = hrtime(true);
        nativeBenchmarkRunnerRemoveTree($lockedFixture);
        $lockExit = proc_close($lockProcess);
        nativeBenchmarkRequire(
            $lockExit === 0
                && ! file_exists($lockedFixture)
                && (hrtime(true) - $cleanupStarted) >= 500_000_000,
            'Native benchmark cleanup did not retry a transient Windows file lock.',
        );
    }

    $blockedWorkspace = $temporary.DIRECTORY_SEPARATOR.'blocked-workspace';
    $stagedInputs = $temporary.DIRECTORY_SEPARATOR.'staged-inputs';
    $publishedInputs = $temporary.DIRECTORY_SEPARATOR.'published-inputs';
    $blockedConfig = $temporary.DIRECTORY_SEPARATOR.'blocked-config.json';
    nativeBenchmarkRequire(
        file_put_contents($blockedWorkspace, 'blocked') === 7 && mkdir($stagedInputs, 0700),
        'Could not create blocked publication fixture.',
    );
    nativeBenchmarkSelfTestRejects(
        static function () use ($blockedWorkspace, $stagedInputs, $publishedInputs, $blockedConfig): void {
            nativeBenchmarkPreparePublishConfig(
                $blockedWorkspace,
                $stagedInputs,
                $publishedInputs,
                $blockedConfig,
                ['published' => true],
            );
        },
        'not a directory',
    );
    nativeBenchmarkRequire(
        ! file_exists($blockedConfig) && is_dir($stagedInputs) && ! file_exists($publishedInputs),
        'Native benchmark inputs or config were published before cleanup succeeded.',
    );
    nativeBenchmarkRequire(unlink($blockedWorkspace) && mkdir($blockedWorkspace, 0700), 'Could not reset cleanup publication fixture.');
    nativeBenchmarkPreparePublishConfig(
        $blockedWorkspace,
        $stagedInputs,
        $publishedInputs,
        $blockedConfig,
        ['published' => true],
    );
    nativeBenchmarkRequire(
        ! file_exists($blockedWorkspace)
            && ! file_exists($stagedInputs)
            && is_dir($publishedInputs)
            && nativeBenchmarkReadJson($blockedConfig) === ['published' => true],
        'Native benchmark inputs and config were not published after cleanup succeeded.',
    );

    $rollbackWorkspace = $temporary.DIRECTORY_SEPARATOR.'rollback-workspace';
    $rollbackStagedInputs = $temporary.DIRECTORY_SEPARATOR.'rollback-staged-inputs';
    $rollbackPublishedInputs = $temporary.DIRECTORY_SEPARATOR.'rollback-published-inputs';
    $blockedOutput = $temporary.DIRECTORY_SEPARATOR.'rollback-config.json';
    nativeBenchmarkRequire(
        mkdir($rollbackWorkspace, 0700)
            && mkdir($rollbackStagedInputs, 0700),
        'Could not create publication rollback fixture.',
    );
    nativeBenchmarkSelfTestRejects(
        static function () use ($rollbackWorkspace, $rollbackStagedInputs, $rollbackPublishedInputs, $blockedOutput): void {
            nativeBenchmarkPreparePublishConfig(
                $rollbackWorkspace,
                $rollbackStagedInputs,
                $rollbackPublishedInputs,
                $blockedOutput,
                ['invalid' => NAN],
            );
        },
        'Inf and NaN cannot be JSON encoded',
    );
    nativeBenchmarkRequire(
        ! file_exists($rollbackWorkspace)
            && ! file_exists($rollbackStagedInputs)
            && ! file_exists($rollbackPublishedInputs)
            && ! file_exists($blockedOutput),
        'Native benchmark input publication was not rolled back after config failure.',
    );

    $primaryFailure = new RuntimeException('primary failure');
    $blockedCleanup = $temporary.DIRECTORY_SEPARATOR.'blocked-cleanup';
    nativeBenchmarkRequire(file_put_contents($blockedCleanup, 'blocked') === 7, 'Could not create combined cleanup failure fixture.');

    try {
        nativeBenchmarkRunnerRethrowAfterCleanup($primaryFailure, $blockedCleanup);
    } catch (RuntimeException $combinedFailure) {
        nativeBenchmarkRequire(
            str_contains($combinedFailure->getMessage(), 'primary failure Cleanup also failed:')
                && $combinedFailure->getPrevious() === $primaryFailure,
            'Native benchmark cleanup obscured its primary failure.',
        );
    }

    nativeBenchmarkRequire(unlink($blockedCleanup), 'Could not remove combined cleanup failure fixture.');

    $newOutput = nativeBenchmarkOutputPath('new-output/config.json', $temporary);
    nativeBenchmarkRequire(
        $newOutput === $temporary.DIRECTORY_SEPARATOR.'new-output'.DIRECTORY_SEPARATOR.'config.json'
            && is_dir(dirname($newOutput))
            && ! file_exists($newOutput),
        'A new native benchmark output path was not prepared safely.',
    );
    $atomicOutput = $temporary.DIRECTORY_SEPARATOR.'atomic.json';
    nativeBenchmarkWriteJson($atomicOutput, ['generation' => 1]);
    nativeBenchmarkWriteJson($atomicOutput, ['generation' => 2]);
    $atomicResidue = glob($temporary.DIRECTORY_SEPARATOR.'.atomic.json.tmp-*');
    nativeBenchmarkRequire(
        nativeBenchmarkReadJson($atomicOutput) === ['generation' => 2]
            && is_array($atomicResidue)
            && $atomicResidue === [],
        'Atomic JSON replacement left stale content or a temporary artifact.',
    );
    $lockCheckouts = [];

    foreach (['invoiceshelf', 'filament'] as $corpus) {
        $lockCheckouts[$corpus] = $temporary.DIRECTORY_SEPARATOR.$corpus;
        mkdir($lockCheckouts[$corpus], 0700);
        file_put_contents($lockCheckouts[$corpus].DIRECTORY_SEPARATOR.'composer.lock', $corpus.' upstream lock', LOCK_EX);
    }
    $definitions = nativeBenchmarkCorpusDefinitions(dirname(__DIR__, 2), $lockCheckouts);
    nativeBenchmarkRequire(
        array_column($definitions, 'id') === DROVE_NATIVE_BENCHMARK_CORPORA
            && array_column($definitions[0]['cohorts'], 'expected')[0]['cases'] === 686
            && array_column($definitions[1]['cohorts'], 'expected')[0]['cases'] === 202
            && array_column($definitions[2]['cohorts'], 'expected')[0]['cases'] === 36
            && array_column($definitions[2]['cohorts'], 'expected')[0]['semantic_hash'] === 'fcdc5a3b8ecec87caf1cb60149e7c551d69f792dc67bfca593d884a2668009f3'
            && array_column($definitions[2]['cohorts'], 'expected')[1]['cases'] === 288
            && array_column($definitions[3]['cohorts'], 'expected')[0]['cases'] === 677
            && array_column($definitions[3]['cohorts'], 'expected')[1]['cases'] === 28,
        'The real native benchmark corpus definitions drifted.',
    );
    $dockerCommand = nativeBenchmarkDockerCommand(
        'sha256:'.str_repeat('a', 64),
        ['cpu_cores' => 30.0, 'memory_bytes' => 17_179_869_184],
        'pest',
        'native',
    );
    nativeBenchmarkRequire(
        in_array('--network=none', $dockerCommand, true)
            && in_array('--name={container_name}', $dockerCommand, true)
            && in_array('--label=org.oxhq.drove.native-benchmark.job-timeout-seconds={job_timeout_seconds}', $dockerCommand, true)
            && in_array('--cpus=30', $dockerCommand, true)
            && in_array('--memory=17179869184', $dockerCommand, true)
            && in_array('--mount=type=bind,source={artifact_dir},target=/artifacts', $dockerCommand, true)
            && array_slice($dockerCommand, -4) === ['pest', '{cohort}', 'native', '{processes}'],
        'The native benchmark fresh-container command drifted.',
    );
    nativeBenchmarkRequireNaturalCorpusProof(['capacity_proof' => ['enabled' => false]], 'self-test');
    nativeBenchmarkSelfTestRejects(
        static function (): void {
            nativeBenchmarkRequireNaturalCorpusProof(['capacity_proof' => ['enabled' => true]], 'self-test');
        },
        'natural corpus',
    );
    nativeBenchmarkSelfTestRejects(
        static function (): void {
            nativeBenchmarkRequireNaturalCorpusProof([], 'self-test');
        },
        'natural corpus',
    );
    $filamentCapacity = [
        'processes' => 8,
        'capacity_proof' => ['enabled' => true],
        'concurrency_barrier' => [
            'requested' => 8,
            'observed' => 8,
            'cases' => 8,
            'one_fork_per_case' => true,
            'scheduler' => [
                'schema' => 1,
                'forks' => 8,
                'scope_workers' => 0,
                'executor_workers' => 8,
                'process_anchors' => 0,
                'peak_live_pids' => 8,
                'peak_outstanding_tasks' => 8,
                'outstanding_task_limit' => 16,
            ],
        ],
    ];
    nativeBenchmarkRequire(nativeBenchmarkFilamentCapacityProof($filamentCapacity), 'Exact Filament capacity proof was rejected.');
    $filamentCapacity['concurrency_barrier']['observed'] = 7;
    nativeBenchmarkRequire(! nativeBenchmarkFilamentCapacityProof($filamentCapacity), 'Tampered Filament capacity proof was accepted.');
    $watchdogStdout = fopen($temporary.DIRECTORY_SEPARATOR.'watchdog.stdout', 'wb');
    $watchdogStderr = fopen($temporary.DIRECTORY_SEPARATOR.'watchdog.stderr', 'wb');

    if (! is_resource($watchdogStdout) || ! is_resource($watchdogStderr)) {
        throw new RuntimeException('Cannot open watchdog self-test logs.');
    }

    $watchdogProcess = proc_open(
        [PHP_BINARY, '-r', 'sleep(30);'],
        [0 => ['file', nativeBenchmarkNullDevice(), 'r'], 1 => $watchdogStdout, 2 => $watchdogStderr],
        $watchdogPipes,
        options: ['bypass_shell' => true],
    );

    if (! is_resource($watchdogProcess)) {
        throw new RuntimeException('Cannot start watchdog self-test process.');
    }

    $watchdogCleanupCalled = false;
    $watchdogStarted = hrtime(true);
    nativeBenchmarkSelfTestRejects(
        static function () use ($watchdogProcess, &$watchdogCleanupCalled): void {
            nativeBenchmarkWaitForProcess(
                $watchdogProcess,
                1,
                'Self-test job',
                static function () use ($watchdogProcess, &$watchdogCleanupCalled): bool {
                    $watchdogCleanupCalled = true;

                    return proc_terminate($watchdogProcess);
                },
            );
        },
        'watchdog timeout',
    );
    fclose($watchdogStdout);
    fclose($watchdogStderr);
    nativeBenchmarkRequire(
        $watchdogCleanupCalled && (hrtime(true) - $watchdogStarted) < 5_000_000_000,
        'Watchdog self-test did not invoke targeted cleanup promptly.',
    );
    $junit = $temporary.DIRECTORY_SEPARATOR.'livewire.xml';
    file_put_contents($junit, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites><testsuite><testcase file="/corpus/src/Foo/UnitTest.php" name="checks value with data set &quot;blue&quot;" assertions="2"><system-out>ok</system-out></testcase></testsuite></testsuites>
XML, LOCK_EX);
    nativeBenchmarkRequire(nativeBenchmarkLivewireRows($junit) === [[
        'id' => 'test:src/Foo/UnitTest.php::checks%20value::dataset:name:blue',
        'status' => 'passed',
        'assertions' => 2,
        'stdout' => 'ok',
        'stderr' => '',
    ]], 'The timed Livewire JUnit normalizer drifted.');
    $contract = nativeBenchmarkCorpusContract();
    $evidencePath = $temporary.DIRECTORY_SEPARATOR.'n1-n5.json';
    $evidenceArtifacts = [];

    foreach (DROVE_NATIVE_BENCHMARK_CORPORA as $corpus) {
        $artifactPath = $temporary.DIRECTORY_SEPARATOR.$corpus.'-gate.json';
        file_put_contents($artifactPath, $corpus.' gate', LOCK_EX);
        $evidenceArtifacts[] = ['corpus' => $corpus, 'path' => $artifactPath, 'sha256' => nativeBenchmarkHash($artifactPath)];
    }
    nativeBenchmarkWriteJson($evidencePath, [
        'schema_version' => 1,
        'verification' => 'passed',
        'drove_revision' => str_repeat('f', 40),
        'phases' => ['N1', 'N2', 'N3', 'N4', 'N5'],
        'corpora' => DROVE_NATIVE_BENCHMARK_CORPORA,
        'filament_final' => true,
        'outcomes' => $contract,
        'artifacts' => $evidenceArtifacts,
    ]);
    $config = [
        'schema_version' => 1,
        'repetitions' => 5,
        'job_timeout_seconds' => 900,
        'n1_n5_evidence' => $evidencePath,
        'quotas' => ['cpu_cores' => 8.0, 'memory_bytes' => 4_294_967_296],
        'corpora' => [],
    ];

    foreach ($contract as $corpusDefinition) {
        $corpus = $corpusDefinition['id'];
        $baselineLock = $temporary.DIRECTORY_SEPARATOR.$corpus.'-baseline.lock';
        $nativeLock = $temporary.DIRECTORY_SEPARATOR.$corpus.'-native.lock';
        file_put_contents($baselineLock, $corpus.' baseline', LOCK_EX);
        file_put_contents($nativeLock, $corpus.' native', LOCK_EX);
        $config['corpora'][] = [
            'id' => $corpus,
            'source_revision' => $corpusDefinition['source_revision'],
            'baseline' => [
                'lock' => $baselineLock,
                'command' => nativeBenchmarkDockerCommand('sha256:'.hash('sha256', $corpus.' baseline image'), $config['quotas'], $corpus, 'baseline'),
            ],
            'native' => [
                'lock' => $nativeLock,
                'command' => nativeBenchmarkDockerCommand('sha256:'.hash('sha256', $corpus.' native image'), $config['quotas'], $corpus, 'native'),
            ],
            'cohorts' => $corpusDefinition['cohorts'],
        ];
    }

    $configPath = $temporary.DIRECTORY_SEPARATOR.'config.json';
    nativeBenchmarkWriteJson($configPath, $config);
    $plan = nativeBenchmarkBuildPlan($configPath, 7_331);
    $planPath = $temporary.DIRECTORY_SEPARATOR.'plan.json';
    nativeBenchmarkWriteJson($planPath, $plan);
    $plan = nativeBenchmarkReadJson($planPath);
    nativeBenchmarkValidatePlan($plan);
    nativeBenchmarkVerifyPinnedInputs($plan);
    nativeBenchmarkRequire(
        count($plan['schedule']) === 160
            && ($plan['job_timeout_seconds'] ?? null) === 900
            && array_all($plan['schedule'], static fn (array $job): bool => ($job['job_timeout_seconds'] ?? null) === 900),
        'Self-test plan did not create the exact 32-cell, five-repetition watchdog-bound matrix.',
    );
    $firstFilament = array_search('filament', array_column($plan['schedule'], 'corpus'), true);
    nativeBenchmarkRequire(
        $firstFilament === 115
            && array_values(array_unique(array_column(array_slice($plan['schedule'], $firstFilament), 'corpus'))) === ['filament'],
        'Self-test plan did not leave Filament as the final corpus rung.',
    );
    $revisionMarker = $temporary.DIRECTORY_SEPARATOR.'drove-revision';
    nativeBenchmarkRequire(file_put_contents($revisionMarker, $plan['drove_revision'].PHP_EOL) !== false, 'Cannot create the self-test Drove revision marker.');
    nativeBenchmarkRequire(
        nativeBenchmarkVerifyImageRevision($revisionMarker, $plan['drove_revision']) === $plan['drove_revision'],
        'Self-test could not bind the image revision marker.',
    );
    nativeBenchmarkRequire(file_put_contents($revisionMarker, 'not-a-revision') !== false, 'Cannot corrupt the self-test Drove revision marker.');
    nativeBenchmarkSelfTestRejects(
        static fn (): string => nativeBenchmarkVerifyImageRevision($revisionMarker, $plan['drove_revision']),
        'not a full lowercase Git SHA',
    );
    nativeBenchmarkRequire(file_put_contents($revisionMarker, str_repeat('e', 40)) !== false, 'Cannot replace the self-test Drove revision marker.');
    nativeBenchmarkSelfTestRejects(
        static fn (): string => nativeBenchmarkVerifyImageRevision($revisionMarker, $plan['drove_revision']),
        'different Drove revision',
    );
    unlink($revisionMarker);
    nativeBenchmarkSelfTestRejects(
        static fn (): string => nativeBenchmarkVerifyImageRevision($revisionMarker, $plan['drove_revision']),
        'has no Drove revision marker',
    );

    foreach ($contract as $corpusDefinition) {
        foreach ($corpusDefinition['cohorts'] as $cohortDefinition) {
            $jobs = array_values(array_filter(
                $plan['schedule'],
                static fn (array $job): bool => $job['corpus'] === $corpusDefinition['id']
                    && $job['cohort'] === $cohortDefinition['id'],
            ));
            $baseline = array_values(array_filter($jobs, static fn (array $job): bool => $job['runner'] === 'baseline'));
            $native = array_values(array_filter($jobs, static fn (array $job): bool => $job['runner'] === 'native'));
            nativeBenchmarkRequire(
                count($baseline) === 5 && array_unique(array_column($baseline, 'requested_processes')) === [1],
                "{$corpusDefinition['id']}/{$cohortDefinition['id']} baseline matrix is invalid.",
            );
            $processMatrix = $cohortDefinition['mode'] === 'parallel' ? DROVE_NATIVE_BENCHMARK_PROCESSES : [1];

            foreach ($processMatrix as $processes) {
                nativeBenchmarkRequire(
                    count(array_filter($native, static fn (array $job): bool => $job['requested_processes'] === $processes)) === 5,
                    "{$corpusDefinition['id']}/{$cohortDefinition['id']} native C$processes matrix is incomplete.",
                );
            }

            nativeBenchmarkRequire(
                count($native) === count($processMatrix) * 5,
                "{$corpusDefinition['id']}/{$cohortDefinition['id']} native matrix contains an unexpected cell.",
            );
        }
    }

    $artifacts = $temporary.DIRECTORY_SEPARATOR.'artifacts';
    mkdir($artifacts, 0700);

    foreach ($plan['schedule'] as $job) {
        $executionJob = nativeBenchmarkExecutionJob($job, $artifacts);
        $baseline = $job['runner'] === 'baseline';
        $wall = $baseline
            ? 100 + $job['repetition']
            : round(1_000 / $job['requested_processes'], 3) + $job['repetition'];
        $runnableCases = $job['expected']['cases'] - ($job['expected']['statuses']['skipped'] ?? 0);
        $observedLanes = $baseline ? 1 : min($job['requested_processes'], $runnableCases);

        if ($job['corpus'] === 'pest' && $job['runner'] === 'native' && $job['requested_processes'] === 2 && $job['repetition'] === 1) {
            $observedLanes--;
        }

        $observation = [
            'schema_version' => 1,
            'job_id' => $job['job_id'],
            'corpus' => $job['corpus'],
            'cohort' => $job['cohort'],
            'cohort_mode' => $job['cohort_mode'],
            'runner' => $job['runner'],
            'requested_processes' => $job['requested_processes'],
            'repetition' => $job['repetition'],
            'source_revision' => $job['source_revision'],
            'drove_revision' => $job['drove_revision'],
            'lock_sha256' => $job['lock_sha256'],
            'image_id' => $executionJob['image_id'],
            'command_sha256' => $executionJob['command_sha256'],
            'isolation_id' => 'container-'.$job['job_id'],
            'quotas' => $job['quotas'],
            'outcome' => $job['expected'],
            'timing' => [
                'wall_ms' => $wall,
                'phases_ms' => $baseline
                    ? ['execution' => max(0, $wall - 10), 'verification' => 10]
                    : ['preparation' => 3, 'planning' => 2, 'execution' => max(0, $wall - 10), 'verification' => 5],
            ],
            'topology' => [
                'observed_lanes' => $observedLanes,
                'forks' => $baseline ? 0 : $runnableCases,
                'runnable_cases' => $baseline ? null : $runnableCases,
                'strategy' => $baseline ? 'upstream' : 'isolated-per-test',
                'fork_semantics' => $baseline ? 'runner-native' : 'one-fork-per-test',
            ],
            'memory' => [
                'sample_count' => 3,
                'aggregate_peak_rss_bytes' => 1_000_000 + $job['repetition'],
                'aggregate_peak_pss_bytes' => 900_000 + $job['repetition'],
                'cgroup_peak_before_bytes' => 500_000,
                'cgroup_peak_memory_bytes' => 2_000_000 + $job['repetition'],
                'cgroup_events_delta' => ['oom' => 0, 'oom_kill' => 0],
            ],
            'platform' => ['os' => 'Linux', 'architecture' => 'x86_64', 'php' => PHP_VERSION],
        ];
        nativeBenchmarkWriteJson($artifacts.DIRECTORY_SEPARATOR.$job['job_id'].'.json', $observation);
    }

    $report = nativeBenchmarkBuildReport($plan, $artifacts);
    nativeBenchmarkRequire(
        ($report['verification'] ?? null) === 'passed'
            && ($report['thresholds_applied'] ?? null) === false
            && ($report['c1_decision'] ?? null) === 'pending_human_review'
            && ($report['job_timeout_seconds'] ?? null) === 900
            && count($report['groups'] ?? []) === 32
            && count($report['comparisons'] ?? []) === 26
            && count($report['native_scaling'] ?? []) === 26
            && count($report['run_artifacts'] ?? []) === 160,
        'Self-test report did not preserve the complete controlled matrix.',
    );
    $pestBaseline = array_values(array_filter(
        $report['groups'],
        static fn (array $group): bool => $group['corpus'] === 'pest'
            && $group['runner'] === 'baseline'
            && $group['requested_processes'] === 1,
    ));
    nativeBenchmarkRequire(
        count($pestBaseline) === 1
            && $pestBaseline[0]['wall_ms'] === [
                'samples' => 5,
                'min' => 101.0,
                'median' => 103.0,
                'p95' => 105.0,
                'max' => 105.0,
                'median_absolute_deviation' => 1.0,
                'relative_median_absolute_deviation' => 0.009709,
            ],
        'Self-test report statistics are not median/p95/MAD exact.',
    );
    $pestC1Comparison = array_values(array_filter(
        $report['comparisons'],
        static fn (array $comparison): bool => $comparison['corpus'] === 'pest'
            && $comparison['native_processes'] === 1,
    ));
    nativeBenchmarkRequire(
        count($pestC1Comparison) === 1
            && array_keys($pestC1Comparison[0]['native_to_baseline_median_ratio']) === [
                'end_to_end_wall_ms', 'execution_phase_ms', 'runner_path_to_baseline_process_ms', 'aggregate_peak_rss_bytes',
                'aggregate_peak_pss_bytes', 'cgroup_peak_memory_bytes',
            ]
            && ($report['comparison_semantics']['decision_basis'] ?? null)
                === 'Use within-native C1-to-C30 scaling as the primary C1/default evidence; upstream ratios are contextual only.',
        'Self-test report does not separate execution from end-to-end wall ratios.',
    );
    $pestC1Scaling = array_values(array_filter(
        $report['native_scaling'],
        static fn (array $scaling): bool => $scaling['corpus'] === 'pest'
            && $scaling['native_processes'] === 1,
    ));
    nativeBenchmarkRequire(
        count($pestC1Scaling) === 1
            && array_all(
                $pestC1Scaling[0]['median_ratio_to_native_c1'],
                static fn (float $ratio): bool => $ratio === 1.0,
            )
            && array_all(
                $pestC1Scaling[0]['native_c1_to_current_median_speedup'],
                static fn (float $ratio): bool => $ratio === 1.0,
            ),
        'Self-test report did not preserve the native C1 scaling identity.',
    );
    $pestC2 = array_values(array_filter(
        $report['groups'],
        static fn (array $group): bool => $group['corpus'] === 'pest'
            && $group['runner'] === 'native'
            && $group['requested_processes'] === 2,
    ));
    nativeBenchmarkRequire(
        count($pestC2) === 1
            && $pestC2[0]['observed_lanes']['min'] === 1.0
            && $pestC2[0]['observed_lanes']['median'] === 2.0
            && $pestC2[0]['observed_lanes']['max'] === 2.0,
        'Self-test report did not preserve measured under-saturation.',
    );
    nativeBenchmarkRequire(
        nativeBenchmarkStatistics([1, 2, 3, 4])['median'] === 2.5
            && nativeBenchmarkStatistics([1, 2, 3, 4])['p95'] === 4.0,
        'Self-test even-sample median or nearest-rank p95 is invalid.',
    );
    $incompletePlan = $plan;
    array_pop($incompletePlan['schedule']);
    nativeBenchmarkSelfTestRejects(
        static function () use ($incompletePlan): void {
            nativeBenchmarkValidatePlan($incompletePlan);
        },
        'exactly 32 cells',
    );
    $unboundPlan = $plan;
    $unboundPlan['drove_revision'] = null;
    nativeBenchmarkSelfTestRejects(
        static function () use ($unboundPlan): void {
            nativeBenchmarkValidatePlan($unboundPlan);
        },
        'plan is invalid',
    );
    $coherentTamper = $plan;
    $coherentTamper['corpora'][0]['cohorts'][0]['expected']['assertions']++;

    foreach ($coherentTamper['schedule'] as &$tamperedJob) {
        if ($tamperedJob['corpus'] === 'pest') {
            $tamperedJob['expected']['assertions']++;
        }
    }

    unset($tamperedJob);
    nativeBenchmarkSelfTestRejects(
        static function () use ($coherentTamper): void {
            nativeBenchmarkValidatePlan($coherentTamper);
        },
        'six-cohort contract',
    );

    $firstJob = array_values(array_filter(
        $plan['schedule'],
        static fn (array $job): bool => $job['runner'] === 'native',
    ))[0];
    $firstExecutionJob = nativeBenchmarkExecutionJob($firstJob, $artifacts);
    $invalid = nativeBenchmarkReadJson($artifacts.DIRECTORY_SEPARATOR.$firstJob['job_id'].'.json');
    $invalid['topology']['forks'] = 2;
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalid, $firstExecutionJob): void {
            nativeBenchmarkValidateObservation($invalid, $firstExecutionJob);
        },
        'batched tests',
    );
    $invalid = nativeBenchmarkReadJson($artifacts.DIRECTORY_SEPARATOR.$firstJob['job_id'].'.json');
    $invalid['memory']['sample_count'] = 0;
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalid, $firstExecutionJob): void {
            nativeBenchmarkValidateObservation($invalid, $firstExecutionJob);
        },
        'no memory samples',
    );
    $nativeJob = array_values(array_filter(
        $plan['schedule'],
        static fn (array $job): bool => $job['runner'] === 'native' && $job['requested_processes'] > 1,
    ))[0];
    $nativeExecutionJob = nativeBenchmarkExecutionJob($nativeJob, $artifacts);
    $invalid = nativeBenchmarkReadJson($artifacts.DIRECTORY_SEPARATOR.$nativeJob['job_id'].'.json');
    $invalid['topology']['observed_lanes'] = 0;
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalid, $nativeExecutionJob): void {
            nativeBenchmarkValidateObservation($invalid, $nativeExecutionJob);
        },
        'impossible observed lanes',
    );
    $invalid = nativeBenchmarkReadJson($artifacts.DIRECTORY_SEPARATOR.$nativeJob['job_id'].'.json');
    $invalid['topology']['observed_lanes'] = $nativeJob['requested_processes'] + 1;
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalid, $nativeExecutionJob): void {
            nativeBenchmarkValidateObservation($invalid, $nativeExecutionJob);
        },
        'impossible observed lanes',
    );
    $invalid = nativeBenchmarkReadJson($artifacts.DIRECTORY_SEPARATOR.$nativeJob['job_id'].'.json');
    $invalid['topology']['runnable_cases'] = 1;
    $invalid['topology']['forks'] = 1;
    $invalid['topology']['observed_lanes'] = 1;
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalid, $nativeExecutionJob): void {
            nativeBenchmarkValidateObservation($invalid, $nativeExecutionJob);
        },
        'isolation semantics',
    );
    $baselineJob = array_values(array_filter(
        $plan['schedule'],
        static fn (array $job): bool => $job['runner'] === 'baseline',
    ))[0];
    $baselineExecutionJob = nativeBenchmarkExecutionJob($baselineJob, $artifacts);
    $invalid = nativeBenchmarkReadJson($artifacts.DIRECTORY_SEPARATOR.$baselineJob['job_id'].'.json');
    $invalid['topology']['forks'] = 1;
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalid, $baselineExecutionJob): void {
            nativeBenchmarkValidateObservation($invalid, $baselineExecutionJob);
        },
        'baseline topology',
    );
    $invalid = nativeBenchmarkReadJson($artifacts.DIRECTORY_SEPARATOR.$nativeJob['job_id'].'.json');
    unset($invalid['timing']['phases_ms']['verification']);
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalid, $nativeExecutionJob): void {
            nativeBenchmarkValidateObservation($invalid, $nativeExecutionJob);
        },
        'phase contract',
    );
    $invalid = nativeBenchmarkReadJson($artifacts.DIRECTORY_SEPARATOR.$nativeJob['job_id'].'.json');
    $invalid['timing']['phases_ms']['execution'] = 0;
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalid, $nativeExecutionJob): void {
            nativeBenchmarkValidateObservation($invalid, $nativeExecutionJob);
        },
        'no positive execution phase',
    );
    $invalid = nativeBenchmarkReadJson($artifacts.DIRECTORY_SEPARATOR.$nativeJob['job_id'].'.json');
    $invalid['image_id'] = 'sha256:'.str_repeat('0', 64);
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalid, $nativeExecutionJob): void {
            nativeBenchmarkValidateObservation($invalid, $nativeExecutionJob);
        },
        'different image or concrete command',
    );
    $invalid = nativeBenchmarkReadJson($artifacts.DIRECTORY_SEPARATOR.$nativeJob['job_id'].'.json');
    $invalid['command_sha256'] = str_repeat('0', 64);
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalid, $nativeExecutionJob): void {
            nativeBenchmarkValidateObservation($invalid, $nativeExecutionJob);
        },
        'different image or concrete command',
    );
    $invalidConfig = $config;
    $invalidConfig['corpora'][0]['cohorts'][0]['mode'] = 'serial-resource';
    $invalidConfig['corpora'][0]['cohorts'][0]['c1_only_reason'] = 'tampered resource mode';
    nativeBenchmarkWriteJson($configPath, $invalidConfig);
    nativeBenchmarkSelfTestRejects(
        static fn (): mixed => nativeBenchmarkBuildPlan($configPath, 7_331),
        'six-cohort contract',
    );
    $invalidConfig = $config;
    array_pop($invalidConfig['corpora'][2]['cohorts']);
    nativeBenchmarkWriteJson($configPath, $invalidConfig);
    nativeBenchmarkSelfTestRejects(
        static fn (): mixed => nativeBenchmarkBuildPlan($configPath, 7_331),
        'six-cohort contract',
    );
    $invalidConfig = $config;
    $invalidConfig['corpora'][3]['source_revision'] = str_repeat('0', 40);
    nativeBenchmarkWriteJson($configPath, $invalidConfig);
    nativeBenchmarkSelfTestRejects(
        static fn (): mixed => nativeBenchmarkBuildPlan($configPath, 7_331),
        'six-cohort contract',
    );
    $invalidConfig = $config;
    unset($invalidConfig['job_timeout_seconds']);
    nativeBenchmarkWriteJson($configPath, $invalidConfig);
    nativeBenchmarkSelfTestRejects(
        static fn (): mixed => nativeBenchmarkBuildPlan($configPath, 7_331),
        'job timeout seconds',
    );
    $invalidConfig = $config;
    $invalidConfig['corpora'][0]['native']['command'][3] = '--network=bridge';
    nativeBenchmarkWriteJson($configPath, $invalidConfig);
    nativeBenchmarkSelfTestRejects(
        static fn (): mixed => nativeBenchmarkBuildPlan($configPath, 7_331),
        'fresh, networkless',
    );
    $invalidConfig = $config;
    $nativeImage = nativeBenchmarkDockerCommandImage($invalidConfig['corpora'][0]['native']['command']);
    $nativeImageIndex = array_search($nativeImage, $invalidConfig['corpora'][0]['native']['command'], true);
    nativeBenchmarkRequire(is_int($nativeImageIndex), 'Self-test could not locate the native image argument.');
    $invalidConfig['corpora'][0]['native']['command'][$nativeImageIndex] = nativeBenchmarkDockerCommandImage($invalidConfig['corpora'][0]['baseline']['command']);
    nativeBenchmarkWriteJson($configPath, $invalidConfig);
    nativeBenchmarkSelfTestRejects(
        static fn (): mixed => nativeBenchmarkBuildPlan($configPath, 7_331),
        'separate images',
    );
    nativeBenchmarkWriteJson($configPath, $config);
    $unboundEvidence = nativeBenchmarkReadJson($evidencePath);
    unset($unboundEvidence['drove_revision']);
    nativeBenchmarkSelfTestRejects(
        static function () use ($unboundEvidence): void {
            nativeBenchmarkValidatePrerequisite($unboundEvidence);
        },
        'N6 is locked',
    );
    $invalidEvidence = nativeBenchmarkReadJson($evidencePath);
    $invalidEvidence['outcomes'][2]['source_revision'] = str_repeat('0', 40);
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalidEvidence): void {
            nativeBenchmarkValidatePrerequisite($invalidEvidence);
        },
        'six-cohort contract',
    );
    $invalidEvidence = nativeBenchmarkReadJson($evidencePath);
    array_pop($invalidEvidence['outcomes'][3]['cohorts']);
    nativeBenchmarkSelfTestRejects(
        static function () use ($invalidEvidence): void {
            nativeBenchmarkValidatePrerequisite($invalidEvidence);
        },
        'six-cohort contract',
    );
    $timeoutTamper = $plan;
    $timeoutTamper['schedule'][0]['job_timeout_seconds']--;
    nativeBenchmarkSelfTestRejects(
        static function () use ($timeoutTamper): void {
            nativeBenchmarkValidatePlan($timeoutTamper);
        },
        'watchdog timeout',
    );
    $secondJob = $plan['schedule'][1];
    $secondPath = $artifacts.DIRECTORY_SEPARATOR.$secondJob['job_id'].'.json';
    $second = nativeBenchmarkReadJson($secondPath);
    $second['isolation_id'] = (string) $invalid['isolation_id'];
    nativeBenchmarkWriteJson($secondPath, $second);
    nativeBenchmarkSelfTestRejects(
        static fn (): mixed => nativeBenchmarkBuildReport($plan, $artifacts),
        'was reused',
    );

    $config['repetitions'] = 4;
    nativeBenchmarkWriteJson($configPath, $config);
    nativeBenchmarkSelfTestRejects(
        static fn (): mixed => nativeBenchmarkBuildPlan($configPath, 7_331),
        'at least five repetitions',
    );
    $config['repetitions'] = 5;
    nativeBenchmarkWriteJson($configPath, $config);
    $failedEvidence = nativeBenchmarkReadJson($evidencePath);
    $failedEvidence['verification'] = 'failed';
    nativeBenchmarkWriteJson($evidencePath, $failedEvidence);
    nativeBenchmarkSelfTestRejects(
        static fn (): mixed => nativeBenchmarkBuildPlan($configPath, 7_331),
        'N6 is locked',
    );

    fwrite(STDOUT, "Native N6 benchmark harness self-test passed.\n");
} finally {
    nativeBenchmarkRunnerRemoveTree($temporary);
}

function nativeBenchmarkSelfTestRejects(Closure $operation, string $messageFragment): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        nativeBenchmarkRequire(str_contains($exception->getMessage(), $messageFragment), "Unexpected rejection: {$exception->getMessage()}");

        return;
    }

    throw new RuntimeException("Self-test expected rejection containing '$messageFragment'.");
}
