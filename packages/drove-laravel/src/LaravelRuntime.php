<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Drove\Environment\CoordinationGuarantee;
use Drove\Environment\EnvironmentPlan;
use Drove\Environment\EnvironmentRuntime;
use Drove\Environment\ResourceKind;
use Drove\Environment\ResourcePlan;
use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Drove\Laravel\Contracts\DatabaseStateAdapter;
use Drove\Laravel\State\AbstractDatabaseStateAdapter;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Laravel\State\SqliteCopyDatabaseStateAdapter;
use Drove\Laravel\State\TransactionalDatabaseStateAdapter;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use ReflectionProperty;
use Throwable;

final class LaravelRuntime implements EnvironmentRuntime
{
    public const string ROOT_PID_BINDING = 'drove.laravel.root_pid';

    /** @var list<string> */
    private const array RUNTIME_MODES = [
        'auto',
        'application',
        'testbench',
    ];

    private static ?self $active = null;

    private readonly ScopeContext $scope;

    private readonly EnvironmentPlan $environment;

    /** @var resource|null */
    private mixed $testbenchDuskFileLock = null;

    private ?int $testbenchDuskFileLockPid = null;

    private ?string $testbenchDuskFileLockScopeId = null;

    private function __construct(
        private readonly string $basePath,
        private readonly Application $application,
        private readonly DatabaseStateAdapter $state,
        private readonly ?string $testbenchProfile = null,
    ) {
        $this->environment = new EnvironmentPlan(
            [
                $state->resourcePlan(),
                new ResourcePlan(
                    ResourceKind::Filesystem,
                    null,
                    [],
                    ['Filesystem state is unmanaged in this alpha.'],
                ),
                new ResourcePlan(
                    ResourceKind::Cache,
                    null,
                    [],
                    ['Cache state is unmanaged in this alpha.'],
                ),
                new ResourcePlan(
                    ResourceKind::Queue,
                    null,
                    [],
                    ['Queue state is unmanaged in this alpha.'],
                ),
                new ResourcePlan(
                    ResourceKind::ObjectStorage,
                    null,
                    [],
                    ['Object-storage state is unmanaged in this alpha.'],
                ),
            ],
            CoordinationGuarantee::BestEffort,
        );
        $this->scope = new ScopeContext(
            application: $application,
            values: ['drove.laravel.runtime' => $this],
            metadata: [
                'framework' => 'laravel',
                'state_adapter' => $state->name(),
                'environment_plan' => $this->environment->toArray(),
            ],
        );
    }

