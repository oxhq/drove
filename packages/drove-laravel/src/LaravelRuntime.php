<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Drove\Laravel\Contracts\DatabaseStateAdapter;
use Drove\Laravel\State\SqliteCopyDatabaseStateAdapter;
use Drove\Laravel\State\TransactionalDatabaseStateAdapter;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class LaravelRuntime
{
    public const string ROOT_PID_BINDING = 'drove.laravel.root_pid';

    private static ?self $active = null;

    private readonly ScopeContext $scope;

    private function __construct(
        private readonly string $basePath,
        private readonly Application $application,
        private readonly DatabaseStateAdapter $state,
    ) {
        $this->scope = new ScopeContext(
            application: $application,
            values: ['drove.laravel.runtime' => $this],
            metadata: [
                'framework' => 'laravel',
                'state_adapter' => $state->name(),
            ],
        );
    }

    /**
     * @param  array{driver?: mixed, connection?: mixed, workspace?: mixed}|DatabaseStateAdapter|null  $state
     */
    public static function boot(
        string $basePath,
        DatabaseStateAdapter|array|null $state = null,
    ): self {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('pcntl_fork')) {
            throw new StateAdapterException(
                'Drove Laravel requires Linux and the pcntl extension.',
            );
        }

        if (getenv('APP_ENV') !== 'testing') {
            throw new StateAdapterException(
                'Drove Laravel requires process APP_ENV=testing before boot.',
            );
        }

        $basePath = realpath($basePath);

        if ($basePath === false || ! is_dir($basePath)) {
            throw new InvalidArgumentException('The Laravel application base path does not exist.');
        }

        $basePath = str_replace('\\', '/', $basePath);

        if (self::$active instanceof self) {
            if (self::$active->basePath !== $basePath) {
                throw new StateAdapterException(
                    'Drove Laravel cannot boot two applications in one process.',
                );
            }

            return self::$active;
        }

        $bootstrap = $basePath.'/bootstrap/app.php';

        if (! is_file($bootstrap)) {
            throw new StateAdapterException(sprintf(
                'Laravel bootstrap file %s does not exist.',
                $bootstrap,
            ));
        }

        $application = require $bootstrap;

        if (! $application instanceof Application) {
            throw new StateAdapterException(
                'Laravel bootstrap/app.php did not return an Application.',
            );
        }

        if (! $application->hasBeenBootstrapped()) {
            $kernel = $application->make(ConsoleKernel::class);

            if (! $kernel instanceof ConsoleKernel) {
                throw new StateAdapterException('Laravel did not resolve its console kernel.');
            }

            $kernel->bootstrap();
        }

        if (! $application->environment('testing')) {
            throw new StateAdapterException(
                'Drove Laravel requires the booted application environment to be testing.',
            );
        }

        $state ??= $application->make('config')->get('drove.state');

        if (is_array($state)) {
            $state = self::resolveStateAdapter($state, $application);
        }

        if (! $state instanceof DatabaseStateAdapter) {
            throw new StateAdapterException(
                'Drove Laravel requires a configured database state adapter.',
            );
        }

        $state->boot($application);
        $runtime = new self($basePath, $application, $state);
        $application->instance(self::class, $runtime);
        $application->instance(self::ROOT_PID_BINDING, getmypid());

        return self::$active = $runtime;
    }

    public function application(): Application
    {
        return $this->application;
    }

    public function scopeContext(): ScopeContext
    {
        return $this->scope;
    }

    public function stateAdapter(): DatabaseStateAdapter
    {
        return $this->state;
    }

    public function bindTestCase(TestCase $case, ScopeContext $scope): void
    {
        $this->assertScope($scope);

        if (is_a($case, 'Orchestra\\Testbench\\TestCase')) {
            throw new StateAdapterException(
                'Drove Laravel does not support Orchestra Testbench TestCase instances yet.',
            );
        }

        if (! $case instanceof LaravelTestCase) {
            return;
        }

        $property = new ReflectionProperty($case, 'app');
        $property->setAccessible(true);
        $bound = $property->getValue($case);

        if ($bound !== null && $bound !== $this->application) {
            throw new StateAdapterException(sprintf(
                'Laravel TestCase %s is already bound to another application.',
                $case::class,
            ));
        }

        $property->setValue($case, $this->application);
        $this->state->assertTestCaseSupported($case);
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    public function beforeDispatch(ScopeContext $scope, array $tasks): void
    {
        $this->assertScope($scope);
        $this->state->beforeDispatch($scope, $tasks);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function enterDescendant(ScopeContext $scope, array $task): void
    {
        $this->assertScope($scope);
        $this->state->enterDescendant($scope, $task);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function leaveDescendant(ScopeContext $scope, array $task): void
    {
        $this->assertScope($scope);
        $this->state->leaveDescendant($scope, $task);
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    public function afterDispatch(ScopeContext $scope, array $tasks): void
    {
        $this->assertScope($scope);
        $this->state->afterDispatch($scope, $tasks);
    }

    private function assertScope(ScopeContext $scope): void
    {
        if ($scope->app() !== $this->application) {
            throw new StateAdapterException(
                'Drove Laravel received a scope from a different application.',
            );
        }
    }

    /**
     * @param  array{driver?: mixed, connection?: mixed, workspace?: mixed}  $configuration
     */
    private static function resolveStateAdapter(
        array $configuration,
        Application $application,
    ): DatabaseStateAdapter {
        $driver = $configuration['driver'] ?? null;
        $connection = $configuration['connection'] ?? null;

        if ($connection !== null && (! is_string($connection) || $connection === '')) {
            throw new StateAdapterException(
                'Drove Laravel state.connection must be a non-empty string or null.',
            );
        }

        return match ($driver) {
            'transaction' => new TransactionalDatabaseStateAdapter($connection),
            'sqlite-copy' => new SqliteCopyDatabaseStateAdapter(
                $connection,
                self::workspace($configuration['workspace'] ?? null, $application),
            ),
            default => throw new StateAdapterException(sprintf(
                'Drove Laravel requires DROVE_LARAVEL_STATE=transaction or sqlite-copy; received %s.',
                is_scalar($driver) && (string) $driver !== ''
                    ? (string) $driver
                    : get_debug_type($driver),
            )),
        };
    }

    private static function workspace(mixed $workspace, Application $application): string
    {
        if ($workspace === null || $workspace === '') {
            return $application->storagePath('framework/drove');
        }

        if (! is_string($workspace)) {
            throw new StateAdapterException(
                'Drove Laravel state.workspace must be a path string.',
            );
        }

        return $workspace;
    }
}
