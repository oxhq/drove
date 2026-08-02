<?php

declare(strict_types=1);

use Random\Engine\Mt19937;
use Random\Randomizer;

const DROVE_NATIVE_BENCHMARK_PROCESSES = [1, 2, 4, 8, 16, 30];
const DROVE_NATIVE_BENCHMARK_CORPORA = ['pest', 'invoiceshelf', 'livewire', 'filament'];

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    nativeBenchmarkMain($_SERVER['argv'] ?? []);
}

/**
 * @param  list<string>  $arguments
 */
function nativeBenchmarkMain(array $arguments): never
{
    try {
        $command = $arguments[1] ?? null;

        if ($command === 'plan' && count($arguments) >= 4 && count($arguments) <= 5) {
            $seed = isset($arguments[4]) ? nativeBenchmarkPositiveInt($arguments[4], 'seed') : random_int(1, PHP_INT_MAX);
            nativeBenchmarkWriteJson($arguments[3], nativeBenchmarkBuildPlan($arguments[2], $seed));
            fwrite(STDOUT, "Native benchmark plan written to {$arguments[3]}.\n");
            exit(0);
        }

        if ($command === 'run' && count($arguments) === 4) {
            nativeBenchmarkRun(nativeBenchmarkReadJson($arguments[2]), $arguments[3]);
            exit(0);
        }

        if ($command === 'report' && count($arguments) === 5) {
            $plan = nativeBenchmarkReadJson($arguments[2]);
            nativeBenchmarkWriteJson($arguments[4], nativeBenchmarkBuildReport($plan, $arguments[3]));
            fwrite(STDOUT, "Native benchmark report written to {$arguments[4]}.\n");
            exit(0);
        }

        throw new RuntimeException(
            "Usage:\n"
            ."  php native-benchmark.php plan CONFIG_JSON PLAN_JSON [SEED]\n"
            ."  php native-benchmark.php run PLAN_JSON ARTIFACT_DIR\n"
            .'  php native-benchmark.php report PLAN_JSON ARTIFACT_DIR REPORT_JSON',
        );
    } catch (Throwable $throwable) {
        fwrite(STDERR, $throwable->getMessage().PHP_EOL);
        exit(2);
    }
}

/**
 * Build an immutable, randomized schedule. The prerequisite evidence makes it
 * impossible to start N6 before the complete N1-N5 ladder has passed.
 *
 * @return array<string, mixed>
 */
function nativeBenchmarkBuildPlan(string $configPath, int $seed): array
{
    $config = nativeBenchmarkReadJson($configPath);
    nativeBenchmarkRequire(($config['schema_version'] ?? null) === 1, 'Native benchmark config schema must be 1.');
    $repetitions = $config['repetitions'] ?? null;
    nativeBenchmarkRequire(is_int($repetitions) && $repetitions >= 5, 'Native benchmarks require at least five repetitions.');
    $quotas = nativeBenchmarkQuotas($config['quotas'] ?? null);
    $prerequisite = nativeBenchmarkFileIdentity($config['n1_n5_evidence'] ?? null, dirname($configPath));
    $prerequisiteEvidence = nativeBenchmarkReadJson($prerequisite['path']);
    nativeBenchmarkValidatePrerequisite($prerequisiteEvidence);
    $droveRevision = $prerequisiteEvidence['drove_revision'];
    $corpora = $config['corpora'] ?? null;
    nativeBenchmarkRequire(
        is_array($corpora)
            && array_is_list($corpora)
            && array_column($corpora, 'id') === DROVE_NATIVE_BENCHMARK_CORPORA,
        'Native benchmark corpora must be ordered Pest, InvoiceShelf, Livewire, Filament.',
    );

    $randomizer = new Randomizer(new Mt19937($seed));
    $schedule = [];
    $normalizedCorpora = [];

    foreach ($corpora as $corpus) {
        nativeBenchmarkRequire(is_array($corpus), 'Each corpus config must be an object.');
        $id = nativeBenchmarkId($corpus['id'] ?? null, 'corpus');
        $revision = $corpus['source_revision'] ?? null;
        nativeBenchmarkRequire(
            is_string($revision) && preg_match('/^[0-9a-f]{40}$/D', $revision) === 1,
            "$id source revision must be a full Git SHA.",
        );
        $runners = [];
        $runnerImages = [];

        foreach (['baseline', 'native'] as $runner) {
            $runnerConfig = $corpus[$runner] ?? null;
            nativeBenchmarkRequire(is_array($runnerConfig), "$id $runner runner config is missing.");
            $lock = nativeBenchmarkFileIdentity($runnerConfig['lock'] ?? null, dirname($configPath));
            $commandTemplate = $runnerConfig['command'] ?? null;
            nativeBenchmarkRequire(
                is_array($commandTemplate)
                    && array_is_list($commandTemplate)
                    && $commandTemplate !== []
                    && count(array_filter($commandTemplate, 'is_string')) === count($commandTemplate),
                "$id $runner command must be a non-empty string list.",
            );
            $runnerImages[$runner] = nativeBenchmarkDockerImage(
                array_values($commandTemplate),
                $quotas,
                $id,
                $runner,
            );
            $runners[$runner] = [
                'lock' => $lock,
                'command' => array_values($commandTemplate),
            ];
        }

        nativeBenchmarkRequire(
            $runners['baseline']['lock']['path'] !== $runners['native']['lock']['path'],
            "$id baseline and native dependency trees must use separate lock files.",
        );
        nativeBenchmarkRequire(
            $runnerImages['baseline'] !== $runnerImages['native'],
            "$id baseline and native dependency trees must use separate images.",
        );
        $cohorts = $corpus['cohorts'] ?? null;
        nativeBenchmarkRequire(is_array($cohorts) && array_is_list($cohorts) && $cohorts !== [], "$id must declare cohorts.");
        $normalizedCohorts = [];

        foreach ($cohorts as $cohort) {
            nativeBenchmarkRequire(is_array($cohort), "$id cohort config must be an object.");
            $cohortId = nativeBenchmarkId($cohort['id'] ?? null, "$id cohort");
            $mode = $cohort['mode'] ?? null;
            nativeBenchmarkRequire(in_array($mode, ['parallel', 'serial-resource'], true), "$id/$cohortId has an invalid mode.");
            $reason = $cohort['c1_only_reason'] ?? null;

            if ($mode === 'serial-resource') {
                nativeBenchmarkRequire(is_string($reason) && trim($reason) !== '', "$id/$cohortId must name its C1-only resource reason.");
            } else {
                nativeBenchmarkRequire($reason === null, "$id/$cohortId cannot carry a C1-only reason when parallel.");
            }

            $expected = nativeBenchmarkExpectedOutcome($cohort['expected'] ?? null, "$id/$cohortId");
            $jobs = [];

            foreach (['baseline', 'native'] as $runner) {
                $processes = $runner === 'baseline' || $mode === 'serial-resource'
                    ? [1]
                    : DROVE_NATIVE_BENCHMARK_PROCESSES;

                foreach ($processes as $processCount) {
                    for ($repetition = 1; $repetition <= $repetitions; $repetition++) {
                        $jobId = sprintf('%s--%s--%s--c%d--r%02d', $id, $cohortId, $runner, $processCount, $repetition);
                        $jobs[] = [
                            'schema_version' => 1,
                            'job_id' => $jobId,
                            'corpus' => $id,
                            'cohort' => $cohortId,
                            'cohort_mode' => $mode,
                            'runner' => $runner,
                            'requested_processes' => $processCount,
                            'repetition' => $repetition,
                            'source_revision' => $revision,
                            'drove_revision' => $droveRevision,
                            'lock_sha256' => $runners[$runner]['lock']['sha256'],
                            'expected' => $expected,
                            'quotas' => $quotas,
                            'command' => $runners[$runner]['command'],
                        ];
                    }
                }
            }

            $jobs = $randomizer->shuffleArray($jobs);
            array_push($schedule, ...$jobs);
            $normalizedCohorts[] = [
                'id' => $cohortId,
                'mode' => $mode,
                'c1_only_reason' => $reason,
                'expected' => $expected,
            ];
        }

        $normalizedCorpora[] = [
            'id' => $id,
            'source_revision' => $revision,
            'baseline' => $runners['baseline'],
            'native' => $runners['native'],
            'cohorts' => $normalizedCohorts,
        ];
    }

    $configuredOutcomes = array_map(static fn (array $corpus): array => [
        'id' => $corpus['id'],
        'source_revision' => $corpus['source_revision'],
        'cohorts' => array_map(static fn (array $cohort): array => [
            'id' => $cohort['id'],
            'mode' => $cohort['mode'],
            'c1_only_reason' => $cohort['c1_only_reason'],
            'expected' => $cohort['expected'],
        ], $corpus['cohorts']),
    ], $normalizedCorpora);
    nativeBenchmarkRequire(
        ($prerequisiteEvidence['outcomes'] ?? null) === $configuredOutcomes,
        'Native benchmark config outcomes diverge from the exact N1-N5 evidence.',
    );

    return [
        'schema_version' => 1,
        'benchmark' => 'native-corpus-n6',
        'drove_revision' => $droveRevision,
        'performance_decision' => 'not_encoded',
        'c1_decision' => 'pending_measurement_and_review',
        'randomization' => [
            'algorithm' => 'php-random-mt19937',
            'scope' => 'within-corpus-cohort',
            'seed' => $seed,
        ],
        'repetitions' => $repetitions,
        'parallel_processes' => DROVE_NATIVE_BENCHMARK_PROCESSES,
        'quotas' => $quotas,
        'config' => [
            'path' => nativeBenchmarkAbsolutePath($configPath, getcwd() ?: '.'),
            'sha256' => nativeBenchmarkHash($configPath),
        ],
        'n1_n5_evidence' => $prerequisite,
        'corpora' => $normalizedCorpora,
        'schedule' => $schedule,
    ];
}

