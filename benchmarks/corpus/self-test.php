<?php

declare(strict_types=1);

$directory = sys_get_temp_dir().'/drove-corpus-self-'.bin2hex(random_bytes(8));

if (! mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create corpus self-test directory.');
}

try {
    testMeasurementWrapper($directory);

    $summary = "Tests: 71 skipped, 714 passed (785)\nAssertions: 1690\n";
    $baselinePath = normalizeFixture(
        $directory,
        'baseline',
        'baseline',
        1,
        $summary,
        returnPath: true,
    );
    $drovePath = normalizeFixture(
        $directory,
        'drove-8',
        'drove',
        8,
        $summary,
        returnPath: true,
    );
    $normalized = json_decode(
        (string) file_get_contents($drovePath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (($normalized['schema_version'] ?? null) !== 2
        || ($normalized['outcome']['tests'] ?? null) !== 785
        || ($normalized['observed_lanes'] ?? null) !== 8
        || ($normalized['metrics']['replay_php_peak_memory_bytes'] ?? null) !== 33_554_432) {
        throw new RuntimeException('Normalizer omitted benchmark v2 telemetry.');
    }

    $droveOnePath = normalizeFixture(
        $directory,
        'drove-1',
        'drove',
        1,
        $summary,
        returnPath: true,
    );
    [$report, $exit] = runCommand([
        PHP_BINARY,
        __DIR__.'/verify.php',
        $baselinePath,
        $droveOnePath,
        $drovePath,
    ]);

    if ($exit !== 0) {
        throw new RuntimeException("Verifier self-test rejected matching results:\n$report");
    }

    $decodedReport = json_decode($report, true, flags: JSON_THROW_ON_ERROR);
    $groups = $decodedReport['diagnostic_comparisons']['groups'] ?? [];

    if (($decodedReport['diagnostic_comparisons']['thresholds_applied'] ?? null) !== false
        || ($decodedReport['drove_revision'] ?? null) !== str_repeat('a', 40)
        || ($decodedReport['platform']['php'] ?? null) !== PHP_VERSION
        || count($groups) !== 3
        || ($groups[0]['runs'] ?? null) !== 1
        || ($groups[0]['runner_identity']['executable'] ?? null) !== 'bin/pest'
        || ($groups[0]['outcome']['tests'] ?? null) !== 785
        || ($groups[0]['wall_ms']['min'] ?? null) !== ($groups[0]['wall_ms']['median'] ?? null)
        || ($groups[0]['wall_ms']['min'] ?? null) !== ($groups[0]['wall_ms']['max'] ?? null)) {
        throw new RuntimeException('Verifier did not emit diagnostic-only grouped summaries.');
    }

    [$completeReport, $completeExit] = runCommand([
        PHP_BINARY,
        __DIR__.'/verify.php',
        '--complete',
        $baselinePath,
        $droveOnePath,
        $drovePath,
    ]);

    if ($completeExit === 0 || ! str_contains($completeReport, 'complete report cohort set mismatch')) {
        throw new RuntimeException('Verifier accepted an incomplete full-corpus report.');
    }

    $impossible = json_decode(
        (string) file_get_contents($drovePath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $impossible['observed_lanes'] = 9;
    file_put_contents($drovePath, json_encode($impossible, JSON_THROW_ON_ERROR));
    [$report, $exit] = runCommand([
        PHP_BINARY,
        __DIR__.'/verify.php',
        $baselinePath,
        $droveOnePath,
        $drovePath,
    ]);

    if ($exit === 0 || ! str_contains($report, 'observed_lanes must equal requested_processes')) {
        throw new RuntimeException('Verifier accepted impossible observed lane telemetry.');
    }
} finally {
    foreach (glob($directory.'/*') ?: [] as $path) {
        unlink($path);
    }

    rmdir($directory);
}

fwrite(STDOUT, "corpus self-test passed\n");

function testMeasurementWrapper(string $directory): void
{
    $measurement = "$directory/wrapper.measurement.json";
    $raw = "$directory/wrapper.raw.log";
    [$output, $exit] = runCommand([
        PHP_BINARY,
        __DIR__.'/measure.php',
        $measurement,
        $raw,
        '--',
        PHP_BINARY,
        '-r',
        'fwrite(STDOUT, "wrapper-ok\n");',
    ]);
    $cgroupV2 = PHP_OS_FAMILY === 'Linux'
        && is_file('/sys/fs/cgroup/cgroup.controllers')
        && is_file('/sys/fs/cgroup/memory.peak');

    if (! $cgroupV2) {
        if ($exit === 0) {
            throw new RuntimeException('Measurement wrapper did not fail closed without cgroup v2.');
        }

        return;
    }

    if ($exit !== 0) {
        throw new RuntimeException("Measurement wrapper self-test failed:\n$output");
    }

    $decoded = json_decode(
        (string) file_get_contents($measurement),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (($decoded['clock'] ?? null) !== 'monotonic'
        || ($decoded['runner_executable'] ?? null) !== '[OTHER]'
        || ($decoded['wall_ms'] ?? 0) <= 0
        || ($decoded['container_peak_memory_bytes'] ?? 0) <= 0
        || file_get_contents($raw) !== "wrapper-ok\n") {
        throw new RuntimeException('Measurement wrapper emitted invalid telemetry.');
    }
}

/**
 * @return array<string, mixed>|string
 */
function normalizeFixture(
    string $directory,
    string $name,
    string $runner,
    int $processes,
    string $rawContents,
    bool $returnPath = false,
): array|string {
    $raw = "$directory/$name.raw.log";
    $measurement = "$directory/$name.measurement.json";
    $replay = "$directory/$name.replay.json";
    $outputPath = "$directory/$name.json";
    $platform = [
        'os_family' => PHP_OS_FAMILY,
        'os' => PHP_OS,
        'architecture' => php_uname('m'),
        'php' => PHP_VERSION,
    ];
    file_put_contents($raw, $rawContents);
    file_put_contents($measurement, json_encode([
        'schema_version' => 1,
        'clock' => 'monotonic',
        'memory_source' => 'cgroup-v2:/sys/fs/cgroup/memory.peak',
        'runner_executable' => $runner === 'baseline' ? 'bin/pest' : 'bin/drove',
        'wall_ms' => $runner === 'baseline' ? 12.5 : 10.25,
        'container_peak_memory_bytes' => $runner === 'baseline' ? 67_108_864 : 134_217_728,
        'exit_code' => 0,
        'platform' => $platform,
    ], JSON_THROW_ON_ERROR));

    if ($runner === 'drove') {
        file_put_contents($replay, json_encode([
            'schema' => 1,
            'kind' => 'run',
            'duration_ms' => 9.75,
            'memory_peak_bytes' => 33_554_432,
            'platform' => $platform,
            'command' => ['processes' => $processes],
            'result' => [
                'exit_code' => 0,
                'observed_concurrency' => ['global' => $processes],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    [$output, $exit] = runCommand([
        PHP_BINARY,
        __DIR__.'/normalize.php',
        'pest',
        $runner,
        'nonserial',
        (string) $processes,
        '140',
        str_repeat('a', 40),
        '0',
        '1',
        $raw,
        $measurement,
        $runner === 'drove' ? $replay : '-',
        $outputPath,
    ]);

    if ($exit !== 0) {
        throw new RuntimeException("Normalizer self-test failed:\n$output");
    }

    if ($returnPath) {
        return $outputPath;
    }

    return json_decode(
        (string) file_get_contents($outputPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * @param  list<string>  $command
 * @return array{string, int}
 */
function runCommand(array $command): array
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        options: ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start corpus self-test command.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [$output, $exit];
}
