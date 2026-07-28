<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\TextUI\Configuration\Builder as PHPUnitConfigurationBuilder;

require __DIR__.'/vendor/autoload.php';

final class InheritedApplicationTestCase extends TestCase
{
    public function bindApplication(Application $app): void
    {
        $this->app = $app;
    }

    public function createApplication()
    {
        throw new RuntimeException('A child attempted to create a fresh Laravel application.');
    }

    public function testPreparedFixture(): void
    {
        $this->assertSame(
            [$this->app->make('drove.root_pid')],
            $GLOBALS['drove_laravel_boot_pids'] ?? [],
        );
        $this->assertSame(
            $this->app->make('drove.application_object_id'),
            spl_object_id($this->app),
        );
        $this->assertDatabaseHas('prepared_fixtures', [
            'id' => $this->app->make('drove.fixture_id'),
            'name' => 'prepared once',
        ]);
    }
}

if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "Drove Phase 0 requires pcntl_fork().\n");
    exit(2);
}

(new PHPUnitConfigurationBuilder())->build(['drove-phase-0']);

/**
 * @return array{duration_ms: float, peak_php_bytes: int, peak_rss_kb: int}
 */
function metrics(int $startedAt): array
{
    $usage = getrusage();

    return [
        'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
        'peak_php_bytes' => memory_get_peak_usage(true),
        'peak_rss_kb' => (int) ($usage['ru_maxrss'] ?? 0),
    ];
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

$runStartedAt = hrtime(true);
$rootPid = getmypid();
$GLOBALS['drove_laravel_boot_pids'] = [];
$bootStartedAt = hrtime(true);
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$bootMetrics = metrics($bootStartedAt);

if ($GLOBALS['drove_laravel_boot_pids'] !== [$rootPid]) {
    throw new RuntimeException('Laravel did not boot exactly once in the root process.');
}

$applicationObjectId = spl_object_id($app);
$app->instance('drove.root_pid', $rootPid);
$app->instance('drove.application_object_id', $applicationObjectId);

set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
});

$redis = $app->make('redis');
$redisPreparedKey = 'drove:phase-0:prepared';
$redisWritesKey = 'drove:phase-0:child-writes';
$disconnectRedis = static function () use ($redis): void {
    foreach ((array) $redis->connections() as $name => $connection) {
        $connection->disconnect();
        $redis->purge($name);
    }
};