/**
 * @param  array<string, mixed>  $plan
 */
function nativeBenchmarkRun(array $plan, string $artifactDirectory): void
{
    nativeBenchmarkValidatePlan($plan);
    nativeBenchmarkVerifyPinnedInputs($plan);
    nativeBenchmarkRequire(
        is_dir($artifactDirectory) || (mkdir($artifactDirectory, 0700, true) && is_dir($artifactDirectory)),
        "Cannot create native benchmark artifact directory $artifactDirectory.",
    );
    $resolvedArtifactDirectory = realpath($artifactDirectory);

    if (! is_string($resolvedArtifactDirectory)) {
        throw new RuntimeException("Cannot resolve native benchmark artifact directory $artifactDirectory.");
    }

    $artifactDirectory = $resolvedArtifactDirectory;

    foreach ($plan['schedule'] as $index => $job) {
        nativeBenchmarkRequire(is_array($job), 'Native benchmark schedule contains an invalid job.');
        $jobId = (string) $job['job_id'];
        $artifactPath = $artifactDirectory.DIRECTORY_SEPARATOR.$jobId.'.json';
        $jobPath = $artifactDirectory.DIRECTORY_SEPARATOR.$jobId.'.job.json';
        $rawPath = $artifactDirectory.DIRECTORY_SEPARATOR.$jobId.'.raw.json';
        $executionJob = nativeBenchmarkExecutionJob($job, $artifactDirectory);
        $command = nativeBenchmarkConcreteCommand($job, $artifactDirectory);

        if (is_file($artifactPath)) {
            nativeBenchmarkValidateObservation(nativeBenchmarkReadJson($artifactPath), $executionJob);
            fwrite(STDOUT, sprintf("[%d/%d] %s already verified.\n", $index + 1, count($plan['schedule']), $jobId));

            continue;
        }

        nativeBenchmarkWriteJson($jobPath, $executionJob);

        $stdoutPath = $artifactDirectory.DIRECTORY_SEPARATOR.$jobId.'.driver.stdout.log';
        $stderrPath = $artifactDirectory.DIRECTORY_SEPARATOR.$jobId.'.driver.stderr.log';
        $stdout = fopen($stdoutPath, 'wb');

        if (! is_resource($stdout)) {
            throw new RuntimeException("$jobId stdout driver log cannot be opened.");
        }

        $stderr = fopen($stderrPath, 'wb');

        if (! is_resource($stderr)) {
            fclose($stdout);

            throw new RuntimeException("$jobId stderr driver log cannot be opened.");
        }

        fwrite(STDOUT, sprintf("[%d/%d] %s\n", $index + 1, count($plan['schedule']), $jobId));

        try {
            $process = proc_open(
                $command,
                [0 => ['file', nativeBenchmarkNullDevice(), 'r'], 1 => $stdout, 2 => $stderr],
                $pipes,
                options: ['bypass_shell' => true],
            );

            if (! is_resource($process)) {
                throw new RuntimeException("$jobId command could not start.");
            }

            $exitCode = proc_close($process);
        } finally {
            fclose($stdout);
            fclose($stderr);
        }

        nativeBenchmarkRequire($exitCode === 0, "$jobId command failed with exit $exitCode; inspect $stderrPath.");
        nativeBenchmarkRequire(is_file($artifactPath), "$jobId did not create $artifactPath.");
        nativeBenchmarkValidateObservation(nativeBenchmarkReadJson($artifactPath), $executionJob);
    }
}

/**
 * @param  array<string, mixed>  $plan
 * @return array<string, mixed>
 */
