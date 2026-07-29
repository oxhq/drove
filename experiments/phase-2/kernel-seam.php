<?php

declare(strict_types=1);

use Drove\Kernel\FailureKind;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\Scheduler;
use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Drove\Kernel\TestOutcome;

require __DIR__.'/vendor/autoload.php';

final class PhaseTwoKernelRuntime
{
    /** @var list<string> */
    public array $trace = [];
}

$expect = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$scheduler = new class implements Scheduler
{
    public function runId(): string
    {
        return 'phase-2-kernel-seam';
    }

    public function map(array $tasks, Closure $execute): array
    {
        $results = [];
        $completionOrder = [];

        foreach (array_reverse($tasks, true) as $ordinal => $task) {
            $startedNs = hrtime(true);
            $value = $execute($task);
            $finishedNs = hrtime(true);
            $results[$ordinal] = [
                'id' => $task['id'],
                'kind' => $task['kind'],
                'scope_id' => $task['scope_id'],
                'ordinal' => $ordinal,
                'status' => 'passed',
                'failure' => null,
                'value' => $value,
                'stdout' => $task['kind'] === 'scope' ? 'scope-output' : '',
                'stderr' => $task['kind'] === 'scope' ? 'scope-diagnostic' : '',
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

        ksort($results);

        return [
            'results' => array_values($results),
            'completion_order' => $completionOrder,
        ];
    }

    public function withPermit(array $scopes, Closure $work): mixed
    {
        return $work();
    }
};

$ids = [
    'test:skipped',
    'test:raw',
    'test:todo',
    'test:passed',
    'test:failed',
];
$runtimes = [
    $ids[0] => new PhaseTwoKernelRuntime,
    $ids[2] => new PhaseTwoKernelRuntime,
    $ids[3] => new PhaseTwoKernelRuntime,
    $ids[4] => new PhaseTwoKernelRuntime,
];
$scopeBindings = [];
$rawContext = null;
$dispatchTrace = [];
$hooks = [
    'hook:before-all' => function (ScopeContext $context) use (&$scopeBindings): void {
        $scopeBindings[] = $this === $context;
    },
    'hook:before-each' => function (ScopeContext $context) use (&$rawContext): void {
        if ($this instanceof PhaseTwoKernelRuntime) {
            $this->trace[] = 'before:'.$context->metadata()['test_id'];

            return;
        }

        $rawContext = $context;
    },
    'hook:after-each' => function (ScopeContext $context): void {
        if ($this instanceof PhaseTwoKernelRuntime) {
            $this->trace[] = 'after:'.$context->metadata()['test_id'];
        }
    },
    'hook:after-all' => function (ScopeContext $context) use (&$scopeBindings): void {
        $scopeBindings[] = $this === $context;
    },
];
$bodies = [
    $ids[0] => function (ScopeContext $context): TestOutcome {
        $this->trace[] = 'body:'.$context->metadata()['test_id'];
        echo 'skipped-output';

        return TestOutcome::skipped('not available');
    },
    $ids[1] => function (ScopeContext $context) use (&$rawContext): string {
        if ($this !== $context || $context !== $rawContext) {
            throw new RuntimeException('A raw closure lost its ScopeContext binding.');
        }

        if ($context->metadata()['test_id'] !== 'test:raw') {
            throw new RuntimeException('The raw test did not receive its child context.');
        }

        echo 'raw-output';

        return 'ignored for Phase 1 compatibility';
    },
    $ids[2] => function (ScopeContext $context): TestOutcome {
        $this->trace[] = 'body:'.$context->metadata()['test_id'];

        return TestOutcome::todo('write later');
    },
    $ids[3] => function (ScopeContext $context): TestOutcome {
        $this->trace[] = 'body:'.$context->metadata()['test_id'];

        return TestOutcome::passed(['answer' => 42]);
    },
    $ids[4] => function (ScopeContext $context) use (&$dispatchTrace): never {
        $dispatchTrace[] = 'body:'.$context->metadata()['test_id'];
        $this->trace[] = 'body:'.$context->metadata()['test_id'];
        echo 'descendant-output';

        throw new RuntimeException('original boom');
    },
];
$tests = [];

foreach ($ids as $ordinal => $id) {
    $tests[] = [
        'id' => $id,
        'name' => 'case '.$ordinal,
        'source' => ['path' => 'tests/KernelSeamTest.php', 'line' => 10 + $ordinal],
        'dataset' => $ordinal === 0 ? ['key' => 'missing', 'label' => 'dataset "missing"'] : null,
        'groups' => $ordinal === 0 ? ['phase-two', 'slow'] : ['phase-two'],
        'timeout_ms' => 1_000,
    ];
}

$plan = [
    'root' => [
        'id' => 'suite:phase-two',
        'type' => 'suite',
        'hooks' => [
            'before_all' => ['hook:before-all'],
            'before_each' => ['hook:before-each'],
            'after_each' => ['hook:after-each'],
            'after_all' => ['hook:after-all'],
        ],
        'tests' => array_slice($tests, 0, 4),
        'children' => [[
            'id' => 'scope:child',
            'type' => 'describe',
            'hooks' => [
                'before_all' => [],
                'before_each' => [],
                'after_each' => [],
                'after_all' => [],
            ],
            'tests' => [$tests[4]],
            'children' => [],
        ]],
    ],
];
$executor = new LifecycleExecutor(
    $scheduler,
    static fn (string $id): Closure => $hooks[$id],
    static fn (string $id): Closure|array => isset($runtimes[$id])
        ? ['closure' => $bodies[$id], 'runtime' => $runtimes[$id]]
        : $bodies[$id],
    static function (ScopeContext $context) use (&$dispatchTrace): void {
        $dispatchTrace[] = 'before:'.$context->metadata()['id'];
    },
    static function (ScopeContext $context, array $task) use (&$dispatchTrace): void {
        $dispatchTrace[] = 'enter:'.$task['id'].':'.$context->metadata()['id'];
    },
    static function (ScopeContext $context, array $task) use (&$dispatchTrace): void {
        $dispatchTrace[] = 'leave:'.$task['id'].':'.$context->metadata()['id'];

        if ($task['id'] === 'test:failed') {
            throw new RuntimeException('descendant cleanup failed');
        }
    },
    static function (ScopeContext $context) use (&$dispatchTrace): void {
        $dispatchTrace[] = 'after:'.$context->metadata()['id'];
    },
);
$run = $executor->run($plan);
$projection = LifecycleExecutor::semanticProjection($run);
$results = array_combine(array_column($run['tests'], 'id'), $run['tests']);
$scopes = array_combine(array_column($run['scopes'], 'id'), $run['scopes']);
$projectedResults = array_combine(
    array_column($projection['tests'], 'id'),
    $projection['tests'],
);
$orderedEventIds = array_values(array_map(
    static fn (array $event): string => $event['test_id'],
    array_filter(
        $run['events'],
        static fn (array $event): bool => $event['type'] === 'test.started',
    ),
));

$expect(array_keys($results) === $ids, 'Kernel results drifted from Scope IR order.');
$expect(
    $run['completion_order'] === ['scope:child', $ids[3], $ids[2], $ids[1], $ids[0], $ids[4]],
    'The proof did not preserve nested completion order.',
);
$expect($orderedEventIds === $ids, 'Lifecycle events drifted from Scope IR order.');
$expect($scopeBindings === [true, true], 'A scope hook was rebound to a test runtime.');
$expect($results[$ids[0]]['status'] === 'skipped', 'Skipped outcome was not preserved.');
$expect($results[$ids[0]]['value'] === 'not available', 'Skipped reason was lost.');
$expect($results[$ids[0]]['stdout'] === 'skipped-output', 'Skipped output drifted.');
$expect($results[$ids[1]]['status'] === 'passed', 'Raw Phase 1 closure did not pass.');
$expect($results[$ids[1]]['value'] === null, 'Raw Phase 1 return values must remain ignored.');
$expect($results[$ids[1]]['stdout'] === 'raw-output', 'Raw output drifted.');
$expect($results[$ids[2]]['status'] === 'todo', 'Todo outcome was not preserved.');
$expect($results[$ids[3]]['value'] === ['answer' => 42], 'Passed outcome value was lost.');
$expect($results[$ids[4]]['status'] === 'failed', 'Thrown test did not fail.');
$expect($results[$ids[4]]['failure']['kind'] === FailureKind::PhpException->value, 'Failure kind changed.');
$expect($results[$ids[4]]['failure']['class'] === RuntimeException::class, 'Throwable class changed.');
$expect($results[$ids[4]]['failure']['message'] === 'original boom', 'Throwable message changed.');
$expect(
    $results[$ids[4]]['teardown_failures'][0]['message'] === 'descendant cleanup failed',
    'Descendant cleanup failure was not reported separately.',
);
$expect(
    $results[$ids[4]]['teardown_failures'][0]['kind'] === FailureKind::TeardownFailure->value,
    'Descendant cleanup failure was misclassified.',
);
$expect($results[$ids[4]]['stdout'] === 'descendant-output', 'Descendant output drifted.');
$expect($scopes['scope:child']['stdout'] === 'scope-output', 'Scope stdout was lost.');
$expect($scopes['scope:child']['stderr'] === 'scope-diagnostic', 'Scope stderr was lost.');
$expect($run['root']['failure'] === $results[$ids[4]]['failure'], 'Skipped or todo test failed the scope.');
$expect(
    $runtimes[$ids[0]]->trace === [
        'before:test:skipped',
        'body:test:skipped',
        'after:test:skipped',
    ],
    'Per-test closures were not bound to their runtime.',
);
$expect(
    array_intersect_key($results[$ids[0]], array_flip(['name', 'source', 'dataset', 'groups']))
        === array_intersect_key($tests[0], array_flip(['name', 'source', 'dataset', 'groups'])),
    'Result metadata drifted.',
);
$expect(
    array_intersect_key($projectedResults[$ids[0]], array_flip(['name', 'source', 'dataset', 'groups']))
        === array_intersect_key($tests[0], array_flip(['name', 'source', 'dataset', 'groups'])),
    'Semantic projection metadata drifted.',
);
$bodyFailurePosition = array_search('body:test:failed', $dispatchTrace, true);
$leaveFailurePosition = array_search('leave:test:failed:scope:child', $dispatchTrace, true);
$expect(
    is_int($bodyFailurePosition)
        && is_int($leaveFailurePosition)
        && $bodyFailurePosition < $leaveFailurePosition,
    'Descendant cleanup did not run after a failed body.',
);
$expect(
    in_array('before:suite:phase-two', $dispatchTrace, true)
        && in_array('after:suite:phase-two', $dispatchTrace, true)
        && in_array('before:scope:child', $dispatchTrace, true)
        && in_array('after:scope:child', $dispatchTrace, true),
    'Dispatch callbacks did not cover nested scope boundaries.',
);

$cleanupOnlyPlan = [
    'root' => [
        'id' => 'suite:cleanup-only',
        'type' => 'suite',
        'hooks' => [
            'before_all' => [],
            'before_each' => [],
            'after_each' => [],
            'after_all' => [],
        ],
        'tests' => [$tests[3]],
        'children' => [],
    ],
];
$cleanupOnlyRun = (new LifecycleExecutor(
    $scheduler,
    static fn (string $id): Closure => $hooks[$id],
    static fn (string $id): Closure|array => isset($runtimes[$id])
        ? ['closure' => $bodies[$id], 'runtime' => $runtimes[$id]]
        : $bodies[$id],
    leaveDescendant: static function (): never {
        throw new RuntimeException('only cleanup failed');
    },
))->run($cleanupOnlyPlan);
$cleanupOnlyResult = $cleanupOnlyRun['tests'][0];
$expect($cleanupOnlyRun['status'] === 'failed', 'A lone descendant cleanup failure passed the run.');
$expect(
    $cleanupOnlyResult['failure']['message'] === 'only cleanup failed'
        && $cleanupOnlyResult['failure'] === $cleanupOnlyResult['teardown_failures'][0],
    'A lone descendant cleanup failure was not promoted to primary.',
);
$cleanupOnlyTerminal = array_find(
    $cleanupOnlyResult['events'],
    static fn (array $event): bool => $event['type'] === 'test.finished',
);
$expect(
    is_array($cleanupOnlyTerminal)
        && $cleanupOnlyTerminal['status'] === 'failed'
        && $cleanupOnlyTerminal['failure'] === $cleanupOnlyResult['failure'],
    'A descendant cleanup failure left a passing terminal event.',
);

$afterDispatchRan = false;
$primaryPreserved = false;
$throwingScheduler = new class implements Scheduler
{
    public function runId(): string
    {
        return 'phase-2-dispatch-failure';
    }

    public function map(array $tasks, Closure $execute): array
    {
        throw new RuntimeException('primary dispatch failed');
    }

    public function withPermit(array $scopes, Closure $work): mixed
    {
        return $work();
    }
};
$throwingExecutor = new LifecycleExecutor(
    $throwingScheduler,
    static fn (string $id): Closure => $hooks[$id],
    static fn (string $id): Closure|array => isset($runtimes[$id])
        ? ['closure' => $bodies[$id], 'runtime' => $runtimes[$id]]
        : $bodies[$id],
    afterDispatch: static function () use (&$afterDispatchRan): never {
        $afterDispatchRan = true;

        throw new RuntimeException('after dispatch also failed');
    },
);

try {
    $throwingExecutor->run($cleanupOnlyPlan);
} catch (RuntimeException $exception) {
    $primaryPreserved = $exception->getMessage() === 'primary dispatch failed';
}

$expect($afterDispatchRan, 'afterDispatch did not run from the parent finally path.');
$expect($primaryPreserved, 'afterDispatch cleanup replaced the primary dispatch failure.');

$afterOnlyRun = (new LifecycleExecutor(
    $scheduler,
    static fn (string $id): Closure => $hooks[$id],
    static fn (string $id): Closure|array => isset($runtimes[$id])
        ? ['closure' => $bodies[$id], 'runtime' => $runtimes[$id]]
        : $bodies[$id],
    afterDispatch: static function (): never {
        throw new StateAdapterException('only after dispatch failed');
    },
))->run($cleanupOnlyPlan);
$expect(
    $afterOnlyRun['status'] === 'failed'
        && $afterOnlyRun['root']['failure']['kind'] === FailureKind::StateAdapterFailure->value
        && $afterOnlyRun['tests'][0]['status'] === 'passed',
    'A lone afterDispatch failure did not preserve completed test results.',
);

$beforeFailureAfterAllRan = false;
$beforeFailureAfterDispatchRan = false;
$beforeFailureHooks = $hooks + [
    'hook:adapter-after-all' => static function () use (&$beforeFailureAfterAllRan): void {
        $beforeFailureAfterAllRan = true;
    },
];
$beforeFailurePlan = $cleanupOnlyPlan;
$beforeFailurePlan['root']['hooks']['after_all'] = ['hook:adapter-after-all'];
$beforeFailureRun = (new LifecycleExecutor(
    $scheduler,
    static fn (string $id): Closure => $beforeFailureHooks[$id],
    static fn (string $id): Closure|array => isset($runtimes[$id])
        ? ['closure' => $bodies[$id], 'runtime' => $runtimes[$id]]
        : $bodies[$id],
    beforeDispatch: static function (): never {
        throw new StateAdapterException('before dispatch failed');
    },
    afterDispatch: static function () use (&$beforeFailureAfterDispatchRan): void {
        $beforeFailureAfterDispatchRan = true;
    },
))->run($beforeFailurePlan);
$expect(
    $beforeFailureRun['status'] === 'failed'
        && $beforeFailureRun['root']['failure']['kind'] === FailureKind::StateAdapterFailure->value
        && $beforeFailureRun['tests'][0]['status'] === 'blocked',
    'A beforeDispatch failure did not become a structured blocked scope.',
);
$expect(
    $beforeFailureAfterDispatchRan && $beforeFailureAfterAllRan,
    'A beforeDispatch failure skipped adapter or user cleanup.',
);

$invalidPlan = $plan;
$invalidPlan['root']['hooks'] = [
    'before_all' => [],
    'before_each' => [],
    'after_each' => [],
    'after_all' => [],
];
$invalidPlan['root']['tests'] = [[
    ...$tests[0],
    'source' => ['path' => 42, 'line' => 'invalid'],
]];
$invalidMetadataRejected = false;

try {
    $executor->run($invalidPlan);
} catch (InvalidArgumentException $exception) {
    $invalidMetadataRejected = $exception->getMessage() === 'Drove Scope IR contains invalid test metadata.';
}

$expect($invalidMetadataRejected, 'Invalid test metadata crossed the kernel boundary.');

echo json_encode([
    'status' => 'passed',
    'tests' => count($run['tests']),
    'ordered' => array_keys($results),
    'completed' => $run['completion_order'],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
