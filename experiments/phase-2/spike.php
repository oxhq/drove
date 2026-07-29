<?php

declare(strict_types=1);

use Drove\Kernel\ScopeContext;
use Drove\Kernel\TestOutcome;
use Drove\Pest\ScopeCompiler;
use Drove\Pest\TestCaseRuntime;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestSuite as PHPUnitTestSuite;
use PHPUnit\Runner\Filter\Factory as FilterFactory;
use PHPUnit\TextUI\Configuration\Registry as PHPUnitConfiguration;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require __DIR__.'/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$GLOBALS['drove_phase_two_hooks'] = [
    'before_all' => 0,
    'before_each' => 0,
    'after_each' => 0,
    'after_all' => 0,
];
$GLOBALS['drove_phase_two_custom'] = [];
$GLOBALS['drove_phase_two_bodies'] = [];
$GLOBALS['drove_phase_two_filtered_hooks'] = 0;

$outputBufferLevel = ob_get_level();
PestKernel::boot(
    PestTestSuite::getInstance(__DIR__, 'tests'),
    new ArrayInput([]),
    new BufferedOutput,
);

while (ob_get_level() > $outputBufferLevel) {
    ob_end_clean();
}

$fixture = realpath(__DIR__.'/tests/ScopeIrTest.php');

if ($fixture === false) {
    throw new RuntimeException('The Phase 2 fixture does not exist.');
}

$compiler = ScopeCompiler::activate(__DIR__, ownsHooks: true);
$suite = PHPUnitTestSuite::empty('drove-phase-two');
$suite->addTestFile($fixture);
(new TestSuiteFilterProcessor)->process(PHPUnitConfiguration::get(), $suite);
$filter = new FilterFactory;
$filter->addExcludeGroupFilter(['filtered-out']);
$suite->injectFilter($filter);

$runtime = TestCaseRuntime::fromSuite($compiler, $suite);
$plan = $compiler->suitePlan([$fixture], ['name' => 'Drove Phase 2']);
$file = $plan['root']['children'][0];
$tests = $file['tests'];
$resolvers = $runtime->resolvers();
$planJson = json_encode($plan, JSON_THROW_ON_ERROR);

$assert(
    json_decode($planJson, true, flags: JSON_THROW_ON_ERROR) === $plan,
    'Generated TestCase objects leaked into scalar Scope IR.',
);
$assert(array_keys($resolvers) === array_column($tests, 'id'), 'Runtime resolver order drifted from filtered Scope IR.');
$assert(count($tests) === 5, 'PHPUnit collect() did not expand the two datasets.');
$assert(
    ! in_array('is removed by PHPUnit filtering', array_column($tests, 'name'), true),
    'PHPUnit-filtered case leaked back into Scope IR.',
);

$datasetTests = array_values(array_filter(
    $tests,
    static fn (array $test): bool => ($test['dataset'] ?? null) !== null,
));
$assert(
    array_column($datasetTests, 'id') === [
        'test:tests/ScopeIrTest.php::runs%20a%20generated%20dataset%20case::dataset:name:dataset%20%22alpha%22',
        'test:tests/ScopeIrTest.php::runs%20a%20generated%20dataset%20case::dataset:name:dataset%20%22beta%22',
    ],
    'Dataset case IDs are not stable: '.json_encode(array_column($datasetTests, 'id'), JSON_THROW_ON_ERROR),
);
$assert(
    array_column($datasetTests, 'groups') === [
        ['datasets', 'fast'],
        ['datasets', 'fast'],
    ],
    'PHPUnit groups were not preserved in Scope IR.',
);
$assert(
    array_column($tests, 'disposition') === ['run', 'run', 'run', 'todo', 'run'],
    'Pest todo disposition was not preserved in Scope IR.',
);

$classes = array_values(array_unique(array_map(
    static fn (array $resolver): string => $resolver['runtime']::class,
    $resolvers,
)));
$assert(count($classes) === 1, 'Phase 2 generated more than one Pest class.');
$class = $classes[0];
$class::setUpBeforeClass();
$results = [];
$emitted = [];
$originalFailure = null;
$scopeContext = new ScopeContext;

