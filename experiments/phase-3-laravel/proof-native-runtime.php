<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Drove\Environment\ResourceCapability;
use Drove\Environment\ResourceKind;
use Drove\Environment\ResourcePlan;
use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Drove\Laravel\ApplicationRuntime;
use Drove\Laravel\Contracts\DatabaseStateProvider;
use Drove\Laravel\DroveLaravelServiceProvider;
use Drove\Laravel\NativeProviderManifest;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Native\Declarations;
use Drove\Native\TestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\ServiceProvider;

use function Drove\Laravel\laravel;

require __DIR__.'/vendor/autoload.php';

final class NativeAdditionalProviderSentinel extends ServiceProvider
{
    public function register(): void
    {
        $marker = getenv('DROVE_NATIVE_PROVIDER_SENTINEL');

        if (is_string($marker)) {
            file_put_contents($marker, 'registered');
        }
    }
}

final class NativeInvalidStateProvider implements DatabaseStateProvider
{
    public int $bootCount = 0;

    public function __construct(
        private readonly ResourceKind $kind,
        private readonly string $planName,
        private readonly string $name,
    ) {}

    public function boot(Application $application): void
    {
        $this->bootCount++;
    }

    public function beforeDispatch(ScopeContext $scope, array $tasks): void {}

    public function enterDescendant(ScopeContext $scope, array $task): void {}

    public function leaveDescendant(ScopeContext $scope, array $task): void {}

    public function afterDispatch(ScopeContext $scope, array $tasks): void {}

    public function name(): string
    {
        return $this->name;
    }

    public function limitations(): array
    {
        return [];
    }

    public function resourcePlan(): ResourcePlan
    {
        return new ResourcePlan(
            $this->kind,
            $this->planName,
            [
                ResourceCapability::Branchable,
                ResourceCapability::ScopeIsolated,
            ],
            [],
        );
    }
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DROVE_LARAVEL' => '0',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$sandbox = sys_get_temp_dir().'/drove-native-laravel-'.bin2hex(random_bytes(8));
$bootstrap = $sandbox.'/bootstrap';
$cache = $bootstrap.'/cache';
$bootstrapMarker = $sandbox.'/bootstrap-executed';
$providerMarker = $sandbox.'/provider-executed';
$configProviderMarker = $sandbox.'/config-provider-executed';
$packageCacheMarker = $sandbox.'/package-cache-executed';
$servicesCacheMarker = $sandbox.'/services-cache-executed';

if (! mkdir($cache, 0777, true) && ! is_dir($cache)) {
    throw new RuntimeException('Unable to create the native Laravel proof sandbox.');
}

$write = static function (string $path, string $contents): void {
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException(sprintf('Unable to write proof file %s.', $path));
    }
};
$write($bootstrap.'/app.php', sprintf(
    "<?php\nfile_put_contents(%s, 'executed');\nreturn null;\n",
    var_export($bootstrapMarker, true),
));
$capture = static function (Closure $work): ?Throwable {
    try {
        $work();

        return null;
    } catch (Throwable $throwable) {
        return $throwable;
    }
};
$state = [
    'driver' => 'sqlite-memory',
    'connection' => 'sqlite',
    'prepared_schema' => true,
];
$wrongKind = new NativeInvalidStateProvider(
    ResourceKind::Filesystem,
    'wrong-kind',
    'wrong-kind',
);
$wrongKindFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $wrongKind,
    ),
);
$identityMismatch = new NativeInvalidStateProvider(
    ResourceKind::Database,
    'plan-name',
    'runtime-name',
);
$identityFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $identityMismatch,
    ),
);

$composer = [
    'name' => 'drove/native-laravel-preflight',
    'type' => 'project',
];
$write(
    $sandbox.'/composer.json',
    json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
);
$write(
    $cache.'/packages.php',
    sprintf(
        <<<'PHP'
<?php
file_put_contents(%s, 'executed', FILE_APPEND);

return [
    'proof/auto-discovered-sentinel' => [
        'providers' => [
            'NativeAdditionalProviderSentinel',
        ],
    ],
];
PHP,
        var_export($packageCacheMarker, true),
    ),
);
$write($bootstrap.'/providers.php', "<?php\nreturn [];\n");
$discoveryFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
    ),
);

