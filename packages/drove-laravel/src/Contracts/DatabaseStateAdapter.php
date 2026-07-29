<?php

declare(strict_types=1);

namespace Drove\Laravel\Contracts;

use Drove\Kernel\ScopeContext;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

interface DatabaseStateAdapter
{
    public function boot(Application $application): void;

    public function assertTestCaseSupported(TestCase $testCase): void;

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

    public function name(): string;

    /**
     * @return list<string>
     */
    public function limitations(): array;
}