function nativeBenchmarkBuildReport(array $plan, string $artifactDirectory): array
{
    nativeBenchmarkValidatePlan($plan);
    nativeBenchmarkVerifyPinnedInputs($plan);
    nativeBenchmarkRequire(is_dir($artifactDirectory), "Native benchmark artifact directory is unavailable: $artifactDirectory.");
    $resolvedArtifactDirectory = realpath($artifactDirectory);

    if (! is_string($resolvedArtifactDirectory)) {
        throw new RuntimeException("Cannot resolve native benchmark artifact directory $artifactDirectory.");
    }

    $artifactDirectory = $resolvedArtifactDirectory;
    $observations = [];
    $isolationIds = [];
    $artifactHashes = [];
    $platform = null;

    foreach ($plan['schedule'] as $job) {
        nativeBenchmarkRequire(is_array($job), 'Native benchmark schedule contains an invalid job.');
        $path = rtrim($artifactDirectory, '/\\').DIRECTORY_SEPARATOR.$job['job_id'].'.json';
        $observation = nativeBenchmarkReadJson($path);
        $executionJob = nativeBenchmarkExecutionJob($job, $artifactDirectory);
        nativeBenchmarkValidateObservation($observation, $executionJob);
        $isolationId = (string) $observation['isolation_id'];
        nativeBenchmarkRequire(! isset($isolationIds[$isolationId]), "Isolation $isolationId was reused; every timed command requires a fresh container.");
        $isolationIds[$isolationId] = true;
        $platform ??= $observation['platform'];
        nativeBenchmarkRequire($observation['platform'] === $platform, 'Native benchmark platform identity changed between timed commands.');
        $observations[] = $observation;
        $artifactHashes[] = [
            'job_id' => $job['job_id'],
            'image_id' => $executionJob['image_id'],
            'command_sha256' => $executionJob['command_sha256'],
            'sha256' => nativeBenchmarkHash($path),
        ];
    }

    /** @var array<string, list<array<string, mixed>>> $cells */
    $cells = [];

    foreach ($observations as $observation) {
        $key = implode('/', [
            $observation['corpus'],
            $observation['cohort'],
            $observation['runner'],
            'c'.$observation['requested_processes'],
        ]);
        $cells[$key][] = $observation;
    }

    ksort($cells, SORT_STRING);

    $groups = [];

    foreach ($cells as $key => $runs) {
        usort($runs, static fn (array $left, array $right): int => $left['repetition'] <=> $right['repetition']);
        $first = $runs[0];
        $phaseNames = array_keys($first['timing']['phases_ms']);
        sort($phaseNames, SORT_STRING);

        foreach ($runs as $run) {
            $actualPhaseNames = array_keys($run['timing']['phases_ms']);
            sort($actualPhaseNames, SORT_STRING);
            nativeBenchmarkRequire($actualPhaseNames === $phaseNames, "$key changed phase telemetry fields between repetitions.");
        }

        $phaseStats = [];

        foreach ($phaseNames as $phase) {
            $phaseStats[$phase] = nativeBenchmarkStatistics(array_map(
                static fn (array $run): int|float => $run['timing']['phases_ms'][$phase],
                $runs,
            ));
        }
        $runnerPath = nativeBenchmarkStatistics(array_map(
            static fn (array $run): int|float => $run['runner'] === 'native'
                ? $run['timing']['phases_ms']['preparation']
                    + $run['timing']['phases_ms']['planning']
                    + $run['timing']['phases_ms']['execution']
                : $run['timing']['phases_ms']['execution'],
            $runs,
        ));

        $groups[$key] = [
            'corpus' => $first['corpus'],
            'cohort' => $first['cohort'],
            'cohort_mode' => $first['cohort_mode'],
            'runner' => $first['runner'],
            'requested_processes' => $first['requested_processes'],
            'runs' => count($runs),
            'outcome' => $first['outcome'],
            'wall_ms' => nativeBenchmarkStatistics(array_column(array_column($runs, 'timing'), 'wall_ms')),
            'phases_ms' => $phaseStats,
            'runner_path_ms' => $runnerPath,
            'observed_lanes' => nativeBenchmarkStatistics(array_column(array_column($runs, 'topology'), 'observed_lanes')),
            'forks' => nativeBenchmarkStatistics(array_column(array_column($runs, 'topology'), 'forks')),
            'aggregate_peak_rss_bytes' => nativeBenchmarkStatistics(array_column(array_column($runs, 'memory'), 'aggregate_peak_rss_bytes')),
            'aggregate_peak_pss_bytes' => nativeBenchmarkStatistics(array_column(array_column($runs, 'memory'), 'aggregate_peak_pss_bytes')),
            'cgroup_peak_memory_bytes' => nativeBenchmarkStatistics(array_column(array_column($runs, 'memory'), 'cgroup_peak_memory_bytes')),
        ];
    }

    $comparisons = [];

    foreach ($groups as $key => $group) {
        if ($group['runner'] !== 'native') {
            continue;
        }

        $baselineKey = $group['corpus'].'/'.$group['cohort'].'/baseline/c1';
        nativeBenchmarkRequire(isset($groups[$baselineKey]), "$key has no independent C1 baseline.");
        $baseline = $groups[$baselineKey];
        $comparisons[] = [
            'corpus' => $group['corpus'],
            'cohort' => $group['cohort'],
            'native_processes' => $group['requested_processes'],
            'native_to_baseline_median_ratio' => [
                'end_to_end_wall_ms' => nativeBenchmarkRatio($group['wall_ms']['median'], $baseline['wall_ms']['median']),
                'execution_phase_ms' => nativeBenchmarkRatio(
                    $group['phases_ms']['execution']['median'],
                    $baseline['phases_ms']['execution']['median'],
                ),
                'runner_path_to_baseline_process_ms' => nativeBenchmarkRatio(
                    $group['runner_path_ms']['median'],
                    $baseline['runner_path_ms']['median'],
                ),
                'aggregate_peak_rss_bytes' => nativeBenchmarkRatio($group['aggregate_peak_rss_bytes']['median'], $baseline['aggregate_peak_rss_bytes']['median']),
                'aggregate_peak_pss_bytes' => nativeBenchmarkRatio($group['aggregate_peak_pss_bytes']['median'], $baseline['aggregate_peak_pss_bytes']['median']),
                'cgroup_peak_memory_bytes' => nativeBenchmarkRatio($group['cgroup_peak_memory_bytes']['median'], $baseline['cgroup_peak_memory_bytes']['median']),
            ],
        ];
    }
    $nativeScaling = [];

    foreach ($groups as $key => $group) {
        if ($group['runner'] !== 'native') {
            continue;
        }

        $nativeC1Key = $group['corpus'].'/'.$group['cohort'].'/native/c1';
        nativeBenchmarkRequire(isset($groups[$nativeC1Key]), "$key has no native C1 scaling reference.");
        $nativeC1 = $groups[$nativeC1Key];
        $nativeScaling[] = [
            'corpus' => $group['corpus'],
            'cohort' => $group['cohort'],
            'native_processes' => $group['requested_processes'],
            'median_ratio_to_native_c1' => [
                'end_to_end_wall_ms' => nativeBenchmarkRatio($group['wall_ms']['median'], $nativeC1['wall_ms']['median']),
                'runner_path_ms' => nativeBenchmarkRatio($group['runner_path_ms']['median'], $nativeC1['runner_path_ms']['median']),
                'execution_phase_ms' => nativeBenchmarkRatio(
                    $group['phases_ms']['execution']['median'],
                    $nativeC1['phases_ms']['execution']['median'],
                ),
                'aggregate_peak_rss_bytes' => nativeBenchmarkRatio($group['aggregate_peak_rss_bytes']['median'], $nativeC1['aggregate_peak_rss_bytes']['median']),
                'aggregate_peak_pss_bytes' => nativeBenchmarkRatio($group['aggregate_peak_pss_bytes']['median'], $nativeC1['aggregate_peak_pss_bytes']['median']),
                'cgroup_peak_memory_bytes' => nativeBenchmarkRatio($group['cgroup_peak_memory_bytes']['median'], $nativeC1['cgroup_peak_memory_bytes']['median']),
            ],
            'native_c1_to_current_median_speedup' => [
                'end_to_end_wall_ms' => nativeBenchmarkRatio($nativeC1['wall_ms']['median'], $group['wall_ms']['median']),
                'runner_path_ms' => nativeBenchmarkRatio($nativeC1['runner_path_ms']['median'], $group['runner_path_ms']['median']),
                'execution_phase_ms' => nativeBenchmarkRatio(
                    $nativeC1['phases_ms']['execution']['median'],
                    $group['phases_ms']['execution']['median'],
                ),
            ],
        ];
    }

    return [
        'schema_version' => 1,
        'benchmark' => 'native-corpus-n6',
        'drove_revision' => $plan['drove_revision'],
        'verification' => 'passed',
        'thresholds_applied' => false,
        'performance_decision' => 'not_encoded',
        'c1_decision' => 'pending_human_review',
        'comparison_semantics' => [
            'decision_basis' => 'Use within-native C1-to-C30 scaling as the primary C1/default evidence; upstream ratios are contextual only.',
            'end_to_end_wall_ms' => 'Whole benchmark-harness wall time; both sides include validation, but their verification work differs.',
            'execution_phase_ms' => 'Diagnostic only: upstream includes its whole runner subprocess, while native isolates scheduler/test execution after preparation and planning.',
            'runner_path_ms' => 'Diagnostic only: native preparation + planning + execution versus the upstream runner subprocess; the PHP startup boundary still differs.',
        ],
        'statistics' => [
            'p95' => 'nearest-rank',
            'dispersion' => 'median-absolute-deviation',
            'relative_dispersion' => 'median-absolute-deviation/median',
        ],
        'plan_sha256' => hash('sha256', nativeBenchmarkCanonicalJson($plan)),
        'randomization' => $plan['randomization'],
        'repetitions' => $plan['repetitions'],
        'quotas' => $plan['quotas'],
        'platform' => $platform,
        'config_sha256' => $plan['config']['sha256'],
        'n1_n5_evidence_sha256' => $plan['n1_n5_evidence']['sha256'],
        'corpora' => nativeBenchmarkReportCorpora($plan['corpora']),
        'groups' => array_values($groups),
        'comparisons' => $comparisons,
        'native_scaling' => $nativeScaling,
        'run_artifacts' => $artifactHashes,
    ];
}

