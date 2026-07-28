<?php

declare(strict_types=1);

use Drove\Kernel\FailureKind;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\PcntlScheduler;
use Drove\Kernel\ScopeContext;
use Drove\Pest\ScopeCompiler;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\Framework\TestSuite as PHPUnitTestSuite;
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

$outputBufferLevel = ob_get_level();
PestKernel::boot(
    PestTestSuite::getInstance(__DIR__, 'kernel-fixtures'),
    new ArrayInput([]),
    new BufferedOutput,
);

while (ob_get_level() > $outputBufferLevel) {
    ob_end_clean();
}

$fixture = realpath(__DIR__.'/kernel-fixtures/KernelTest.php');

if ($fixture === false) {
    throw new RuntimeException('The Phase 1 kernel fixture does not exist.');
}

$compiler = ScopeCompiler::activate(__DIR__);
$suite = PHPUnitTestSuite::empty('drove-phase-one-kernel');
$suite->addTestFile($fixture);
(new TestSuiteFilterProcessor)->process(PHPUnitConfiguration::get(), $suite);

$basePlan = $compiler->suitePlan([$fixture]);
$scopeIds = [];
$testNames = [];
$plannedIds = [];

$collect = static function (array $node) use (&$collect, &$scopeIds, &$testNames, &$plannedIds): void {
    $name = $node['name'] ?? null;

    if (is_string($name)) {
        $scopeIds[$name][] = $node['id'];
    }

    foreach ($node['tests'] as $test) {
        $plannedIds[] = $test['id'];
        $testNames[$test['id']] = $test['name'];
    }

    foreach ($node['children'] as $child) {
        $collect($child);
    }
};
$collect($basePlan['root']);

$findTest = static function (string $needle) use ($testNames): string {
    foreach ($testNames as $id => $name) {
        if (str_contains($name, $needle)) {
            return $id;
        }
    }

    throw new RuntimeException('Missing planned test: '.$needle);
};

$serialId = $scopeIds['serial'][0] ?? throw new RuntimeException('Missing serial scope.');
$timeoutId = $findTest('kills a timed out process tree');
$timeouts = array_fill_keys($plannedIds, 3_000);
$timeouts[$timeoutId] = 100;
$limits = [$serialId => 1];
$plan = $compiler->suitePlan([$fixture], [
    'name' => 'Drove Phase 1',
    'scope_concurrency' => $limits,
    'test_timeouts' => $timeouts,
]);

$runAt = static function (int $concurrency) use ($compiler, $limits, $plan): array {
    $scheduler = new PcntlScheduler(
        'phase-1-c'.$concurrency,
        $concurrency,
        $limits,
        3_000,
        50,
    );
    $executor = new LifecycleExecutor(
        $scheduler,
        static fn (string $id): Closure => $compiler->hook($id),
        static fn (string $id): Closure => $compiler->closure($id),
    );

    return $executor->run($plan, new ScopeContext);
};

$sequential = $runAt(1);
$parallel = $runAt(8);
$assert(
    LifecycleExecutor::semanticProjection($sequential)
        === LifecycleExecutor::semanticProjection($parallel),
    'Concurrency 1 and 8 changed Phase 1 semantics.',
);
$assert($sequential['status'] === 'failed' && $sequential['exit_code'] === 1, 'The failing matrix did not aggregate exit 1.');
$assert($parallel['status'] === 'failed' && $parallel['exit_code'] === 1, 'Parallel aggregate status drifted.');
$assert(array_column($sequential['tests'], 'id') === $plannedIds, 'Sequential results left plan order.');
$assert(array_column($parallel['tests'], 'id') === $plannedIds, 'Parallel results left plan order.');
$assert($sequential['observed_concurrency']['global'] === 1, 'Sequential mode exceeded one active test.');
$assert($parallel['observed_concurrency']['global'] === 8, 'Parallel mode did not exercise all eight permits.');
$assert(
    $parallel['observed_concurrency']['scopes'][$serialId] === 1,
    'The serial scope exceeded its permit.',
);

$tests = array_column($parallel['tests'], null, 'id');
$scopes = array_column($parallel['scopes'], null, 'id');
$test = static fn (string $needle): array => $tests[$findTest($needle)];
$scope = static function (string $name) use ($scopes, $scopeIds): array {
    $id = $scopeIds[$name][0] ?? throw new RuntimeException('Missing scope result: '.$name);

    return $scopes[$id];
};

