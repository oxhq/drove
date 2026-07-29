<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require __DIR__.'/vendor/autoload.php';

$cache = __DIR__.'/bootstrap/cache/config.php';

if (file_exists($cache)) {
    throw new RuntimeException(
        'The runtime guard proof refuses to overwrite an existing configuration cache.',
    );
}

$environment = [
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => __DIR__.'/database/database.sqlite',
    'DROVE_LARAVEL' => false,
    'DROVE_LARAVEL_DB_CONNECTION' => 'sqlite',
    'DROVE_LARAVEL_SQLITE_WORKSPACE' => __DIR__.'/storage/framework/drove',
    'DROVE_LARAVEL_STATE' => 'sqlite-copy',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
];

$run = static function (array $command, array $overrides = []) use ($environment): array {
    $process = new Process(
        $command,
        __DIR__,
        $overrides + $environment,
    );
    $process->setTimeout(60);

    return [
        'exit_code' => $process->run(),
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
    ];
};

$processGuard = $run(
    [PHP_BINARY, 'runtime-guard-worker.php', 'process'],
    ['APP_ENV' => 'production'],
);
$connectionGuard = $run(
    [PHP_BINARY, 'runtime-guard-worker.php', 'connection'],
    [
        'APP_ENV' => 'testing',
        'DROVE_LARAVEL_DB_CONNECTION' => 'secondary',
    ],
);
$cacheBuild = null;
$applicationGuard = null;
$cachedEnvironment = null;

try {
    $cacheBuild = $run(
        [PHP_BINARY, 'artisan', 'config:cache', '--no-ansi'],
        ['APP_ENV' => 'production'],
    );

    if (($cacheBuild['exit_code'] ?? null) === 0 && is_file($cache)) {
        $cached = require $cache;
        $cachedEnvironment = is_array($cached)
            ? ($cached['app']['env'] ?? null)
            : null;
        $applicationGuard = $run(
            [PHP_BINARY, 'runtime-guard-worker.php', 'application'],
            ['APP_ENV' => 'testing'],
        );
    }
} finally {
    if (is_file($cache) && ! unlink($cache)) {
        throw new RuntimeException('Unable to remove the runtime guard configuration cache.');
    }
}

$cacheRemoved = ! file_exists($cache);
$passed = ($processGuard['exit_code'] ?? null) === 0
    && ($connectionGuard['exit_code'] ?? null) === 0
    && ($cacheBuild['exit_code'] ?? null) === 0
    && $cachedEnvironment === 'production'
    && ($applicationGuard['exit_code'] ?? null) === 0
    && $cacheRemoved;

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'process_guard' => $processGuard,
    'connection_guard' => $connectionGuard,
    'cache_build' => $cacheBuild,
    'cached_environment' => $cachedEnvironment,
    'application_guard' => $applicationGuard,
    'cache_removed' => $cacheRemoved,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
