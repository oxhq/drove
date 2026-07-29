<?php

declare(strict_types=1);

$run = static function (string ...$arguments): array {
    $command = [PHP_BINARY, __DIR__.'/vendor/bin/drove', ...$arguments];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the installed Drove CLI.');
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
$expect = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$markerPath = sys_get_temp_dir().'/drove-phase-two-compatibility-'.getmypid();
@unlink($markerPath);
putenv('DROVE_COMPATIBILITY_MARKER='.$markerPath);
$cases = [
    'path' => $run('tests/OtherTest.php'),
    'filter' => $run('tests/FastTest.php', '--filter=alpha'),
    'group' => $run('tests', '--group=slow'),
    'exclude_group' => $run('tests/FastTest.php', '--exclude-group=slow'),
    'testsuite' => $run('--testsuite=Other'),
    'parallel' => $run('--parallel', '--processes=2', 'tests/FastTest.php'),
    'compatibility_c1' => $run('--parallel', '--processes=1', 'tests/CompatibilityTest.php'),
    'compatibility_c8' => $run('--parallel', '--processes=8', 'tests/CompatibilityTest.php'),
    'slow' => $run('tests/SlowTest.php'),
    'failure' => $run('tests/FailingTest.php'),
    'coverage' => $run('--coverage'),
    'coverage_text' => $run('--coverage-text'),
    'process_isolation' => $run('--process-isolation'),
    'ordinary_phpunit' => $run('unsupported/OrdinaryPhpUnitTest.php'),
    'dependency' => $run('unsupported/DependencyTest.php'),
    'process_isolation_metadata' => $run('unsupported/ProcessIsolationTest.php'),
    'static_lifecycle' => $run('unsupported/StaticLifecycleTest.php'),
    'xml_process_isolation' => $run('--configuration=unsupported/process-isolation.xml'),
    'xml_enforce_time_limit' => $run('--configuration=unsupported/enforce-time-limit.xml'),
];
$markers = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
@unlink($markerPath);
putenv('DROVE_COMPATIBILITY_MARKER');

foreach ([
    'path',
    'filter',
    'group',
    'exclude_group',
    'testsuite',
    'parallel',
    'compatibility_c1',
    'compatibility_c8',
    'slow',
] as $name) {
    $expect($cases[$name]['exit'] === 0, $name.' did not exit successfully: '.$cases[$name]['stderr']);
}

$expect(
    str_contains($cases['path']['stdout'], 'gamma other')
        && ! str_contains($cases['path']['stdout'], 'alpha fast'),
    'Path selection drifted.',
);
$expect(
    str_contains($cases['filter']['stdout'], 'alpha fast')
        && str_contains($cases['filter']['stdout'], 'alpha-output')
        && ! str_contains($cases['filter']['stdout'], 'beta slow'),
    'Filter selection or stdout rendering drifted.',
);
$expect(
    str_contains($cases['group']['stdout'], 'beta slow')
        && ! str_contains($cases['group']['stdout'], 'alpha fast'),
    'Group selection drifted.',
);
$expect(
    str_contains($cases['exclude_group']['stdout'], 'alpha fast')
        && ! str_contains($cases['exclude_group']['stdout'], 'beta slow'),
    'Excluded group selection drifted.',
);
$expect(
    str_contains($cases['testsuite']['stdout'], 'gamma other')
        && ! str_contains($cases['testsuite']['stdout'], 'alpha fast'),
    'Testsuite selection drifted.',
);
$alpha = strpos($cases['parallel']['stdout'], 'alpha fast');
$beta = strpos($cases['parallel']['stdout'], 'beta slow');
$expect(
    $alpha !== false && $beta !== false && $alpha < $beta,
    'Parallel rendering drifted from discovery order.',
);
$compatibilityOutput = $cases['compatibility_c1']['stdout'];
$semanticOutput = static fn (string $output): string => preg_replace(
    '/\ADrove [^\r\n]+\R/',
    '',
    $output,
) ?? throw new RuntimeException('Unable to normalize Drove output.');

$expect(
    str_contains($compatibilityOutput, 'runs a named dataset')
        && str_contains($compatibilityOutput, 'named:10')
        && str_contains($compatibilityOutput, 'runs a positional dataset')
        && str_contains($compatibilityOutput, 'positional:20')
        && str_contains($compatibilityOutput, 'preserves an installed skip')
        && str_contains($compatibilityOutput, 'installed skip')
        && str_contains($compatibilityOutput, 'preserves an installed todo'),
    'The installed compatibility surface did not render the expected cases.',
);
$expect(
    $semanticOutput($cases['compatibility_c1']['stdout'])
        === $semanticOutput($cases['compatibility_c8']['stdout']),
    'The installed Drover run changed semantics between concurrency one and eight.',
);
$expectedMarkers = [
    'before_all' => 2,
    'before_each' => 8,
    'set_up' => 8,
    'tear_down' => 8,
    'after_each' => 8,
    'after_all' => 2,
];

$expect(is_array($markers), 'The installed compatibility marker is unreadable.');

foreach ($expectedMarkers as $marker => $count) {
    $expect(
        count(array_keys($markers, $marker, true)) === $count,
        sprintf('The installed %s lifecycle ran an unexpected number of times.', $marker),
    );
}

$expect(
    $markers[0] === 'before_all'
        && $markers[array_key_last($markers)] === 'after_all',
    'The installed Drove scope lifecycle order drifted.',
);
$expect(
    str_contains($cases['slow']['stdout'], 'passes after one second'),
    'A passing test inherited an implicit one-second timeout.',
);
$expect(
    $cases['failure']['exit'] === 1
        && str_contains($cases['failure']['stdout'], 'fails conventionally'),
    'Test failures did not use exit code 1.',
);
$expect(
    $cases['coverage']['exit'] === 2
        && str_contains($cases['coverage']['stderr'], '--coverage mode is not supported yet'),
    'Unsupported coverage did not use an explicit exit-2 diagnostic.',
);
$expect(
    $cases['coverage_text']['exit'] === 2
        && str_contains($cases['coverage_text']['stderr'], '--coverage-text mode is not supported yet'),
    'Unsupported coverage output did not use an explicit exit-2 diagnostic.',
);
$expect(
    $cases['process_isolation']['exit'] === 2
        && str_contains($cases['process_isolation']['stderr'], '--process-isolation mode is not supported yet'),
    'Unsupported process isolation did not use an explicit exit-2 diagnostic.',
);
$expect(
    $cases['ordinary_phpunit']['exit'] === 2
        && str_contains($cases['ordinary_phpunit']['stderr'], 'Drove only supports generated Pest cases'),
    'An ordinary PHPUnit case was not rejected explicitly.',
);
$expect(
    $cases['dependency']['exit'] === 2
        && str_contains($cases['dependency']['stderr'], 'Drove does not support test dependencies yet'),
    'Dependency metadata was not rejected explicitly.',
);
$expect(
    $cases['process_isolation_metadata']['exit'] === 2
        && str_contains($cases['process_isolation_metadata']['stderr'], 'process-isolation metadata'),
    'Process-isolation metadata was not rejected explicitly.',
);
$expect(
    $cases['static_lifecycle']['exit'] === 2
        && str_contains($cases['static_lifecycle']['stderr'], 'custom static TestCase lifecycle methods'),
    'Custom static TestCase lifecycle was not rejected explicitly.',
);
$expect(
    $cases['xml_process_isolation']['exit'] === 2
        && str_contains($cases['xml_process_isolation']['stderr'], 'processIsolation from PHPUnit XML'),
    'XML processIsolation was not rejected explicitly.',
);
$expect(
    $cases['xml_enforce_time_limit']['exit'] === 2
        && str_contains($cases['xml_enforce_time_limit']['stderr'], 'enforceTimeLimit from PHPUnit XML'),
    'XML enforceTimeLimit was not rejected explicitly.',
);

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'backend' => 'drover',
    'concurrency' => [1, 8],
    'selections' => ['path', 'filter', 'group', 'exclude-group', 'testsuite'],
    'exit_codes' => [
        'passed' => $cases['path']['exit'],
        'failed' => $cases['failure']['exit'],
        'unsupported' => $cases['coverage']['exit'],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
