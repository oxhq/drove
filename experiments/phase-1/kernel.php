<?php

declare(strict_types=1);

use Drove\Kernel\ChildProtocol;
use Drove\Kernel\DroverScheduler;
use Drove\Kernel\FailureKind;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\PcntlScheduler;
use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Drove\Pest\ScopeCompiler;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\Framework\TestSuite as PHPUnitTestSuite;
use PHPUnit\TextUI\Configuration\Registry as PHPUnitConfiguration;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$backend = getenv('DROVE_KERNEL_BACKEND');

if ($backend === false || $backend === '') {
    $summaries = [];

    foreach (['pcntl', 'drover'] as $candidate) {
        putenv('DROVE_KERNEL_BACKEND='.$candidate);
        $process = proc_open(
            [PHP_BINARY, __FILE__],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the '.$candidate.' kernel conformance run.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || ! is_string($stdout)) {
            throw new RuntimeException(sprintf(
                "%s kernel conformance failed (exit %d).\n%s\n%s",
                $candidate,
                $exitCode,
                is_string($stdout) ? $stdout : '',
                is_string($stderr) ? $stderr : '',
            ));
        }

        $summary = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($summary) || ($summary['status'] ?? null) !== 'passed') {
            throw new RuntimeException($candidate.' returned an invalid kernel conformance summary.');
        }

        $summaries[$candidate] = $summary;
    }

    putenv('DROVE_KERNEL_BACKEND');

    if ($summaries['pcntl']['semantic_hash'] !== $summaries['drover']['semantic_hash']) {
        throw new RuntimeException('Pcntl and Drover changed the Phase 1 semantic projection.');
    }

    fwrite(STDOUT, json_encode([
        'status' => 'passed',
        'backends' => $summaries,
        'semantic_hash' => $summaries['pcntl']['semantic_hash'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

    exit(0);
}

if (! in_array($backend, ['pcntl', 'drover'], true)) {
    throw new RuntimeException('Unknown Drove kernel backend: '.$backend);
}

require __DIR__.'/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$firstDifference = null;
$firstDifference = static function (mixed $expected, mixed $actual, string $path = '$') use (&$firstDifference): ?string {
    if (get_debug_type($expected) !== get_debug_type($actual)) {
        return sprintf(
            '%s type is %s, expected %s',
            $path,
            get_debug_type($actual),
            get_debug_type($expected),
        );
    }

    if (is_array($expected)) {
        if (array_keys($expected) !== array_keys($actual)) {
            return sprintf('%s keys differ', $path);
        }

        foreach ($expected as $key => $value) {
            $difference = $firstDifference($value, $actual[$key], $path.'['.json_encode($key).']');

            if ($difference !== null) {
                return $difference;
            }
        }

        return null;
    }

    if ($expected !== $actual) {
        $expectedValue = substr(json_encode($expected, JSON_THROW_ON_ERROR), 0, 160);
        $actualValue = substr(json_encode($actual, JSON_THROW_ON_ERROR), 0, 160);

        return sprintf('%s is %s, expected %s', $path, $actualValue, $expectedValue);
    }

    return null;
};

$vectorDirectory = getenv('DROVER_PROTOCOL_VECTORS')
    ?: realpath(__DIR__.'/../../native/drover/protocol/v1');

if (! is_string($vectorDirectory) || ! is_dir($vectorDirectory)) {
    throw new RuntimeException('The shared ChildProtocol v1 vectors are unavailable.');
}

$vectorTask = [
    'id' => 'task:golden',
    'kind' => 'test',
    'scope_id' => 'scope:golden',
    'scopes' => ['scope:golden'],
    'timeout_ms' => 1_000,
    'permit' => true,
    'ordinal' => 7,
];
$vectors = array_map(
    static function (string $filename) use ($vectorDirectory): string {
        $json = file_get_contents($vectorDirectory.'/'.$filename);

        return is_string($json)
            ? $json
            : throw new RuntimeException('Unable to read shared protocol vector '.$filename);
    },
    ['started.json', 'event.json', 'value.json', 'finished.json'],
);
$vectorResult = (new ChildProtocol('golden-run'))->validateSequence($vectorTask, $vectors);
$assert(
    $vectorResult['stdout'] === 'golden'
        && $vectorResult['stderr'] === ''
        && $vectorResult['value'] === ['ok' => true],
    'PHP rejected the shared ChildProtocol v1 vectors.',
);

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

$fileId = $basePlan['root']['children'][0]['id'];
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
$contextTestId = 'test:scope-context';
$contextTestName = 'exposes root scope context';
$plan['root']['path'] = '';
$plan['root']['metadata'] = ['layer' => 'root', 'root_marker' => true];
$plan['root']['state_policy'] = 'transactional';
array_unshift($plan['root']['tests'], [
    'id' => $contextTestId,
    'name' => $contextTestName,
    'timeout_ms' => 1_000,
]);
array_unshift($plannedIds, $contextTestId);
$testNames[$contextTestId] = $contextTestName;
$fileNode = &$plan['root']['children'][0];
$fileNode['metadata'] = ['layer' => 'file', 'file_marker' => true];

foreach ($fileNode['children'] as &$childNode) {
    if (($childNode['name'] ?? null) === 'parallel') {
        $childNode['metadata'] = ['layer' => 'parallel'];
    }

    if (($childNode['name'] ?? null) === 'snapshots') {
        $childNode['state_policy'] = 'snapshot';
    }
}

unset($childNode, $fileNode);

$contextTest = static function (ScopeContext $context): void {
    $metadata = $context->metadata();

    if ($metadata !== [
        'id' => 'suite:root',
        'type' => 'suite',
        'name' => 'Drove Phase 1',
        'path' => '',
        'layer' => 'root',
        'root_marker' => true,
        'bootstrap' => true,
    ]) {
        throw new RuntimeException('The root scope received incomplete metadata.');
    }

    if ($context->state() !== ['policy' => 'transactional', 'adapter' => 'sqlite']) {
        throw new RuntimeException('The root scope received incomplete state.');
    }

    $context->share('nullable', null);

    if ($context->get('nullable') !== null) {
        throw new RuntimeException('The root scope could not retrieve a shared null value.');
    }
};

$runAt = static function (int $concurrency) use (
    $backend,
    $compiler,
    $contextTest,
    $contextTestId,
    $limits,
    $plan,
): array {
    $scheduler = match ($backend) {
        'pcntl' => new PcntlScheduler(
            'phase-1-c'.$concurrency,
            $concurrency,
            $limits,
            3_000,
            50,
        ),
        'drover' => new DroverScheduler(
            'phase-1-c'.$concurrency,
            $concurrency,
            $limits,
            3_000,
            50,
        ),
    };
    $executor = new LifecycleExecutor(
        $scheduler,
        static fn (string $id): Closure => $compiler->hook($id),
        static fn (string $id): Closure => $id === $contextTestId
            ? $contextTest
            : $compiler->closure($id),
    );

    return $executor->run(
        $plan,
        new ScopeContext(
            metadata: ['bootstrap' => true],
            state: ['adapter' => 'sqlite'],
        ),
    );
};

$sequential = $runAt(1);
$parallel = $runAt(8);
$sequentialProjection = LifecycleExecutor::semanticProjection($sequential);
$parallelProjection = LifecycleExecutor::semanticProjection($parallel);
$assert(
    $sequentialProjection === $parallelProjection,
    'Concurrency 1 and 8 changed Phase 1 semantics: '
        .($firstDifference($sequentialProjection, $parallelProjection) ?? 'unknown difference'),
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
$assert($worker['stdout'] === 'worker:0', 'Worker output was not captured exactly once.');
$workerAfterEach = array_values(array_filter(
    $worker['events'],
    static fn (array $event): bool => $event['type'] === 'hook.finished'
        && $event['phase'] === 'after_each',
));
$assert(
    array_map(
        static fn (array $event): string => substr($event['hook_id'], -1),
        $workerAfterEach,
    ) === ['1', '0'],
    'afterEach hooks did not unwind in reverse registration order.',
);
$assert($test('reads first duplicate snapshot')['status'] === 'passed', 'The first duplicate scope received the wrong snapshot.');
$assert($test('reads second duplicate snapshot')['status'] === 'passed', 'The second duplicate scope received the wrong snapshot.');

$unwind = $test('unwinds completed levels only');
$assert($unwind['failure']['kind'] === FailureKind::SetupFailure->value, 'Setup failure was misclassified.');
$unwindEvents = array_column($unwind['events'], null, 'type');
$unwindAfterEach = array_values(array_filter(
    $unwind['events'],
    static fn (array $event): bool => $event['type'] === 'hook.finished'
        && $event['phase'] === 'after_each',
));
$assert(isset($unwindEvents['test.body.skipped']), 'Failed setup still ran the body.');
$assert(
    count($unwindAfterEach) === 2
        && array_map(
            static fn (array $event): string => substr($event['hook_id'], -1),
            $unwindAfterEach,
        ) === ['1', '0'],
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
$assert($test('is recursively blocked')['status'] === 'blocked', 'A failed beforeAll materialized a child scope.');
$failedBeforeAllScope = $scope('before all fails');
$assert($failedBeforeAllScope['failure']['kind'] === FailureKind::SetupFailure->value, 'beforeAll failure was misclassified.');
$assert(
    ! array_any(
        $parallel['events'],
        static fn (array $event): bool => $event['scope_id'] === $failedBeforeAllScope['id']
            && $event['phase'] === 'after_all',
    ),
    'afterAll ran for an uninitialized scope.',
);
$assert($test('still runs sibling scope')['stdout'] === 'sibling passed', 'A failed scope blocked its sibling.');

$afterAllChild = $test('keeps passing child result');
$assert($afterAllChild['status'] === 'passed', 'afterAll failure rewrote its child result.');
$assert($scope('after all fails')['failure']['kind'] === FailureKind::TeardownFailure->value, 'afterAll failure was misclassified.');
$assert(
    array_any(
        $parallel['events'],
        static fn (array $event): bool => $event['scope_id'] === $fileId
            && $event['type'] === 'hook.finished'
            && $event['phase'] === 'after_all'
            && $event['status'] === 'passed',
    ),
    'Ancestor afterAll did not run after a blocked child.',
);

$expectedKinds = [
    'classifies php exception' => FailureKind::PhpException->value,
    'classifies php fatal' => FailureKind::PhpFatalError->value,
    'classifies signal' => FailureKind::SignalTermination->value,
    'classifies missing terminal frame' => FailureKind::ChildProtocolFailure->value,
    'kills a timed out process tree' => FailureKind::Timeout->value,
];

$assert(
    FailureKind::for(new StateAdapterException('adapter failed'), 'executor')
        === FailureKind::StateAdapterFailure,
    'State adapter failures lost their dedicated classification.',
);

foreach ($expectedKinds as $name => $kind) {
    $failed = $test($name);
    $actual = $failed['failure']['kind'];
    $assert($actual === $kind, sprintf(
        '%s was %s (%s), expected %s.',
        $name,
        $actual,
        $failed['failure']['message'],
        $kind,
    ));
}

$large = $test('frames large output');
$assert($large['status'] === 'passed' && strlen($large['stdout']) === 1_200_000, 'Large framed output was truncated.');
$assert($test('runs sentinel after failures')['stdout'] === 'sentinel passed', 'The root did not survive child failures.');

foreach ([$sequential, $parallel] as $run) {
    $timedOut = array_column($run['tests'], null, 'id')[$timeoutId];
    usleep(300_000);
    $assert(
        ! file_exists('/tmp/drove-timeout-tree-'.$timedOut['telemetry']['pid']),
        'A timed-out grandchild escaped its process group.',
    );
}

$nativeChildExitChecked = false;

if ($backend === 'drover') {
    $sentinelPrefix = '/tmp/drove-inherited-shutdown-'.bin2hex(random_bytes(8)).'-';
    $parentPid = getmypid();
    register_shutdown_function(static function () use ($parentPid, $sentinelPrefix): void {
        if (getmypid() !== $parentPid) {
            file_put_contents($sentinelPrefix.getmypid(), 'inherited shutdown ran');
        }
    });
    $exitProbe = new DroverScheduler('phase-1-native-child-exit', 1, termGraceMs: 25);
    $exitProbeRun = $exitProbe->map(
        [
            [
                'id' => 'normal-native-exit',
                'kind' => 'test',
                'scope_id' => 'root',
                'scopes' => ['root'],
                'timeout_ms' => 1_000,
                'permit' => true,
            ],
            [
                'id' => 'signal-native-exit',
                'kind' => 'scope',
                'scope_id' => 'root',
                'scopes' => ['root'],
                'timeout_ms' => 100,
                'permit' => false,
            ],
        ],
        static function (array $task): string {
            if ($task['id'] === 'signal-native-exit') {
                usleep(2_000_000);
            }

            return 'native exit';
        },
    );
    $exitResults = array_column($exitProbeRun['results'], null, 'id');
    $assert(
        $exitResults['normal-native-exit']['status'] === 'passed'
            && $exitResults['signal-native-exit']['failure']['kind'] === FailureKind::Timeout->value,
        'The native child-exit lifecycle probe did not exercise both termination paths.',
    );

    foreach ($exitResults as $result) {
        $assert(
            ! file_exists($sentinelPrefix.$result['telemetry']['pid']),
            'A Drover child ran inherited PHP shutdown machinery.',
        );
    }

    $nativeChildExitChecked = true;
}

foreach ($parallel['events'] as $sequence => $event) {
    $assert($event['sequence'] === $sequence, 'Canonical event sequence is not contiguous.');
    $assert($event['run_id'] === 'phase-1-c8', 'An event has the wrong run ID.');
}

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'backend' => $backend,
    'tests' => count($parallel['tests']),
    'events' => count($parallel['events']),
    'observed_concurrency' => $parallel['observed_concurrency'],
    'failure_kinds' => array_values($expectedKinds),
    'native_child_exit_checked' => $nativeChildExitChecked,
    'semantic_hash' => hash(
        'sha256',
        json_encode(LifecycleExecutor::semanticProjection($parallel), JSON_THROW_ON_ERROR),
    ),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