$composer['extra']['laravel']['dont-discover'] = ['*'];
$write(
    $sandbox.'/composer.json',
    json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
);
$write(
    $cache.'/config.php',
    "<?php return ['app' => ['providers' => ['NativeAdditionalProviderSentinel']]];\n",
);
$configCacheFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
    ),
);
unlink($cache.'/config.php');

$write(
    $bootstrap.'/providers.php',
    <<<'PHP'
<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\RejectedNativeProvider;

return [
    AppServiceProvider::class,
    RejectedNativeProvider::class,
];
PHP,
);
$sentinelFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
        [AppServiceProvider::class],
    ),
);

$write($bootstrap.'/providers.php', "<?php\nreturn [];\n");
$transactionFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        ['driver' => 'transaction', 'connection' => 'mysql'],
    ),
);
$prepareFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
        prepare: static function (Application $app, string $extra): void {
            //
        },
    ),
);
$prepareTypeFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
        prepare: static function (string $application): void {
            //
        },
    ),
);
$prepareReferenceFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
        prepare: static function (Application &$application): void {
            //
        },
    ),
);
$prepareVariadicFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
        prepare: static function (Application ...$applications): void {
            //
        },
    ),
);
$providerListFailure = $capture(
    static function () use ($sandbox): void {
        NativeProviderManifest::assertAllowed($sandbox, ['not a class']);
    },
);
$write(
    $bootstrap.'/app.php',
    <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->create();
PHP,
);
$customConfigCache = $sandbox.'/custom-config-cache.php';
$write($customConfigCache, "<?php return ['app' => ['env' => 'testing']];\n");
putenv('APP_CONFIG_CACHE='.$customConfigCache);
$_ENV['APP_CONFIG_CACHE'] = $customConfigCache;
$_SERVER['APP_CONFIG_CACHE'] = $customConfigCache;
$customConfigCacheFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
    ),
);
putenv('APP_CONFIG_CACHE');
unset($_ENV['APP_CONFIG_CACHE'], $_SERVER['APP_CONFIG_CACHE']);
RegisterProviders::flushState();
$write(
    $bootstrap.'/app.php',
    <<<'PHP'
<?php

use Illuminate\Foundation\Application;

$application = Application::configure(basePath: dirname(__DIR__))
    ->create();
$application->bootstrapWith([]);

return $application;
PHP,
);
$hiddenBootstrapFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
    ),
);
RegisterProviders::flushState();
$write(
    $bootstrap.'/app.php',
    <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([NativeAdditionalProviderSentinel::class])
    ->create();
PHP,
);
putenv('DROVE_NATIVE_PROVIDER_SENTINEL='.$providerMarker);
$configuredProviderFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
    ),
);
RegisterProviders::flushState();
$config = $sandbox.'/config';

if (! mkdir($config) && ! is_dir($config)) {
    throw new RuntimeException('Unable to create the native Laravel proof config directory.');
}

