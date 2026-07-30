<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

require __DIR__.'/vendor/autoload.php';

$expect = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$execute = static function (string ...$command): array {
    $process = proc_open(array_values($command), [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start a coverage proof command.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit' => proc_close($process),
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
};
$run = static fn (string ...$arguments): array => $execute(
    PHP_BINARY,
    __DIR__.'/vendor/bin/drove',
    '--pest',
    ...$arguments,
);
$readJson = static function (string $path): array {
    $decoded = json_decode(
        (string) file_get_contents($path),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (! is_array($decoded)) {
        throw new RuntimeException('Expected a JSON object in '.$path.'.');
    }

    return $decoded;
};
$coverageProjection = static function (string $path): array {
    $serialized = require $path;
    $coverage = is_array($serialized) ? ($serialized['codeCoverage'] ?? null) : null;

    if (! $coverage instanceof ProcessedCodeCoverageData) {
        throw new RuntimeException('Coverage report is missing valid processed coverage data.');
    }

    $lines = $coverage->lineCoverage();
    ksort($lines, SORT_STRING);

    foreach ($lines as &$fileLines) {
        ksort($fileLines, SORT_NUMERIC);

        foreach ($fileLines as &$testIds) {
            if (is_array($testIds)) {
                sort($testIds, SORT_STRING);
            }
        }
    }

    unset($fileLines, $testIds);

    return $lines;
};
$revision = getenv('DROVE_EVIDENCE_REVISION');
$expect(
    is_string($revision) && preg_match('/^[0-9a-f]{40}$/', $revision) === 1,
    'DROVE_EVIDENCE_REVISION must be the exact 40-character Git SHA.',
);
$expect(
    extension_loaded('pcov')
        && ini_get('pcov.enabled') === '1'
        && phpversion('pcov') === '1.0.12'
        && ! extension_loaded('xdebug'),
    'The coverage proof requires PCOV 1.0.12 enabled without Xdebug.',
);

$workspace = sys_get_temp_dir().'/drove-coverage-proof-'.bin2hex(random_bytes(8));
$expect(mkdir($workspace, 0700), 'Could not create the coverage proof workspace.');
$concurrency = [1, 2, 4, 8, 16, 30];

try {
    $baselinePath = $workspace.'/phpunit.cov';
    $baselineRun = $execute(
        PHP_BINARY,
        __DIR__.'/vendor/bin/phpunit',
        '--configuration=phpunit.xml',
        '--coverage-php='.$baselinePath,
        'tests/CoverageAggregationTest.php',
    );
    $expect(
        $baselineRun['exit'] === 0 && is_file($baselinePath),
        'PHPUnit coverage baseline failed: '.$baselineRun['stderr'],
    );
    $baseline = $coverageProjection($baselinePath);
    $expect($baseline !== [], 'PHPUnit coverage baseline was empty.');
    $projectionHash = hash(
        'sha256',
        json_encode($baseline, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
    $matrix = [];

    foreach ($concurrency as $processes) {
        fwrite(STDERR, 'coverage-proof: C'.$processes.PHP_EOL);
        $path = $workspace.'/c'.$processes.'.cov';
        $coverageRun = $run(
            '--parallel',
            '--processes='.$processes,
            '--coverage-php='.$path,
            'tests/CoverageAggregationTest.php',
        );
        $expect(
            $coverageRun['exit'] === 0 && is_file($path),
            'Drove C'.$processes.' coverage failed: '.$coverageRun['stderr'],
        );
        $expect(
            $coverageProjection($path) === $baseline,
            'Drove C'.$processes.' covered-line data drifted from PHPUnit.',
        );
        $matrix['c'.$processes] = $projectionHash;
    }

    fwrite(STDERR, 'coverage-proof: mixed terminal states'.PHP_EOL);
    $mixedPath = $workspace.'/mixed.cov';
    $mixedReplayPath = $workspace.'/mixed.json';
    $mixed = $run(
        '--parallel',
        '--processes=4',
        '--drove-timeout-ms=500',
        '--replay='.$mixedReplayPath,
        '--coverage-php='.$mixedPath,
        'tests/CoverageAggregationTest.php',
        'tests/FailingTest.php',
        'unsupported/NativeSkippedTest.php',
        'tests/SlowTest.php',
        'unsupported/CrashingTest.php',
    );
    $expect(
        $mixed['exit'] === 1
            && is_file($mixedPath)
            && is_file($mixedReplayPath)
            && $coverageProjection($mixedPath) === $baseline,
        'Mixed terminal states corrupted or lost the coverage report: '.$mixed['stderr'],
    );
    $mixedReplay = $readJson($mixedReplayPath);
    $failureKinds = array_column($mixedReplay['result']['failures'] ?? [], 'kind');
    sort($failureKinds, SORT_STRING);
    $expect(
        ($mixedReplay['result']['counts'] ?? null) === [
            'failed' => 3,
            'passed' => 5,
            'skipped' => 1,
        ]
            && $failureKinds === [
                'assertion_failure',
                'signal_termination',
                'timeout',
            ],
        'Mixed coverage run did not retain failed, skipped, timed-out, and crashed states.',
    );

    fwrite(STDERR, 'coverage-proof: explicit rejection'.PHP_EOL);
    $noDriverPath = $workspace.'/no-driver.cov';
    $noDriver = $execute(
        PHP_BINARY,
        '-d',
        'pcov.enabled=0',
        __DIR__.'/vendor/bin/drove',
        '--pest',
        '--coverage-php='.$noDriverPath,
        'tests/CoverageAggregationTest.php',
    );
    $expect(
        $noDriver['exit'] === 2
            && ! file_exists($noDriverPath)
            && str_contains(
                $noDriver['stderr'],
                'Drove could not initialize the requested code coverage driver.',
            ),
        'Coverage without a driver did not fail explicitly before report creation.',
    );
    $unsupported = $run('--coverage', 'tests/CoverageAggregationTest.php');
    $expect(
        $unsupported['exit'] === 2
            && str_contains($unsupported['stderr'], '--coverage mode is not supported yet'),
        'The unsupported bare --coverage switch did not fail explicitly.',
    );
    $nativeUnsupported = $execute(
        PHP_BINARY,
        __DIR__.'/vendor/bin/drove',
        '--coverage',
        'tests/CoverageAggregationTest.php',
    );
    $expect(
        $nativeUnsupported['exit'] === 2
            && str_contains(
                $nativeUnsupported['stderr'],
                'Drove native: Unknown native option --coverage.',
            ),
        'The native frontend did not reject coverage explicitly.',
    );

    $coveredLines = 0;

    foreach ($baseline as $fileLines) {
        foreach ($fileLines as $testIds) {
            if (is_array($testIds) && $testIds !== []) {
                $coveredLines++;
            }
        }
    }

    fwrite(STDOUT, json_encode([
        'schema' => 1,
        'status' => 'passed',
        'revision' => $revision,
        'frontend' => 'pest-phpunit-bridge',
        'platform' => [
            'os_family' => PHP_OS_FAMILY,
            'architecture' => php_uname('m'),
            'php' => PHP_VERSION,
        ],
        'driver' => [
            'name' => 'pcov',
            'version' => phpversion('pcov'),
            'mode' => 'line',
        ],
        'reference' => 'phpunit',
        'projection_sha256' => $projectionHash,
        'covered_files' => count($baseline),
        'covered_lines' => $coveredLines,
        'matrix' => $matrix,
        'mixed_terminal_states' => [
            'counts' => $mixedReplay['result']['counts'],
            'failure_kinds' => $failureKinds,
            'report' => 'valid',
        ],
        'rejections' => [
            'no_driver' => 'explicit',
            'bare_coverage' => 'explicit',
            'native_frontend' => 'explicit',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
} finally {
    foreach (glob($workspace.'/*') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($workspace);
}
