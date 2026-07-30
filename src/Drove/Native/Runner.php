<?php

declare(strict_types=1);

namespace Drove\Native;

use Drove\Environment\EnvironmentRuntime;
use Drove\Extension\RunSummary;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\Scheduler;
use Drove\Kernel\ScopeContext;

final readonly class Runner
{
    public function __construct(
        private Scheduler $scheduler,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function run(
        DeclarationRegistry $declarations,
        ?Selection $selection = null,
    ): array {
        $extensions = $declarations->extensions();
        $metricsToken = bin2hex(random_bytes(16));
        $plan = $declarations->plan($selection);
        $environment = $declarations->resolveEnvironment();

        if ($environment instanceof EnvironmentRuntime) {
            $environment->assertPlanSupported($plan);
            $plan['environment'] = $environment->environmentPlan()->toArray();
        }

        [$executionPlan, $synthetic, $order] = $this->executionPlan($plan);
        $run = new LifecycleExecutor(
            $this->scheduler,
            fn (string $id): \Closure => $declarations->resolveHook($id),
            function (
                string $id,
                ScopeContext $scope = new ScopeContext,
            ) use ($declarations, $extensions, $metricsToken): array {
                $context = new TestContext($extensions, $metricsToken, $scope);

                return [
                    'closure' => $context->caseClosure($declarations->resolveCase($id)),
                    'runtime' => $context,
                ];
            },
            beforeDispatch: $environment instanceof EnvironmentRuntime ? $environment->beforeDispatch(...) : null,
            enterDescendant: $environment instanceof EnvironmentRuntime ? $environment->enterDescendant(...) : null,
            leaveDescendant: $environment instanceof EnvironmentRuntime ? $environment->leaveDescendant(...) : null,
            afterDispatch: $environment instanceof EnvironmentRuntime ? $environment->afterDispatch(...) : null,
        )->run($executionPlan, $environment?->scopeContext());

        $byId = [];

        foreach ([...$run['tests'], ...$synthetic] as $test) {
            $byId[$test['id']] = $test;
        }

        $run['tests'] = array_map(
            static fn (string $id): array => $byId[$id],
            $order,
        );

        foreach ($run['tests'] as &$test) {
            $metrics = TestContext::extractMetrics(
                is_string($test['stdout'] ?? null) ? $test['stdout'] : '',
                $metricsToken,
            );
            $test['stdout'] = $metrics['output'];
            $test['assertions'] = $metrics['assertions'] ?? ($test['assertions'] ?? null);
            $test['cleanups'] = $metrics['cleanups'] ?? ($test['cleanups'] ?? null);
        }

        unset($test);

        if (! $extensions->isEmpty()) {
            $counts = [];

            foreach ($run['tests'] as $test) {
                $status = is_string($test['status'] ?? null) ? $test['status'] : 'failed';
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }

            $run['extension_reports'] = $extensions->reports(new RunSummary(
                $run['status'],
                $run['exit_code'],
                $counts,
            ));
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{array<string, mixed>, list<array<string, mixed>>, list<string>}
     */
    private function executionPlan(array $plan): array
    {
        $synthetic = [];
        $order = [];
        $root = $plan['root'];
        $plan['root'] = $this->runnableScope($root, [], $synthetic, $order, true);

        return [$plan, $synthetic, $order];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $ancestors
     * @param  list<array<string, mixed>>  $synthetic
     * @param  list<string>  $order
     * @return array<string, mixed>|null
     */
    private function runnableScope(
        array $scope,
        array $ancestors,
        array &$synthetic,
        array &$order,
        bool $root = false,
    ): ?array {
        $scopeId = $scope['id'] ?? null;

        if (! is_string($scopeId)) {
            throw new \LogicException('Native execution plan contains a scope without a string ID.');
        }

        $scopeIds = [...$ancestors, $scopeId];
        $tests = [];

        foreach ($scope['tests'] as $test) {
            if (! is_array($test)) {
                throw new \LogicException('Native execution plan contains an invalid test.');
            }

            $id = $test['id'] ?? null;

            if (! is_string($id)) {
                throw new \LogicException('Native execution plan contains a test without a string ID.');
            }

            $order[] = $id;
            $disposition = $test['disposition'] ?? 'run';

            if ($disposition === 'run') {
                $tests[] = $test;

                continue;
            }

            $synthetic[] = [
                'id' => $id,
                'scope_id' => $scopeId,
                'scopes' => $scopeIds,
                'name' => $test['name'],
                'source' => $test['source'],
                'dataset' => $test['dataset'],
                'groups' => $test['groups'],
                'status' => $disposition,
                'failure' => null,
                'teardown_failures' => [],
                'value' => $test['disposition_reason'] ?? '',
                'stdout' => '',
                'stderr' => '',
                'assertions' => 0,
                'cleanups' => 0,
                'events' => [],
                'telemetry' => null,
            ];
        }

        $children = [];

        foreach ($scope['children'] as $child) {
            $runnable = $this->runnableScope(
                $child,
                $scopeIds,
                $synthetic,
                $order,
            );

            if ($runnable !== null) {
                $children[] = $runnable;
            }
        }

        if (! $root && $tests === [] && $children === []) {
            return null;
        }

        $scope['tests'] = $tests;
        $scope['children'] = $children;

        return $scope;
    }
}
