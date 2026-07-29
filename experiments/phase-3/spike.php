<?php

declare(strict_types=1);

use Drove\Pest\ScopeCompiler;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\Framework\TestSuite as PHPUnitTestSuite;
use PHPUnit\Runner\TestSuiteLoader;
use PHPUnit\TextUI\Configuration\Registry as PHPUnitConfiguration;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require __DIR__.'/vendor/autoload.php';

set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
});

$outputBufferLevel = ob_get_level();
PestKernel::boot(
    PestTestSuite::getInstance(__DIR__, 'tests'),
    new ArrayInput([]),
    new BufferedOutput,
);

while (ob_get_level() > $outputBufferLevel) {
    ob_end_clean();
}

$fixture = realpath(__DIR__.'/tests/HookIrTest.php');

if ($fixture === false) {
    throw new RuntimeException('The Phase 3 Pest fixture does not exist.');
}

$compiler = ScopeCompiler::activate(__DIR__);
$suite = PHPUnitTestSuite::empty('drove-phase-three');
$suite->addTestFile($fixture);
(new TestSuiteFilterProcessor)->process(PHPUnitConfiguration::get(), $suite);

$classSuite = $suite->tests()[0] ?? null;

if (! $classSuite instanceof PHPUnitTestSuite || count($classSuite->tests()) !== 2) {
    throw new RuntimeException('Pest did not generate exactly two Phase 3 cases.');
}

$fileId = 'file:tests/HookIrTest.php';
$outerId = 'scope:tests/HookIrTest.php::0';
$firstScopeId = 'scope:tests/HookIrTest.php::0.0';
$secondScopeId = 'scope:tests/HookIrTest.php::0.1';
$fileBeforeAll = 'hook:'.$fileId.'::before_all:0';
$fileBeforeEachBeta = 'hook:'.$fileId.'::before_each:0';
$fileBeforeEachAlpha = 'hook:'.$fileId.'::before_each:1';
$fileAfterEach = 'hook:'.$fileId.'::after_each:0';
$fileAfterAll = 'hook:'.$fileId.'::after_all:0';
$outerBeforeEach = 'hook:'.$outerId.'::before_each:0';
$outerAfterEach = 'hook:'.$outerId.'::after_each:0';
$firstBeforeEach = 'hook:'.$firstScopeId.'::before_each:0';
$firstAfterEach = 'hook:'.$firstScopeId.'::after_each:0';
$secondBeforeEach = 'hook:'.$secondScopeId.'::before_each:0';
$secondAfterEach = 'hook:'.$secondScopeId.'::after_each:0';
$firstTestId = 'test:tests/HookIrTest.php::%60outer%60%20%E2%86%92%20%60same%60%20%E2%86%92%20it%20runs%20first%20duplicate%20scope';
$secondTestId = 'test:tests/HookIrTest.php::%60outer%60%20%E2%86%92%20%60same%60%20%E2%86%92%20it%20runs%20second%20duplicate%20scope';

$expectedPlan = [
    'id' => $fileId,
    'type' => 'file',
    'path' => 'tests/HookIrTest.php',
    'hooks' => [
        'before_all' => [$fileBeforeAll],
        'before_each' => [$fileBeforeEachBeta, $fileBeforeEachAlpha],
        'after_each' => [$fileAfterEach],
        'after_all' => [$fileAfterAll],
    ],
    'tests' => [],
    'children' => [
        [
            'id' => $outerId,
            'type' => 'describe',
            'name' => 'outer',
            'hooks' => [
                'before_all' => [],
                'before_each' => [$outerBeforeEach],
                'after_each' => [$outerAfterEach],
                'after_all' => [],
            ],
            'tests' => [],
            'children' => [
                [
                    'id' => $firstScopeId,
                    'type' => 'describe',
                    'name' => 'same',
                    'hooks' => [
                        'before_all' => [],
                        'before_each' => [$firstBeforeEach],
                        'after_each' => [$firstAfterEach],
                        'after_all' => [],
                    ],
                    'tests' => [
                        [
                            'id' => $firstTestId,
                            'name' => '`outer` → `same` → it runs first duplicate scope',
                            'before_each' => [
                                $fileBeforeEachBeta,
                                $fileBeforeEachAlpha,
                                $outerBeforeEach,
                                $firstBeforeEach,
                            ],
                            'after_each' => [
                                $firstAfterEach,
                                $outerAfterEach,
                                $fileAfterEach,
                            ],
                        ],
                    ],
                    'children' => [],
                ],
                [
                    'id' => $secondScopeId,
                    'type' => 'describe',
                    'name' => 'same',
                    'hooks' => [
                        'before_all' => [],
                        'before_each' => [$secondBeforeEach],
                        'after_each' => [$secondAfterEach],
                        'after_all' => [],
                    ],
                    'tests' => [
                        [
                            'id' => $secondTestId,
                            'name' => '`outer` → `same` → it runs second duplicate scope',
                            'before_each' => [
                                $fileBeforeEachBeta,
                                $fileBeforeEachAlpha,
                                $outerBeforeEach,
                                $secondBeforeEach,
                            ],
                            'after_each' => [
                                $secondAfterEach,
                                $outerAfterEach,
                                $fileAfterEach,
                            ],
                        ],
                    ],
                    'children' => [],
                ],
            ],
        ],
    ],
];

