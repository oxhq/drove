<?php

declare(strict_types=1);

use Drove\Kernel\StateAdapterException;
use Drove\Laravel\LaravelRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require __DIR__.'/vendor/autoload.php';

$runtime = LaravelRuntime::boot(__DIR__);
$scope = $runtime->scopeContext();
$testbenchCase = new class('placeholder') extends PHPUnit\Framework\TestCase
{
    public function placeholder(): void
    {
        //
    }
};
class_alias($testbenchCase::class, 'Orchestra\\Testbench\\TestCase');
$testbenchFailure = null;

try {
    $runtime->bindTestCase($testbenchCase, $scope);
} catch (StateAdapterException $exception) {
    $testbenchFailure = $exception->getMessage();
}

$invalidTasks = [
    ['id' => '', 'kind' => 'test'],
];
$beforeFailure = null;

try {
    $runtime->beforeDispatch($scope, $invalidTasks);
} catch (StateAdapterException $exception) {
    $beforeFailure = $exception->getMessage();
}

$passed = $beforeFailure === 'Drove Laravel received an invalid descendant task.'
    && $testbenchFailure
        === 'Drove Laravel does not support Orchestra Testbench TestCase instances yet.';

try {
    $runtime->afterDispatch($scope, $invalidTasks);
} catch (Throwable $throwable) {
    $passed = false;
    $afterFailure = $throwable::class.': '.$throwable->getMessage();
}

$sqliteTraitChecked = $runtime->stateAdapter()->name() === 'sqlite-copy';
$sqliteTraitFailure = null;

if ($sqliteTraitChecked) {
    $case = new class('placeholder') extends TestCase
    {
        use RefreshDatabase;

        public function placeholder(): void
        {
            //
        }
    };

    try {
        $runtime->bindTestCase($case, $scope);
    } catch (Throwable $throwable) {
        $sqliteTraitFailure = $throwable::class.': '.$throwable->getMessage();
        $passed = false;
    }
}

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'adapter' => $runtime->stateAdapter()->name(),
    'before_failure' => $beforeFailure,
    'after_failure' => $afterFailure ?? null,
    'testbench_failure' => $testbenchFailure,
    'sqlite_refresh_database_checked' => $sqliteTraitChecked,
    'sqlite_refresh_database_failure' => $sqliteTraitFailure,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