try {
    foreach ($file['hooks']['before_all'] as $hookId) {
        $compiler->hook($hookId)->call($scopeContext);
    }

    foreach ($file['children'] as $child) {
        foreach ($child['hooks']['before_all'] as $hookId) {
            $compiler->hook($hookId)->call($scopeContext);
        }

        foreach (array_reverse($child['hooks']['after_all']) as $hookId) {
            $compiler->hook($hookId)->call($scopeContext);
        }
    }

    foreach ($tests as $test) {
        $id = $test['id'];
        $resolver = $resolvers[$id];

        foreach ($test['before_each'] as $hookId) {
            $compiler->hook($hookId)->call($resolver['runtime']);
        }

        $outputLevel = ob_get_level();
        ob_start();

        try {
            $outcome = ($resolver['closure'])();
            $assert($outcome instanceof TestOutcome, 'Runtime resolver did not return a TestOutcome.');
            $results[$id] = is_array($outcome->value)
                ? $outcome->value
                : ['id' => $id, 'status' => $outcome->status, 'message' => $outcome->value];
        } catch (AssertionFailedError $failure) {
            if ($test['name'] !== 'rethrows the original assertion failure') {
                throw $failure;
            }

            $originalFailure = $failure;
            $results[$id] = ['id' => $id, 'status' => 'failed'];
        } finally {
            $emitted[$id] = '';

            while (ob_get_level() > $outputLevel) {
                $emitted[$id] = ob_get_clean().$emitted[$id];
            }

            foreach (array_reverse($test['after_each']) as $hookId) {
                $compiler->hook($hookId)->call($resolver['runtime']);
            }
        }
    }

    foreach (array_reverse($file['hooks']['after_all']) as $hookId) {
        $compiler->hook($hookId)->call($scopeContext);
    }
} finally {
    $class::tearDownAfterClass();
}

$assert(
    array_column($results, 'status') === ['passed', 'passed', 'skipped', 'todo', 'failed'],
    'runBare() did not preserve pass, skip, and todo outcomes.',
);
$assert(
    array_slice(array_column($results, 'output'), 0, 2) === ['alpha:1', 'beta:2'],
    'runBare() did not preserve per-dataset output.',
);
$assert(
    array_values($emitted) === ['alpha:1', 'beta:2', '', '', ''],
    'Runtime resolver did not emit captured output exactly once.',
);
$assert(
    array_slice(array_column($results, 'assertions'), 0, 2) === [3, 3],
    'Pest assertions did not execute through the generated TestCase: '
        .json_encode(array_column($results, 'assertions'), JSON_THROW_ON_ERROR),
);
$assert(
    $originalFailure instanceof ExpectationFailedException
        && $originalFailure->getMessage() === 'Failed asserting that two strings are identical.',
    'TestCaseRuntime did not rethrow the original assertion failure: '
        .($originalFailure instanceof Throwable
            ? $originalFailure::class.': '.$originalFailure->getMessage()
            : 'none'),
);
$assert(
    $GLOBALS['drove_phase_two_hooks'] === [
        'before_all' => 1,
        'before_each' => 5,
        'after_each' => 5,
        'after_all' => 1,
    ],
    'Pest hooks were lost or executed twice.',
);
$assert($GLOBALS['drove_phase_two_filtered_hooks'] === 0, 'Hooks ran for a fully filtered describe subtree.');
$assert($file['children'] === [], 'A fully filtered describe subtree remained in Scope IR.');
$assert(
    array_keys($GLOBALS['drove_phase_two_bodies']) === ['alpha', 'beta']
        && array_column($GLOBALS['drove_phase_two_bodies'], 'binding') === [
            'custom-test-case',
            'custom-test-case',
        ],
    'Dataset arguments or custom TestCase binding were lost.',
);
$assert(
    $GLOBALS['drove_phase_two_custom'][0] === 'before_class'
        && end($GLOBALS['drove_phase_two_custom']) === 'after_class'
        && count(array_filter(
            $GLOBALS['drove_phase_two_custom'],
            static fn (string $event): bool => str_starts_with($event, 'set_up:'),
        )) === 5
        && count(array_filter(
            $GLOBALS['drove_phase_two_custom'],
            static fn (string $event): bool => str_starts_with($event, 'tear_down:'),
        )) === 5,
    'Custom TestCase lifecycle did not run exactly once per case.',
);

try {
    $runtime->run($tests[0]['id']);
    throw new RuntimeException('TestCaseRuntime accepted a second run.');
} catch (LogicException) {
    //
}

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'cases' => count($results),
    'ids' => array_keys($results),
    'outcomes' => array_column($results, 'status'),
    'hooks' => $GLOBALS['drove_phase_two_hooks'],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