    /**
     * @param  array{driver?: mixed, connection?: mixed, prepared_schema?: mixed, workspace?: mixed}|DatabaseStateAdapter|null  $state
     */
    public static function boot(
        string $basePath,
        DatabaseStateAdapter|array|null $state = null,
    ): self {
        self::assertRuntimeRequirements();
        $basePath = self::resolveBasePath($basePath);

        if (self::$active instanceof self) {
            if (self::$active->basePath !== $basePath) {
                throw new StateAdapterException(
                    'Drove Laravel cannot boot two applications in one process.',
                );
            }

            if (self::$active->testbenchProfile !== null) {
                throw new StateAdapterException(
                    'Drove Laravel cannot reuse an Orchestra Testbench application for a Laravel application suite.',
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

        return self::activate($basePath, $application, $state);
    }

    public static function bootBeforeSuite(string $basePath): ?self
    {
        $basePath = self::resolveBasePath($basePath);
        $mode = self::runtimeMode();

        if ($mode === 'testbench') {
            return null;
        }

        if ($mode === 'auto'
            && ! is_file($basePath.'/bootstrap/app.php')) {
            return null;
        }

        return self::boot($basePath);
    }

    /**
     * @param  array{driver?: mixed, connection?: mixed, prepared_schema?: mixed, workspace?: mixed}|DatabaseStateAdapter|null  $state
     */
    public static function bootForSuite(
        string $basePath,
        TestSuite $suite,
        DatabaseStateAdapter|array|null $state = null,
    ): self {
        $testbenchCases = [];
        $hasLaravelApplicationCase = false;

        foreach ($suite->collect() as $case) {
            if (! $case instanceof TestCase) {
                continue;
            }

            if (TestbenchBridge::isTestCase($case)) {
                TestbenchBridge::inspect($case);
                $testbenchCases[] = $case;

                continue;
            }

            $hasLaravelApplicationCase = $hasLaravelApplicationCase
                || $case instanceof LaravelTestCase;
        }

        $mode = self::runtimeMode();

        if ($testbenchCases === []) {
            if ($mode === 'testbench') {
                throw new StateAdapterException(
                    'DROVE_LARAVEL_RUNTIME=testbench requires an Orchestra Testbench TestCase in the selected suite.',
                );
            }

            $runtime = self::boot($basePath, $state);
            $runtime->preflightSuite($suite);

            return $runtime;
        }

        if ($mode === 'application') {
            throw new StateAdapterException(
                'DROVE_LARAVEL_RUNTIME=application cannot execute Orchestra Testbench TestCase instances.',
            );
        }

        if ($hasLaravelApplicationCase) {
            throw new StateAdapterException(
                'Drove Laravel cannot mix Orchestra Testbench and Laravel application TestCase instances in one suite.',
            );
        }

        $profiles = [];

        foreach ($testbenchCases as $case) {
            $profile = TestbenchBridge::profile($case);
            $profiles[$profile] = $case;
        }

        if (count($profiles) !== 1) {
            throw new StateAdapterException(sprintf(
                'Drove Laravel requires one Orchestra Testbench application profile; received [%s].',
                implode(', ', array_keys($profiles)),
            ));
        }

        self::assertRuntimeRequirements();
        $basePath = self::resolveBasePath($basePath);
        $profile = array_key_first($profiles);

        if (self::$active instanceof self) {
            if (self::$active->basePath !== $basePath) {
                throw new StateAdapterException(
                    'Drove Laravel cannot boot two applications in one process.',
                );
            }

            if (self::$active->testbenchProfile !== $profile) {
                throw new StateAdapterException(
                    'Drove Laravel cannot reuse a different application for an Orchestra Testbench suite.',
                );
            }

            self::$active->preflightSuite($suite);

            return self::$active;
        }

        self::preflightTestbenchState($suite, $state);
        $representative = $profiles[$profile];

        $application = TestbenchBridge::createApplication($representative);

        if (! $application->providerIsLoaded(DroveLaravelServiceProvider::class)) {
            $application->register(DroveLaravelServiceProvider::class);
        }

        $runtime = self::activate(
            $basePath,
            $application,
            $state,
            $profile,
        );
        $runtime->preflightSuite($suite);

        return $runtime;
    }

    /**
     * @param  array{driver?: mixed, connection?: mixed, prepared_schema?: mixed, workspace?: mixed}|DatabaseStateAdapter|null  $state
     */
    private static function activate(
        string $basePath,
        Application $application,
        DatabaseStateAdapter|array|null $state,
        ?string $testbenchProfile = null,
    ): self {
        if (! $application->hasBeenBootstrapped()) {
            $kernel = $application->make(ConsoleKernel::class);
            $kernel->bootstrap();
        }

        if (! $application->environment('testing')) {
            throw new StateAdapterException(
                'Drove Laravel requires the booted application environment to be testing.',
            );
        }

        $state ??= $application->make('config')->get('drove.state');

        if (is_array($state)) {
            $state = self::resolveStateAdapter($state);
        }

        if (! $state instanceof DatabaseStateAdapter) {
            throw new StateAdapterException(
                'Drove Laravel requires a configured database state adapter.',
            );
        }

        $state->boot($application);
        $runtime = new self($basePath, $application, $state, $testbenchProfile);
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

    public function environmentPlan(): EnvironmentPlan
    {
        return $this->environment;
    }

    /**
     * @param  array<string, mixed>  $suitePlan
     */
    public function assertPlanSupported(array $suitePlan): void
    {
        $this->environment->assertSuiteSupported($suitePlan);
    }

    public function bindTestCase(TestCase $case, ScopeContext $scope): void
    {
        $this->assertScope($scope);

        if (TestbenchBridge::isTestCase($case)) {
            $this->bindTestbenchTestCase($case);

            return;
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

    private function bindTestbenchTestCase(TestCase $case): void
    {
        if ($this->testbenchProfile === null) {
            throw new StateAdapterException(
                'Drove Laravel Orchestra Testbench cases require bootForSuite().',
            );
        }

        $this->state->assertTestCaseSupported($case);
        TestbenchBridge::bind(
            $case,
            $this->application,
            $this->testbenchProfile,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    public function beforeDispatch(ScopeContext $scope, array $tasks): void
    {
        $this->assertScope($scope);
        $this->environment->assertDispatchSupported($tasks);
        $this->state->beforeDispatch($scope, $tasks);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function enterDescendant(ScopeContext $scope, array $task): void
    {
        $this->assertScope($scope);
        $locked = $this->acquireTestbenchDuskFileLock($task);

        try {
            $this->state->enterDescendant($scope, $task);
        } catch (Throwable $throwable) {
            if ($locked) {
                $this->releaseTestbenchDuskFileLock($task);
            }

            throw $throwable;
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function leaveDescendant(ScopeContext $scope, array $task): void
    {
        $this->assertScope($scope);

        try {
            $this->state->leaveDescendant($scope, $task);
        } finally {
            $this->releaseTestbenchDuskFileLock($task);
        }
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

    private function preflightSuite(TestSuite $suite): void
    {
        foreach ($suite->collect() as $case) {
            if ($case instanceof LaravelTestCase
                || ($case instanceof TestCase
                    && TestbenchBridge::isTestCase($case))) {
                $this->state->assertTestCaseSupported($case);
            }
        }
    }

    /**
     * @param  array{driver?: mixed, connection?: mixed, prepared_schema?: mixed, workspace?: mixed}|DatabaseStateAdapter|null  $state
     */
    private static function preflightTestbenchState(
        TestSuite $suite,
        DatabaseStateAdapter|array|null $state,
    ): void {
        if ($state === null) {
            $configuration = require dirname(__DIR__).'/config/drove.php';
            $state = $configuration['state'] ?? null;

            if (! is_array($state) || ($state['driver'] ?? null) === null) {
                return;
            }
        }

        if (is_array($state)) {
            $state = self::resolveStateAdapter($state);
        }

        if (! $state instanceof AbstractDatabaseStateAdapter) {
            return;
        }

        foreach ($suite->collect() as $case) {
            if ($case instanceof TestCase
                && TestbenchBridge::isTestCase($case)) {
                $state->preflightTestCase($case);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function acquireTestbenchDuskFileLock(array $task): bool
    {
        if (! $this->isTestbenchDuskFileScope($task)) {
            return false;
        }

        $pid = getmypid();

        if (! is_int($pid)) {
            throw new StateAdapterException(
                'Drove Laravel could not resolve the Testbench Dusk file-scope process.',
            );
        }

        if (is_resource($this->testbenchDuskFileLock)) {
            throw new StateAdapterException(
                'A Testbench Dusk file scope is already active in this process.',
            );
        }

        // Ponytail ceiling: one process-global lock mirrors PHPUnit's serial
        // Dusk class lifecycle without growing a port/resource registry.
        $lock = fopen(
            sys_get_temp_dir().'/drove-testbench-dusk-file-scope.lock',
            'c+',
        );

        if (! is_resource($lock)) {
            throw new StateAdapterException(
                'Drove Laravel could not open the Testbench Dusk file-scope lock.',
            );
        }

        if (! flock($lock, LOCK_EX)) {
            fclose($lock);

            throw new StateAdapterException(
                'Drove Laravel could not acquire the Testbench Dusk file-scope lock.',
            );
        }

        $this->testbenchDuskFileLock = $lock;
        $this->testbenchDuskFileLockPid = $pid;
        $this->testbenchDuskFileLockScopeId = $task['id'];

        return true;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function releaseTestbenchDuskFileLock(array $task): void
    {
        if (! $this->isTestbenchDuskFileScope($task)) {
            return;
        }

        $pid = getmypid();

        if (! is_int($pid)
            || $this->testbenchDuskFileLockPid !== $pid
            || $this->testbenchDuskFileLockScopeId !== ($task['id'] ?? null)
            || ! is_resource($this->testbenchDuskFileLock)) {
            throw new StateAdapterException(
                'The Testbench Dusk file-scope lock lifecycle is unbalanced.',
            );
        }

        $lock = $this->testbenchDuskFileLock;
        $this->testbenchDuskFileLock = null;
        $this->testbenchDuskFileLockPid = null;
        $this->testbenchDuskFileLockScopeId = null;

        if (! flock($lock, LOCK_UN)) {
            fclose($lock);

            throw new StateAdapterException(
                'Drove Laravel could not release the Testbench Dusk file-scope lock.',
            );
        }

        fclose($lock);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function isTestbenchDuskFileScope(array $task): bool
    {
        return $this->testbenchProfile !== null
            && TestbenchBridge::isDuskProfile($this->testbenchProfile)
            && ($task['kind'] ?? null) === 'scope'
            && is_string($task['id'] ?? null)
            && str_starts_with($task['id'], 'file:');
    }

    /**
     * @param  array{driver?: mixed, connection?: mixed, prepared_schema?: mixed, workspace?: mixed}  $configuration
     */
    private static function resolveStateAdapter(
        array $configuration,
    ): DatabaseStateAdapter {
        $driver = $configuration['driver'] ?? null;
        $connection = $configuration['connection'] ?? null;

        if ($connection !== null && (! is_string($connection) || $connection === '')) {
            throw new StateAdapterException(
                'Drove Laravel state.connection must be a non-empty string or null.',
            );
        }

        $preparedSchema = $configuration['prepared_schema'] ?? false;

        if (! is_bool($preparedSchema)) {
            throw new StateAdapterException(
                'Drove Laravel state.prepared_schema must be a boolean.',
            );
        }

        return match ($driver) {
            'transaction' => new TransactionalDatabaseStateAdapter($connection),
            'sqlite-memory' => new InMemorySqliteDatabaseStateAdapter(
                $connection,
                $preparedSchema,
            ),
            'sqlite-copy' => new SqliteCopyDatabaseStateAdapter(
                $connection,
                self::workspace($configuration['workspace'] ?? null),
                $preparedSchema,
            ),
            default => throw new StateAdapterException(sprintf(
                'Drove Laravel requires DROVE_LARAVEL_STATE=transaction, sqlite-memory, or sqlite-copy; received %s.',
                is_scalar($driver) && (string) $driver !== ''
                    ? (string) $driver
                    : get_debug_type($driver),
            )),
        };
    }

    private static function workspace(mixed $workspace): ?string
    {
        if ($workspace === null || $workspace === '') {
            return null;
        }

        if (! is_string($workspace)) {
            throw new StateAdapterException(
                'Drove Laravel state.workspace must be a path string.',
            );
        }

        return $workspace;
    }

    private static function assertRuntimeRequirements(): void
    {
        if (! in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)
            || ! function_exists('pcntl_fork')) {
            throw new StateAdapterException(
                'Drove Laravel requires Linux or macOS and the pcntl extension.',
            );
        }

        if (getenv('APP_ENV') !== 'testing') {
            throw new StateAdapterException(
                'Drove Laravel requires process APP_ENV=testing before boot.',
            );
        }
    }

    private static function resolveBasePath(string $basePath): string
    {
        $basePath = realpath($basePath);

        if ($basePath === false || ! is_dir($basePath)) {
            throw new InvalidArgumentException('The Laravel application base path does not exist.');
        }

        return str_replace('\\', '/', $basePath);
    }

    private static function runtimeMode(): string
    {
        $mode = getenv('DROVE_LARAVEL_RUNTIME');
        $mode = $mode === false || $mode === '' ? 'auto' : $mode;

        if (! in_array($mode, self::RUNTIME_MODES, true)) {
            throw new StateAdapterException(sprintf(
                'DROVE_LARAVEL_RUNTIME must be auto, application, or testbench; received %s.',
                $mode,
            ));
        }

        return $mode;
    }
}
