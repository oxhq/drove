<?php

declare(strict_types=1);

use Drove\Environment\CoordinationGuarantee;
use Drove\Environment\EnvironmentPlan;
use Drove\Environment\EnvironmentRuntime;
use Drove\Environment\ResourceKind;
use Drove\Environment\ResourcePlan;
use Drove\Kernel\Scheduler;
use Drove\Kernel\ScopeContext;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Drove\Native\Surface\SupportedSurface;
use Drove\Native\TestContext;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class NativePhaseFourCoreScheduler implements Scheduler
{
    public function runId(): string
    {
        return 'native-phase-4-core';
    }

    public function map(array $tasks, Closure $execute): array
    {
        $results = [];
        $completionOrder = [];

        foreach ($tasks as $ordinal => $task) {
            $startedNs = hrtime(true);
            $value = $execute($task);
            $finishedNs = hrtime(true);
            $results[] = [
                'id' => $task['id'],
                'kind' => $task['kind'],
                'scope_id' => $task['scope_id'],
                'ordinal' => $ordinal,
                'status' => 'passed',
                'failure' => null,
                'value' => $value,
                'stdout' => '',
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
}

final class NativePhaseFourCoreRuntime implements EnvironmentRuntime
{
    /** @var list<string> */
    public array $calls = [];

    private readonly EnvironmentPlan $plan;

    public function __construct(
        private readonly object $application,
    ) {
        $this->plan = new EnvironmentPlan(
            array_map(
                static fn (ResourceKind $kind): ResourcePlan => new ResourcePlan(
                    $kind,
                    null,
                    [],
                    [],
                ),
                ResourceKind::cases(),
            ),
            CoordinationGuarantee::Atomic,
        );
    }

    public function environmentPlan(): EnvironmentPlan
    {
        $this->calls[] = 'environment-plan';

        return $this->plan;
    }

    public function assertPlanSupported(array $suitePlan): void
    {
        $this->calls[] = 'assert-plan';

        if (($suitePlan['root']['type'] ?? null) !== 'suite') {
            throw new RuntimeException('The native environment received an invalid plan.');
        }
    }

    public function scopeContext(): ScopeContext
    {
        $this->calls[] = 'scope-context';

        return new ScopeContext($this->application);
    }

    public function beforeDispatch(ScopeContext $scope, array $tasks): void
    {
        $this->assertApplication($scope);
        $this->calls[] = 'before:'.($tasks[0]['kind'] ?? 'empty');
    }

    public function enterDescendant(ScopeContext $scope, array $task): void
    {
        $this->assertApplication($scope);
        $this->calls[] = 'enter:'.$task['kind'];
    }

    public function leaveDescendant(ScopeContext $scope, array $task): void
    {
        $this->assertApplication($scope);
        $this->calls[] = 'leave:'.$task['kind'];
    }

    public function afterDispatch(ScopeContext $scope, array $tasks): void
    {
        $this->assertApplication($scope);
        $this->calls[] = 'after:'.($tasks[0]['kind'] ?? 'empty');
    }

    private function assertApplication(ScopeContext $scope): void
    {
        if ($scope->app() !== $this->application) {
            throw new RuntimeException('The native environment lost its prepared application.');
        }
    }
}

$rootPath = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$application = new stdClass;
$factoryCalls = 0;
$runtime = null;
$registry = Declarations::capture(
    static function () use (
        $application,
        &$factoryCalls,
        &$runtime,
    ): void {
        \Drove\Native\environment(
            'prepared',
            static function () use ($application, &$factoryCalls, &$runtime): EnvironmentRuntime {
                $factoryCalls++;

                return $runtime = new NativePhaseFourCoreRuntime($application);
            },
        );
        \Drove\Native\test('runs in the prepared environment', static function (): void {
            \Drove\Native\expect(true)->toBe(true);
        });
    },
    $rootPath,
    'Drove native phase 4 core',
);

$assert($factoryCalls === 0, 'The native environment resolved during declaration.');

$lateRejected = false;

try {
    $registry->resolveEnvironment();
} catch (LogicException) {
    $lateRejected = true;
}

$assert($lateRejected, 'The native environment resolved before planning.');
$run = new Runner(new NativePhaseFourCoreScheduler)->run($registry);
$assert($factoryCalls === 1, 'The native environment factory did not resolve exactly once.');
$assert($runtime instanceof NativePhaseFourCoreRuntime, 'The native environment returned no runtime.');
$assert($registry->resolveEnvironment() === $runtime, 'The native environment did not cache its runtime.');
$assert($factoryCalls === 1, 'Resolving the native environment twice invoked its factory twice.');
$assert(
    new TestContext(scope: new ScopeContext($application))->app() === $application,
    'TestContext::app() lost the prepared application.',
);
$assert(($run['status'] ?? null) === 'passed', 'The native environment proof did not pass.');
$assert(($run['tests'][0]['assertions'] ?? null) === 1, 'The native environment lost assertions.');
$assert(
    $runtime->calls === [
        'assert-plan',
        'environment-plan',
        'scope-context',
        'before:test',
        'enter:test',
        'leave:test',
        'after:test',
    ],
    'The inert file scope created a worker or resource branch instead of dispatching its test directly.',
);

$duplicateRejected = false;

try {
    Declarations::capture(
        static function (): void {
            \Drove\Native\environment('first', static fn (): never => throw new LogicException);
            \Drove\Native\environment('second', static fn (): never => throw new LogicException);
        },
        $rootPath,
    );
} catch (LogicException) {
    $duplicateRejected = true;
}

$assert($duplicateRejected, 'The native frontend accepted duplicate environments.');
$invalidDeclarationRejected = false;

try {
    Declarations::capture(
        static function (): void {
            \Drove\Native\environment(
                '',
                static fn (mixed $plan): never => throw new LogicException,
            );
        },
        $rootPath,
    );
} catch (InvalidArgumentException) {
    $invalidDeclarationRejected = true;
}

$assert($invalidDeclarationRejected, 'The native frontend accepted an invalid environment declaration.');
$arityRejected = false;

try {
    Declarations::capture(
        static function (): void {
            \Drove\Native\environment(
                'invalid-arity',
                static fn (mixed $plan): never => throw new LogicException,
            );
        },
        $rootPath,
    );
} catch (InvalidArgumentException) {
    $arityRejected = true;
}

$assert($arityRejected, 'The native frontend accepted a parameterized environment factory.');
$invalidFactory = Declarations::capture(
    static function (): void {
        \Drove\Native\environment(
            'invalid-runtime',
            static fn (): stdClass => new stdClass,
        );
    },
    $rootPath,
);
$invalidFactory->plan();
$invalidRuntimeRejected = false;

try {
    $invalidFactory->resolveEnvironment();
} catch (LogicException) {
    $invalidRuntimeRejected = true;
}

$assert($invalidRuntimeRejected, 'The native frontend accepted a non-EnvironmentRuntime factory.');
$surface = SupportedSurface::load();
$assert(
    ($surface->manifest()['functions']['environment'] ?? null) === ['environment']
        && in_array('app', $surface->manifest()['methods']['context'] ?? [], true),
    'The supported surface omitted the native environment frontend.',
);
$assert(
    $surface->scan(<<<'PHP'
<?php

namespace Proof;

use function Drove\Native\environment;

environment('prepared', static fn () => null);
PHP) === [],
    'The supported surface rejected the imported native environment frontend.',
);

fwrite(STDOUT, "Native Phase 4 core proof passed.\n");
