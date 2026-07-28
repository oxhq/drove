<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/vendor/autoload.php';

if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "Drove Phase 0 requires pcntl_fork().\n");
    exit(2);
}

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
$bootProof = (object) ['count' => 0, 'pid' => null];
$bootStartedAt = hrtime(true);
$app = require __DIR__.'/bootstrap/app.php';
$app->booted(static function () use ($bootProof): void {
    $bootProof->count++;
    $bootProof->pid = getmypid();
});
$app->make(Kernel::class)->bootstrap();
$bootMetrics = metrics($bootStartedAt);

if ($bootProof->count !== 1 || $bootProof->pid !== $rootPid) {
    throw new RuntimeException('Laravel did not boot exactly once in the root process.');
}

$beforeAllProof = (object) ['count' => 0, 'pid' => null];
$beforeAllStartedAt = hrtime(true);
$scopeState = (static function () use ($beforeAllProof): object {
    $beforeAllProof->count++;
    $beforeAllProof->pid = getmypid();

    Schema::dropIfExists('prepared_fixtures');
    Schema::create('prepared_fixtures', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    return (object) [
        'fixture_id' => DB::table('prepared_fixtures')->insertGetId(['name' => 'prepared once']),
        'mutations' => ['prepared'],
    ];
})();
$beforeAllMetrics = metrics($beforeAllStartedAt);

if ($beforeAllProof->count !== 1 || $beforeAllProof->pid !== $rootPid) {
    throw new RuntimeException('The prepared scope did not run exactly once in the root process.');
}

$tests = [
    'first sibling' => static function (object $state): array {
        $state->mutations[] = 'first';

        return [
            'fixture' => DB::table('prepared_fixtures')->find($state->fixture_id)->name,
            'mutations' => $state->mutations,
        ];
    },
    'second sibling' => static function (object $state): array {
        $state->mutations[] = 'second';

        return [
            'fixture' => DB::table('prepared_fixtures')->find($state->fixture_id)->name,
            'mutations' => $state->mutations,
        ];
    },
];

$connectionNames = array_keys($app->make('db')->getConnections());

foreach ($connectionNames as $connectionName) {
    if (DB::connection($connectionName)->transactionLevel() !== 0) {
        throw new RuntimeException(sprintf('Database connection %s has an open transaction.', $connectionName));
    }

    DB::disconnect($connectionName);
}

$children = [];

foreach ($tests as $name => $test) {
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
            'laravel_boots' => $bootProof->count,
            'laravel_boot_pid' => $bootProof->pid,
            'before_all_executions' => $beforeAllProof->count,
            'before_all_pid' => $beforeAllProof->pid,
        ];

        try {
            foreach ($connectionNames as $connectionName) {
                DB::reconnect($connectionName);
            }

            $result['value'] = $test($scopeState);
        } catch (Throwable $throwable) {
            $result['status'] = 'failed';
            $result['error'] = $throwable::class.': '.$throwable->getMessage();
        } finally {
            foreach ($connectionNames as $connectionName) {
                DB::disconnect($connectionName);
            }
        }

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
    DB::reconnect($connectionName);
}

$rootFixture = DB::table('prepared_fixtures')->find($scopeState->fixture_id)->name;
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
        && $result['before_all_executions'] === 1
        && $result['before_all_pid'] === $rootPid
        && $result['value']['fixture'] === 'prepared once'
        && $result['value']['mutations'] === $expectedMutations[$result['test']]);
$rootStateStayedPrepared = $scopeState->mutations === ['prepared'];

$passed = $childProcessesPassed
    && $resultsPassed
    && $rootStateStayedPrepared
    && $rootFixture === 'prepared once';

$summary = [
    'status' => $passed ? 'passed' : 'failed',
    'laravel_bootstraps' => $bootProof->count,
    'bootstrap_pid' => $bootProof->pid,
    'before_all_executions' => $beforeAllProof->count,
    'before_all_pid' => $beforeAllProof->pid,
    'root_pid' => $rootPid,
    'root_state_after_children' => $scopeState->mutations,
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

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

exit($passed ? 0 : 1);
