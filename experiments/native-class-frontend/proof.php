<?php

declare(strict_types=1);

use Drove\Kernel\DroverScheduler;
use Drove\Kernel\Scheduler;
use Drove\Native\ClassFrontend;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Drove\Native\Surface\DependencyGuard;
use DroveClassFixture\LivewireShapedUnitTest;

$rootPath = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($rootPath): void {
    if (! str_starts_with($class, 'Drove\\')) {
        return;
    }

    $file = $rootPath.'/src/Drove/'
        .str_replace('\\', '/', substr($class, strlen('Drove\\')))
        .'.php';

    if (is_file($file)) {
        require $file;
    }
});

require $rootPath.'/src/Drove/Native/functions.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$frameworkCase = 'PHPUnit\\Framework\\TestCase';
$assert(! class_exists($frameworkCase, false), 'The class frontend loaded an external test runtime.');
$fixture = __DIR__.'/LivewireShapedUnitTest.php';
require $fixture;
$declared = 0;
$registry = Declarations::capture(
    static function () use (&$declared, $fixture): void {
        $declared = (new ClassFrontend)->declareFiles([$fixture]);
    },
    $rootPath,
    'Drove native class frontend',
);
$plan = $registry->plan();
$fileScope = $plan['root']['children'][0] ?? null;
$tests = is_array($fileScope) ? ($fileScope['tests'] ?? []) : [];
$assert($declared === 1, 'The class frontend did not discover exactly one test class.');
$assert(count($tests) === 3, 'The class frontend did not expand the expected three cases.');
$assert(
    ($tests[0]['id'] ?? null)
        === 'test:experiments/native-class-frontend/LivewireShapedUnitTest.php::test_convention_method',
    'The class frontend did not preserve the PHPUnit-compatible file identity.',
);
$assert(
    ! str_contains(json_encode($plan, JSON_THROW_ON_ERROR), 'testConnection'),
    'The class frontend auto-discovered an unmarked helper class.',
);
$assert(
    array_column($tests, 'name') === [
        'test_convention_method',
        'adds_values [small]',
        'adds_values [larger]',
    ],
    'The class frontend emitted unstable method or dataset names.',
);
$assert(
    ($tests[0]['groups'] ?? null) === ['livewire']
        && ($tests[1]['groups'] ?? null) === ['livewire', 'dataset'],
    'The class frontend lost class or method groups.',
);
$assert(
    count($fileScope['hooks']['before_all'] ?? []) === 1
        && count($fileScope['hooks']['after_all'] ?? []) === 1,
    'The class frontend did not lower class lifecycle hooks.',
);

$inlineScheduler = new class implements Scheduler
{
    public function runId(): string
    {
        return 'native-class-frontend-inline';
    }

    public function map(array $tasks, Closure $execute): array
    {
        $results = [];
        $completionOrder = [];

        foreach ($tasks as $ordinal => $task) {
            $startedNs = hrtime(true);
            ob_start();

            try {
                $value = $execute($task);
                $output = ob_get_clean();
            } catch (Throwable $throwable) {
                ob_end_clean();

                throw $throwable;
            }

            $finishedNs = hrtime(true);
            $results[] = [
                'id' => $task['id'],
                'kind' => $task['kind'],
                'scope_id' => $task['scope_id'],
                'ordinal' => $ordinal,
                'status' => 'passed',
                'failure' => null,
                'value' => $value,
                'stdout' => is_string($output) ? $output : '',
                'stderr' => '',
                'events' => [],
                'telemetry' => [
                    'pid' => getmypid(),
                    'pgid' => getmypid(),
                    'started_ns' => $startedNs,
                    'finished_ns' => $finishedNs,
                    'duration_ms' => ($finishedNs - $startedNs) / 1_000_000,
                    'exit_code' => 0,
                    'signal' => null,
                ],
            ];
            $completionOrder[] = $task['id'];
        }

        return ['results' => $results, 'completion_order' => $completionOrder];
    }

    public function withPermit(array $scopes, Closure $work): mixed
    {
        return $work();
    }
};
$schedulerName = getenv('DROVE_NATIVE_CLASS_SCHEDULER') ?: 'inline';
$processes = (int) (getenv('DROVE_NATIVE_CLASS_PROCESSES') ?: 1);
$assert(in_array($schedulerName, ['inline', 'drover'], true), 'The class frontend scheduler is invalid.');
$assert($processes >= 1 && $processes <= 30, 'The class frontend process limit is invalid.');
$scheduler = $schedulerName === 'drover'
    ? new DroverScheduler('native-class-frontend-'.$processes, $processes)
    : $inlineScheduler;

$run = new Runner($scheduler)->run($registry);
$assert($run['status'] === 'passed', 'The native class frontend run failed.');
$assert(count($run['tests']) === 3, 'The native class frontend omitted a runnable case.');
$assert(
    array_column($run['tests'], 'assertions') === [3, 3, 3],
    'The native class lifecycle did not preserve assertion accounting.',
);
$assert(
    array_column($run['tests'], 'stdout') === [
        'setup|convention|teardown|',
        'setup|dataset:3|teardown|',
        'setup|dataset:42|teardown|',
    ],
    'The instance lifecycle did not surround each class test exactly once.',
);
$assert(
    LivewireShapedUnitTest::$beforeClass === 1
        && LivewireShapedUnitTest::$afterClass === 1,
    'The class lifecycle did not run exactly once.',
);
$guard = (new DependencyGuard)->inspect($rootPath.'/src/Drove/Native');
$assert($guard['violations'] === [], 'The class frontend contaminated the native dependency boundary.');
$assert(! class_exists($frameworkCase, false), 'The class frontend loaded an external test runtime while running.');

echo json_encode([
    'schema' => 1,
    'ok' => true,
    'frontend' => 'native-class',
    'scheduler' => $schedulerName,
    'processes' => $processes,
    'classes' => $declared,
    'cases' => count($run['tests']),
    'assertions' => array_sum(array_column($run['tests'], 'assertions')),
    'groups' => array_values(array_unique(array_merge(...array_column($tests, 'groups')))),
    'class_lifecycle' => [
        'before' => LivewireShapedUnitTest::$beforeClass,
        'after' => LivewireShapedUnitTest::$afterClass,
    ],
    'dependency_guard' => [
        'inspected_files' => $guard['inspected_files'],
        'violations' => count($guard['violations']),
        'external_runtime_loaded' => class_exists($frameworkCase, false),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
