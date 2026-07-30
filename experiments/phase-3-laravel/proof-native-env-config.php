<?php

declare(strict_types=1);

use Drove\Environment\ResourceCapability;
use Drove\Environment\ResourceKind;
use Drove\Environment\ResourcePlan;
use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Drove\Laravel\ApplicationRuntime;
use Drove\Laravel\Contracts\DatabaseStateProvider;
use Illuminate\Foundation\Application;

require __DIR__.'/vendor/autoload.php';

final class NativeEnvStateProvider implements DatabaseStateProvider
{
    public int $bootCount = 0;

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
        return 'env-cache-proof';
    }

    public function limitations(): array
    {
        return [];
    }

    public function resourcePlan(): ResourcePlan
    {
        return new ResourcePlan(
            ResourceKind::Database,
            $this->name(),
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
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

putenv('APP_CONFIG_CACHE');
unset($_ENV['APP_CONFIG_CACHE'], $_SERVER['APP_CONFIG_CACHE']);

$mode = $argv[1] ?? 'config';

if ($mode === 'provider-caches') {
    foreach (['APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE'] as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    $sandbox = sys_get_temp_dir().'/drove-native-env-caches-'.bin2hex(random_bytes(8));
    $bootstrap = $sandbox.'/bootstrap';
    $cache = $bootstrap.'/cache';
    $config = $sandbox.'/config';
    $packageMarker = $sandbox.'/package-cache-executed';
    $servicesMarker = $sandbox.'/services-cache-executed';
    $packageCache = $sandbox.'/env-packages.php';
    $servicesCache = $sandbox.'/env-services.php';

    if (! mkdir($cache, 0777, true) || ! mkdir($config, 0777, true)) {
        throw new RuntimeException('Unable to create the env provider-cache proof sandbox.');
    }

    $write = static function (string $path, string $contents): void {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write proof file %s.', $path));
        }
    };
    $write(
        $sandbox.'/composer.json',
        json_encode([
            'name' => 'drove/native-env-cache-proof',
            'extra' => [
                'laravel' => [
                    'dont-discover' => ['*'],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
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
    $write($bootstrap.'/providers.php', "<?php\nreturn [];\n");
    $write(
        $config.'/app.php',
        "<?php\nreturn ['env' => 'testing'];\n",
    );
    $write(
        $packageCache,
        sprintf(
            "<?php\nfile_put_contents(%s, 'executed');\nreturn ['proof' => ['providers' => ['NativeEnvUnauthorizedProvider']]];\n",
            var_export($packageMarker, true),
        ),
    );
    $write(
        $servicesCache,
        sprintf(
            "<?php\nfile_put_contents(%s, 'executed');\nreturn ['providers' => [], 'eager' => ['NativeEnvUnauthorizedProvider'], 'deferred' => [], 'when' => []];\n",
            var_export($servicesMarker, true),
        ),
    );
    $write(
        $sandbox.'/.env',
        'APP_PACKAGES_CACHE='.str_replace('\\', '/', $packageCache).PHP_EOL
            .'APP_SERVICES_CACHE='.str_replace('\\', '/', $servicesCache).PHP_EOL,
    );
    $sources = [$packageCache, $servicesCache];
    $hashesBefore = array_map(
        static fn (string $path): string|false => hash_file('sha256', $path),
        $sources,
    );
    $temporaryBefore = [
        ...(glob(sys_get_temp_dir().'/drove-laravel-native-packages-*.php') ?: []),
        ...(glob(sys_get_temp_dir().'/drove-laravel-native-services-*.php') ?: []),
    ];
    $state = new NativeEnvStateProvider;
    $failure = null;

    try {
        ApplicationRuntime::bootNative($sandbox, $state);
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }

    $hashesAfter = array_map(
        static fn (string $path): string|false => hash_file('sha256', $path),
        $sources,
    );
    $temporaryAfter = [
        ...(glob(sys_get_temp_dir().'/drove-laravel-native-packages-*.php') ?: []),
        ...(glob(sys_get_temp_dir().'/drove-laravel-native-services-*.php') ?: []),
    ];
    $result = [
        'package_sentinel_cold' => ! file_exists($packageMarker),
        'services_sentinel_cold' => ! file_exists($servicesMarker),
        'original_hashes_unchanged' => $hashesBefore === $hashesAfter,
        'temporary_artifact_count' => count(array_diff(
            $temporaryAfter,
            $temporaryBefore,
        )),
        'environment_restored' => getenv('APP_PACKAGES_CACHE') === false
            && getenv('APP_SERVICES_CACHE') === false
            && ! array_key_exists('APP_PACKAGES_CACHE', $_ENV)
            && ! array_key_exists('APP_SERVICES_CACHE', $_ENV)
            && ! array_key_exists('APP_PACKAGES_CACHE', $_SERVER)
            && ! array_key_exists('APP_SERVICES_CACHE', $_SERVER),
    ];
    $passed = $failure === null
        && $state->bootCount === 1
        && $result === [
            'package_sentinel_cold' => true,
            'services_sentinel_cold' => true,
            'original_hashes_unchanged' => true,
            'temporary_artifact_count' => 0,
            'environment_restored' => true,
        ];
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
        'failure' => $failure?->getMessage(),
        ...$result,
    ], JSON_THROW_ON_ERROR).PHP_EOL);

    exit($passed ? 0 : 1);
}

$sandbox = sys_get_temp_dir().'/drove-native-env-config-'.bin2hex(random_bytes(8));
$bootstrap = $sandbox.'/bootstrap';
$marker = $sandbox.'/config-cache-executed';
$configCache = $sandbox.'/config.php';

if (! mkdir($bootstrap, 0777, true) && ! is_dir($bootstrap)) {
    throw new RuntimeException('Unable to create the env config proof sandbox.');
}

$write = static function (string $path, string $contents): void {
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException(sprintf('Unable to write proof file %s.', $path));
    }
};
$write(
    $sandbox.'/composer.json',
    json_encode([
        'name' => 'drove/native-env-config-proof',
        'extra' => [
            'laravel' => [
                'dont-discover' => ['*'],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
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
$write($bootstrap.'/providers.php', "<?php\nreturn [];\n");
$write(
    $sandbox.'/.env',
    'APP_CONFIG_CACHE='.str_replace('\\', '/', $configCache).PHP_EOL,
);
$write(
    $configCache,
    sprintf(
        "<?php\nfile_put_contents(%s, 'executed');\nreturn [];\n",
        var_export($marker, true),
    ),
);

$failure = null;

try {
    ApplicationRuntime::bootNative(
        $sandbox,
        [
            'driver' => 'sqlite-memory',
            'connection' => 'sqlite',
            'prepared_schema' => true,
        ],
    );
} catch (Throwable $throwable) {
    $failure = $throwable;
}

$cold = ! file_exists($marker);
$passed = $failure instanceof StateAdapterException
    && str_contains($failure->getMessage(), 'cached configuration')
    && $cold;
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
    'failure' => $failure?->getMessage(),
    'cold' => $cold,
], JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