$workerId = null;

foreach ($testNames as $id => $name) {
    if (str_ends_with($name, 'worker 0') && ! str_contains($name, 'serial worker 0')) {
        $workerId = $id;

        break;
    }
}

if ($workerId === null) {
    throw new RuntimeException('Missing parallel worker 0.');
}

$worker = $tests[$workerId];
$assert($worker['status'] === 'passed', 'A parallel worker failed.');
$assert($worker['value']['prepared'] === ['file', 'parallel'], 'Nested beforeAll snapshot was not inherited.');
$assert($worker['stdout'] === 'worker:0', 'Worker output was not captured exactly once.');
$assert(
    array_slice($worker['context']['trace'], -2) === ['file.after_each.2', 'file.after_each.1'],
    'afterEach hooks did not unwind in reverse registration order.',
);

$assert(
    $test('reads first duplicate snapshot')['value'] === ['file', 'snapshots', 'first'],
    'The first duplicate scope received the wrong snapshot.',
);
$assert(
    $test('reads second duplicate snapshot')['value'] === ['file', 'snapshots', 'second'],
    'The second duplicate scope received the wrong snapshot.',
);

$unwind = $test('unwinds completed levels only');
$assert($unwind['failure']['kind'] === FailureKind::SetupFailure->value, 'Setup failure was misclassified.');
$assert(
    ! in_array('unwind.body', $unwind['context']['trace'], true)
        && ! in_array('unwind.after_each', $unwind['context']['trace'], true)
        && array_slice($unwind['context']['trace'], -2) === ['file.after_each.2', 'file.after_each.1'],
    'Failed setup unwound an incomplete scope.',
);

$bodyAndTeardown = $test('keeps body and teardown failures');
$assert(
    $bodyAndTeardown['failure']['kind'] === FailureKind::AssertionFailure->value
        && ($bodyAndTeardown['teardown_failures'][0]['kind'] ?? null) === FailureKind::TeardownFailure->value,
    'Body and teardown failures were not kept separately.',
);

$blocked = $test('is blocked by before all');
$assert($blocked['status'] === 'blocked', 'A failed beforeAll did not block its descendant.');
$assert($blocked['failure']['kind'] === FailureKind::BlockedDescendant->value, 'Blocked descendant was misclassified.');
$assert($scope('before all fails')['failure']['kind'] === FailureKind::SetupFailure->value, 'beforeAll failure was misclassified.');
$assert($test('still runs sibling scope')['status'] === 'passed', 'A failed scope blocked its sibling.');

$afterAllChild = $test('keeps passing child result');
$assert($afterAllChild['status'] === 'passed', 'afterAll failure rewrote its child result.');
$assert($scope('after all fails')['failure']['kind'] === FailureKind::TeardownFailure->value, 'afterAll failure was misclassified.');

$expectedKinds = [
    'classifies php exception' => FailureKind::PhpException->value,
    'classifies php fatal' => FailureKind::PhpFatalError->value,
    'classifies signal' => FailureKind::SignalTermination->value,
    'classifies missing terminal frame' => FailureKind::ChildProtocolFailure->value,
    'kills a timed out process tree' => FailureKind::Timeout->value,
];

foreach ($expectedKinds as $name => $kind) {
    $assert($test($name)['failure']['kind'] === $kind, $name.' was misclassified.');
}

$large = $test('frames large output');
$assert($large['status'] === 'passed' && strlen($large['stdout']) === 200_000, 'Large framed output was truncated.');
$assert($test('runs sentinel after failures')['value'] === 'sentinel passed', 'The root did not survive child failures.');

foreach ($parallel['events'] as $sequence => $event) {
    $assert($event['sequence'] === $sequence, 'Canonical event sequence is not contiguous.');
    $assert($event['run_id'] === 'phase-1-c8', 'An event has the wrong run ID.');
}

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'tests' => count($parallel['tests']),
    'events' => count($parallel['events']),
    'observed_concurrency' => $parallel['observed_concurrency'],
    'failure_kinds' => array_values($expectedKinds),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
