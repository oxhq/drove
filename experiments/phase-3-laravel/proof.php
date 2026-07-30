<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require __DIR__.'/vendor/autoload.php';

$proofDirectory = __DIR__.'/storage/framework/drove-proof';
$copyDirectory = __DIR__.'/storage/framework/drove';
$database = __DIR__.'/database/database.sqlite';

foreach ([$proofDirectory, $copyDirectory, dirname($database)] as $directory) {
    if (! is_dir($directory)
        && ! mkdir($directory, 0777, true)
        && ! is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create %s.', $directory));
    }
}

foreach (glob($proofDirectory.'/*') ?: [] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}

foreach (glob($copyDirectory.'/drove-*') ?: [] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}

if (is_file($database)) {
    unlink($database);
}

touch($database);
$pdo = new PDO('sqlite:'.$database);
$journalMode = strtolower((string) $pdo->query('PRAGMA journal_mode=DELETE')->fetchColumn());
$pdo = null;

$runtimeGuards = new Process([
    PHP_BINARY,
    'proof-runtime-guards.php',
], __DIR__);
$runtimeGuards->setTimeout(120);
$runtimeGuardsExitCode = $runtimeGuards->run();

$adapterFinally = new Process([
    PHP_BINARY,
    'proof-adapter-finally.php',
], __DIR__, [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database,
    'DROVE_LARAVEL' => false,
    'DROVE_LARAVEL_DB_CONNECTION' => 'sqlite',
    'DROVE_LARAVEL_SQLITE_WORKSPACE' => $copyDirectory,
    'DROVE_LARAVEL_STATE' => 'sqlite-copy',
]);
$adapterFinally->setTimeout(60);
$adapterFinallyExitCode = $adapterFinally->run();

$process = new Process([
    PHP_BINARY,
    'artisan',
    'drove',
    '--',
    '--pest',
    'tests/Feature/PreparedApplicationTest.php',
    '--parallel',
    '--processes=2',
], __DIR__);
$process->setTimeout(180);
$exitCode = $process->run();

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
$copyArtifacts = glob($copyDirectory.'/drove-*') ?: [];
$rootTables = [];

try {
    $pdo = new PDO('sqlite:'.$database);
    $rootTables = $pdo
        ->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
} finally {
    $pdo = null;
}

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
    && $runtimeGuardsExitCode === 0
    && $adapterFinallyExitCode === 0
    && $journalMode === 'delete'
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
    && ($scope['rows'] ?? null) === ['prepared']
    && ($after['rows'] ?? null) === ['prepared']
    && ($alpha['rows'] ?? null) === ['prepared', 'alpha']
    && ($beta['rows'] ?? null) === ['prepared', 'beta']
    && count(array_unique($testPids)) === 2
    && array_all($testPids, static fn (int $pid): bool => $pid !== $rootPid)
    && count(array_unique($applicationIds)) === 1
    && $rootTables === []
    && $copyArtifacts === [];

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'exit_code' => $exitCode,
    'journal_mode' => $journalMode,
    'boot_pids' => $bootPids,
    'scope' => $scope,
    'after' => $after,
    'tests' => [
        'alpha' => $alpha,
        'beta' => $beta,
    ],
    'root_tables' => $rootTables,
    'copy_artifacts' => $copyArtifacts,
    'guards' => [
        'runtime' => [
            'exit_code' => $runtimeGuardsExitCode,
            'stdout' => $runtimeGuards->getOutput(),
            'stderr' => $runtimeGuards->getErrorOutput(),
        ],
        'adapter_finally' => [
            'exit_code' => $adapterFinallyExitCode,
            'stdout' => $adapterFinally->getOutput(),
            'stderr' => $adapterFinally->getErrorOutput(),
        ],
    ],
    'stdout' => $process->getOutput(),
    'stderr' => $process->getErrorOutput(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
