<?php

declare(strict_types=1);

use Drove\Kernel\StateAdapterException;
use Drove\Laravel\LaravelRuntime;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require __DIR__.'/vendor/autoload.php';

$runtime = LaravelRuntime::boot(__DIR__);
$scope = $runtime->scopeContext();
$cases = [
    LazilyRefreshDatabase::class => new class('placeholder') extends TestCase
    {
        use LazilyRefreshDatabase;

        public function placeholder(): void
        {
            //
        }
    },
    RefreshDatabase::class => new class('placeholder') extends TestCase
    {
        use RefreshDatabase;

        public function placeholder(): void
        {
            //
        }
    },
    DatabaseMigrations::class => new class('placeholder') extends TestCase
    {
        use DatabaseMigrations;

        public function placeholder(): void
        {
            //
        }
    },
    DatabaseTruncation::class => new class('placeholder') extends TestCase
    {
        use DatabaseTruncation;

        public function placeholder(): void
        {
            //
        }
    },
];
$rejections = [];

foreach ($cases as $trait => $case) {
    try {
        $runtime->bindTestCase($case, $scope);
    } catch (StateAdapterException $exception) {
        $rejections[$trait] = $exception->getMessage();
    }
}

$allowed = new class('placeholder') extends TestCase
{
    use DatabaseTransactions;

    public function placeholder(): void
    {
        //
    }
};
$allowedFailure = null;

try {
    $runtime->bindTestCase($allowed, $scope);
} catch (Throwable $throwable) {
    $allowedFailure = $throwable::class.': '.$throwable->getMessage();
}

$connection = $runtime->application()->make('db')->connection('mysql');
$connection->statement('DROP TABLE IF EXISTS drove_non_transactional_guard');
$connection->statement(
    'CREATE TABLE drove_non_transactional_guard (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=MyISAM',
);
$engineFailure = null;

try {
    $runtime->beforeDispatch($scope, [[
        'id' => 'test:non-transactional-engine',
        'kind' => 'test',
    ]]);
} catch (StateAdapterException $exception) {
    $engineFailure = $exception->getMessage();
} finally {
    $connection->statement('DROP TABLE IF EXISTS drove_non_transactional_guard');
}

$passed = $allowedFailure === null
    && count($rejections) === count($cases)
    && $engineFailure
        === 'Drove Laravel transaction mode requires InnoDB tables; received drove_non_transactional_guard (MyISAM).';

foreach (array_keys($cases) as $trait) {
    $passed = $passed
        && ($rejections[$trait] ?? null)
            === 'Drove Laravel transaction mode does not support TestCase trait '.$trait.'.';
}

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'rejections' => $rejections,
    'database_transactions_failure' => $allowedFailure,
    'non_transactional_engine_failure' => $engineFailure,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
