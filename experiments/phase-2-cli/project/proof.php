<?php

declare(strict_types=1);

use Drove\Console\Renderer;

require __DIR__.'/vendor/autoload.php';

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
    '--pest',
    ...$arguments,
]);
$prepareCase = $execute([PHP_BINARY, __DIR__.'/prepare-case.php']);
$expect = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$syntheticKernelOutput = (new Renderer)->render([
    'run_id' => 'synthetic',
    'exit_code' => 0,
    'tests' => [[
        'id' => 'synthetic:test',
        'name' => 'synthetic test',
        'status' => 'passed',
        'value' => ['assertions' => 99],
    ]],
    'scopes' => [],
]);
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
$nativeBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/phpunit',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/OrdinaryPhpUnitTest.php',
]);
$nativeBaselineMarkers = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
file_put_contents($markerPath, '');
$nativePhpUnit = $run('unsupported/OrdinaryPhpUnitTest.php');
$nativeMarkers = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
file_put_contents($markerPath, '');
$mixedPhpUnit = $run('--testsuite=Mixed');
$mixedMarkers = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
file_put_contents($markerPath, '');
$nativePlan = $execute([PHP_BINARY, __DIR__.'/native-plan.php']);
$riskyBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/phpunit',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/NativeRiskyTest.php',
]);
$failOnRiskyBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/phpunit',
    '--configuration=unsupported/fail-on-risky.xml',
    '--colors=never',
]);
$strictOutputBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/phpunit',
    '--configuration=unsupported/disallow-output.xml',
    '--colors=never',
]);
$classSkipBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/phpunit',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/NativeSkippedClassHookTest.php',
]);
$requiredClassHookBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/phpunit',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/NativeRequiredClassHookTest.php',
]);
$nativeDeprecationBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/phpunit',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/NativeDeprecationExpectationTest.php',
]);
$nativePhpunitWarningBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/pest',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/NativePhpunitWarningTest.php',
]);
$nativePhpunitWarningOptOutBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/pest',
    '--configuration=unsupported/phpunit-warning-opt-out.xml',
    '--colors=never',
]);
$coverageMetadataBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/pest',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/NativeCoverageMetadataTest.php',
]);
$invalidProviderBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/pest',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/InvalidDataProviderTest.php',
]);
$planningWarningBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/pest',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/NativePlanningWarningTest.php',
]);
$emptyPestFileBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/pest',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/EmptyPestTest.php',
    'tests/FastTest.php',
]);
$generatedClassSkipBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/pest',
    '--configuration=phpunit.xml',
    '--colors=never',
    'unsupported/StaticSkippedTest.php',
]);
$emptySelectionBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/pest',
    '--configuration=phpunit.xml',
    '--colors=never',
    '--filter=does-not-exist',
    'unsupported/OrdinaryPhpUnitTest.php',
]);
$cleanEmptyBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/pest',
    '--configuration=unsupported/empty-clean.xml',
    '--colors=never',
]);
$cleanEmptyFailBaseline = $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/pest',
    '--configuration=unsupported/empty-clean-fail.xml',
    '--colors=never',
]);
$cases = [
    'path' => $run('tests/OtherTest.php'),
    'filter' => $run('tests/FastTest.php', '--filter=alpha'),
    'empty_selection' => $run('unsupported/OrdinaryPhpUnitTest.php', '--filter=does-not-exist'),
    'clean_empty_suite' => $run('--configuration=unsupported/empty-clean.xml'),
    'clean_empty_suite_fail' => $run('--configuration=unsupported/empty-clean-fail.xml'),
    'group' => $run('tests', '--group=slow'),
    'exclude_group' => $run('tests/FastTest.php', '--exclude-group=slow'),
    'testsuite' => $run('--testsuite=Other'),
    'parallel' => $run('--parallel', '--processes=2', 'tests/FastTest.php'),
    'compatibility_c1' => $compatibilityC1,
    'compatibility_c8' => $run('--parallel', '--processes=8', 'tests/CompatibilityTest.php'),
    'slow' => $run('tests/SlowTest.php'),
    'failure' => $run('tests/FailingTest.php'),
    'partial_assertions' => $run('tests/FastTest.php', 'tests/FailingTest.php'),
    'coverage' => $run('--coverage'),
    'process_isolation' => $run('--process-isolation'),
    'ordinary_phpunit' => $nativePhpUnit,
    'native_filter' => $run('unsupported/OrdinaryPhpUnitTest.php', '--filter=test_native_dataset'),
    'native_group' => $run('unsupported/OrdinaryPhpUnitTest.php', '--group=native'),
    'mixed_phpunit' => $mixedPhpUnit,
    'dependency' => $run('unsupported/DependencyTest.php'),
    'native_dependency' => $run('unsupported/NativeDependencyTest.php'),
    'process_isolation_metadata' => $run('unsupported/ProcessIsolationTest.php'),
    'native_process_isolation_metadata' => $run('unsupported/NativeProcessIsolationTest.php'),
    'multiple_native_classes' => $run('unsupported/MultipleNativeTest.php'),
    'native_non_public_class_hook' => $run('unsupported/NativeNonPublicClassHookTest.php'),
    'native_skipped_class_hook' => $run('unsupported/NativeSkippedClassHookTest.php'),
    'native_required_class_hook' => $run('unsupported/NativeRequiredClassHookTest.php'),
    'native_deprecation_expectation' => $run('unsupported/NativeDeprecationExpectationTest.php'),
    'native_phpunit_warning' => $run('unsupported/NativePhpunitWarningTest.php'),
    'native_phpunit_warning_opt_out' => $run(
        '--configuration=unsupported/phpunit-warning-opt-out.xml',
    ),
    'native_coverage_metadata' => $run('unsupported/NativeCoverageMetadataTest.php'),
    'invalid_provider' => $run('unsupported/InvalidDataProviderTest.php'),
    'planning_warning' => $run('unsupported/NativePlanningWarningTest.php'),
    'empty_pest_file' => $run('unsupported/EmptyPestTest.php', 'tests/FastTest.php'),
    'generated_skipped_class_hook' => $run('unsupported/StaticSkippedTest.php'),
    'generated_attribute_class_hook' => $run('unsupported/StaticAttributeHookTest.php'),
    'native_risky' => $run('unsupported/NativeRiskyTest.php'),
    'static_lifecycle' => $staticLifecycle,
    'static_setup_failure' => $staticSetupFailure,
    'static_teardown_failure' => $staticTeardownFailure,
    'xml_process_isolation' => $run('--configuration=unsupported/process-isolation.xml'),
    'xml_enforce_time_limit' => $run('--configuration=unsupported/enforce-time-limit.xml'),
    'xml_fail_on_incomplete' => $run('--configuration=unsupported/fail-on-incomplete.xml'),
    'xml_fail_on_risky' => $run('--configuration=unsupported/fail-on-risky.xml'),
    'xml_fail_on_skipped' => $run('--configuration=unsupported/fail-on-skipped.xml'),
    'xml_stop_on_failure' => $run('--configuration=unsupported/stop-on-failure.xml'),
    'xml_strict_global_state' => $run('--configuration=unsupported/strict-global-state.xml'),
    'xml_strict_coverage' => $run('--configuration=unsupported/strict-coverage.xml'),
    'xml_disallow_output' => $run('--configuration=unsupported/disallow-output.xml'),
    'xml_extensions' => $run('--configuration=unsupported/extensions.xml'),
    'xml_execution_order' => $run('--configuration=unsupported/execution-order.xml'),
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
    'ordinary_phpunit',
    'native_filter',
    'native_group',
    'mixed_phpunit',
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
    $nativePlan['exit'] === 0
        && str_contains($nativePlan['stdout'], '"status": "passed"'),
    'Native PHPUnit descriptor planning drifted: '.$nativePlan['stderr'],
);
$nativeLifecycle = [
    'native_before_class_high',
    'native_set_up_before_class',
    'native_before_class_low',
    'native_set_up', 'native_body_named', 'native_tear_down',
    'native_set_up', 'native_body_positional', 'native_tear_down',
    'native_set_up', 'native_body_incomplete', 'native_tear_down',
    'native_after_class_high',
    'native_tear_down_after_class',
    'native_after_class_low',
];
$expect(
    $nativeBaseline['exit'] === 0
        && $nativeBaselineMarkers === $nativeLifecycle
        && $nativeMarkers === $nativeLifecycle,
    'The native PHPUnit static or per-case lifecycle order drifted.',
);
$expect(
    $mixedMarkers === $nativeMarkers,
    'The native PHPUnit lifecycle changed inside a mixed Pest/PHPUnit suite.',
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
    $cases['partial_assertions']['exit'] === 1
        && str_contains($cases['partial_assertions']['stdout'], 'alpha fast')
        && str_contains($cases['partial_assertions']['stdout'], 'fails conventionally')
        && ! str_contains($cases['partial_assertions']['stdout'], 'Assertions:'),
    'A failed run rendered a partial PHPUnit assertion total as complete.',
);
$expect(
    $emptySelectionBaseline['exit'] === 1
        && $cases['empty_selection']['exit'] === 1
        && str_contains($cases['empty_selection']['stdout'], 'Tests:  (0)'),
    'An explicitly empty selection did not preserve PHPUnit exit semantics.',
);
$expect(
    $cleanEmptyBaseline['exit'] === 0
        && $cases['clean_empty_suite']['exit'] === 0
        && str_contains($cases['clean_empty_suite']['stdout'], 'Tests:  (0)'),
    'A clean empty suite inherited the PHPUnit-warning exit policy.',
);
$expect(
    $cleanEmptyFailBaseline['exit'] === 1
        && $cases['clean_empty_suite_fail']['exit'] === 1
        && str_contains($cases['clean_empty_suite_fail']['stdout'], 'Tests:  (0)'),
    'A clean empty suite ignored failOnEmptyTestSuite.',
);
$expect(
    $riskyBaseline['exit'] === 0
        && str_contains($riskyBaseline['stdout'], 'Risky: 1')
        && $cases['native_risky']['exit'] === 0
        && str_contains($cases['native_risky']['stdout'], '1 risky, 1 passed')
        && str_contains($cases['native_risky']['stdout'], 'This test did not perform any assertions')
        && str_contains($cases['native_risky']['stdout'], 'Assertions: 0'),
    'Native PHPUnit useless-test riskiness drifted from the conventional runner.',
);
$expect(
    $cases['coverage']['exit'] === 2
        && str_contains($cases['coverage']['stderr'], '--coverage mode is not supported yet'),
    'Unsupported coverage did not use an explicit exit-2 diagnostic.',
);
$expect(
    $cases['process_isolation']['exit'] === 2
        && str_contains($cases['process_isolation']['stderr'], '--process-isolation mode is not supported yet'),
    'Unsupported process isolation did not use an explicit exit-2 diagnostic.',
);
$expect(
    str_contains($cases['ordinary_phpunit']['stdout'], 'test_native_dataset with data set "named row"')
        && str_contains($cases['ordinary_phpunit']['stdout'], 'native-output:named')
        && str_contains($cases['ordinary_phpunit']['stdout'], 'test_native_dataset with data set #0')
        && str_contains($cases['ordinary_phpunit']['stdout'], 'test_native_incomplete')
        && str_contains($cases['ordinary_phpunit']['stdout'], 'native incomplete proof')
        && str_contains($cases['ordinary_phpunit']['stdout'], '1 incomplete, 2 passed'),
    'Native PHPUnit datasets, output, or incomplete reporting drifted.',
);
$expect(
    str_contains(
        $cases['ordinary_phpunit']['stdout'],
        'Tests: 1 incomplete, 2 passed (3)'.PHP_EOL.'Assertions: 2'.PHP_EOL,
    ),
    'Native PHPUnit assertion aggregation drifted.',
);
$expect(
    str_contains($cases['native_filter']['stdout'], 'test_native_dataset with data set "named row"')
        && str_contains($cases['native_filter']['stdout'], 'test_native_dataset with data set #0')
        && ! str_contains($cases['native_filter']['stdout'], 'test_native_incomplete'),
    'Native PHPUnit filter selection drifted.',
);
$expect(
    str_contains($cases['native_group']['stdout'], 'test_native_dataset with data set "named row"')
        && str_contains($cases['native_group']['stdout'], 'test_native_incomplete'),
    'Native PHPUnit group selection drifted.',
);
$mixedPest = strpos($cases['mixed_phpunit']['stdout'], 'alpha fast');
$mixedNative = strpos($cases['mixed_phpunit']['stdout'], 'test_native_dataset with data set "named row"');
$expect(
    $mixedPest !== false
        && $mixedNative !== false
        && $mixedPest < $mixedNative
        && str_contains($cases['mixed_phpunit']['stdout'], '1 incomplete, 5 passed'),
    'Mixed Pest/native PHPUnit planning drifted from discovery order.',
);
$expect(
    str_contains(
        $cases['mixed_phpunit']['stdout'],
        'Tests: 1 incomplete, 5 passed (6)'.PHP_EOL.'Assertions: 5'.PHP_EOL,
    ),
    'Mixed Pest/native PHPUnit assertion aggregation drifted.',
);
$expect(
    str_contains($syntheticKernelOutput, 'Tests: 1 passed (1)')
        && ! str_contains($syntheticKernelOutput, 'Assertions:'),
    'Synthetic kernel outcomes were incorrectly included in PHPUnit assertion totals.',
);
$expect(
    $cases['dependency']['exit'] === 2
        && str_contains($cases['dependency']['stderr'], 'Drove does not support test dependencies yet'),
    'Dependency metadata was not rejected explicitly.',
);
$expect(
    $cases['native_dependency']['exit'] === 2
        && str_contains($cases['native_dependency']['stderr'], 'Drove does not support test dependencies yet'),
    'Native PHPUnit dependency metadata was not rejected explicitly.',
);
$expect(
    $cases['process_isolation_metadata']['exit'] === 2
        && str_contains($cases['process_isolation_metadata']['stderr'], 'process-isolation metadata'),
    'Process-isolation metadata was not rejected explicitly.',
);
$expect(
    $cases['native_process_isolation_metadata']['exit'] === 2
        && str_contains($cases['native_process_isolation_metadata']['stderr'], 'process-isolation metadata'),
    'Native PHPUnit process-isolation metadata was not rejected explicitly.',
);
$expect(
    $cases['multiple_native_classes']['exit'] === 2
        && str_contains(
            $cases['multiple_native_classes']['stderr'],
            'Drove supports one native PHPUnit TestCase class per file',
        )
        && str_contains($cases['multiple_native_classes']['stderr'], 'FirstMultipleNativeTest')
        && str_contains($cases['multiple_native_classes']['stderr'], 'SecondMultipleNativeTest'),
    'Multiple native PHPUnit classes in one file were not rejected during suite loading.',
);
$expect(
    $cases['native_non_public_class_hook']['exit'] === 2
        && str_contains(
            $cases['native_non_public_class_hook']['stderr'],
            'to be public and static',
        ),
    'A non-public native PHPUnit class hook bypassed the explicit compatibility guard.',
);
$expect(
    $classSkipBaseline['exit'] === 0
        && $cases['native_skipped_class_hook']['exit'] === 0
        && str_contains($cases['native_skipped_class_hook']['stdout'], '1 skipped')
        && str_contains($cases['native_skipped_class_hook']['stdout'], 'native class skip proof'),
    'A native PHPUnit class-level skip did not preserve default skip semantics.',
);
$expect(
    $requiredClassHookBaseline['exit'] === 0
        && $cases['native_required_class_hook']['exit'] === 0
        && str_contains($cases['native_required_class_hook']['stdout'], '1 skipped')
        && ! str_contains($cases['native_required_class_hook']['stdout'], 'later native class hook ran')
        && ! str_contains($cases['native_required_class_hook']['stderr'], 'later native class hook ran'),
    'A native PHPUnit class-hook requirement was not checked before invocation.',
);
$expect(
    $nativeDeprecationBaseline['exit'] === 0
        && $cases['native_deprecation_expectation']['exit'] === 0
        && str_contains($cases['native_deprecation_expectation']['stdout'], '2 passed')
        && str_contains($cases['native_deprecation_expectation']['stdout'], 'Assertions: 3'),
    'Native PHPUnit error-handler expectations or opt-out semantics drifted.',
);
$expect(
    $nativePhpunitWarningBaseline['exit'] === 1
        && $cases['native_phpunit_warning']['exit'] === 1
        && str_contains($cases['native_phpunit_warning']['stdout'], '1 passed')
        && str_contains($cases['native_phpunit_warning']['stdout'], 'PHPUnit warnings: detected'),
    'A runtime PHPUnit warning did not preserve its default exit policy.',
);
$expect(
    $nativePhpunitWarningOptOutBaseline['exit'] === 0
        && $cases['native_phpunit_warning_opt_out']['exit'] === 0
        && str_contains(
            $cases['native_phpunit_warning_opt_out']['stdout'],
            'PHPUnit warnings: detected',
        ),
    'A runtime PHPUnit warning ignored the explicit warning-policy opt-out.',
);
$expect(
    $coverageMetadataBaseline['exit'] === 1
        && $cases['native_coverage_metadata']['exit'] === 2
        && str_contains($cases['native_coverage_metadata']['stderr'], 'coverage metadata'),
    'Unsupported PHPUnit coverage metadata was not rejected before execution.',
);
$expect(
    $invalidProviderBaseline['exit'] === 2
        && $cases['invalid_provider']['exit'] === 2
        && str_contains($cases['invalid_provider']['stderr'], 'PHPUnit error during discovery'),
    'A data-provider discovery error was silently dropped.',
);
$expect(
    $planningWarningBaseline['exit'] === 1
        && $cases['planning_warning']['exit'] === 1
        && str_contains($cases['planning_warning']['stdout'], '1 passed')
        && str_contains($cases['planning_warning']['stdout'], 'PHPUnit warnings: detected'),
    'A planning-time PHPUnit warning did not preserve its default exit policy.',
);
$expect(
    $emptyPestFileBaseline['exit'] === 0
        && $cases['empty_pest_file']['exit'] === 0
        && str_contains($cases['empty_pest_file']['stdout'], '3 passed')
        && ! str_contains($cases['empty_pest_file']['stdout'], 'PHPUnit warnings: detected'),
    'An empty Pest file leaked its internal IgnorableTestCase warning.',
);
$expect(
    $generatedClassSkipBaseline['exit'] === 0
        && $cases['generated_skipped_class_hook']['exit'] === 0
        && str_contains($cases['generated_skipped_class_hook']['stdout'], '1 skipped')
        && str_contains(
            $cases['generated_skipped_class_hook']['stdout'],
            'generated Pest class skip proof',
        ),
    'A generated Pest class-level skip did not preserve default skip semantics.',
);
$expect(
    $cases['generated_attribute_class_hook']['exit'] === 2
        && str_contains(
            $cases['generated_attribute_class_hook']['stderr'],
            'attribute class hook',
        ),
    'A generated Pest attribute class hook was not rejected explicitly.',
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
$expect(
    $cases['xml_fail_on_incomplete']['exit'] === 2
        && str_contains($cases['xml_fail_on_incomplete']['stderr'], 'failOnIncomplete from PHPUnit XML'),
    'XML failOnIncomplete was not rejected explicitly.',
);
$expect(
    $failOnRiskyBaseline['exit'] === 1
        && $cases['xml_fail_on_risky']['exit'] === 1
        && str_contains($cases['xml_fail_on_risky']['stdout'], '1 risky, 1 passed'),
    'XML failOnRisky exit semantics drifted from the conventional runner.',
);
$expect(
    $cases['xml_fail_on_skipped']['exit'] === 2
        && str_contains($cases['xml_fail_on_skipped']['stderr'], 'PHPUnit failOn policy'),
    'An unsupported XML failOn policy was ignored.',
);
$expect(
    $cases['xml_stop_on_failure']['exit'] === 2
        && str_contains($cases['xml_stop_on_failure']['stderr'], 'PHPUnit stopOn policies'),
    'An unsupported XML stopOn policy was ignored.',
);
$expect(
    $cases['xml_strict_global_state']['exit'] === 2
        && str_contains(
            $cases['xml_strict_global_state']['stderr'],
            'beStrictAboutChangesToGlobalState from PHPUnit XML',
        ),
    'XML global-state strictness was not rejected explicitly.',
);
$expect(
    $cases['xml_strict_coverage']['exit'] === 2
        && str_contains($cases['xml_strict_coverage']['stderr'], 'strict PHPUnit coverage modes'),
    'XML coverage strictness was not rejected explicitly.',
);
$expect(
    $strictOutputBaseline['exit'] === 1
        && str_contains($strictOutputBaseline['stdout'], 'Risky: 1')
        && $cases['xml_disallow_output']['exit'] === 1
        && str_contains($cases['xml_disallow_output']['stdout'], '1 risky, 1 passed')
        && str_contains(
            $cases['xml_disallow_output']['stdout'],
            'Test code or tested code printed unexpected output: unexpected output',
        )
        && str_contains($cases['xml_disallow_output']['stdout'], 'Assertions: 2'),
    'XML output strictness drifted from PHPUnit expected/unexpected-output semantics.',
);
$expect(
    $cases['xml_extensions']['exit'] === 2
        && str_contains($cases['xml_extensions']['stderr'], 'does not support PHPUnit extensions'),
    'A PHPUnit extension bootstrap was ignored.',
);
$expect(
    $cases['xml_execution_order']['exit'] === 2
        && str_contains($cases['xml_execution_order']['stderr'], 'non-default PHPUnit execution order'),
    'A non-default PHPUnit execution order was ignored.',
);

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'backend' => 'drover',
    'concurrency' => [1, 8],
    'selections' => ['path', 'filter', 'group', 'exclude-group', 'testsuite', 'mixed'],
    'exit_codes' => [
        'passed' => $cases['path']['exit'],
        'failed' => $cases['failure']['exit'],
        'unsupported' => $cases['coverage']['exit'],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
