<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Drove\Laravel\ApplicationRuntime;
use Drove\Laravel\DroveLaravelServiceProvider;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Native\Declarations;

use function Drove\Laravel\laravel;

require __DIR__.'/vendor/autoload.php';

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

$registry = Declarations::capture(
    static function (): void {
        laravel(
            __DIR__,
            new InMemorySqliteDatabaseStateAdapter('sqlite', true),
            [
                AppServiceProvider::class,
                DroveLaravelServiceProvider::class,
            ],
        );
    },
    __DIR__,
);
$registry->plan();
$runtime = $registry->resolveEnvironment();
$passed = $runtime instanceof ApplicationRuntime
    && $runtime->application()->environment('testing');

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'pid' => getmypid(),
    'services_manifest' => is_file(__DIR__.'/bootstrap/cache/services.php'),
], JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
