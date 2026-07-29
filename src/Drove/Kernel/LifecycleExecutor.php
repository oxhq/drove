<?php

declare(strict_types=1);

namespace Drove\Kernel;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final readonly class LifecycleExecutor
{
    /**
     * @param  Closure(string): (Closure|array<string, mixed>)  $testResolver
     */
    public function __construct(
        private Scheduler $scheduler,
        private Closure $hookResolver,
        private Closure $testResolver,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $suitePlan
     * @return array<string, mixed>
     */
    public function run(array $suitePlan, ?ScopeContext $context = null): array
    {
        $root = $suitePlan['root'] ?? $suitePlan;

        if (! is_array($root) || ($root['type'] ?? null) !== 'suite') {
            throw new InvalidArgumentException('Drove requires a suite-root Scope IR plan.');
        }

        $startedNs = hrtime(true);
        $result = $this->runScope(
            $root,
            $this->scopeContext($root, $context ?? new ScopeContext),
            [],
        );
        $finishedNs = hrtime(true);
        $events = [[
            'type' => 'run.started',
            'scope_id' => $root['id'],
            'test_id' => null,
            'hook_id' => null,
            'phase' => null,
            'status' => 'running',
            'failure' => null,
            'pid' => getmypid(),
        ], ...$result['events'], [
            'type' => 'run.finished',
            'scope_id' => $root['id'],
            'test_id' => null,
            'hook_id' => null,
            'phase' => null,
            'status' => $result['scope']['status'],
            'failure' => $result['scope']['failure'],
            'pid' => getmypid(),
        ]];

        foreach ($events as $sequence => &$event) {
            $event = [
                'schema' => 1,
                'run_id' => $this->scheduler->runId(),
                'sequence' => $sequence,
            ] + $event;
        }

        unset($event);

        $scopePeaks = [];

        foreach ($result['scopes'] as $scope) {
            if (is_int($scope['concurrency'] ?? null)) {
                $scopePeaks[$scope['id']] = $this->peak($result['tests'], $scope['id']);
            }
        }

        return [
            'schema' => 1,
            'run_id' => $this->scheduler->runId(),
            'status' => $result['scope']['status'],
            'exit_code' => $result['scope']['status'] === 'passed' ? 0 : 1,
            'duration_ms' => round(($finishedNs - $startedNs) / 1_000_000, 3),
            'root' => $result['scope'],
            'scopes' => $result['scopes'],
            'tests' => $result['tests'],
            'events' => $events,
            'completion_order' => $result['completion_order'],
            'observed_concurrency' => [
                'global' => $this->peak($result['tests']),
                'scopes' => $scopePeaks,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    public static function semanticProjection(array $run): array
    {
        $tests = array_map(static function (array $test): array {
            unset($test['telemetry'], $test['events']);

            return $test;
        }, $run['tests']);

        $events = array_map(static function (array $event): array {
            unset(
                $event['run_id'],
                $event['sequence'],
                $event['pid'],
                $event['duration_ms'],
                $event['started_ns'],
                $event['finished_ns'],
            );

            return $event;
        }, $run['events']);

        return [
            'status' => $run['status'],
            'exit_code' => $run['exit_code'],
            'root' => $run['root'],
            'scopes' => $run['scopes'],
            'tests' => $tests,
            'events' => $events,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array{id: string, before_each: list<string>, after_each: list<string>}>  $levels
     * @return array{scope: array<string, mixed>, scopes: list<array<string, mixed>>, tests: list<array<string, mixed>>, events: list<array<string, mixed>>, completion_order: list<string>}
     */
    private function runScope(array $node, ScopeContext $context, array $levels): array
    {
        $scopeId = $this->string($node, 'id');
        $hooks = $this->hooks($node);
        $scopeIds = [...array_column($levels, 'id'), $scopeId];
        $events = [$this->event('scope.started', $scopeId, status: 'running')];
        $scopeFailures = [];
        $initialized = true;

        $this->scheduler->withPermit($scopeIds, function () use (
            $hooks,
            $context,
            $scopeId,
            &$events,
            &$scopeFailures,
            &$initialized,
        ): void {
            foreach ($hooks['before_all'] as $hookId) {
                $events[] = $this->event('hook.started', $scopeId, hookId: $hookId, phase: 'before_all');

                try {
                    $this->invoke($this->hook($hookId), $context);
                    $events[] = $this->event(
                        'hook.finished',
                        $scopeId,
                        hookId: $hookId,
                        phase: 'before_all',
                        status: 'passed',
                    );
                } catch (Throwable $throwable) {
                    $failure = $this->failure($throwable, 'before_all', $hookId);
                    $scopeFailures[] = $failure;
                    $initialized = false;
                    $events[] = $this->event(
                        'hook.finished',
                        $scopeId,
                        hookId: $hookId,
                        phase: 'before_all',
                        status: 'failed',
                        failure: $failure,
                    );

                    break;
                }
            }
        });

        $scope = [
            'id' => $scopeId,
            'type' => $this->string($node, 'type'),
            'status' => 'passed',
            'failure' => null,
            'failures' => [],
            'initialized' => $initialized,
            'concurrency' => $node['concurrency'] ?? null,
        ];

        if (! $initialized) {
            $blocked = $this->blockedDescendants($node, $scopeFailures[0]);
            $deferred = $this->runDeferred($context, $scopeIds, $scopeId);
            array_push($events, ...$blocked['events'], ...$deferred['events']);
            array_push($scopeFailures, ...$deferred['failures']);
            $scope['status'] = 'failed';
            $scope['failure'] = $scopeFailures[0];
            $scope['failures'] = $scopeFailures;
            $events[] = $this->event(
                'scope.finished',
                $scopeId,
                status: 'failed',
                failure: $scope['failure'],
            );

            return [
                'scope' => $scope,
                'scopes' => [$scope, ...$blocked['scopes']],
                'tests' => $blocked['tests'],
                'events' => $events,
                'completion_order' => [],
            ];
        }

        $currentLevel = [
            'id' => $scopeId,
            'before_each' => $hooks['before_each'],
            'after_each' => $hooks['after_each'],
        ];
        $nextLevels = [...$levels, $currentLevel];
        $jobs = [];
        $tasks = [];

        foreach ($node['tests'] ?? [] as $test) {
            if (! is_array($test)) {
                throw new InvalidArgumentException('Drove Scope IR contains an invalid test node.');
            }

            $id = $this->string($test, 'id');
            $jobs[$id] = ['kind' => 'test', 'node' => $test];
            $tasks[] = [
                'id' => $id,
                'kind' => 'test',
                'scope_id' => $scopeId,
                'scopes' => $scopeIds,
                'timeout_ms' => $test['timeout_ms'] ?? 1_000,
                'permit' => true,
            ];
        }

        foreach ($node['children'] ?? [] as $child) {
            if (! is_array($child)) {
                throw new InvalidArgumentException('Drove Scope IR contains an invalid child scope.');
            }

            $id = $this->string($child, 'id');
            $jobs[$id] = ['kind' => 'scope', 'node' => $child];
            $tasks[] = [
                'id' => $id,
                'kind' => 'scope',
                'scope_id' => $id,
                'scopes' => [...$scopeIds, $id],
                'timeout_ms' => $child['timeout_ms'] ?? 30_000,
                'permit' => false,
            ];
        }

        $mapped = $this->scheduler->map(
            $tasks,
            function (array $task) use ($jobs, $context, $nextLevels): array {
                $job = $jobs[$task['id']];

                if ($job['kind'] === 'test') {
                    return $this->runTest($job['node'], $nextLevels, $context);
                }

                $child = $job['node'];

                return $this->runScope($child, $this->scopeContext($child, $context), $nextLevels);
            },
        );

        $tests = [];
        $scopes = [];
        $completionOrder = $mapped['completion_order'];

        foreach ($mapped['results'] as $transport) {
            $job = $jobs[$transport['id']];
            $events[] = $this->taskEvent('task.started', $transport);

            if ($job['kind'] === 'test') {
                $test = $transport['status'] === 'passed' && is_array($transport['value'])
                    ? $transport['value']
                    : $this->transportTestFailure($job['node'], $scopeIds, $transport);
                $test['telemetry'] = $transport['telemetry'];
                $test['stdout'] = ($test['stdout'] ?? '').($transport['stdout'] ?? '');
                $test['stderr'] = ($test['stderr'] ?? '').($transport['stderr'] ?? '');
                $tests[] = $test;
                array_push($events, ...$test['events']);
            } else {
                $child = $transport['status'] === 'passed' && is_array($transport['value'])
                    ? $transport['value']
                    : $this->transportScopeFailure($job['node'], $transport);
                array_push($tests, ...$child['tests']);
                array_push($scopes, ...$child['scopes']);
                array_push($events, ...$child['events']);
                array_push($completionOrder, ...$child['completion_order']);
            }

            $events[] = $this->taskEvent('task.finished', $transport);
        }

        $this->scheduler->withPermit($scopeIds, function () use (
            $hooks,
            $context,
            $scopeId,
            &$events,
            &$scopeFailures,
        ): void {
            foreach (array_reverse($hooks['after_all']) as $hookId) {
                $events[] = $this->event('hook.started', $scopeId, hookId: $hookId, phase: 'after_all');

                try {
                    $this->invoke($this->hook($hookId), $context);
                    $events[] = $this->event(
                        'hook.finished',
                        $scopeId,
                        hookId: $hookId,
                        phase: 'after_all',
                        status: 'passed',
                    );
                } catch (Throwable $throwable) {
                    $failure = $this->failure($throwable, 'after_all', $hookId);
                    $scopeFailures[] = $failure;
                    $events[] = $this->event(
                        'hook.finished',
                        $scopeId,
                        hookId: $hookId,
                        phase: 'after_all',
                        status: 'failed',
                        failure: $failure,
                    );
                }
            }
        });

        $deferred = $this->runDeferred($context, $scopeIds, $scopeId);
        array_push($events, ...$deferred['events']);
        array_push($scopeFailures, ...$deferred['failures']);

        $descendantFailed = array_any(
            $tests,
            static fn (array $test): bool => $test['failure'] !== null,
        ) || array_any(
            $scopes,
            static fn (array $child): bool => $child['failure'] !== null,
        );
        $failedDescendant = array_find(
            [...$tests, ...$scopes],
            static fn (array $result): bool => $result['failure'] !== null,
        );
        $scope['status'] = $scopeFailures === [] && ! $descendantFailed ? 'passed' : 'failed';
        $scope['failure'] = $scopeFailures[0] ?? $failedDescendant['failure'] ?? null;
        $scope['failures'] = $scopeFailures;
        $events[] = $this->event(
            'scope.finished',
            $scopeId,
            status: $scope['status'],
            failure: $scope['failure'],
        );

        return [
            'scope' => $scope,
            'scopes' => [$scope, ...$scopes],
            'tests' => $tests,
            'events' => $events,
            'completion_order' => $completionOrder,
        ];
    }

    /**
     * @param  array<string, mixed>  $test
     * @param  list<array{id: string, before_each: list<string>, after_each: list<string>}>  $levels
     * @return array<string, mixed>
     */
    private function runTest(array $test, array $levels, ScopeContext $context): array
    {
        $testId = $this->string($test, 'id');

        if ($levels === []) {
            throw new InvalidArgumentException('A Drove test must belong to a scope.');
        }

        $scopeId = $levels[count($levels) - 1]['id'];
        $context = $context->child(['test_id' => $testId]);
        ['closure' => $body, 'runtime' => $runtime] = $this->test($testId);
        $events = [$this->event('test.started', $scopeId, $testId, status: 'running')];
        $completed = [];
        $primaryFailure = null;
        $teardownFailures = [];
        $outcome = TestOutcome::passed();
        $outputLevel = ob_get_level();
        ob_start();

        foreach ($levels as $level) {
            $levelCompleted = true;

            foreach ($level['before_each'] as $hookId) {
                $events[] = $this->event(
                    'hook.started',
                    $level['id'],
                    $testId,
                    $hookId,
                    'before_each',
                );

                try {
                    $this->invoke($this->hook($hookId), $context, $runtime);
                    $events[] = $this->event(
                        'hook.finished',
                        $level['id'],
                        $testId,
                        $hookId,
                        'before_each',
                        'passed',
                    );
                } catch (Throwable $throwable) {
                    $primaryFailure = $this->failure($throwable, 'before_each', $hookId);
                    $events[] = $this->event(
                        'hook.finished',
                        $level['id'],
                        $testId,
                        $hookId,
                        'before_each',
                        'failed',
                        $primaryFailure,
                    );
                    $levelCompleted = false;

                    break;
                }
            }

            if (! $levelCompleted) {
                break;
            }

            $completed[] = $level;
        }

        if ($primaryFailure === null) {
            $events[] = $this->event(
                'test.body.started',
                $scopeId,
                $testId,
                status: 'running',
            );

            try {
                $returned = $this->invoke($body, $context, $runtime);
                $outcome = $returned instanceof TestOutcome
                    ? $returned
                    : TestOutcome::passed();
                $events[] = $this->event(
                    'test.body.finished',
                    $scopeId,
                    $testId,
                    status: $outcome->status,
                );
            } catch (Throwable $throwable) {
                $primaryFailure = $this->failure($throwable, 'test', null);
                $events[] = $this->event(
                    'test.body.finished',
                    $scopeId,
                    $testId,
                    status: 'failed',
                    failure: $primaryFailure,
                );
            }
        } else {
            $events[] = $this->event(
                'test.body.skipped',
                $scopeId,
                $testId,
                status: 'blocked',
                failure: $primaryFailure,
            );
        }

        foreach (array_reverse($completed) as $level) {
            foreach (array_reverse($level['after_each']) as $hookId) {
                $events[] = $this->event(
                    'hook.started',
                    $level['id'],
                    $testId,
                    $hookId,
                    'after_each',
                );

                try {
                    $this->invoke($this->hook($hookId), $context, $runtime);
                    $events[] = $this->event(
                        'hook.finished',
                        $level['id'],
                        $testId,
                        $hookId,
                        'after_each',
                        'passed',
                    );
                } catch (Throwable $throwable) {
                    $failure = $this->failure($throwable, 'after_each', $hookId);
                    $teardownFailures[] = $failure;
                    $primaryFailure ??= $failure;
                    $events[] = $this->event(
                        'hook.finished',
                        $level['id'],
                        $testId,
                        $hookId,
                        'after_each',
                        'failed',
                        $failure,
                    );
                }
            }
        }

        $stdout = '';

        while (ob_get_level() > $outputLevel) {
            $stdout = ob_get_clean().$stdout;
        }

        $status = $primaryFailure === null ? $outcome->status : 'failed';
        $events[] = $this->event(
            'test.finished',
            $scopeId,
            $testId,
            status: $status,
            failure: $primaryFailure,
        );

        return [
            'id' => $testId,
            'scope_id' => $scopeId,
            'scopes' => array_column($levels, 'id'),
            ...$this->testMetadata($test),
            'status' => $status,
            'failure' => $primaryFailure,
            'teardown_failures' => $teardownFailures,
            'value' => $outcome->value,
            'stdout' => $stdout,
            'stderr' => '',
            'events' => $events,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $failure
     * @return array{scopes: list<array<string, mixed>>, tests: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function blockedDescendants(array $node, array $failure): array
    {
        $scopeId = $this->string($node, 'id');
        $scopes = [];
        $tests = [];
        $events = [];

        foreach ($node['tests'] ?? [] as $test) {
            $id = $this->string($test, 'id');
            $blockedFailure = [
                'kind' => FailureKind::BlockedDescendant->value,
                'message' => sprintf('Blocked by failed scope %s.', $scopeId),
                'class' => null,
                'file' => null,
                'line' => null,
                'phase' => 'before_all',
                'hook_id' => $failure['hook_id'] ?? null,
            ];
            $tests[] = [
                'id' => $id,
                'scope_id' => $scopeId,
                'scopes' => [$scopeId],
                ...$this->testMetadata($test),
                'status' => 'blocked',
                'failure' => $blockedFailure,
                'teardown_failures' => [],
                'value' => null,
                'stdout' => '',
                'stderr' => '',
                'events' => [],
                'telemetry' => null,
            ];
            $events[] = $this->event(
                'test.finished',
                $scopeId,
                $id,
                status: 'blocked',
                failure: $blockedFailure,
            );
        }

        foreach ($node['children'] ?? [] as $child) {
            $childId = $this->string($child, 'id');
            $blocked = $this->blockedDescendants($child, $failure);
            $childFailure = [
                'kind' => FailureKind::BlockedDescendant->value,
                'message' => sprintf('Blocked by failed scope %s.', $scopeId),
                'class' => null,
                'file' => null,
                'line' => null,
                'phase' => 'before_all',
                'hook_id' => $failure['hook_id'] ?? null,
            ];
            $scopes[] = [
                'id' => $childId,
                'type' => $this->string($child, 'type'),
                'status' => 'blocked',
                'failure' => $childFailure,
                'failures' => [$childFailure],
                'initialized' => false,
                'concurrency' => $child['concurrency'] ?? null,
            ];
            array_push($scopes, ...$blocked['scopes']);
            array_push($tests, ...$blocked['tests']);
            $events[] = $this->event(
                'scope.finished',
                $childId,
                status: 'blocked',
                failure: $childFailure,
            );
            array_push($events, ...$blocked['events']);
        }

        return ['scopes' => $scopes, 'tests' => $tests, 'events' => $events];
    }

    /**
     * @param  list<string>  $scopeIds
     * @return array{events: list<array<string, mixed>>, failures: list<array<string, mixed>>}
     */
    private function runDeferred(
        ScopeContext $context,
        array $scopeIds,
        string $scopeId,
    ): array {
        $events = [];
        $failures = [];

        $this->scheduler->withPermit($scopeIds, function () use (
            $context,
            $scopeId,
            &$events,
            &$failures,
        ): void {
            foreach ($context->drainDeferred() as $ordinal => $cleanup) {
                $hookId = sprintf('defer:%s:%d', $scopeId, $ordinal);
                $events[] = $this->event('hook.started', $scopeId, hookId: $hookId, phase: 'defer');

                try {
                    $this->invoke($cleanup, $context);
                    $events[] = $this->event(
                        'hook.finished',
                        $scopeId,
                        hookId: $hookId,
                        phase: 'defer',
                        status: 'passed',
                    );
                } catch (Throwable $throwable) {
                    $failure = $this->failure($throwable, 'defer', $hookId);
                    $failures[] = $failure;
                    $events[] = $this->event(
                        'hook.finished',
                        $scopeId,
                        hookId: $hookId,
                        phase: 'defer',
                        status: 'failed',
                        failure: $failure,
                    );
                }
            }
        });

        return ['events' => $events, 'failures' => $failures];
    }

    /**
     * @param  array<string, mixed>  $test
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $transport
     * @return array<string, mixed>
     */
    private function transportTestFailure(array $test, array $scopes, array $transport): array
    {
        return [
            'id' => $this->string($test, 'id'),
            'scope_id' => $transport['scope_id'],
            'scopes' => $scopes,
            ...$this->testMetadata($test),
            'status' => 'failed',
            'failure' => $transport['failure'],
            'teardown_failures' => [],
            'value' => null,
            'stdout' => $transport['stdout'] ?? '',
            'stderr' => $transport['stderr'] ?? '',
            'events' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $transport
     * @return array{scope: array<string, mixed>, scopes: list<array<string, mixed>>, tests: list<array<string, mixed>>, events: list<array<string, mixed>>, completion_order: list<string>}
     */
    private function transportScopeFailure(array $node, array $transport): array
    {
        $blocked = $this->blockedDescendants($node, $transport['failure']);
        $scope = [
            'id' => $this->string($node, 'id'),
            'type' => $this->string($node, 'type'),
            'status' => 'failed',
            'failure' => $transport['failure'],
            'failures' => [$transport['failure']],
            'initialized' => false,
            'concurrency' => $node['concurrency'] ?? null,
        ];

        return [
            'scope' => $scope,
            'scopes' => [$scope, ...$blocked['scopes']],
            'tests' => $blocked['tests'],
            'events' => $blocked['events'],
            'completion_order' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $transport
     * @return array<string, mixed>
     */
    private function taskEvent(string $type, array $transport): array
    {
        return [
            'type' => $type,
            'scope_id' => $transport['scope_id'],
            'test_id' => $transport['kind'] === 'test' ? $transport['id'] : null,
            'hook_id' => null,
            'phase' => 'scheduler',
            'status' => $type === 'task.started' ? 'running' : $transport['status'],
            'failure' => $type === 'task.finished' ? $transport['failure'] : null,
            'pid' => $transport['telemetry']['pid'],
            'duration_ms' => $type === 'task.finished'
                ? $transport['telemetry']['duration_ms']
                : null,
            'started_ns' => $transport['telemetry']['started_ns'],
            'finished_ns' => $type === 'task.finished'
                ? $transport['telemetry']['finished_ns']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{before_all: list<string>, before_each: list<string>, after_each: list<string>, after_all: list<string>}
     */
    private function hooks(array $node): array
    {
        $hooks = $node['hooks'] ?? null;

        if (! is_array($hooks)) {
            throw new InvalidArgumentException('Drove Scope IR contains an invalid hook set.');
        }

        foreach (['before_all', 'before_each', 'after_each', 'after_all'] as $phase) {
            if (! is_array($hooks[$phase] ?? null)
                || ! array_is_list($hooks[$phase])
                || array_any($hooks[$phase], static fn (mixed $id): bool => ! is_string($id))) {
                throw new InvalidArgumentException(sprintf('Drove Scope IR contains invalid %s hooks.', $phase));
            }
        }

        return $hooks;
    }

    private function hook(string $id): Closure
    {
        $hook = ($this->hookResolver)($id);

        return $hook instanceof Closure
            ? $hook
            : throw new RuntimeException(sprintf('Drove could not resolve hook %s.', $id));
    }

    /**
     * @return array{closure: Closure, runtime: ?object}
     */
    private function test(string $id): array
    {
        $test = ($this->testResolver)($id);

        if ($test instanceof Closure) {
            return ['closure' => $test, 'runtime' => null];
        }

        $closure = $test['closure'] ?? null;
        $runtime = $test['runtime'] ?? null;

        if (! $closure instanceof Closure
            || ($runtime !== null && ! is_object($runtime))) {
            throw new RuntimeException(sprintf('Drove could not resolve test %s.', $id));
        }

        return ['closure' => $closure, 'runtime' => $runtime];
    }

    private function invoke(
        Closure $closure,
        ScopeContext $context,
        ?object $runtime = null,
    ): mixed {
        $reflection = new ReflectionFunction($closure);

        if ($reflection->getNumberOfRequiredParameters() > 1) {
            throw new InvalidArgumentException('Drove lifecycle closures accept at most one context argument.');
        }

        $arguments = $reflection->getNumberOfParameters() === 0 ? [] : [$context];

        return $reflection->isStatic()
            ? $closure(...$arguments)
            : $closure->call($runtime ?? $context, ...$arguments);
    }

    /**
     * @param  array<string, mixed>  $test
     * @return array{name: ?string, source: array{path: string, line: int}|null, dataset: array{key: int|string, label: string}|null, groups: list<string>}
     */
    private function testMetadata(array $test): array
    {
        $name = $test['name'] ?? null;
        $source = $test['source'] ?? null;
        $dataset = $test['dataset'] ?? null;
        $groups = $test['groups'] ?? [];

        if ($name !== null && ! is_string($name)) {
            throw new InvalidArgumentException('Drove Scope IR contains invalid test metadata.');
        }

        $normalizedSource = null;

        if ($source !== null) {
            $path = is_array($source) ? ($source['path'] ?? null) : null;
            $line = is_array($source) ? ($source['line'] ?? null) : null;

            if (! is_string($path) || ! is_int($line)) {
                throw new InvalidArgumentException('Drove Scope IR contains invalid test metadata.');
            }

            $normalizedSource = ['path' => $path, 'line' => $line];
        }

        $normalizedDataset = null;

        if ($dataset !== null) {
            $key = is_array($dataset) ? ($dataset['key'] ?? null) : null;
            $label = is_array($dataset) ? ($dataset['label'] ?? null) : null;

            if ((! is_int($key) && ! is_string($key)) || ! is_string($label)) {
                throw new InvalidArgumentException('Drove Scope IR contains invalid test metadata.');
            }

            $normalizedDataset = ['key' => $key, 'label' => $label];
        }

        if (! is_array($groups) || ! array_is_list($groups)) {
            throw new InvalidArgumentException('Drove Scope IR contains invalid test metadata.');
        }

        $normalizedGroups = [];

        foreach ($groups as $group) {
            if (! is_string($group)) {
                throw new InvalidArgumentException('Drove Scope IR contains invalid test metadata.');
            }

            $normalizedGroups[] = $group;
        }

        return [
            'name' => $name,
            'source' => $normalizedSource,
            'dataset' => $normalizedDataset,
            'groups' => $normalizedGroups,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function scopeContext(array $node, ScopeContext $parent): ScopeContext
    {
        $id = $this->string($node, 'id');
        $type = $this->string($node, 'type');
        $path = is_string($node['path'] ?? null)
            ? $node['path']
            : ($parent->metadata()['path'] ?? '');
        $name = is_string($node['name'] ?? null)
            ? $node['name']
            : ($type === 'file' ? $path : $id);
        $metadata = [
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'path' => $path,
        ] + (is_array($node['metadata'] ?? null) ? $node['metadata'] : []);
        $policy = is_string($node['state_policy'] ?? null)
            ? $node['state_policy']
            : 'inherit';
        $state = $policy !== 'inherit' || ! array_key_exists('policy', $parent->state())
            ? ['policy' => $policy]
            : [];

        return $parent->child($metadata, $state);
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(Throwable $throwable, string $phase, ?string $hookId): array
    {
        return [
            'kind' => FailureKind::for($throwable, $phase)->value,
            'message' => $throwable->getMessage(),
            'class' => $throwable::class,
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'phase' => $phase,
            'hook_id' => $hookId,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $failure
     * @return array<string, mixed>
     */
    private function event(
        string $type,
        string $scopeId,
        ?string $testId = null,
        ?string $hookId = null,
        ?string $phase = null,
        string $status = 'running',
        ?array $failure = null,
    ): array {
        return [
            'type' => $type,
            'scope_id' => $scopeId,
            'test_id' => $testId,
            'hook_id' => $hookId,
            'phase' => $phase,
            'status' => $status,
            'failure' => $failure,
            'pid' => getmypid(),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function string(array $node, string $key): string
    {
        $value = $node[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : throw new InvalidArgumentException(sprintf('Drove Scope IR is missing %s.', $key));
    }

    /**
     * @param  list<array<string, mixed>>  $tests
     */
    private function peak(array $tests, ?string $scopeId = null): int
    {
        $points = [];

        foreach ($tests as $test) {
            if ($scopeId !== null && ! in_array($scopeId, $test['scopes'], true)) {
                continue;
            }

            $started = $test['telemetry']['started_ns'] ?? null;
            $finished = $test['telemetry']['finished_ns'] ?? null;

            if (is_int($started) && is_int($finished)) {
                $points[] = [$started, 1];
                $points[] = [$finished, -1];
            }
        }

        usort($points, static fn (array $left, array $right): int => $left[0] <=> $right[0]
            ?: $left[1] <=> $right[1]);

        $active = 0;
        $peak = 0;

        foreach ($points as [, $delta]) {
            $active += $delta;
            $peak = max($peak, $active);
        }

        return $peak;
    }
}
