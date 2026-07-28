<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as LaravelKernel;
use Illuminate\Support\Facades\DB;
use Pest\Contracts\HasPrintableTestCaseName;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PHPUnit\Framework\TestSuite as PHPUnitTestSuite;
use PHPUnit\TextUI\Configuration\Registry as PHPUnitConfiguration;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

require __DIR__.'/vendor/autoload.php';

if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "Drove Phase 1 requires pcntl_fork().\n");
    exit(2);
}

$databaseDirectory = __DIR__.'/database';

if (! is_dir($databaseDirectory) && ! mkdir($databaseDirectory, 0777, true) && ! is_dir($databaseDirectory)) {
    throw new RuntimeException(sprintf('Unable to create %s.', $databaseDirectory));
}

$database = $databaseDirectory.'/drove.sqlite';
touch($database);

$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

$rootPid = getmypid();
$GLOBALS['drove_laravel_boot_pids'] = [];
$GLOBALS['drove_scope_state'] = (object) ['mutations' => ['root']];
$GLOBALS['drove_before_all_proof'] = (object) ['count' => 0, 'pid' => null];
$GLOBALS['drove_after_all_proof'] = (object) ['count' => 0, 'pid' => null];

$app = require __DIR__.'/bootstrap/app.php';
$app->make(LaravelKernel::class)->bootstrap();

set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
});

if ($GLOBALS['drove_laravel_boot_pids'] !== [$rootPid]) {
    throw new RuntimeException('Laravel did not boot exactly once in the root process.');
}

$applicationObjectId = spl_object_id($app);
$app->instance('drove.root_pid', $rootPid);
$app->instance('drove.application_object_id', $applicationObjectId);
TestCase::$preparedApplication = $app;

$outputBufferLevel = ob_get_level();
PestKernel::boot(
    PestTestSuite::getInstance(__DIR__, 'tests'),
    new ArrayInput([]),
    new BufferedOutput,
);

while (ob_get_level() > $outputBufferLevel) {
    ob_end_clean();
}

$fixture = realpath(__DIR__.'/tests/PreparedStateTest.php');

if ($fixture === false) {
    throw new RuntimeException('The Pest fixture does not exist.');
}

$suite = PHPUnitTestSuite::empty('drove-phase-one');
$suite->addTestFile($fixture);
(new TestSuiteFilterProcessor)->process(PHPUnitConfiguration::get(), $suite);

$classSuite = $suite->tests()[0] ?? null;
$testCase = $classSuite instanceof PHPUnitTestSuite
    ? ($classSuite->tests()[0] ?? null)
    : null;

if (! $testCase instanceof PHPUnitTestCase || ! $testCase instanceof HasPrintableTestCaseName) {
    throw new RuntimeException('Pest did not discover one generated test case.');
}

if (count($suite->tests()) !== 1 || count($classSuite->tests()) !== 1) {
    throw new RuntimeException('Pest discovery did not return exactly one test.');
}

$factory = PestTestSuite::getInstance()->tests->get($fixture);
$method = $factory === null ? null : array_values($factory->methods)[0] ?? null;

if ($factory === null
    || count($factory->methods) !== 1
    || $method === null
    || ! $method->closure instanceof Closure
    || $method->description === null) {
    throw new RuntimeException('Pest discovery did not retain one explicit test closure.');
}

$relativeFixture = str_replace('\\', '/', substr($fixture, strlen(__DIR__) + 1));
$source = new ReflectionFunction($method->closure);
$discoveryManifest = [
    'id' => 'file:'.$relativeFixture,
    'path' => $relativeFixture,
    'tests' => [[
        'id' => 'test:'.$relativeFixture.'::'.$method->description,
        'name' => $method->description,
        'source' => [
            'path' => $relativeFixture,
            'line' => $source->getStartLine(),
        ],
    ]],
];
$expectedDiscoveryManifest = [
    'id' => 'file:tests/PreparedStateTest.php',
    'path' => 'tests/PreparedStateTest.php',
    'tests' => [[
        'id' => 'test:tests/PreparedStateTest.php::it reads root-prepared state in a forked child',
        'name' => 'it reads root-prepared state in a forked child',
        'source' => ['path' => 'tests/PreparedStateTest.php', 'line' => 39],
    ]],
];

$generatedClass = $testCase::class;
$generatedMethod = $testCase->name();
$generatedFile = (new ReflectionClass($generatedClass))->getFileName();
$loaderFile = (new ReflectionClass(PHPUnit\Runner\TestSuiteLoader::class))->getFileName();
$localFactoryHash = hash_file('sha256', '/pest/src/Factories/TestCaseFactory.php');
$installedFactoryHash = hash_file('sha256', __DIR__.'/vendor/pestphp/pest/src/Factories/TestCaseFactory.php');
$usesLocalPest = is_string($localFactoryHash)
    && hash_equals($localFactoryHash, (string) $installedFactoryHash)
    && is_string($loaderFile)
    && str_ends_with(str_replace('\\', '/', $loaderFile), '/vendor/pestphp/pest/overrides/Runner/TestSuiteLoader.php');

if (! $usesLocalPest
    || ! is_subclass_of($generatedClass, TestCase::class)
    || $generatedClass::$__filename !== $fixture
    || ! is_string($generatedFile)
    || ! str_contains($generatedFile, 'TestCaseFactory.php')) {
    throw new RuntimeException('The discovered case was not generated by the local Pest fork.');
}

$generatedClass::setUpBeforeClass();
$databaseManager = null;
$connectionNames = [];
$rootConnectionsReady = false;

