<?php

declare(strict_types=1);

use Drove\Environment\ResourceCapability;
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
    DatabaseTransactions::class => new class('placeholder') extends TestCase
    {
        use DatabaseTransactions;

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

$unsupportedPlan = [
    'type' => 'suite',
    'id' => 'suite:transaction-topology',
    'tests' => [],
    'children' => [
        [
            'type' => 'file',
            'id' => 'file:first',
            'tests' => [],
            'children' => [],
        ],
        [
            'type' => 'file',
            'id' => 'file:second',
            'tests' => [],
            'children' => [],
        ],
    ],
];
$planFailure = null;
$runtimeGuardFailure = null;

try {
    $runtime->assertPlanSupported($unsupportedPlan);
} catch (InvalidArgumentException $exception) {
    $planFailure = $exception->getMessage();
}

try {
    $runtime->beforeDispatch($scope, [
        ['id' => 'file:first', 'kind' => 'scope'],
        ['id' => 'file:second', 'kind' => 'scope'],
    ]);
} catch (InvalidArgumentException $exception) {
    $runtimeGuardFailure = $exception->getMessage();
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

$passed = count($rejections) === count($cases)
    && $runtime->stateAdapter()->resourcePlan()->capabilities() === [
        ResourceCapability::LeafIsolated,
    ]
    && $planFailure
        === 'Environment resource database (transaction) cannot isolate sibling or mixed scope dispatches.'
    && $runtimeGuardFailure === $planFailure
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
    'resource' => $runtime->stateAdapter()->resourcePlan(),
    'plan_failure' => $planFailure,
    'runtime_guard_failure' => $runtimeGuardFailure,
    'non_transactional_engine_failure' => $engineFailure,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