$write($bootstrap.'/providers.php', "<?php\nreturn [];\n");
$write(
    $bootstrap.'/app.php',
    <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->create();
PHP,
);
$write(
    $config.'/app.php',
    <<<'PHP'
<?php

use Illuminate\Support\ServiceProvider;

return [
    'env' => 'testing',
    'providers' => [
        ...ServiceProvider::defaultProviders()->toArray(),
        NativeAdditionalProviderSentinel::class,
    ],
];
PHP,
);
putenv('DROVE_NATIVE_PROVIDER_SENTINEL='.$configProviderMarker);
$configProviderFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $state,
    ),
);
RegisterProviders::flushState();
$configProviderStayedCold = ! file_exists($configProviderMarker);
$write(
    $config.'/app.php',
    <<<'PHP'
<?php

return [
    'env' => 'testing',
];
PHP,
);
$write(
    $cache.'/services.php',
    sprintf(
        <<<'PHP'
<?php
file_put_contents(%s, 'executed', FILE_APPEND);

return [
    'providers' => [],
    'eager' => [
        NativeAdditionalProviderSentinel::class,
    ],
    'deferred' => [],
    'when' => [],
];
PHP,
        var_export($servicesCacheMarker, true),
    ),
);
$customPackageCache = $sandbox.'/custom-packages.php';
$customServicesCache = $sandbox.'/custom-services.php';
$write($customPackageCache, file_get_contents($cache.'/packages.php') ?: '');
$write($customServicesCache, file_get_contents($cache.'/services.php') ?: '');
$cacheSources = [
    $cache.'/packages.php',
    $cache.'/services.php',
    $customPackageCache,
    $customServicesCache,
];
$cacheHashesBefore = array_map(
    static fn (string $path): string|false => hash_file('sha256', $path),
    $cacheSources,
);
$temporaryCachesBefore = [
    ...(glob(sys_get_temp_dir().'/drove-laravel-native-packages-*.php') ?: []),
    ...(glob(sys_get_temp_dir().'/drove-laravel-native-services-*.php') ?: []),
];
putenv('APP_PACKAGES_CACHE='.$customPackageCache);
$_ENV['APP_PACKAGES_CACHE'] = $customPackageCache;
$_SERVER['APP_PACKAGES_CACHE'] = $customPackageCache;
putenv('APP_SERVICES_CACHE='.$customServicesCache);
$_ENV['APP_SERVICES_CACHE'] = $customServicesCache;
$_SERVER['APP_SERVICES_CACHE'] = $customServicesCache;
$cacheIsolationState = new NativeInvalidStateProvider(
    ResourceKind::Database,
    'cache-isolation',
    'cache-isolation',
);
$cacheIsolationFailure = $capture(
    static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
        $sandbox,
        $cacheIsolationState,
    ),
);
$customCacheEnvironmentRestored = getenv('APP_PACKAGES_CACHE') === $customPackageCache
    && getenv('APP_SERVICES_CACHE') === $customServicesCache;
putenv('APP_PACKAGES_CACHE');
unset($_ENV['APP_PACKAGES_CACHE'], $_SERVER['APP_PACKAGES_CACHE']);
putenv('APP_SERVICES_CACHE');
unset($_ENV['APP_SERVICES_CACHE'], $_SERVER['APP_SERVICES_CACHE']);
RegisterProviders::flushState();
$cacheHashesAfter = array_map(
    static fn (string $path): string|false => hash_file('sha256', $path),
    $cacheSources,
);
$temporaryCachesAfter = [
    ...(glob(sys_get_temp_dir().'/drove-laravel-native-packages-*.php') ?: []),
    ...(glob(sys_get_temp_dir().'/drove-laravel-native-services-*.php') ?: []),
];
$packageCacheStayedCold = ! file_exists($packageCacheMarker);
$servicesCacheStayedCold = ! file_exists($servicesCacheMarker);
$cacheOriginalsUnchanged = $cacheHashesBefore === $cacheHashesAfter;
$temporaryCacheArtifacts = array_values(array_diff(
    $temporaryCachesAfter,
    $temporaryCachesBefore,
));
$preflightKeptBootstrapCold = ! file_exists($bootstrapMarker)
    && ! file_exists($providerMarker)
    && $configProviderStayedCold
    && $packageCacheStayedCold
    && $servicesCacheStayedCold
    && $wrongKind->bootCount === 0
    && $identityMismatch->bootCount === 0;

$phpunitLoadedBefore = class_exists('PHPUnit\\Framework\\TestCase', false);
$testbenchLoadedBefore = class_exists('Orchestra\\Testbench\\TestCase', false);
/**
 * @param  list<string>  $arguments
 * @return array{
 *     exit_code: int,
 *     result: array<string, mixed>|null,
 *     stderr: string
 * }
 */
