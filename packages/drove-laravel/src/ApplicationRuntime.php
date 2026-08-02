<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Closure;
use Drove\Environment\CoordinationGuarantee;
use Drove\Environment\EnvironmentPlan;
use Drove\Environment\EnvironmentRuntime;
use Drove\Environment\ResourceCapability;
use Drove\Environment\ResourceKind;
use Drove\Environment\ResourcePlan;
use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Drove\Laravel\Contracts\DatabaseStateProvider;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Laravel\State\SqliteCopyDatabaseStateAdapter;
use Drove\Laravel\State\TransactionalDatabaseStateAdapter;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

final class ApplicationRuntime implements EnvironmentRuntime
{
    public const string ROOT_PID_BINDING = 'drove.laravel.root_pid';

    private const array PROVIDER_CACHE_ENVIRONMENT = [
        'APP_PACKAGES_CACHE' => 'packages',
        'APP_SERVICES_CACHE' => 'services',
    ];

    private readonly EnvironmentPlan $environment;

    private readonly ScopeContext $scope;

    private ?LaravelTestContext $testContext = null;

    private ?int $testContextPid = null;

    private function __construct(
        private readonly Application $application,
        private readonly DatabaseStateProvider $state,
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
     * @param  array{driver?: mixed, connection?: mixed, prepared_schema?: mixed, workspace?: mixed}|DatabaseStateProvider  $state
     * @param  list<class-string>  $providers
     */
    public static function bootNative(
        string $basePath,
        DatabaseStateProvider|array $state,
        array $providers = [],
        ?Closure $prepare = null,
    ): self {
        self::assertRuntimeRequirements();
        $basePath = self::resolveBasePath($basePath);
        $state = is_array($state) ? self::resolveState($state) : $state;
        $resource = $state->resourcePlan();

        if ($resource->kind !== ResourceKind::Database
            || $resource->provider !== $state->name()) {
            throw new StateAdapterException(
                'Native Laravel database provider identity must match its database ResourcePlan.',
            );
        }

        if (! $resource->supports(ResourceCapability::Branchable)
            || ! $resource->supports(ResourceCapability::ScopeIsolated)) {
            throw new StateAdapterException(sprintf(
                'Native Laravel requires a branchable, scope-isolated database provider; %s remains bridge-only.',
                $state->name(),
            ));
        }

        self::assertPrepareClosure($prepare);
        NativeProviderManifest::assertAllowed($basePath, $providers);

        return self::withIsolatedProviderCaches(
            static function (array $cachePaths) use (
                $basePath,
                $prepare,
                $providers,
                $state,
            ): self {
                $application = self::loadApplication($basePath);

                if ($application->hasBeenBootstrapped()) {
                    throw new StateAdapterException(
                        'Native Laravel requires bootstrap/app.php to return an unbootstrapped Application.',
                    );
                }

                self::assertConfigurationNotCached($application);

                NativeProviderManifest::assertConfigured(
                    $basePath,
                    $providers,
                );
                $application->beforeBootstrapping(
                    LoadConfiguration::class,
                    self::assertConfigurationNotCached(...),
                );
                $application->beforeBootstrapping(
                    RegisterProviders::class,
                    static function (Application $application) use (
                        $basePath,
                        $cachePaths,
                        $providers,
                    ): void {
                        self::assertProviderCachePaths(
                            $application,
                            $cachePaths,
                        );
                        self::clearProviderCacheFiles($cachePaths);
                        NativeProviderManifest::assertAllowed(
                            $basePath,
                            $providers,
                        );
                        NativeProviderManifest::assertConfigured(
                            $basePath,
                            $providers,
                        );
                        NativeProviderManifest::assertLoadedConfiguration(
                            $application,
                            $providers,
                        );
                    },
                );

                return self::fromApplication(
                    $application,
                    $state,
                    $prepare,
                );
            },
        );
    }

    /**
     * @param  array{driver?: mixed, connection?: mixed, prepared_schema?: mixed, workspace?: mixed}|DatabaseStateProvider|null  $state
     */
    public static function fromApplication(
        Application $application,
        DatabaseStateProvider|array|null $state,
        ?Closure $prepare = null,
    ): self {
        self::assertPrepareClosure($prepare);

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
        $state = is_array($state) ? self::resolveState($state) : $state;

        if (! $state instanceof DatabaseStateProvider) {
            throw new StateAdapterException(
                'Drove Laravel requires a configured database state adapter.',
            );
        }

        if ($prepare instanceof Closure) {
            $reflection = new ReflectionFunction($prepare);
            $reflection->getNumberOfParameters() === 0
                ? $prepare()
                : $prepare($application);
        }

        $state->boot($application);
        $runtime = new self($application, $state);
        $application->instance(self::class, $runtime);
        $application->instance(self::ROOT_PID_BINDING, getmypid());

        return $runtime;
    }

    public function application(): Application
    {
        return $this->application;
    }

    public function stateProvider(): DatabaseStateProvider
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

    public function scopeContext(): ScopeContext
    {
        return $this->scope;
    }

    public function testContext(): LaravelTestContext
    {
        $pid = getmypid();

        if (! is_int($pid)) {
            throw new StateAdapterException(
                'Native Laravel could not resolve the current process ID.',
            );
        }

        if ($this->testContextPid !== $pid) {
            $this->testContext = new LaravelTestContext($this->application);
            $this->testContextPid = $pid;
        }

        return $this->testContext ?? throw new StateAdapterException(
            'Native Laravel could not create its test context.',
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

    private static function assertPrepareClosure(?Closure $prepare): void
    {
        if (! $prepare instanceof Closure) {
            return;
        }

        $parameters = new ReflectionFunction($prepare)->getParameters();

        if (count($parameters) > 1) {
            throw new InvalidArgumentException(
                'Native Laravel prepare closures accept zero arguments or one Application.',
            );
        }

        if ($parameters === []) {
            return;
        }

        $parameter = $parameters[0];

        if ($parameter->isPassedByReference()
            || $parameter->isVariadic()
            || ! self::acceptsApplication($parameter->getType())) {
            throw new InvalidArgumentException(
                'Native Laravel prepare closure parameters must accept one Application by value.',
            );
        }
    }

    private static function acceptsApplication(?ReflectionType $type): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionUnionType) {
            return array_any(
                $type->getTypes(),
                self::acceptsApplication(...),
            );
        }

        if ($type instanceof ReflectionIntersectionType) {
            return array_all(
                $type->getTypes(),
                self::acceptsApplication(...),
            );
        }

        if (! $type instanceof ReflectionNamedType) {
            return false;
        }

        if ($type->isBuiltin()) {
            return in_array($type->getName(), ['mixed', 'object'], true);
        }

        return is_a(Application::class, $type->getName(), true);
    }

    /**
     * @param  array{driver?: mixed, connection?: mixed, prepared_schema?: mixed, workspace?: mixed}  $configuration
     */
    public static function resolveState(
        array $configuration,
    ): DatabaseStateProvider {
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

    private static function loadApplication(string $basePath): Application
    {
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

        return $application;
    }

    private static function assertConfigurationNotCached(
        Application $application,
    ): void {
        if ($application->configurationIsCached()
            || is_file($application->getCachedConfigPath())) {
            throw new StateAdapterException(
                'Native Laravel rejects cached configuration because it can hide providers from preflight.',
            );
        }
    }

    /**
     * @param  Closure(array{packages: string, services: string}): self  $boot
     */
    private static function withIsolatedProviderCaches(Closure $boot): self
    {
        $token = bin2hex(random_bytes(16));
        $temporary = rtrim(sys_get_temp_dir(), '/\\');
        $paths = [
            'packages' => $temporary.'/drove-laravel-native-packages-'.$token.'.php',
            'services' => $temporary.'/drove-laravel-native-services-'.$token.'.php',
        ];
        $environment = [];

        foreach (self::PROVIDER_CACHE_ENVIRONMENT as $name => $kind) {
            $environment[$name] = self::captureEnvironmentVariable($name);
        }

        $runtime = null;
        $primaryFailure = null;

        try {
            foreach (self::PROVIDER_CACHE_ENVIRONMENT as $name => $kind) {
                self::setEnvironmentVariable($name, $paths[$kind]);
            }

            $runtime = $boot($paths);
        } catch (Throwable $failure) {
            $primaryFailure = $failure;
        }

        $cleanupFailure = null;

        try {
            self::clearProviderCacheFiles($paths);
        } catch (Throwable $failure) {
            $cleanupFailure = $failure;
        }

        $restoreFailures = [];

        foreach ($environment as $name => $value) {
            if (! self::restoreEnvironmentVariable($name, $value)) {
                $restoreFailures[] = $name;
            }
        }

        if ($primaryFailure instanceof Throwable) {
            throw $primaryFailure;
        }

        if ($cleanupFailure instanceof Throwable) {
            throw $cleanupFailure;
        }

        if ($restoreFailures !== []) {
            throw new StateAdapterException(sprintf(
                'Native Laravel could not restore provider cache environment [%s].',
                implode(', ', $restoreFailures),
            ));
        }

        if (! $runtime instanceof self) {
            throw new StateAdapterException(
                'Native Laravel provider-cache isolation completed without a runtime.',
            );
        }

        return $runtime;
    }

    /**
     * @param  array{packages: string, services: string}  $paths
     */
    private static function assertProviderCachePaths(
        Application $application,
        array $paths,
    ): void {
        $actual = [
            'packages' => $application->getCachedPackagesPath(),
            'services' => $application->getCachedServicesPath(),
        ];

        foreach ($paths as $kind => $expected) {
            if (str_replace('\\', '/', $actual[$kind])
                !== str_replace('\\', '/', $expected)) {
                throw new StateAdapterException(sprintf(
                    'Native Laravel requires its isolated %s cache path to remain unchanged during configuration load.',
                    $kind,
                ));
            }
        }
    }

    /**
     * @param  array{packages: string, services: string}  $paths
     */
    private static function clearProviderCacheFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (! file_exists($path) && ! is_link($path)) {
                continue;
            }

            if (is_dir($path) || ! unlink($path)) {
                throw new StateAdapterException(sprintf(
                    'Native Laravel could not remove isolated provider cache %s.',
                    $path,
                ));
            }
        }
    }

