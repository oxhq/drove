<?php

declare(strict_types=1);

$execute = static function (array $command): array {
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
$run = static fn (string ...$arguments): array => $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/drove',
    ...$arguments,
]);
$prepareCase = $execute([PHP_BINARY, __DIR__.'/prepare-case.php']);
$expect = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$markerPath = sys_get_temp_dir().'/drove-phase-two-compatibility-'.getmypid();
@unlink($markerPath);
putenv('DROVE_COMPATIBILITY_MARKER='.$markerPath);
$compatibilityC1 = $run('--parallel', '--processes=1', 'tests/CompatibilityTest.php');
$markers = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
file_put_contents($markerPath, '');
$namedLifecycle = $run('tests/CompatibilityTest.php', '--filter=runs a named dataset');
$namedMarkers = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
file_put_contents($markerPath, '');
$nestedLifecycle = $run('tests/CompatibilityTest.php', '--filter=runs a nested describe case');
$nestedMarkers = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
file_put_contents($markerPath, '');
$staticLifecycle = $run('unsupported/StaticLifecycleTest.php');
$staticMarkers = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
file_put_contents($markerPath, '');
$staticSetupFailure = $run('unsupported/StaticSetupFailureTest.php');
$staticSetupFailureMarkers = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
file_put_contents($markerPath, '');
$staticTeardownFailure = $run('unsupported/StaticTeardownFailureTest.php');
$staticTeardownFailureMarkers = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
file_put_contents($markerPath, '');
$cases = [
    'path' => $run('tests/OtherTest.php'),
    'filter' => $run('tests/FastTest.php', '--filter=alpha'),
    'group' => $run('tests', '--group=slow'),
    'exclude_group' => $run('tests/FastTest.php', '--exclude-group=slow'),
    'testsuite' => $run('--testsuite=Other'),
    'parallel' => $run('--parallel', '--processes=2', 'tests/FastTest.php'),
    'compatibility_c1' => $compatibilityC1,
    'compatibility_c8' => $run('--parallel', '--processes=8', 'tests/CompatibilityTest.php'),
    'slow' => $run('tests/SlowTest.php'),
    'failure' => $run('tests/FailingTest.php'),
    'coverage' => $run('--coverage'),
    'coverage_text' => $run('--coverage-text'),
    'process_isolation' => $run('--process-isolation'),
    'ordinary_phpunit' => $run('unsupported/OrdinaryPhpUnitTest.php'),
    'dependency' => $run('unsupported/DependencyTest.php'),
    'process_isolation_metadata' => $run('unsupported/ProcessIsolationTest.php'),
    'static_lifecycle' => $staticLifecycle,
    'static_setup_failure' => $staticSetupFailure,
    'static_teardown_failure' => $staticTeardownFailure,
    'xml_process_isolation' => $run('--configuration=unsupported/process-isolation.xml'),
    'xml_enforce_time_limit' => $run('--configuration=unsupported/enforce-time-limit.xml'),
];
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
    'static_lifecycle',
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
    $prepareCase['exit'] === 0
        && str_contains($prepareCase['stdout'], '"status": "passed"'),
    'The installed per-case preparation seam did not run before runBare: '.$prepareCase['stderr'],
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
    $alpha !== false
        && $beta !== false
        && $alpha < $beta
        && str_contains($cases['parallel']['stdout'], 'supports the it alias'),
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
        && str_contains($compatibilityOutput, 'preserves an installed todo')
        && str_contains($compatibilityOutput, 'runs a nested describe case'),
    'The installed compatibility surface did not render the expected cases.',
);
$expect(
    $semanticOutput($cases['compatibility_c1']['stdout'])
        === $semanticOutput($cases['compatibility_c8']['stdout']),
    'The installed Drover run changed semantics between concurrency one and eight.',
);
$expectedMarkers = [
    'before_all' => 1,
    'nested_before_all' => 1,
    'before_each' => 5,
    'nested_before_each' => 1,
    'set_up' => 5,
    'tear_down' => 5,
    'nested_after_each' => 1,
    'after_each' => 5,
    'nested_after_all' => 1,
    'after_all' => 1,
];

$expect(is_array($markers), 'The installed compatibility marker is unreadable.');

foreach ($expectedMarkers as $marker => $count) {
    $expect(
        count(array_keys($markers, $marker, true)) === $count,
        sprintf('The installed %s lifecycle ran an unexpected number of times.', $marker),
    );
}

$expect(
    $namedLifecycle['exit'] === 0
        && $namedMarkers === [
            'before_all',
            'set_up', 'before_each', 'body_named', 'after_each', 'tear_down',
            'after_all',
        ],
    'The installed file-level Pest and TestCase lifecycle order drifted.',
);
$expect(
    $nestedLifecycle['exit'] === 0
        && $nestedMarkers === [
            'before_all',
            'nested_before_all',
            'set_up', 'before_each', 'nested_before_each', 'body_nested',
            'after_each', 'nested_after_each', 'tear_down',
            'nested_after_all',
            'after_all',
        ],
    'The installed nested Pest and TestCase lifecycle order drifted.',
);
$expect(
    $staticMarkers === [
        'set_up_before_class',
        'static_before_all',
        'static_set_up',
        'static_before_each',
        'static_body',
        'static_after_each',
        'static_tear_down',
        'static_after_all',
        'tear_down_after_class',
    ],
    'The installed static TestCase lifecycle order drifted.',
);
$expect(
    $staticSetupFailure['exit'] === 1
        && $staticSetupFailureMarkers === ['failing_set_up_before_class']
        && str_contains($staticSetupFailure['stdout'], 'is blocked by class setup failure')
        && str_contains($staticSetupFailure['stdout'], 'class setup failed')
        && str_contains($staticSetupFailure['stdout'], '1 blocked'),
    'A failed static setup did not block only its file descendants.',
);
$expect(
    $staticTeardownFailure['exit'] === 1
        && $staticTeardownFailureMarkers === [
            'teardown_set_up_before_class',
            'teardown_before_all',
            'teardown_before_each',
            'teardown_body',
            'teardown_after_each',
            'teardown_after_all',
            'failing_tear_down_after_class',
        ]
        && str_contains($staticTeardownFailure['stdout'], '✓ passes before class teardown fails')
        && str_contains($staticTeardownFailure['stdout'], 'class teardown failed')
        && str_contains($staticTeardownFailure['stdout'], '1 passed'),
    'A failed static teardown rewrote the passing test result or escaped its file scope.',
);
$expect($markers[0] === 'before_all'
    && $markers[array_key_last($markers)] === 'after_all', 'The installed Drove scope lifecycle order drifted.');
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
