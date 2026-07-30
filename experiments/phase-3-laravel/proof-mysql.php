<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require __DIR__.'/vendor/autoload.php';

function droveRequiredMysqlEnvironment(string $name, bool $allowEmpty = false): string
{
    $value = getenv($name);

    if (! is_string($value) || (! $allowEmpty && $value === '')) {
        throw new RuntimeException(sprintf('%s must be supplied for the MySQL proof.', $name));
    }

    return $value;
}

function droveMysqlProofPdo(): PDO
{
    $host = droveRequiredMysqlEnvironment('DB_HOST');
    $port = filter_var(
        droveRequiredMysqlEnvironment('DB_PORT'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65_535]],
    );

    if (! is_int($port)) {
        throw new RuntimeException('DB_PORT must be a valid TCP port.');
    }

    $database = droveRequiredMysqlEnvironment('DB_DATABASE');
    $username = droveRequiredMysqlEnvironment('DB_USERNAME');
    $password = droveRequiredMysqlEnvironment('DB_PASSWORD', allowEmpty: true);
    $deadline = microtime(true) + 45;
    $failure = null;

    do {
        try {
            return new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $host,
                    $port,
                    $database,
                ),
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        } catch (PDOException $exception) {
            $failure = $exception;
            usleep(250_000);
        }
    } while (microtime(true) < $deadline);

    throw new RuntimeException(
        'The MySQL proof database did not become ready.',
        previous: $failure,
    );
}

function droveMysqlProofTableExists(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'drove_transaction_proof'
SQL,
    );
    $statement->execute();

    return (int) $statement->fetchColumn() !== 0;
}

$proofDirectory = __DIR__.'/storage/framework/drove-proof-mysql';

if (! is_dir($proofDirectory)
    && ! mkdir($proofDirectory, 0777, true)
    && ! is_dir($proofDirectory)) {
    throw new RuntimeException(sprintf('Unable to create %s.', $proofDirectory));
}

foreach (glob($proofDirectory.'/*') ?: [] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}

$pdo = droveMysqlProofPdo();
$pdo->exec('DROP TABLE IF EXISTS `drove_transaction_proof`');
$adapterFinally = new Process([
    PHP_BINARY,
    'proof-adapter-finally.php',
], __DIR__, [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'DB_CONNECTION' => 'mysql',
    'DROVE_LARAVEL' => false,
    'DROVE_LARAVEL_DB_CONNECTION' => 'mysql',
    'DROVE_LARAVEL_STATE' => 'transaction',
]);
$adapterFinally->setTimeout(60);
$adapterFinallyExitCode = $adapterFinally->run();
$traitGuard = new Process([
    PHP_BINARY,
    'proof-transaction-traits.php',
], __DIR__, [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'DB_CONNECTION' => 'mysql',
    'DROVE_LARAVEL' => false,
    'DROVE_LARAVEL_DB_CONNECTION' => 'mysql',
    'DROVE_LARAVEL_STATE' => 'transaction',
]);
$traitGuard->setTimeout(60);
$traitGuardExitCode = $traitGuard->run();
$process = new Process([
    PHP_BINARY,
    'artisan',
    'drove',
    '--',
    '--pest',
    '--configuration=phpunit.mysql.xml',
    'tests/Feature/TransactionalDatabaseTest.php',
    '--parallel',
    '--processes=2',
], __DIR__);
$process->setTimeout(180);

try {
    $exitCode = $process->run();
    $tablePresentAfterDrove = droveMysqlProofTableExists($pdo);
} finally {
    $pdo->exec('DROP TABLE IF EXISTS `drove_transaction_proof`');
}

$tablePresentAfterCleanup = droveMysqlProofTableExists($pdo);

$readJson = static function (string $name) use ($proofDirectory): ?array {
    $path = $proofDirectory.'/'.$name.'.json';

    if (! is_file($path)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
};

$bootPids = is_file($proofDirectory.'/boot-pids.log')
    ? array_values(array_filter(array_map(
        static fn (string $pid): int => (int) trim($pid),
        file($proofDirectory.'/boot-pids.log', FILE_IGNORE_NEW_LINES) ?: [],
    )))
    : [];
$scope = $readJson('scope');
$after = $readJson('after');
$alpha = $readJson('alpha');
$beta = $readJson('beta');
$pdo = null;

$rootPid = $bootPids[0] ?? null;
$testPids = array_values(array_filter([
    $alpha['pid'] ?? null,
    $beta['pid'] ?? null,
], is_int(...)));
$applicationIds = [
    $scope['application_id'] ?? null,
    $after['application_id'] ?? null,
    $alpha['application_id'] ?? null,
    $beta['application_id'] ?? null,
];
$passed = $exitCode === 0
    && $adapterFinallyExitCode === 0
    && $traitGuardExitCode === 0
    && count($bootPids) === 1
    && is_int($rootPid)
    && is_array($scope)
    && is_array($after)
    && is_array($alpha)
    && is_array($beta)
    && ($scope['pid'] ?? null) !== $rootPid
    && ($scope['root_pid'] ?? null) === $rootPid
    && ($after['pid'] ?? null) === ($scope['pid'] ?? null)
    && ($after['root_pid'] ?? null) === $rootPid
    && ($scope['transaction_level'] ?? null) === 0
    && ($after['transaction_level'] ?? null) === 0
    && ($alpha['transaction_level'] ?? null) === 1
    && ($beta['transaction_level'] ?? null) === 1
    && ($scope['rows'] ?? null) === ['prepared']
    && ($after['rows'] ?? null) === ['prepared']
    && ($alpha['rows'] ?? null) === ['prepared', 'alpha']
    && ($beta['rows'] ?? null) === ['prepared', 'beta']
    && count(array_unique($testPids)) === 2
    && array_all($testPids, static fn (int $pid): bool => $pid !== $rootPid)
    && count(array_unique($applicationIds)) === 1
    && ! $tablePresentAfterDrove
    && ! $tablePresentAfterCleanup;

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'exit_code' => $exitCode,
    'boot_pids' => $bootPids,
    'scope' => $scope,
    'after' => $after,
    'tests' => [
        'alpha' => $alpha,
        'beta' => $beta,
    ],
    'table_present_after_drove' => $tablePresentAfterDrove,
    'table_present_after_cleanup' => $tablePresentAfterCleanup,
    'adapter_finally_guard' => [
        'exit_code' => $adapterFinallyExitCode,
        'stdout' => $adapterFinally->getOutput(),
        'stderr' => $adapterFinally->getErrorOutput(),
    ],
    'transaction_trait_guard' => [
        'exit_code' => $traitGuardExitCode,
        'stdout' => $traitGuard->getOutput(),
        'stderr' => $traitGuard->getErrorOutput(),
    ],
    'stdout' => $process->getOutput(),
    'stderr' => $process->getErrorOutput(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