$plan = $compiler->scopePlan($fixture);
$planJson = json_encode($plan, JSON_THROW_ON_ERROR);
$planIsScalar = json_decode($planJson, true, flags: JSON_THROW_ON_ERROR) === $plan;
$hookIds = [];
$testIds = [];

$collectIds = static function (array $scope) use (&$collectIds, &$hookIds, &$testIds): void {
    foreach ($scope['hooks'] as $ids) {
        array_push($hookIds, ...$ids);
    }

    foreach ($scope['tests'] as $test) {
        $testIds[] = $test['id'];
    }

    foreach ($scope['children'] as $child) {
        $collectIds($child);
    }
};
$collectIds($plan);

$expectedHooks = [
    $fileBeforeAll => ['file.before_all', 9],
    $fileBeforeEachBeta => ['file.before_each_beta', 14],
    $fileBeforeEachAlpha => ['file.before_each_alpha', 19],
    $fileAfterEach => ['file.after_each', 24],
    $fileAfterAll => ['file.after_all', 29],
    $outerBeforeEach => ['outer.before_each', 35],
    $outerAfterEach => ['outer.after_each', 40],
    $firstBeforeEach => ['first.before_each', 46],
    $firstAfterEach => ['first.after_each', 51],
    $secondBeforeEach => ['second.before_each', 63],
    $secondAfterEach => ['second.after_each', 68],
];
$hookSources = [];
$hooksMatch = array_keys($expectedHooks) === $hookIds;

foreach ($expectedHooks as $hookId => [$label, $line]) {
    $hook = $compiler->hook($hookId);
    $source = new ReflectionFunction($hook);
    $hookSources[$hookId] = [
        'path' => str_replace('\\', '/', (string) $source->getFileName()),
        'line' => $source->getStartLine(),
    ];
    $hooksMatch = $hooksMatch
        && $hook === $GLOBALS['drove_phase_three_hooks'][$label]
        && realpath((string) $source->getFileName()) === $fixture
        && $source->getStartLine() === $line;
}

$testsMatch = $testIds === [$firstTestId, $secondTestId]
    && $compiler->closure($firstTestId) === $GLOBALS['drove_phase_three_tests']['first']
    && $compiler->closure($secondTestId) === $GLOBALS['drove_phase_three_tests']['second'];

$loaderFile = (new ReflectionClass(TestSuiteLoader::class))->getFileName();
$localCompilerHash = hash_file('sha256', '/pest/src/Drove/Pest/ScopeCompiler.php');
$installedCompilerHash = hash_file('sha256', __DIR__.'/vendor/oxhq/drove/src/Drove/Pest/ScopeCompiler.php');
$localFunctionsHash = hash_file('sha256', '/pest/src/Functions.php');
$installedFunctionsHash = hash_file('sha256', __DIR__.'/vendor/oxhq/drove/src/Functions.php');
$usesLocalDrove = is_string($localCompilerHash)
    && hash_equals($localCompilerHash, (string) $installedCompilerHash)
    && is_string($localFunctionsHash)
    && hash_equals($localFunctionsHash, (string) $installedFunctionsHash)
    && is_string($loaderFile)
    && str_ends_with(str_replace('\\', '/', $loaderFile), '/vendor/oxhq/drove/overrides/Runner/TestSuiteLoader.php');

$passed = $planIsScalar
    && $plan === $expectedPlan
    && $compiler->scopePlan($fixture) === $expectedPlan
    && $hooksMatch
    && $testsMatch
    && $GLOBALS['drove_phase_three_executions'] === []
    && $usesLocalDrove;

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'pest_version' => Pest\version(),
    'local_drove_compiler_sha256' => $localCompilerHash,
    'local_functions_sha256' => $localFunctionsHash,
    'loader' => $loaderFile,
    'scope_ir' => $plan,
    'hook_sources' => $hookSources,
    'test_ids' => $testIds,
    'executed_bodies' => $GLOBALS['drove_phase_three_executions'],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