    /**
     * @return array{
     *     process: string|false,
     *     env_exists: bool,
     *     env: mixed,
     *     server_exists: bool,
     *     server: mixed
     * }
     */
    private static function captureEnvironmentVariable(string $name): array
    {
        return [
            'process' => getenv($name),
            'env_exists' => array_key_exists($name, $_ENV),
            'env' => $_ENV[$name] ?? null,
            'server_exists' => array_key_exists($name, $_SERVER),
            'server' => $_SERVER[$name] ?? null,
        ];
    }

    private static function setEnvironmentVariable(
        string $name,
        string $value,
    ): void {
        if (! putenv($name.'='.$value)) {
            throw new StateAdapterException(sprintf(
                'Native Laravel could not isolate %s.',
                $name,
            ));
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * @param  array{
     *     process: string|false,
     *     env_exists: bool,
     *     env: mixed,
     *     server_exists: bool,
     *     server: mixed
     * }  $value
     */
    private static function restoreEnvironmentVariable(
        string $name,
        array $value,
    ): bool {
        $processRestored = $value['process'] === false
            ? putenv($name)
            : putenv($name.'='.$value['process']);

        if ($value['env_exists']) {
            $_ENV[$name] = $value['env'];
        } else {
            unset($_ENV[$name]);
        }

        if ($value['server_exists']) {
            $_SERVER[$name] = $value['server'];
        } else {
            unset($_SERVER[$name]);
        }

        return $processRestored;
    }
}