/**
 * @param  list<array<string, mixed>>  $corpora
 * @return list<array<string, mixed>>
 */
function nativeBenchmarkReportCorpora(array $corpora): array
{
    return array_map(
        static fn (array $corpus): array => [
            'id' => $corpus['id'],
            'source_revision' => $corpus['source_revision'],
            'baseline_lock_sha256' => $corpus['baseline']['lock']['sha256'],
            'native_lock_sha256' => $corpus['native']['lock']['sha256'],
            'baseline_image_id' => $corpus['baseline']['command'][9],
            'native_image_id' => $corpus['native']['command'][9],
            'cohorts' => $corpus['cohorts'],
        ],
        $corpora,
    );
}

/**
 * Resolve the exact outer Docker invocation that produced a timed observation.
 * The hash deliberately includes the concrete artifact directory and filenames,
 * so an observation cannot be replayed under a different benchmark run.
 *
 * @param  array<string, mixed>  $job
 * @return list<string>
 */
function nativeBenchmarkConcreteCommand(array $job, string $artifactDirectory): array
{
    $jobId = nativeBenchmarkId($job['job_id'] ?? null, 'job');
    $replacements = [
        '{artifact_dir}' => str_replace('\\', '/', $artifactDirectory),
        '{artifact_file}' => $jobId.'.json',
        '{job_file}' => $jobId.'.job.json',
        '{raw_file}' => $jobId.'.raw.json',
        '{job_id}' => $jobId,
        '{corpus}' => (string) ($job['corpus'] ?? ''),
        '{cohort}' => (string) ($job['cohort'] ?? ''),
        '{runner}' => (string) ($job['runner'] ?? ''),
        '{processes}' => (string) ($job['requested_processes'] ?? ''),
        '{repetition}' => (string) ($job['repetition'] ?? ''),
    ];
    $template = $job['command'] ?? null;
    nativeBenchmarkRequire(is_array($template) && array_is_list($template), "$jobId command is invalid.");
    $command = [];

    foreach ($template as $argument) {
        nativeBenchmarkRequire(is_string($argument), "$jobId command contains a non-string argument.");
        $argument = strtr($argument, $replacements);
        nativeBenchmarkRequire(preg_match('/\{[a-z_]+\}/D', $argument) !== 1, "$jobId command contains an unresolved placeholder.");
        $command[] = $argument;
    }

    return $command;
}

/**
 * @param  array<string, mixed>  $job
 * @return array<string, mixed>
 */