$beforeAllProof = (object) ['count' => 0, 'pid' => null];
$beforeAllStartedAt = hrtime(true);
$scopeState = (static function () use ($beforeAllProof, $redis, $redisPreparedKey, $redisWritesKey): object {
    $beforeAllProof->count++;
    $beforeAllProof->pid = getmypid();

    Schema::dropIfExists('prepared_fixtures');
    Schema::create('prepared_fixtures', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    $redisConnection = $redis->connection();
    $redisConnection->del($redisPreparedKey, $redisWritesKey);
    $redisConnection->set($redisPreparedKey, 'prepared once');

    return (object) [
        'fixture_id' => DB::table('prepared_fixtures')->insertGetId(['name' => 'prepared once']),
        'mutations' => ['prepared'],
    ];
})();
$beforeAllMetrics = metrics($beforeAllStartedAt);

if ($beforeAllProof->count !== 1 || $beforeAllProof->pid !== $rootPid) {
    throw new RuntimeException('The prepared scope did not run exactly once in the root process.');
}

$app->instance('drove.fixture_id', $scopeState->fixture_id);

$tests = [
    'first sibling' => 'first',
    'second sibling' => 'second',
];

$databaseManager = $app->make('db');
$connectionNames = array_keys($databaseManager->getConnections());

foreach ($connectionNames as $connectionName) {
    if ($databaseManager->connection($connectionName)->transactionLevel() !== 0) {
        throw new RuntimeException(sprintf('Database connection %s has an open transaction.', $connectionName));
    }

    $databaseManager->disconnect($connectionName);
}

$preparedRedisConnection = $redis->connection();
$redisConnectedBeforeDetach = $preparedRedisConnection->client()->isConnected();
$disconnectRedis();
$redisDetachedBeforeFork = ! $preparedRedisConnection->client()->isConnected()
    && $redis->connections() === [];

if (! $redisConnectedBeforeDetach || ! $redisDetachedBeforeFork) {
    throw new RuntimeException('Redis was not disconnected and purged before forking.');
}

$children = [];

foreach ($tests as $name => $mutation) {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($sockets === false) {
        throw new RuntimeException('Unable to create child result pipe.');
    }

    [$parentSocket, $childSocket] = $sockets;
    $pid = pcntl_fork();

    if ($pid === -1) {
        fclose($parentSocket);
        fclose($childSocket);
        throw new RuntimeException(sprintf('Unable to fork %s.', $name));
    }

    if ($pid === 0) {
        fclose($parentSocket);
        $testStartedAt = hrtime(true);
        $result = [
            'test' => $name,
            'pid' => getmypid(),
            'status' => 'passed',
            'before_all_executions' => $beforeAllProof->count,
            'before_all_pid' => $beforeAllProof->pid,
        ];

        try {
            foreach ($connectionNames as $connectionName) {
                $databaseManager->reconnect($connectionName);
            }

            $childRedisConnection = $redis->connection();
            $result['redis_connection_recreated'] = $childRedisConnection !== $preparedRedisConnection;
            $scopeState->mutations[] = $mutation;
            $redisWritePid = (string) getmypid();
            $childRedisConnection->hset($redisWritesKey, $name, $redisWritePid);
            $fixture = DB::table('prepared_fixtures')->find($scopeState->fixture_id)->name;
            $testCase = new InheritedApplicationTestCase('testPreparedFixture');
            $testCase->bindApplication($app);
            $testCase->run();

            if (! $testCase->status()->isSuccess()) {
                throw new RuntimeException(sprintf(
                    'Inherited Laravel TestCase ended with %s: %s',
                    $testCase->status()->asString(),
                    $testCase->status()->message(),
                ));
            }

            $result['value'] = [
                'fixture' => $fixture,
                'mutations' => $scopeState->mutations,
                'test_case' => $testCase::class,
                'test_status' => $testCase->status()->asString(),
                'assertions' => $testCase->numberOfAssertionsPerformed(),
                'application_object_id' => spl_object_id($app),
                'redis_fixture' => $childRedisConnection->get($redisPreparedKey),
                'redis_write_pid' => $redisWritePid,
            ];
        } catch (Throwable $throwable) {
            $result['status'] = 'failed';
            $result['error'] = $throwable::class.': '.$throwable->getMessage();
            $result['error_at'] = $throwable->getFile().':'.$throwable->getLine();
        } finally {
            foreach ($connectionNames as $connectionName) {
                $databaseManager->disconnect($connectionName);
            }

            $disconnectRedis();
        }

        $result['laravel_boots'] = count($GLOBALS['drove_laravel_boot_pids']);
        $result['laravel_boot_pid'] = $GLOBALS['drove_laravel_boot_pids'][0] ?? null;
        $result['laravel_boot_pids'] = $GLOBALS['drove_laravel_boot_pids'];
        $result += metrics($testStartedAt);
        fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
        fclose($childSocket);

        exit($result['status'] === 'passed' ? 0 : 1);
    }

    fclose($childSocket);
    $children[$pid] = [
        'name' => $name,
        'socket' => $parentSocket,
    ];
}

$exitCodes = [];

// ponytail: results are tiny JSON; read pipes concurrently before adding captured test output.
while (($pid = pcntl_wait($status)) > 0) {
    $exitCodes[$pid] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128;
}

$results = [];

foreach ($children as $pid => $child) {
    $payload = trim((string) stream_get_contents($child['socket']));
    fclose($child['socket']);

    $results[] = $payload === ''
        ? ['test' => $child['name'], 'pid' => $pid, 'status' => 'failed', 'error' => 'No child result.']
        : json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
}

usort($results, static fn (array $left, array $right): int => $left['test'] <=> $right['test']);

foreach ($connectionNames as $connectionName) {
    $databaseManager->reconnect($connectionName);
}

$rootFixture = DB::table('prepared_fixtures')->find($scopeState->fixture_id)->name;
$rootRedisConnection = $redis->connection();
$rootRedisFixture = $rootRedisConnection->get($redisPreparedKey);
$redisChildWrites = $rootRedisConnection->hgetall($redisWritesKey);
ksort($redisChildWrites);
$expectedRedisWrites = [];

foreach ($results as $result) {
    $expectedRedisWrites[$result['test']] = (string) $result['pid'];
}

ksort($expectedRedisWrites);
$redisWritesAreShared = $redisChildWrites === $expectedRedisWrites;
$childProcessesPassed = count($exitCodes) === count($tests)
    && array_all($exitCodes, static fn (int $exitCode): bool => $exitCode === 0);
$expectedMutations = [
    'first sibling' => ['prepared', 'first'],
    'second sibling' => ['prepared', 'second'],
];
$resultsPassed = count($results) === count($tests)
    && array_all($results, static fn (array $result): bool => $result['status'] === 'passed'
        && $result['laravel_boots'] === 1
        && $result['laravel_boot_pid'] === $rootPid
        && $result['laravel_boot_pids'] === [$rootPid]
        && $result['before_all_executions'] === 1
        && $result['before_all_pid'] === $rootPid
        && $result['redis_connection_recreated'] === true
        && $result['value']['fixture'] === 'prepared once'
        && $result['value']['test_case'] === InheritedApplicationTestCase::class
        && $result['value']['test_status'] === 'success'
        && $result['value']['assertions'] >= 3
        && $result['value']['application_object_id'] === $applicationObjectId
        && $result['value']['mutations'] === $expectedMutations[$result['test']]
        && $result['value']['redis_fixture'] === 'prepared once'
        && $result['value']['redis_write_pid'] === (string) $result['pid']);
$rootStateStayedPrepared = $scopeState->mutations === ['prepared'];

$passed = $childProcessesPassed
    && $resultsPassed
    && $rootStateStayedPrepared
    && $rootFixture === 'prepared once'
    && $rootRedisFixture === 'prepared once'
    && $redisWritesAreShared;

$summary = [
    'status' => $passed ? 'passed' : 'failed',
    'laravel_bootstraps' => count($GLOBALS['drove_laravel_boot_pids']),
    'bootstrap_pid' => $GLOBALS['drove_laravel_boot_pids'][0] ?? null,
    'bootstrap_pids' => $GLOBALS['drove_laravel_boot_pids'],
    'before_all_executions' => $beforeAllProof->count,
    'before_all_pid' => $beforeAllProof->pid,
    'root_pid' => $rootPid,
    'root_state_after_children' => $scopeState->mutations,
    'redis' => [
        'connected_before_detach' => $redisConnectedBeforeDetach,
        'detached_and_purged_before_fork' => $redisDetachedBeforeFork,
        'prepared_value' => $rootRedisFixture,
        'child_writes_are_shared' => $redisWritesAreShared,
        'child_writes' => $redisChildWrites,
    ],
    'boot' => $bootMetrics,
    'before_all' => $beforeAllMetrics,
    'run' => metrics($runStartedAt),
    'children' => $results,
    'extensions' => [
        'pcntl' => extension_loaded('pcntl'),
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'redis' => extension_loaded('redis'),
    ],
];

$disconnectRedis();

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

exit($passed ? 0 : 1);
