<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Drove\Environment\CoordinationGuarantee;
use Drove\Environment\EnvironmentPlan;
use Drove\Environment\EnvironmentRuntime;
use Drove\Environment\ResourceCapability;
use Drove\Environment\ResourceKind;
use Drove\Environment\ResourcePlan;
use Drove\Kernel\ScopeContext;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;

final class LaravelRuntime implements EnvironmentRuntime
{
    private readonly EnvironmentPlan $environment;

    public function __construct()
    {
        $resources = [new ResourcePlan(
            ResourceKind::Database,
            'proof-leaf',
            [ResourceCapability::LeafIsolated],
            [],
        )];

        foreach (array_slice(ResourceKind::cases(), 1) as $kind) {
            $resources[] = new ResourcePlan(
                $kind,
                null,
                [],
                [$kind->value.' is unmanaged in the preflight proof.'],
            );
        }

        $this->environment = new EnvironmentPlan(
            $resources,
            CoordinationGuarantee::BestEffort,
        );
    }

    public static function bootBeforeSuite(string $rootPath): ?self
    {
        return null;
    }

    public static function bootForSuite(string $rootPath, TestSuite $suite): self
    {
        return new self;
    }

    public function environmentPlan(): EnvironmentPlan
    {
        return $this->environment;
    }

    public function assertPlanSupported(array $suitePlan): void
    {
        $this->environment->assertSuiteSupported($suitePlan);
    }

    public function scopeContext(): ScopeContext
    {
        return new ScopeContext;
    }

    public function bindTestCase(TestCase $case, ScopeContext $scope): void
    {
        //
    }

    public function beforeDispatch(ScopeContext $scope, array $tasks): void
    {
        $this->markProvider();
        $this->environment->assertDispatchSupported($tasks);
    }

    public function enterDescendant(ScopeContext $scope, array $task): void
    {
        $this->markProvider();
    }

    public function leaveDescendant(ScopeContext $scope, array $task): void
    {
        $this->markProvider();
    }

    public function afterDispatch(ScopeContext $scope, array $tasks): void
    {
        $this->markProvider();
    }

    private function markProvider(): void
    {
        $path = getenv('DROVE_ENV_PROVIDER_MARKER');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, 'entered'.PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