function nativeBenchmarkExecutionJob(array $job, string $artifactDirectory): array
{
    $command = nativeBenchmarkConcreteCommand($job, $artifactDirectory);
    $image = $command[9] ?? null;
    nativeBenchmarkRequire(is_string($image) && preg_match('/^sha256:[0-9a-f]{64}$/D', $image) === 1, 'Concrete benchmark command has no content-addressed image.');

    return [
        ...$job,
        'image_id' => $image,
        'command_sha256' => hash('sha256', json_encode($command, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
    ];
}

/**
 * @param  array<string, mixed>  $observation
 * @param  array<string, mixed>  $job
 */
function nativeBenchmarkValidateObservation(array $observation, array $job): void
{
    foreach (['job_id', 'corpus', 'cohort', 'cohort_mode', 'runner', 'requested_processes', 'repetition', 'source_revision', 'drove_revision', 'lock_sha256'] as $field) {
        nativeBenchmarkRequire(($observation[$field] ?? null) === ($job[$field] ?? null), "{$job['job_id']} observation changed $field.");
    }

    nativeBenchmarkRequire(
        ($observation['image_id'] ?? null) === ($job['image_id'] ?? null)
            && ($observation['command_sha256'] ?? null) === ($job['command_sha256'] ?? null),
        "{$job['job_id']} observation was produced by a different image or concrete command.",
    );

    nativeBenchmarkRequire(($observation['schema_version'] ?? null) === 1, "{$job['job_id']} observation schema must be 1.");
    nativeBenchmarkRequire(is_string($observation['isolation_id'] ?? null) && trim($observation['isolation_id']) !== '', "{$job['job_id']} has no container isolation identity.");
    nativeBenchmarkRequire(
        nativeBenchmarkQuotas($observation['quotas'] ?? null) === nativeBenchmarkQuotas($job['quotas'] ?? null),
        "{$job['job_id']} did not run under the planned CPU and memory quotas.",
    );
    $expected = nativeBenchmarkExpectedOutcome($job['expected'] ?? null, (string) $job['job_id']);
    $actual = nativeBenchmarkExpectedOutcome($observation['outcome'] ?? null, (string) $job['job_id']);
    nativeBenchmarkRequire($actual === $expected, "{$job['job_id']} outcome drifted from its pinned independent baseline.");
    $timing = $observation['timing'] ?? null;
    nativeBenchmarkRequire(is_array($timing) && nativeBenchmarkPositiveNumber($timing['wall_ms'] ?? null), "{$job['job_id']} has invalid wall time.");
    $phases = $timing['phases_ms'] ?? null;
    nativeBenchmarkRequire(is_array($phases) && ! array_is_list($phases), "{$job['job_id']} has no phase telemetry.");

    foreach ($phases as $phase => $milliseconds) {
        nativeBenchmarkRequire(is_string($phase) && $phase !== '' && nativeBenchmarkNonNegativeNumber($milliseconds), "{$job['job_id']} has invalid phase telemetry.");
    }

    $phaseNames = array_keys($phases);
    sort($phaseNames, SORT_STRING);
    $expectedPhaseNames = $job['runner'] === 'native'
        ? ['execution', 'planning', 'preparation', 'verification']
        : ['execution', 'verification'];
    nativeBenchmarkRequire($phaseNames === $expectedPhaseNames, "{$job['job_id']} changed the benchmark phase contract.");
    nativeBenchmarkRequire(nativeBenchmarkPositiveNumber($phases['execution']), "{$job['job_id']} has no positive execution phase.");
    nativeBenchmarkRequire(
        array_sum($phases) <= $timing['wall_ms'] + 1,
        "{$job['job_id']} phase time exceeds end-to-end wall time.",
    );

    $topology = $observation['topology'] ?? null;
    nativeBenchmarkRequire(is_array($topology), "{$job['job_id']} has no topology telemetry.");
    $lanes = $topology['observed_lanes'] ?? null;
    $forks = $topology['forks'] ?? null;
    $runnableCases = $topology['runnable_cases'] ?? null;
    $expectedRunnableCases = $expected['cases'] - ($expected['statuses']['skipped'] ?? 0);
    nativeBenchmarkRequire(is_int($lanes) && $lanes >= 1 && $lanes <= $job['requested_processes'], "{$job['job_id']} has impossible observed lanes.");
    nativeBenchmarkRequire(is_int($forks) && $forks >= 0, "{$job['job_id']} has invalid fork telemetry.");

    if ($job['runner'] === 'native') {
        nativeBenchmarkRequire(
            ($topology['strategy'] ?? null) === 'isolated-per-test'
                && ($topology['fork_semantics'] ?? null) === 'one-fork-per-test'
                && is_int($runnableCases)
                && $runnableCases === $expectedRunnableCases
                && $forks === $runnableCases
                && $lanes === min($job['requested_processes'], $runnableCases),
            "{$job['job_id']} changed native isolation semantics or batched tests.",
        );
    } else {
        nativeBenchmarkRequire(
            ($topology['strategy'] ?? null) === 'upstream'
                && ($topology['fork_semantics'] ?? null) === 'runner-native'
                && $lanes === 1
                && $forks === 0
                && $runnableCases === null,
            "{$job['job_id']} baseline topology identity is invalid.",
        );
    }

    $memory = $observation['memory'] ?? null;
    nativeBenchmarkRequire(is_array($memory), "{$job['job_id']} has no memory telemetry.");
    nativeBenchmarkRequire(is_int($memory['sample_count'] ?? null) && $memory['sample_count'] > 0, "{$job['job_id']} has no memory samples.");

    foreach (['aggregate_peak_rss_bytes', 'aggregate_peak_pss_bytes', 'cgroup_peak_memory_bytes'] as $field) {
        nativeBenchmarkRequire(is_int($memory[$field] ?? null) && $memory[$field] > 0, "{$job['job_id']} has invalid $field.");
    }

    nativeBenchmarkRequire(
        is_int($memory['cgroup_peak_before_bytes'] ?? null)
            && $memory['cgroup_peak_before_bytes'] >= 0
            && $memory['cgroup_peak_memory_bytes'] >= $memory['cgroup_peak_before_bytes'],
        "{$job['job_id']} has invalid cgroup peak bounds.",
    );
    $events = $memory['cgroup_events_delta'] ?? null;
    nativeBenchmarkRequire(is_array($events) && ! array_is_list($events), "{$job['job_id']} has no cgroup event telemetry.");

    foreach (['oom', 'oom_kill'] as $event) {
        nativeBenchmarkRequire(($events[$event] ?? null) === 0, "{$job['job_id']} observed a cgroup $event event.");
    }

    $platform = $observation['platform'] ?? null;
    nativeBenchmarkRequire(
        is_array($platform)
            && is_string($platform['os'] ?? null)
            && is_string($platform['architecture'] ?? null)
            && is_string($platform['php'] ?? null),
        "{$job['job_id']} has invalid platform identity.",
    );
}

/**
 * @param  list<int|float>  $values
 * @return array{samples: int, min: float, median: float, p95: float, max: float, median_absolute_deviation: float, relative_median_absolute_deviation: float|null}
 */
function nativeBenchmarkStatistics(array $values): array
{
    if ($values === []) {
        throw new RuntimeException('Cannot summarize an empty native benchmark sample.');
    }

    foreach ($values as $value) {
        nativeBenchmarkRequire(nativeBenchmarkNonNegativeNumber($value), 'Native benchmark statistics require non-negative numbers.');
    }

    $values = array_map(floatval(...), $values);
    sort($values, SORT_NUMERIC);
    $median = nativeBenchmarkMedian($values);
    $deviations = array_map(static fn (float $value): float => abs($value - $median), $values);
    sort($deviations, SORT_NUMERIC);
    $mad = nativeBenchmarkMedian($deviations);

    return [
        'samples' => count($values),
        'min' => round($values[0], 3),
        'median' => round($median, 3),
        'p95' => round($values[(int) ceil(count($values) * 0.95) - 1], 3),
        'max' => round($values[array_key_last($values)], 3),
        'median_absolute_deviation' => round($mad, 3),
        'relative_median_absolute_deviation' => $median > 0 ? round($mad / $median, 6) : null,
    ];
}

/**
 * @param  list<float>  $values
 */
function nativeBenchmarkMedian(array $values): float
{
    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2;
}

/**
 * @param  array<string, mixed>  $plan
 */
function nativeBenchmarkValidatePlan(array $plan): void
{
    $randomization = $plan['randomization'] ?? null;
    $corpora = $plan['corpora'] ?? null;
    nativeBenchmarkRequire(
        ($plan['schema_version'] ?? null) === 1
            && ($plan['benchmark'] ?? null) === 'native-corpus-n6'
            && is_string($plan['drove_revision'] ?? null)
            && preg_match('/^[0-9a-f]{40}$/D', $plan['drove_revision']) === 1
            && ($plan['c1_decision'] ?? null) === 'pending_measurement_and_review'
            && is_int($plan['repetitions'] ?? null)
            && $plan['repetitions'] >= 5
            && ($plan['parallel_processes'] ?? null) === DROVE_NATIVE_BENCHMARK_PROCESSES
            && is_array($plan['schedule'] ?? null)
            && array_is_list($plan['schedule'])
            && $plan['schedule'] !== [],
        'Native benchmark plan is invalid.',
    );
    nativeBenchmarkRequire(
        is_array($randomization)
            && ($randomization['algorithm'] ?? null) === 'php-random-mt19937'
            && ($randomization['scope'] ?? null) === 'within-corpus-cohort'
            && is_int($randomization['seed'] ?? null)
            && $randomization['seed'] > 0,
        'Native benchmark plan randomization identity is invalid.',
    );
    nativeBenchmarkRequire(
        is_array($corpora)
            && array_is_list($corpora)
            && array_column($corpora, 'id') === DROVE_NATIVE_BENCHMARK_CORPORA,
        'Native benchmark plan corpus ladder is invalid.',
    );
    $quotas = nativeBenchmarkQuotas($plan['quotas'] ?? null);

    foreach ($corpora as $corpus) {
        nativeBenchmarkRequire(is_array($corpus), 'Native benchmark plan corpus is invalid.');
        $id = nativeBenchmarkId($corpus['id'] ?? null, 'corpus');
        $images = [];

        foreach (['baseline', 'native'] as $runner) {
            $command = $corpus[$runner]['command'] ?? null;
            nativeBenchmarkRequire(is_array($command) && array_is_list($command), "$id $runner command is invalid.");
            $images[$runner] = nativeBenchmarkDockerImage($command, $quotas, $id, $runner);
        }

        nativeBenchmarkRequire($images['baseline'] !== $images['native'], "$id baseline and native images are not separate.");
    }
    $jobIds = [];

    foreach ($plan['schedule'] as $job) {
        nativeBenchmarkRequire(is_array($job), 'Native benchmark plan contains a non-object job.');
        $jobId = nativeBenchmarkId($job['job_id'] ?? null, 'job');
        nativeBenchmarkRequire(! isset($jobIds[$jobId]), "Native benchmark plan duplicates $jobId.");
        $jobIds[$jobId] = true;
        nativeBenchmarkRequire(in_array($job['requested_processes'] ?? null, DROVE_NATIVE_BENCHMARK_PROCESSES, true), "$jobId has an invalid process count.");
        nativeBenchmarkRequire(in_array($job['runner'] ?? null, ['baseline', 'native'], true), "$jobId has an invalid runner.");
        nativeBenchmarkRequire(($job['drove_revision'] ?? null) === $plan['drove_revision'], "$jobId changed the planned Drove revision.");
        nativeBenchmarkRequire(is_array($job['command'] ?? null) && array_is_list($job['command']), "$jobId has no command.");
        nativeBenchmarkExpectedOutcome($job['expected'] ?? null, $jobId);
    }

    nativeBenchmarkRequire(
        $plan['schedule'] === nativeBenchmarkRebuildSchedule($plan),
        'Native benchmark schedule is incomplete, reordered, or inconsistent with its randomized plan.',
    );
}

/**
 * @param  array<string, mixed>  $plan
 * @return list<array<string, mixed>>
 */
function nativeBenchmarkRebuildSchedule(array $plan): array
{
    $randomizer = new Randomizer(new Mt19937($plan['randomization']['seed']));
    $schedule = [];

    foreach ($plan['corpora'] as $corpus) {
        nativeBenchmarkRequire(is_array($corpus), 'Native benchmark plan corpus is invalid.');

        foreach ($corpus['cohorts'] ?? [] as $cohort) {
            nativeBenchmarkRequire(is_array($cohort), 'Native benchmark plan cohort is invalid.');
            $jobs = [];

            foreach (['baseline', 'native'] as $runner) {
                $processes = $runner === 'baseline' || $cohort['mode'] === 'serial-resource'
                    ? [1]
                    : DROVE_NATIVE_BENCHMARK_PROCESSES;

                foreach ($processes as $processCount) {
                    for ($repetition = 1; $repetition <= $plan['repetitions']; $repetition++) {
                        $jobId = sprintf(
                            '%s--%s--%s--c%d--r%02d',
                            $corpus['id'],
                            $cohort['id'],
                            $runner,
                            $processCount,
                            $repetition,
                        );
                        $jobs[] = [
                            'schema_version' => 1,
                            'job_id' => $jobId,
                            'corpus' => $corpus['id'],
                            'cohort' => $cohort['id'],
                            'cohort_mode' => $cohort['mode'],
                            'runner' => $runner,
                            'requested_processes' => $processCount,
                            'repetition' => $repetition,
                            'source_revision' => $corpus['source_revision'],
                            'drove_revision' => $plan['drove_revision'],
                            'lock_sha256' => $corpus[$runner]['lock']['sha256'],
                            'expected' => $cohort['expected'],
                            'quotas' => $plan['quotas'],
                            'command' => $corpus[$runner]['command'],
                        ];
                    }
                }
            }

            array_push($schedule, ...$randomizer->shuffleArray($jobs));
        }
    }

    return $schedule;
}

/**
 * @param  array<string, mixed>  $plan
 */
function nativeBenchmarkVerifyPinnedInputs(array $plan): void
{
    $config = $plan['config'] ?? null;
    nativeBenchmarkRequire(is_array($config), 'Native benchmark plan has no config identity.');
    nativeBenchmarkRequire(nativeBenchmarkHash((string) $config['path']) === ($config['sha256'] ?? null), 'Native benchmark config changed after planning.');
    $evidence = $plan['n1_n5_evidence'] ?? null;
    nativeBenchmarkRequire(is_array($evidence), 'Native benchmark plan has no N1-N5 evidence identity.');
    nativeBenchmarkRequire(nativeBenchmarkHash((string) $evidence['path']) === ($evidence['sha256'] ?? null), 'N1-N5 evidence changed after planning.');
    $evidencePayload = nativeBenchmarkReadJson((string) $evidence['path']);
    nativeBenchmarkValidatePrerequisite($evidencePayload);
    nativeBenchmarkRequire(
        $evidencePayload['drove_revision'] === ($plan['drove_revision'] ?? null),
        'The N1-N5 Drove revision changed after planning.',
    );

    foreach ($plan['corpora'] ?? [] as $corpus) {
        nativeBenchmarkRequire(is_array($corpus), 'Native benchmark plan corpus is invalid.');

        foreach (['baseline', 'native'] as $runner) {
            $lock = $corpus[$runner]['lock'] ?? null;
            nativeBenchmarkRequire(is_array($lock), 'Native benchmark plan lock identity is invalid.');
            nativeBenchmarkRequire(nativeBenchmarkHash((string) $lock['path']) === ($lock['sha256'] ?? null), "{$corpus['id']} $runner lock changed after planning.");
        }
    }

    $rebuilt = nativeBenchmarkBuildPlan((string) $config['path'], $plan['randomization']['seed']);
    nativeBenchmarkRequire(
        nativeBenchmarkCanonicalJson($plan) === nativeBenchmarkCanonicalJson($rebuilt),
        'Native benchmark plan diverged from its pinned config, evidence, or randomized seed.',
    );
}

/**
 * @return array{cpu_cores: float, memory_bytes: int}
 */
function nativeBenchmarkQuotas(mixed $value): array
{
    nativeBenchmarkRequire(is_array($value), 'Native benchmark CPU and memory quotas are required.');
    $cpu = $value['cpu_cores'] ?? null;
    $memory = $value['memory_bytes'] ?? null;
    nativeBenchmarkRequire(nativeBenchmarkPositiveNumber($cpu), 'Native benchmark CPU quota must be finite and positive.');
    nativeBenchmarkRequire(is_int($memory) && $memory > 0, 'Native benchmark memory quota must be finite and positive.');

    return ['cpu_cores' => (float) $cpu, 'memory_bytes' => $memory];
}

/**
 * @return array{cases: int, assertions: int, statuses: array<string, int>, semantic_hash: string}
 */
function nativeBenchmarkExpectedOutcome(mixed $value, string $subject): array
{
    nativeBenchmarkRequire(is_array($value), "$subject expected outcome is missing.");
    $cases = $value['cases'] ?? null;
    $assertions = $value['assertions'] ?? null;
    $statuses = $value['statuses'] ?? null;
    $hash = $value['semantic_hash'] ?? null;
    nativeBenchmarkRequire(is_int($cases) && $cases > 0, "$subject expected cases are invalid.");
    nativeBenchmarkRequire(is_int($assertions) && $assertions >= 0, "$subject expected assertions are invalid.");
    nativeBenchmarkRequire(is_array($statuses) && ! array_is_list($statuses), "$subject expected statuses are invalid.");
    $normalized = [];

    foreach ($statuses as $status => $count) {
        nativeBenchmarkRequire(is_string($status) && $status !== '' && is_int($count) && $count >= 0, "$subject expected status entry is invalid.");
        $normalized[$status] = $count;
    }

    ksort($normalized, SORT_STRING);
    nativeBenchmarkRequire(array_sum($normalized) === $cases, "$subject status counts do not equal its cases.");
    nativeBenchmarkRequire(is_string($hash) && preg_match('/^[0-9a-f]{64}$/D', $hash) === 1, "$subject semantic hash is invalid.");

    return ['cases' => $cases, 'assertions' => $assertions, 'statuses' => $normalized, 'semantic_hash' => $hash];
}

/**
 * @return array{path: string, sha256: string}
 */
function nativeBenchmarkFileIdentity(mixed $value, string $baseDirectory): array
{
    nativeBenchmarkRequire(is_string($value) && trim($value) !== '', 'Native benchmark pinned file path is invalid.');
    $path = nativeBenchmarkAbsolutePath($value, $baseDirectory);
    nativeBenchmarkRequire(is_file($path), "Native benchmark pinned file does not exist: $path.");

    return ['path' => $path, 'sha256' => nativeBenchmarkHash($path)];
}

/**
 * @param  array<string, mixed>  $evidence
 */
function nativeBenchmarkValidatePrerequisite(array $evidence): void
{
    $outcomes = $evidence['outcomes'] ?? null;
    $artifacts = $evidence['artifacts'] ?? null;
    nativeBenchmarkRequire(
        ($evidence['schema_version'] ?? null) === 1
            && ($evidence['verification'] ?? null) === 'passed'
            && is_string($evidence['drove_revision'] ?? null)
            && preg_match('/^[0-9a-f]{40}$/D', $evidence['drove_revision']) === 1
            && ($evidence['phases'] ?? null) === ['N1', 'N2', 'N3', 'N4', 'N5']
            && ($evidence['corpora'] ?? null) === DROVE_NATIVE_BENCHMARK_CORPORA
            && ($evidence['filament_final'] ?? null) === true
            && is_array($outcomes)
            && array_is_list($outcomes)
            && array_column($outcomes, 'id') === DROVE_NATIVE_BENCHMARK_CORPORA
            && is_array($artifacts)
            && array_is_list($artifacts)
            && $artifacts !== [],
        'N6 is locked until exact N1-N5 evidence passes all four corpora with Filament last.',
    );
    $artifactCorpora = [];
    $artifactPaths = [];

    foreach ($artifacts as $artifact) {
        nativeBenchmarkRequire(is_array($artifact), 'N1-N5 evidence contains an invalid artifact identity.');
        $corpus = nativeBenchmarkId($artifact['corpus'] ?? null, 'N1-N5 artifact corpus');
        $path = $artifact['path'] ?? null;
        $hash = $artifact['sha256'] ?? null;
        nativeBenchmarkRequire(is_string($path) && is_file($path), "N1-N5 artifact is unavailable: $path.");
        nativeBenchmarkRequire(is_string($hash) && nativeBenchmarkHash($path) === $hash, "N1-N5 artifact changed: $path.");
        nativeBenchmarkRequire(! isset($artifactPaths[$path]), "N1-N5 evidence duplicates artifact $path.");
        $artifactPaths[$path] = true;

        if (! in_array($corpus, $artifactCorpora, true)) {
            $artifactCorpora[] = $corpus;
        }
    }

    nativeBenchmarkRequire($artifactCorpora === DROVE_NATIVE_BENCHMARK_CORPORA, 'N1-N5 evidence artifacts are incomplete or do not leave Filament last.');

    foreach ($outcomes as $corpus) {
        nativeBenchmarkRequire(is_array($corpus), 'N1-N5 evidence contains an invalid corpus outcome.');
        $cohorts = $corpus['cohorts'] ?? null;
        nativeBenchmarkRequire(is_array($cohorts) && array_is_list($cohorts) && $cohorts !== [], 'N1-N5 evidence contains no corpus cohorts.');

        foreach ($cohorts as $cohort) {
            nativeBenchmarkRequire(is_array($cohort), 'N1-N5 evidence contains an invalid cohort outcome.');
            nativeBenchmarkId($cohort['id'] ?? null, 'N1-N5 evidence cohort');
            $mode = $cohort['mode'] ?? null;
            $reason = $cohort['c1_only_reason'] ?? null;
            nativeBenchmarkRequire(in_array($mode, ['parallel', 'serial-resource'], true), 'N1-N5 evidence contains an invalid cohort mode.');

            if ($mode === 'serial-resource') {
                nativeBenchmarkRequire(is_string($reason) && trim($reason) !== '', 'N1-N5 serial evidence has no resource reason.');
            } else {
                nativeBenchmarkRequire($reason === null, 'N1-N5 parallel evidence cannot carry a C1-only reason.');
            }

            nativeBenchmarkExpectedOutcome($cohort['expected'] ?? null, 'N1-N5 evidence cohort');
        }
    }
}

/**
 * @return array<string, mixed>
 */
function nativeBenchmarkReadJson(string $path): array
{
    $contents = file_get_contents($path);

    if (! is_string($contents) || trim($contents) === '') {
        throw new RuntimeException("Cannot read JSON artifact $path.");
    }

    $decoded = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
    nativeBenchmarkRequire(is_array($decoded) && ! array_is_list($decoded), "JSON artifact must contain an object: $path.");

    return $decoded;
}

function nativeBenchmarkCanonicalJson(mixed $value): string
{
    return json_encode(
        nativeBenchmarkCanonicalValue($value),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
}

function nativeBenchmarkCanonicalValue(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(nativeBenchmarkCanonicalValue(...), $value);
    }

    ksort($value, SORT_STRING);

    foreach ($value as $key => $item) {
        $value[$key] = nativeBenchmarkCanonicalValue($item);
    }

    return $value;
}

/**
 * @param  array<string, mixed>  $payload
 */
function nativeBenchmarkWriteJson(string $path, array $payload): void
{
    $directory = dirname($path);
    nativeBenchmarkRequire(is_dir($directory) || (mkdir($directory, 0700, true) && is_dir($directory)), "Cannot create $directory.");
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    nativeBenchmarkRequire(file_put_contents($path, $encoded, LOCK_EX) !== false, "Cannot write JSON artifact $path.");
}

function nativeBenchmarkHash(string $path): string
{
    $hash = hash_file('sha256', $path);

    if (! is_string($hash)) {
        throw new RuntimeException("Cannot hash $path.");
    }

    return $hash;
}

function nativeBenchmarkVerifyImageRevision(string $path, mixed $expected): string
{
    nativeBenchmarkRequire(
        is_string($expected) && preg_match('/^[0-9a-f]{40}$/D', $expected) === 1,
        'The timed job has no valid Drove revision.',
    );
    nativeBenchmarkRequire(is_file($path), "The benchmark image has no Drove revision marker: $path.");
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException("The benchmark image Drove revision marker is unreadable: $path.");
    }

    $actual = trim($contents);
    nativeBenchmarkRequire(
        preg_match('/^[0-9a-f]{40}$/D', $actual) === 1,
        'The benchmark image Drove revision marker is not a full lowercase Git SHA.',
    );
    nativeBenchmarkRequire($actual === $expected, 'The benchmark image was built from a different Drove revision.');

    return $actual;
}

function nativeBenchmarkAbsolutePath(string $path, string $baseDirectory): string
{
    $absolute = preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~D', $path) === 1
        ? $path
        : rtrim($baseDirectory, '/\\').DIRECTORY_SEPARATOR.$path;
    $resolved = realpath($absolute);

    if (! is_string($resolved)) {
        throw new RuntimeException("Cannot resolve $absolute.");
    }

    return $resolved;
}

function nativeBenchmarkOutputPath(string $path, string $baseDirectory): string
{
    nativeBenchmarkRequire(
        trim($path) !== '' && ! str_ends_with($path, '/') && ! str_ends_with($path, '\\'),
        'Native benchmark output path is invalid.',
    );
    $absolute = preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~D', $path) === 1
        ? $path
        : rtrim($baseDirectory, '/\\').DIRECTORY_SEPARATOR.$path;

    if (file_exists($absolute)) {
        nativeBenchmarkRequire(is_file($absolute), "Native benchmark output is not a file: $absolute.");
        $resolved = realpath($absolute);

        if (! is_string($resolved)) {
            throw new RuntimeException("Cannot resolve native benchmark output $absolute.");
        }

        return $resolved;
    }

    $directory = dirname($absolute);
    nativeBenchmarkRequire(
        is_dir($directory) || (mkdir($directory, 0700, true) && is_dir($directory)),
        "Cannot create native benchmark output directory $directory.",
    );
    $resolvedDirectory = realpath($directory);

    if (! is_string($resolvedDirectory)) {
        throw new RuntimeException("Cannot resolve native benchmark output directory $directory.");
    }

    return rtrim($resolvedDirectory, '/\\').DIRECTORY_SEPARATOR.basename($absolute);
}

function nativeBenchmarkId(mixed $value, string $subject): string
{
    nativeBenchmarkRequire(is_string($value) && preg_match('/^[a-z0-9][a-z0-9-]*$/D', $value) === 1, "$subject identifier is invalid.");

    return $value;
}

function nativeBenchmarkPositiveInt(mixed $value, string $subject): int
{
    $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (! is_int($integer)) {
        throw new RuntimeException("$subject must be a positive integer.");
    }

    return $integer;
}

function nativeBenchmarkPositiveNumber(mixed $value): bool
{
    return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0;
}

function nativeBenchmarkNonNegativeNumber(mixed $value): bool
{
    return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0;
}

function nativeBenchmarkRatio(int|float $numerator, int|float $denominator): float
{
    nativeBenchmarkRequire($denominator > 0, 'Cannot calculate a benchmark ratio against zero.');

    return round($numerator / $denominator, 6);
}

/**
 * @param  array{cpu_cores: float, memory_bytes: int}  $quotas
 * @return list<string>
 */
function nativeBenchmarkDockerCommand(string $image, array $quotas, string $corpus, string $runner): array
{
    nativeBenchmarkRequire(preg_match('/^sha256:[0-9a-f]{64}$/D', $image) === 1, 'The native benchmark image must be content-addressed.');

    return [
        'docker', 'run', '--rm', '--network=none',
        '--cpus='.rtrim(rtrim(sprintf('%.6F', $quotas['cpu_cores']), '0'), '.'),
        '--memory='.$quotas['memory_bytes'], '--memory-swap='.$quotas['memory_bytes'],
        '--tmpfs=/tmp:rw,exec,nosuid,size=4294967296',
        '--mount=type=bind,source={artifact_dir},target=/artifacts',
        $image,
        'php', '/drove/benchmarks/corpus/native-benchmark-measure.php',
        '/artifacts/{job_file}', '/artifacts/{artifact_file}', '/artifacts/{raw_file}', '--',
        'php', '/drove/benchmarks/corpus/native-benchmark-runner.php',
        $corpus, '{cohort}', $runner, '{processes}',
    ];
}

/**
 * @param  list<string>  $command
 * @param  array{cpu_cores: float, memory_bytes: int}  $quotas
 */
function nativeBenchmarkDockerImage(array $command, array $quotas, string $corpus, string $runner): string
{
    $image = $command[9] ?? null;
    nativeBenchmarkRequire(
        is_string($image) && $command === nativeBenchmarkDockerCommand($image, $quotas, $corpus, $runner),
        "$corpus $runner command must use the exact fresh, networkless, quota-controlled benchmark container.",
    );

    return $image;
}

function nativeBenchmarkNullDevice(): string
{
    return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
}

function nativeBenchmarkRequire(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
