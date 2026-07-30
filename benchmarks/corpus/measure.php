<?php

declare(strict_types=1);

if ($argc < 5 || $argv[3] !== '--') {
    fwrite(STDERR, "usage: measure.php MEASUREMENT_JSON RAW_LOG -- COMMAND...\n");
    exit(2);
}

[, $measurementPath, $rawPath] = $argv;
$command = array_slice($argv, 4);
$runnerExecutable = str_replace('\\', '/', $command[1] ?? '');

if (preg_match('#^(?:bin|vendor/bin)/(?:drove|pest|phpunit)$#', $runnerExecutable) !== 1) {
    $runnerExecutable = '[OTHER]';
}

$controllersPath = '/sys/fs/cgroup/cgroup.controllers';
$peakPath = '/sys/fs/cgroup/memory.peak';

try {
    $controllers = file_get_contents($controllersPath);
    $membership = file_get_contents('/proc/self/cgroup');

    if ($controllers === false
        || preg_match('/(?:^|\s)memory(?:\s|$)/', $controllers) !== 1
        || $membership === false
        || preg_match('/^0::/m', $membership) !== 1) {
        throw new RuntimeException('Corpus measurement requires a cgroup-v2 memory controller.');
    }

    readPeak($peakPath);

    $raw = fopen($rawPath, 'xb');

    if ($raw === false) {
        throw new RuntimeException("Cannot create raw log $rawPath");
    }

    $started = hrtime(true);

    try {
        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => $raw,
                2 => $raw,
            ],
            $pipes,
            options: ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start corpus command.');
        }

        $exitCode = proc_close($process);
    } finally {
        fclose($raw);
    }

    if (! is_int($exitCode) || $exitCode < 0) {
        throw new RuntimeException('Cannot resolve corpus command exit code.');
    }

    $measurement = [
        'schema_version' => 1,
        'clock' => 'monotonic',
        'memory_source' => 'cgroup-v2:/sys/fs/cgroup/memory.peak',
        'runner_executable' => $runnerExecutable,
        'wall_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
        'container_peak_memory_bytes' => readPeak($peakPath),
        'exit_code' => $exitCode,
        'platform' => [
            'os_family' => PHP_OS_FAMILY,
            'os' => PHP_OS,
            'architecture' => php_uname('m'),
            'php' => PHP_VERSION,
        ],
    ];

    $encoded = json_encode(
        $measurement,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;

    if (file_put_contents($measurementPath, $encoded, LOCK_EX) === false) {
        throw new RuntimeException("Cannot write measurement $measurementPath");
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage().PHP_EOL);
    exit(2);
}

exit($exitCode);

function readPeak(string $path): int
{
    $value = file_get_contents($path);
    $peak = $value === false
        ? false
        : filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

    if (! is_int($peak)) {
        throw new RuntimeException('Cannot read numeric cgroup-v2 memory.peak.');
    }

    return $peak;
}
