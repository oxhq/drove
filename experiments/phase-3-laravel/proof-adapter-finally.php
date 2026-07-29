<?php

declare(strict_types=1);

use Drove\Kernel\StateAdapterException;
use Drove\Laravel\LaravelRuntime;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require __DIR__.'/vendor/autoload.php';

$runtime = LaravelRuntime::boot(__DIR__);
$scope = $runtime->scopeContext();
$testbenchCase = new class('placeholder') extends Orchestra\Testbench\TestCase
{
    public function placeholder(): void
    {
        //
    }
};
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
        === 'Drove Laravel Orchestra Testbench cases require bootForSuite().';

try {
    $runtime->afterDispatch($scope, $invalidTasks);
} catch (Throwable $throwable) {
    $passed = false;
    $afterFailure = $throwable::class.': '.$throwable->getMessage();
}

$sqliteTraitChecked = $runtime->stateAdapter()->name() === 'sqlite-copy';
$sqliteTraitFailure = null;
$sqliteTruncationFailure = null;

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

    $truncationCase = new class('placeholder') extends TestCase
    {
        use DatabaseTruncation;

        public function placeholder(): void
        {
            //
        }
    };

    try {
        $runtime->bindTestCase($truncationCase, $scope);
    } catch (StateAdapterException $exception) {
        $sqliteTruncationFailure = $exception->getMessage();
    }

    $passed = $passed
        && $sqliteTruncationFailure
            === 'Drove Laravel sqlite-copy mode does not support TestCase trait '
                .DatabaseTruncation::class.'.';
}

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'adapter' => $runtime->stateAdapter()->name(),
    'before_failure' => $beforeFailure,
    'after_failure' => $afterFailure ?? null,
    'testbench_failure' => $testbenchFailure,
    'sqlite_refresh_database_checked' => $sqliteTraitChecked,
    'sqlite_refresh_database_failure' => $sqliteTraitFailure,
    'sqlite_truncation_failure' => $sqliteTruncationFailure,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
