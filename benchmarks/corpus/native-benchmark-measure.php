<?php

declare(strict_types=1);

require __DIR__.'/native-benchmark.php';

try {
    $arguments = $_SERVER['argv'] ?? [];
    $separator = array_search('--', $arguments, true);
    nativeBenchmarkRequire(
        count($arguments) >= 6 && $separator === 4,
        'Usage: php native-benchmark-measure.php JOB_JSON OBSERVATION_JSON RAW_JSON -- COMMAND...',
    );
    nativeBenchmarkRequire(PHP_OS_FAMILY === 'Linux', 'Native benchmark memory measurement requires Linux procfs and cgroup v2.');
    $jobPath = $arguments[1];
    $observationPath = $arguments[2];
    $rawPath = $arguments[3];
    nativeBenchmarkRequire(! file_exists($observationPath), "Observation already exists: $observationPath.");
    $job = nativeBenchmarkReadJson($jobPath);
    $command = array_values(array_slice($arguments, 5));
    nativeBenchmarkRequire($command !== [] && count(array_filter($command, 'is_string')) === count($command), 'Measured command is invalid.');
    $droveRevision = nativeBenchmarkVerifyImageRevision('/usr/local/share/drove-revision', $job['drove_revision'] ?? null);
    $cgroup = nativeBenchmarkCgroup();
    $quotas = nativeBenchmarkCgroupQuotas($cgroup);
    $peakBefore = nativeBenchmarkCgroupInt($cgroup.'/memory.peak');
    $eventsBefore = nativeBenchmarkCgroupEvents($cgroup.'/memory.events');
    $pipes = [];
    $started = hrtime(true);
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        options: ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Measured command could not start.');
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $rootPid = null;
    $peakRss = 0;
    $peakPss = 0;
    $sampleCount = 0;
    $lastStatus = null;

    do {
        $status = proc_get_status($process);
        $lastStatus = $status;
        $stdoutChunk = stream_get_contents($pipes[1]);
        $stderrChunk = stream_get_contents($pipes[2]);
        $stdout .= is_string($stdoutChunk) ? $stdoutChunk : '';
        $stderr .= is_string($stderrChunk) ? $stderrChunk : '';

        if ($rootPid === null && $status['pid'] > 0) {
            $rootPid = $status['pid'];
        }

        if (is_int($rootPid)) {
            $rss = 0;
            $pss = 0;
            $sampled = 0;

            foreach (nativeBenchmarkProcessTree($rootPid) as $pid) {
                $memory = nativeBenchmarkProcessMemory($pid);

                if ($memory === null) {
                    continue;
                }

                $rss += $memory['rss_bytes'];
                $pss += $memory['pss_bytes'];
                $sampled++;
            }

            if ($sampled > 0) {
                $sampleCount++;
                $peakRss = max($peakRss, $rss);
                $peakPss = max($peakPss, $pss);
            }
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
    $wallMs = round((hrtime(true) - $started) / 1_000_000, 3);
    $eventsAfter = nativeBenchmarkCgroupEvents($cgroup.'/memory.events');
    $eventDelta = [];

    foreach (array_unique([...array_keys($eventsBefore), ...array_keys($eventsAfter)]) as $event) {
        $eventDelta[$event] = max(0, ($eventsAfter[$event] ?? 0) - ($eventsBefore[$event] ?? 0));
    }

    ksort($eventDelta, SORT_STRING);
    nativeBenchmarkWriteJson($rawPath, [
        'schema_version' => 1,
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ]);
    nativeBenchmarkRequire($exitCode === 0, "Measured command failed with exit $exitCode; inspect $rawPath.");
    nativeBenchmarkRequire($stderr === '', "Measured command wrote to stderr; inspect $rawPath.");
    nativeBenchmarkRequire($sampleCount > 0 && $peakRss > 0 && $peakPss > 0, 'Measured command completed without a stable RSS/PSS sample.');
    $proof = json_decode($stdout, true, 128, JSON_THROW_ON_ERROR);
    nativeBenchmarkRequire(is_array($proof) && ! array_is_list($proof) && ($proof['schema_version'] ?? null) === 1, 'Measured command did not emit a native benchmark proof object.');
    $hostname = gethostname();
    nativeBenchmarkRequire(is_string($hostname) && trim($hostname) !== '', 'Cannot identify the fresh benchmark container.');
    $observation = [
        'schema_version' => 1,
        'job_id' => $job['job_id'] ?? null,
        'corpus' => $job['corpus'] ?? null,
        'cohort' => $job['cohort'] ?? null,
        'cohort_mode' => $job['cohort_mode'] ?? null,
        'runner' => $job['runner'] ?? null,
        'requested_processes' => $job['requested_processes'] ?? null,
        'repetition' => $job['repetition'] ?? null,
        'source_revision' => $job['source_revision'] ?? null,
        'drove_revision' => $droveRevision,
        'lock_sha256' => $job['lock_sha256'] ?? null,
        'image_id' => $job['image_id'] ?? null,
        'command_sha256' => $job['command_sha256'] ?? null,
        'isolation_id' => $hostname,
        'quotas' => $quotas,
        'outcome' => $proof['outcome'] ?? null,
        'timing' => [
            'wall_ms' => $wallMs,
            'phases_ms' => $proof['timing']['phases_ms'] ?? null,
        ],
        'topology' => $proof['topology'] ?? null,
        'memory' => [
            'sample_count' => $sampleCount,
            'aggregate_peak_rss_bytes' => $peakRss,
            'aggregate_peak_pss_bytes' => $peakPss,
            'cgroup_peak_before_bytes' => $peakBefore,
            'cgroup_peak_memory_bytes' => nativeBenchmarkCgroupInt($cgroup.'/memory.peak'),
            'cgroup_events_delta' => $eventDelta,
        ],
        'platform' => [
            'os' => PHP_OS,
            'architecture' => php_uname('m'),
            'php' => PHP_VERSION,
            'kernel' => php_uname('r'),
        ],
    ];
    nativeBenchmarkValidateObservation($observation, $job);
    nativeBenchmarkWriteJson($observationPath, $observation);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage().PHP_EOL);
    exit(2);
}

/**
 * @return list<int>
 */
function nativeBenchmarkProcessTree(int $rootPid): array
{
    $pending = [$rootPid];
    $seen = [];

    while ($pending !== []) {
        $pid = array_pop($pending);

        if ($pid < 1 || isset($seen[$pid]) || ! is_dir('/proc/'.$pid)) {
            continue;
        }

        $seen[$pid] = true;
        $children = @file_get_contents('/proc/'.$pid.'/task/'.$pid.'/children');

        if (! is_string($children) || trim($children) === '') {
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
}

/**
 * @return array{rss_bytes: int, pss_bytes: int}|null
 */
function nativeBenchmarkProcessMemory(int $pid): ?array
{
    $status = @file_get_contents('/proc/'.$pid.'/status');
    $smaps = @file_get_contents('/proc/'.$pid.'/smaps_rollup');

    if (! is_string($status) || ! is_string($smaps)) {
        return null;
    }

    $rss = null;
    $pss = null;

    if (preg_match('/^VmRSS:\s+(\d+)\s+kB$/miD', $status, $match) === 1) {
        $rss = (int) $match[1] * 1024;
    }

    if (preg_match('/^Pss:\s+(\d+)\s+kB$/miD', $smaps, $match) === 1) {
        $pss = (int) $match[1] * 1024;
    }

    return is_int($rss) && is_int($pss)
        ? ['rss_bytes' => $rss, 'pss_bytes' => $pss]
        : null;
}

function nativeBenchmarkCgroup(): string
{
    $controllers = file_get_contents('/sys/fs/cgroup/cgroup.controllers');
    $membership = file_get_contents('/proc/self/cgroup');

    if (! is_string($controllers)
        || preg_match('/(?:^|\s)memory(?:\s|$)/', $controllers) !== 1
        || preg_match('/(?:^|\s)cpu(?:\s|$)/', $controllers) !== 1
        || ! is_string($membership)
        || preg_match('/^0::([^\r\n]*)$/mD', $membership, $match) !== 1) {
        throw new RuntimeException('Native benchmark measurement requires cgroup v2 CPU and memory controllers.');
    }

    $relative = trim($match[1], '/');
    $path = '/sys/fs/cgroup'.($relative === '' ? '' : '/'.$relative);

    if (! is_file($path.'/memory.peak')) {
        $path = '/sys/fs/cgroup';
    }

    nativeBenchmarkRequire(is_file($path.'/memory.peak') && is_file($path.'/cpu.max') && is_file($path.'/memory.max'), 'Cannot resolve the benchmark cgroup.');

    return $path;
}

/**
 * @return array{cpu_cores: float, memory_bytes: int}
 */
function nativeBenchmarkCgroupQuotas(string $cgroup): array
{
    $cpu = file_get_contents($cgroup.'/cpu.max');
    $memory = file_get_contents($cgroup.'/memory.max');

    if (! is_string($cpu) || ! is_string($memory)) {
        throw new RuntimeException('Cannot read benchmark cgroup quotas.');
    }

    $parts = preg_split('/\s+/', trim($cpu));

    if (! is_array($parts) || count($parts) !== 2 || $parts[0] === 'max') {
        throw new RuntimeException('Native benchmark CPU quota must be finite.');
    }

    $quota = filter_var($parts[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $period = filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $memoryBytes = filter_var(trim($memory), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (! is_int($quota) || ! is_int($period) || ! is_int($memoryBytes)) {
        throw new RuntimeException('Native benchmark cgroup quotas are invalid or unlimited.');
    }

    return ['cpu_cores' => round($quota / $period, 6), 'memory_bytes' => $memoryBytes];
}

function nativeBenchmarkCgroupInt(string $path): int
{
    $contents = file_get_contents($path);
    $value = is_string($contents)
        ? filter_var(trim($contents), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
        : false;

    if (! is_int($value)) {
        throw new RuntimeException("Cannot read numeric cgroup metric $path.");
    }

    return $value;
}

/**
 * @return array<string, int>
 */
function nativeBenchmarkCgroupEvents(string $path): array
{
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException("Cannot read cgroup events $path.");
    }

    $events = [];

    foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
        if (preg_match('/^([a-z_]+)\s+(\d+)$/D', $line, $match) === 1) {
            $events[$match[1]] = (int) $match[2];
        }
    }

    ksort($events, SORT_STRING);
    nativeBenchmarkRequire(isset($events['oom'], $events['oom_kill']), 'Cgroup memory events do not expose OOM counters.');

    return $events;
}