$runPhpProof = static function (
    string $script,
    array $arguments = [],
): array {
    $process = proc_open(
        [PHP_BINARY, __DIR__.'/'.$script, ...$arguments],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        __DIR__,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to launch the native reboot proof.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $result = is_string($stdout)
        ? json_decode(trim($stdout), true)
        : null;

    return [
        'exit_code' => $exitCode,
        'result' => is_array($result) ? $result : null,
        'stderr' => is_string($stderr) ? trim($stderr) : '',
    ];
};
$envConfigCacheProof = $runPhpProof('proof-native-env-config.php');
$envProviderCacheProof = $runPhpProof(
    'proof-native-env-config.php',
    ['provider-caches'],
);
$firstSeparateBoot = $runPhpProof('proof-native-reboot.php');
$secondSeparateBoot = $runPhpProof('proof-native-reboot.php');
$prepareCount = 0;
$registry = Declarations::capture(
    static function () use (&$prepareCount): void {
        laravel(
            __DIR__,
            new InMemorySqliteDatabaseStateAdapter('sqlite', true),
            [
                AppServiceProvider::class,
                DroveLaravelServiceProvider::class,
            ],
            static function (Application $application) use (&$prepareCount): void {
                $prepareCount++;
                $database = $application->make('db')->connection('sqlite');
                $database->statement(
                    'CREATE TABLE native_phase_four (id INTEGER PRIMARY KEY, value TEXT NOT NULL)',
                );
                $database->table('native_phase_four')->insert([
                    'id' => 1,
                    'value' => 'prepared',
                ]);
            },
        );
    },
    __DIR__,
);
$registry->plan();
$runtime = $registry->resolveEnvironment();

if (! $runtime instanceof ApplicationRuntime) {
    throw new RuntimeException('Native Laravel did not resolve an ApplicationRuntime.');
}

$cachedRuntime = $registry->resolveEnvironment();
$database = $runtime->application()->make('db')->connection('sqlite');
$rows = $database->table('native_phase_four')->pluck('value')->all();
$resource = $runtime->stateProvider()->resourcePlan();
$phpunitLoadedAfter = class_exists('PHPUnit\\Framework\\TestCase', false);
$testbenchLoadedAfter = class_exists('Orchestra\\Testbench\\TestCase', false);

$passed = $wrongKindFailure instanceof StateAdapterException
    && $identityFailure instanceof StateAdapterException
    && $discoveryFailure instanceof StateAdapterException
    && str_contains($discoveryFailure->getMessage(), 'dont-discover')
    && $configCacheFailure instanceof StateAdapterException
    && str_contains($configCacheFailure->getMessage(), 'config.php')
    && $sentinelFailure instanceof StateAdapterException
    && str_contains(
        $sentinelFailure->getMessage(),
        'RejectedNativeProvider',
    )
    && $transactionFailure instanceof StateAdapterException
    && str_contains($transactionFailure->getMessage(), 'remains bridge-only')
    && $prepareFailure instanceof InvalidArgumentException
    && $prepareTypeFailure instanceof InvalidArgumentException
    && $prepareReferenceFailure instanceof InvalidArgumentException
    && $prepareVariadicFailure instanceof InvalidArgumentException
    && $providerListFailure instanceof InvalidArgumentException
    && $customConfigCacheFailure instanceof StateAdapterException
    && str_contains(
        $customConfigCacheFailure->getMessage(),
        'cached configuration',
    )
    && $hiddenBootstrapFailure instanceof StateAdapterException
    && str_contains(
        $hiddenBootstrapFailure->getMessage(),
        'unbootstrapped Application',
    )
    && $configuredProviderFailure instanceof StateAdapterException
    && str_contains(
        $configuredProviderFailure->getMessage(),
        NativeAdditionalProviderSentinel::class,
    )
    && $configProviderFailure instanceof StateAdapterException
    && str_contains(
        $configProviderFailure->getMessage(),
        NativeAdditionalProviderSentinel::class,
    )
    && $configProviderStayedCold
    && $cacheIsolationFailure === null
    && $cacheIsolationState->bootCount === 1
    && $customCacheEnvironmentRestored
    && $packageCacheStayedCold
    && $servicesCacheStayedCold
    && $cacheOriginalsUnchanged
    && $temporaryCacheArtifacts === []
    && $preflightKeptBootstrapCold
    && ! $phpunitLoadedBefore
    && ! $testbenchLoadedBefore
    && $envConfigCacheProof['exit_code'] === 0
    && $envConfigCacheProof['stderr'] === ''
    && ($envConfigCacheProof['result']['status'] ?? null) === 'passed'
    && ($envConfigCacheProof['result']['cold'] ?? null) === true
    && $envProviderCacheProof['exit_code'] === 0
    && $envProviderCacheProof['stderr'] === ''
    && ($envProviderCacheProof['result']['status'] ?? null) === 'passed'
    && $firstSeparateBoot['exit_code'] === 0
    && $firstSeparateBoot['stderr'] === ''
    && ($firstSeparateBoot['result']['status'] ?? null) === 'passed'
    && ($firstSeparateBoot['result']['services_manifest'] ?? null) === true
    && $secondSeparateBoot['exit_code'] === 0
    && $secondSeparateBoot['stderr'] === ''
    && ($secondSeparateBoot['result']['status'] ?? null) === 'passed'
    && ($secondSeparateBoot['result']['services_manifest'] ?? null) === true
    && ($firstSeparateBoot['result']['pid'] ?? null)
        !== ($secondSeparateBoot['result']['pid'] ?? null)
    && ! $phpunitLoadedAfter
    && ! $testbenchLoadedAfter
    && $prepareCount === 1
    && $cachedRuntime === $runtime
    && $rows === ['prepared']
    && new TestContext(scope: $runtime->scopeContext())->app() === $runtime->application()
    && $resource->supports(ResourceCapability::Branchable)
    && $resource->supports(ResourceCapability::ScopeIsolated);

$remove = static function (string $directory) use (&$remove): void {
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $directory.'/'.$name;
        is_dir($path) ? $remove($path) : unlink($path);
    }

    rmdir($directory);
};
$remove($sandbox);

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'preflight' => [
        'wrong_resource_kind' => $wrongKindFailure?->getMessage(),
        'resource_identity' => $identityFailure?->getMessage(),
        'package_discovery' => $discoveryFailure?->getMessage(),
        'config_cache' => $configCacheFailure?->getMessage(),
        'custom_config_cache' => $customConfigCacheFailure?->getMessage(),
        'env_config_cache' => $envConfigCacheProof['result']['failure'] ?? null,
        'env_config_cache_cold' => $envConfigCacheProof['result']['cold'] ?? false,
        'env_cache_isolation' => [
            'package_sentinel_cold' => $envProviderCacheProof['result']['package_sentinel_cold'] ?? false,
            'services_sentinel_cold' => $envProviderCacheProof['result']['services_sentinel_cold'] ?? false,
            'original_hashes_unchanged' => $envProviderCacheProof['result']['original_hashes_unchanged'] ?? false,
            'temporary_artifact_count' => $envProviderCacheProof['result']['temporary_artifact_count'] ?? -1,
            'environment_restored' => $envProviderCacheProof['result']['environment_restored'] ?? false,
        ],
        'hidden_bootstrap' => $hiddenBootstrapFailure?->getMessage(),
        'sentinel' => $sentinelFailure?->getMessage(),
        'configured_provider' => $configuredProviderFailure?->getMessage(),
        'config_provider' => $configProviderFailure?->getMessage(),
        'config_provider_cold' => $configProviderStayedCold,
        'cache_isolation' => [
            'package_sentinel_cold' => $packageCacheStayedCold,
            'services_sentinel_cold' => $servicesCacheStayedCold,
            'original_hashes_unchanged' => $cacheOriginalsUnchanged,
            'temporary_artifact_count' => count($temporaryCacheArtifacts),
            'environment_restored' => $customCacheEnvironmentRestored,
        ],
        'transaction' => $transactionFailure?->getMessage(),
        'prepare' => $prepareFailure?->getMessage(),
        'prepare_type' => $prepareTypeFailure?->getMessage(),
        'prepare_reference' => $prepareReferenceFailure?->getMessage(),
        'prepare_variadic' => $prepareVariadicFailure?->getMessage(),
        'providers' => $providerListFailure?->getMessage(),
        'bootstrap_cold' => $preflightKeptBootstrapCold,
        'separate_boots' => [
            'first' => $firstSeparateBoot,
            'second' => $secondSeparateBoot,
        ],
    ],
    'native' => [
        'prepare_count' => $prepareCount,
        'rows' => $rows,
        'branchable' => $resource->supports(ResourceCapability::Branchable),
        'scope_isolated' => $resource->supports(ResourceCapability::ScopeIsolated),
        'phpunit_loaded' => $phpunitLoadedAfter,
        'testbench_loaded' => $testbenchLoadedAfter,
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