try {
    $beforeAllProof = $GLOBALS['drove_before_all_proof'];
    $scopeState = $GLOBALS['drove_scope_state'];

    if ($beforeAllProof->count !== 1
        || $beforeAllProof->pid !== $rootPid
        || $scopeState->mutations !== ['root', 'prepared']) {
        throw new RuntimeException('Pest beforeAll did not prepare the root scope exactly once.');
    }

    $databaseManager = $app->make('db');
    $connectionNames = array_keys($databaseManager->getConnections());

    foreach ($connectionNames as $connectionName) {
        if ($databaseManager->connection($connectionName)->transactionLevel() !== 0) {
            throw new RuntimeException(sprintf('Database connection %s has an open transaction.', $connectionName));
        }

        $databaseManager->disconnect($connectionName);
    }

    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($sockets === false) {
        throw new RuntimeException('Unable to create the child result pipe.');
    }

    [$parentSocket, $childSocket] = $sockets;
    $childPid = pcntl_fork();

    if ($childPid === -1) {
        fclose($parentSocket);
        fclose($childSocket);
        throw new RuntimeException('Unable to fork the discovered Pest test.');
    }

    if ($childPid === 0) {
        fclose($parentSocket);
        $result = ['status' => 'passed', 'pid' => getmypid()];

        try {
            foreach ($connectionNames as $connectionName) {
                $databaseManager->reconnect($connectionName);
            }

            $testCase->run();

            if (! $testCase->status()->isSuccess()) {
                throw new RuntimeException(sprintf(
                    'Generated Pest test ended with %s: %s',
                    $testCase->status()->asString(),
                    $testCase->status()->message(),
                ));
            }

            $result += [
                'generated_class' => $generatedClass,
                'generated_method' => $generatedMethod,
                'test_status' => $testCase->status()->asString(),
                'assertions' => $testCase->numberOfAssertionsPerformed(),
                'pest_ran' => $testCase->__ran,
                'application_request_pids' => TestCase::$applicationRequestPids,
                'application_object_id' => spl_object_id($app),
                'laravel_boot_pids' => $GLOBALS['drove_laravel_boot_pids'],
                'execution' => $GLOBALS['drove_pest_execution'] ?? null,
            ];
        } catch (Throwable $throwable) {
            $result['status'] = 'failed';
            $result['error'] = $throwable::class.': '.$throwable->getMessage();
            $result['error_at'] = $throwable->getFile().':'.$throwable->getLine();
        } finally {
            foreach ($connectionNames as $connectionName) {
                $databaseManager->disconnect($connectionName);
            }
        }

        fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
        fclose($childSocket);

        exit($result['status'] === 'passed' ? 0 : 1);
    }

    fclose($childSocket);
    $waitStatus = 0;

    do {
        $waitedPid = pcntl_waitpid($childPid, $waitStatus);
    } while ($waitedPid === -1 && pcntl_get_last_error() === PCNTL_EINTR);

    $payload = trim((string) stream_get_contents($parentSocket));
    fclose($parentSocket);

    $result = $payload === ''
        ? ['status' => 'failed', 'pid' => $childPid, 'error' => 'No child result.']
        : json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $childExitCode = $waitedPid === $childPid && pcntl_wifexited($waitStatus)
        ? pcntl_wexitstatus($waitStatus)
        : 128;

    foreach ($connectionNames as $connectionName) {
        $databaseManager->reconnect($connectionName);
    }

    $rootConnectionsReady = true;
    $rootFixture = DB::table('prepared_fixtures')->find($scopeState->fixture_id)?->name;
} finally {
    if (getmypid() === $rootPid) {
        try {
            if ($databaseManager !== null && ! $rootConnectionsReady) {
                foreach ($connectionNames as $connectionName) {
                    $databaseManager->reconnect($connectionName);
                }
            }
        } finally {
            $generatedClass::tearDownAfterClass();
        }
    }
}

$afterAllProof = $GLOBALS['drove_after_all_proof'];

$passed = $childExitCode === 0
    && $discoveryManifest === $expectedDiscoveryManifest
    && ($result['status'] ?? null) === 'passed'
    && ($result['generated_class'] ?? null) === $generatedClass
    && ($result['generated_method'] ?? null) === $generatedMethod
    && ($result['test_status'] ?? null) === 'success'
    && ($result['assertions'] ?? 0) >= 4
    && ($result['pest_ran'] ?? null) === true
    && ($result['application_request_pids'] ?? null) === [$childPid]
    && ($result['application_object_id'] ?? null) === $applicationObjectId
    && ($result['laravel_boot_pids'] ?? null) === [$rootPid]
    && ($result['execution']['pid'] ?? null) === $childPid
    && ($result['execution']['class'] ?? null) === $generatedClass
    && ($result['execution']['method'] ?? null) === $generatedMethod
    && ($result['execution']['mutations'] ?? null) === ['root', 'prepared', 'child']
    && $scopeState->mutations === ['root', 'prepared']
    && $rootFixture === 'prepared once'
    && $afterAllProof->count === 1
    && $afterAllProof->pid === $rootPid;

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'pest_version' => Pest\version(),
    'local_pest_factory_sha256' => $localFactoryHash,
    'loader' => $loaderFile,
    'generated_class' => $generatedClass,
    'generated_method' => $generatedMethod,
    'discovery_manifest' => $discoveryManifest,
    'laravel_boot_pids' => $GLOBALS['drove_laravel_boot_pids'],
    'before_all' => ['count' => $beforeAllProof->count, 'pid' => $beforeAllProof->pid],
    'after_all' => ['count' => $afterAllProof->count, 'pid' => $afterAllProof->pid],
    'root_scope_after_child' => $scopeState->mutations,
    'child' => $result,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
