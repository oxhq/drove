<?php

declare(strict_types=1);

require __DIR__.'/support.php';

try {
    $arguments = $_SERVER['argv'] ?? [];
    $separator = array_search('--', $arguments, true);

    nativePhaseFiveAssert(
        count($arguments) >= 5 && $separator === 2,
        'Usage: php monitor.php output.json -- command [arguments...]',
    );
    $outputPath = $arguments[1];
    $command = [];

    foreach (array_slice($arguments, 3) as $argument) {
        nativePhaseFiveAssert(
            is_string($argument),
            'Native Phase 5 monitor received a non-string command argument.',
        );
        $command[] = $argument;
    }

    nativePhaseFiveAssert(
        is_string($outputPath)
            && $outputPath !== ''
            && $command !== [],
        'Native Phase 5 monitor received invalid arguments.',
    );
    nativePhaseFiveAssert(
        PHP_OS_FAMILY === 'Linux',
        'Native Phase 5 aggregate process monitoring requires Linux procfs.',
    );
    $directory = dirname($outputPath);
    nativePhaseFiveAssert(
        is_dir($directory)
            || (mkdir($directory, 0700, true) && is_dir($directory)),
        'Could not create the native Phase 5 monitor output directory.',
    );
    $phasePath = $outputPath.'.phase.'.getmypid();
    nativePhaseFiveAssert(
        ! file_exists($phasePath)
            && file_put_contents($phasePath, 'bootstrap', LOCK_EX) !== false,
        'Could not create the native Phase 5 phase sentinel.',
    );
    $populationAckPath = $outputPath.'.population-ack.'.getmypid();
    $populationAckTempPath = $populationAckPath.'.tmp';
    nativePhaseFiveAssert(
        ! file_exists($populationAckPath) && ! file_exists($populationAckTempPath),
        'Native Phase 5 population ACK path already exists.',
    );
    putenv('DROVE_PHASE5_PHASE_FILE='.$phasePath);
    putenv('DROVE_PHASE5_POPULATION_ACK_FILE='.$populationAckPath);
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2),
    );
    nativePhaseFiveAssert(
        is_resource($process),
        'Could not start the native Phase 5 monitored process.',
    );
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $global = [
        'sample_count' => 0,
        'discarded_unstable_sample_count' => 0,
        'aggregate_peak_rss_bytes' => 0,
        'aggregate_peak_pss_bytes' => 0,
        'aggregate_peak_swap_bytes' => 0,
        'peak_live_pids' => 0,
        'peak_live_pid_sample_count' => 0,
        'peak_live_pid_aggregate_peak_rss_bytes' => 0,
        'peak_live_pid_aggregate_peak_pss_bytes' => 0,
        'peak_live_pid_aggregate_peak_swap_bytes' => 0,
    ];
    $phases = [];
    $perProcess = [];
    $rootPid = null;
    $cgroupPath = null;
    $cgroupEventsBefore = null;
    $cgroupEventsAfter = null;
    $lastStatus = null;
    $stableExecutionPopulation = null;
    $stableExecutionPopulationSamples = 0;
    $acknowledgedPopulation = 0;

    /**
     * @return list<int>
     */
    $processTree = static function (int $root): array {
        $seen = [];
        $pending = [$root];

        while ($pending !== []) {
            $pid = array_pop($pending);
            if ($pid < 1) {
                continue;
            }
            if (isset($seen[$pid])) {
                continue;
            }
            if (! is_dir('/proc/'.$pid)) {
                continue;
            }

            $seen[$pid] = true;
            $children = @file_get_contents('/proc/'.$pid.'/task/'.$pid.'/children');
            if (! is_string($children)) {
                continue;
            }
            if (trim($children) === '') {
                continue;
            }

            foreach (preg_split('/\s+/', trim($children)) ?: [] as $child) {
                $childPid = (int) $child;

                if ($childPid > 0) {
                    $pending[] = $childPid;
                }
            }
        }

        $pids = array_map(intval(...), array_keys($seen));
        sort($pids, SORT_NUMERIC);

        return $pids;
    };

    /**
     * @return array{rss_bytes: int, pss_bytes: int, swap_bytes: int}|null
     */
    $memory = static function (int $pid): ?array {
        $status = @file_get_contents('/proc/'.$pid.'/status');
        $smaps = @file_get_contents('/proc/'.$pid.'/smaps_rollup');

        if (! is_string($status) || ! is_string($smaps)) {
            return null;
        }

        $rss = null;
        $pss = null;
        $swap = 0;

        foreach (preg_split('/\R/', $status) ?: [] as $line) {
            if (preg_match('/\AVmRSS:\s+(\d+)\s+kB\z/D', $line, $match) === 1) {
                $rss = (int) $match[1] * 1024;
            }

            if (preg_match('/\AVmSwap:\s+(\d+)\s+kB\z/D', $line, $match) === 1) {
                $swap = (int) $match[1] * 1024;
            }
        }

        foreach (preg_split('/\R/', $smaps) ?: [] as $line) {
            if (preg_match('/\APss:\s+(\d+)\s+kB\z/D', $line, $match) === 1) {
                $pss = (int) $match[1] * 1024;

                break;
            }
        }

        return is_int($rss) && is_int($pss)
            ? ['rss_bytes' => $rss, 'pss_bytes' => $pss, 'swap_bytes' => $swap]
            : null;
    };

    /**
     * @return array<string, int>|null
     */
    $readCgroupEvents = static function (?string $path): ?array {
        if (! is_string($path)) {
            return null;
        }

        $contents = @file_get_contents($path.'/memory.events');

        if (! is_string($contents)) {
            return null;
        }

        $events = [];

        foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
            if (preg_match('/\A([a-z_]+)\s+(\d+)\z/D', $line, $match) === 1) {
                $events[$match[1]] = (int) $match[2];
            }
        }

        ksort($events, SORT_STRING);

        return $events;
    };

    /**
     * @param  array<string, int>  $bucket
     */
    $recordPeakPopulation = static function (
        array &$bucket,
        int $sampledPids,
        int $aggregateRss,
        int $aggregatePss,
        int $aggregateSwap,
    ): void {
        if ($sampledPids > $bucket['peak_live_pids']) {
            $bucket['peak_live_pids'] = $sampledPids;
            $bucket['peak_live_pid_sample_count'] = 1;
            $bucket['peak_live_pid_aggregate_peak_rss_bytes'] = $aggregateRss;
            $bucket['peak_live_pid_aggregate_peak_pss_bytes'] = $aggregatePss;
            $bucket['peak_live_pid_aggregate_peak_swap_bytes'] = $aggregateSwap;

            return;
        }

        if ($sampledPids === $bucket['peak_live_pids']) {
            $bucket['peak_live_pid_sample_count']++;
            $bucket['peak_live_pid_aggregate_peak_rss_bytes'] = max(
                $bucket['peak_live_pid_aggregate_peak_rss_bytes'],
                $aggregateRss,
            );
            $bucket['peak_live_pid_aggregate_peak_pss_bytes'] = max(
                $bucket['peak_live_pid_aggregate_peak_pss_bytes'],
                $aggregatePss,
            );
            $bucket['peak_live_pid_aggregate_peak_swap_bytes'] = max(
                $bucket['peak_live_pid_aggregate_peak_swap_bytes'],
                $aggregateSwap,
            );
        }
    };

    do {
        $status = proc_get_status($process);
        $lastStatus = $status;

        if ($rootPid === null && $status['pid'] > 0) {
            $rootPid = $status['pid'];
            $cgroup = @file_get_contents('/proc/'.$rootPid.'/cgroup');

            if (is_string($cgroup)
                && preg_match('/(?:\A|\n)0::([^\r\n]+)(?:\r?\n|\z)/D', $cgroup, $match) === 1) {
                $relative = trim($match[1], '/');
                $cgroupPath = '/sys/fs/cgroup'.($relative === '' ? '' : '/'.$relative);
                $cgroupEventsBefore = $readCgroupEvents($cgroupPath);
            }
        }

        $stdoutChunk = stream_get_contents($pipes[1]);
        $stderrChunk = stream_get_contents($pipes[2]);
        $stdout .= is_string($stdoutChunk) ? $stdoutChunk : '';
        $stderr .= is_string($stderrChunk) ? $stderrChunk : '';
        $phase = nativePhaseFiveReadPhase($phasePath);
        $pids = is_int($rootPid) ? $processTree($rootPid) : [];
        $aggregateRss = 0;
        $aggregatePss = 0;
        $aggregateSwap = 0;
        $sampledPids = 0;
        $processSamples = [];

        foreach ($pids as $pid) {
            $sample = $memory($pid);

            if ($sample === null) {
                continue;
            }

            $sampledPids++;
            $aggregateRss += $sample['rss_bytes'];
            $aggregatePss += $sample['pss_bytes'];
            $aggregateSwap += $sample['swap_bytes'];
            $processSamples[$pid] = $sample;
        }

        $phaseAfter = nativePhaseFiveReadPhase($phasePath);
        $pidsAfter = is_int($rootPid) ? $processTree($rootPid) : [];
        $stableSample = $phaseAfter === $phase
            && $pidsAfter === $pids
            && $sampledPids === count($pids);

        if (! $stableSample || $phase !== 'execution' || $sampledPids < 1) {
            $stableExecutionPopulation = null;
            $stableExecutionPopulationSamples = 0;
        } else {
            if ($stableExecutionPopulation === $sampledPids) {
                $stableExecutionPopulationSamples++;
            } else {
                $stableExecutionPopulation = $sampledPids;
                $stableExecutionPopulationSamples = 1;
            }

            if ($stableExecutionPopulationSamples === 3
                && $sampledPids > $acknowledgedPopulation) {
                nativePhaseFiveWriteJson($populationAckTempPath, [
                    'schema' => 1,
                    'phase' => 'execution',
                    'live_pids' => $sampledPids,
                    'stable_sample_count' => $stableExecutionPopulationSamples,
                ]);
                nativePhaseFiveAssert(
                    rename($populationAckTempPath, $populationAckPath),
                    'Could not publish the native Phase 5 population ACK.',
                );
                $acknowledgedPopulation = $sampledPids;
            }
        }

        if (! $stableSample) {
            $global['discarded_unstable_sample_count']++;
        } elseif ($sampledPids > 0) {
            foreach ($processSamples as $pid => $sample) {
                $perProcess[$pid] ??= [
                    'pid' => $pid,
                    'peak_rss_bytes' => 0,
                    'peak_pss_bytes' => 0,
                    'peak_swap_bytes' => 0,
                ];
                $perProcess[$pid]['peak_rss_bytes'] = max(
                    $perProcess[$pid]['peak_rss_bytes'],
                    $sample['rss_bytes'],
                );
                $perProcess[$pid]['peak_pss_bytes'] = max(
                    $perProcess[$pid]['peak_pss_bytes'],
                    $sample['pss_bytes'],
                );
                $perProcess[$pid]['peak_swap_bytes'] = max(
                    $perProcess[$pid]['peak_swap_bytes'],
                    $sample['swap_bytes'],
                );
            }

            $global['sample_count']++;
            $global['aggregate_peak_rss_bytes'] = max(
                $global['aggregate_peak_rss_bytes'],
                $aggregateRss,
            );
            $global['aggregate_peak_pss_bytes'] = max(
                $global['aggregate_peak_pss_bytes'],
                $aggregatePss,
            );
            $global['aggregate_peak_swap_bytes'] = max(
                $global['aggregate_peak_swap_bytes'],
                $aggregateSwap,
            );
            $recordPeakPopulation(
                $global,
                $sampledPids,
                $aggregateRss,
                $aggregatePss,
                $aggregateSwap,
            );

            $phases[$phase] ??= [
                'sample_count' => 0,
                'aggregate_peak_rss_bytes' => 0,
                'aggregate_peak_pss_bytes' => 0,
                'aggregate_peak_swap_bytes' => 0,
                'peak_live_pids' => 0,
                'peak_live_pid_sample_count' => 0,
                'peak_live_pid_aggregate_peak_rss_bytes' => 0,
                'peak_live_pid_aggregate_peak_pss_bytes' => 0,
                'peak_live_pid_aggregate_peak_swap_bytes' => 0,
            ];
            $phases[$phase]['sample_count']++;
            $phases[$phase]['aggregate_peak_rss_bytes'] = max(
                $phases[$phase]['aggregate_peak_rss_bytes'],
                $aggregateRss,
            );
            $phases[$phase]['aggregate_peak_pss_bytes'] = max(
                $phases[$phase]['aggregate_peak_pss_bytes'],
                $aggregatePss,
            );
            $phases[$phase]['aggregate_peak_swap_bytes'] = max(
                $phases[$phase]['aggregate_peak_swap_bytes'],
                $aggregateSwap,
            );
            $recordPeakPopulation(
                $phases[$phase],
                $sampledPids,
                $aggregateRss,
                $aggregatePss,
                $aggregateSwap,
            );
        }

        if (! $status['running']) {
            break;
        }

        usleep(1_000);
    } while (true);

    $stdoutChunk = stream_get_contents($pipes[1]);
    $stderrChunk = stream_get_contents($pipes[2]);
    $stdout .= is_string($stdoutChunk) ? $stdoutChunk : '';
    $stderr .= is_string($stderrChunk) ? $stderrChunk : '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedExitCode = proc_close($process);
    $exitCode = $lastStatus['exitcode'] >= 0
        ? $lastStatus['exitcode']
        : $closedExitCode;
    $cgroupEventsAfter = $readCgroupEvents($cgroupPath);
    @unlink($phasePath);
    @unlink($populationAckPath);
    @unlink($populationAckTempPath);
    nativePhaseFiveAssert(
        $exitCode === 0 && $stderr === '',
        sprintf(
            'Native Phase 5 monitored command failed with exit %d: %s',
            $exitCode,
            trim($stderr),
        ),
    );
    $summary = json_decode($stdout, true, 128, JSON_THROW_ON_ERROR);
    nativePhaseFiveAssert(
        is_array($summary)
            && ! array_is_list($summary)
            && ($summary['schema'] ?? null) === 1
            && ($summary['ok'] ?? null) === true
            && is_array($summary['telemetry'] ?? null),
        'Native Phase 5 monitored command did not emit a valid summary.',
    );
    ksort($phases, SORT_STRING);
    ksort($perProcess, SORT_NUMERIC);
    $eventDelta = null;

    if (is_array($cgroupEventsBefore) && is_array($cgroupEventsAfter)) {
        $eventDelta = [];

        foreach (array_unique([
            ...array_keys($cgroupEventsBefore),
            ...array_keys($cgroupEventsAfter),
        ]) as $key) {
            $eventDelta[$key] = max(
                0,
                ($cgroupEventsAfter[$key] ?? 0) - ($cgroupEventsBefore[$key] ?? 0),
            );
        }

        ksort($eventDelta, SORT_STRING);
    }

    $summary['telemetry']['process_monitor'] = [
        'schema' => 1,
        'measurement_source' => 'procfs',
        'poll_interval_us' => 1_000,
        'root_pid' => $rootPid,
        'global' => $global,
        'phases' => $phases,
        'captured_process_count' => count($perProcess),
        'per_process_peak' => array_values($perProcess),
        'cgroup_memory_events_delta' => $eventDelta,
    ];
    nativePhaseFiveWriteJson($outputPath, $summary);
    echo json_encode(
        $summary,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
} catch (Throwable $throwable) {
    if (isset($phasePath) && is_string($phasePath)) {
        @unlink($phasePath);
    }
    if (isset($populationAckPath) && is_string($populationAckPath)) {
        @unlink($populationAckPath);
    }
    if (isset($populationAckTempPath) && is_string($populationAckTempPath)) {
        @unlink($populationAckTempPath);
    }

    $acknowledgement = isset($acknowledgedPopulation)
        ? sprintf(
            ' [population_ack current=%s consecutive=%s published=%s]',
            json_encode(
                isset($stableExecutionPopulation) ? $stableExecutionPopulation : null,
            ),
            json_encode(
                isset($stableExecutionPopulationSamples)
                    ? $stableExecutionPopulationSamples
                    : null,
            ),
            json_encode($acknowledgedPopulation),
        )
        : '';
    fwrite(
        STDERR,
        $throwable::class.': '.$throwable->getMessage().$acknowledgement.PHP_EOL,
    );
    exit(1);
}
