<?php

declare(strict_types=1);

namespace Drove\Environment;

use Drove\Kernel\ScopeContext;

/** @experimental */
interface EnvironmentRuntime
{
    public function environmentPlan(): EnvironmentPlan;

    /**
     * @param  array<string, mixed>  $suitePlan
     */
    public function assertPlanSupported(array $suitePlan): void;

    public function scopeContext(): ScopeContext;

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    public function beforeDispatch(ScopeContext $scope, array $tasks): void;

    /**
     * @param  array<string, mixed>  $task
     */
    public function enterDescendant(ScopeContext $scope, array $task): void;

    /**
     * @param  array<string, mixed>  $task
     */
    public function leaveDescendant(ScopeContext $scope, array $task): void;

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    public function afterDispatch(ScopeContext $scope, array $tasks): void;
}
