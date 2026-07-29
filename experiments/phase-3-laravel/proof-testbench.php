<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require __DIR__.'/vendor/autoload.php';

$configuredProcesses = getenv('DROVE_PROOF_PROCESSES');
$processes = filter_var(
    $configuredProcesses === false ? '2' : $configuredProcesses,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 8]],
);

if (! is_int($processes)) {
    throw new RuntimeException('DROVE_PROOF_PROCESSES must be between 1 and 8.');
}

$proofDirectory = __DIR__.'/storage/framework/drove-proof-testbench';
$guardDirectory = __DIR__.'/storage/framework/drove-proof-testbench-guard';

$resetDirectory = static function (string $directory): void {
    if (! is_dir($directory)
        && ! mkdir($directory, 0777, true)
        && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the Testbench proof directory.');
    }

    foreach (glob($directory.'/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
};

$resetDirectory($proofDirectory);
$resetDirectory($guardDirectory);

$command = [
    PHP_BINARY,
    __DIR__.'/vendor/bin/drove',
    '--configuration=phpunit.testbench.xml',
    '--parallel',
    '--processes='.$processes,
    'tests/Testbench/LivewirePreparedStateTest.php',
];
$environment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'testbench',
    'DB_DATABASE' => ':memory:',
    'DROVE_LARAVEL_DB_CONNECTION' => 'testbench',
    'DROVE_LARAVEL_RUNTIME' => 'testbench',
    'DROVE_LARAVEL_STATE' => 'sqlite-memory',
];
$guard = new Process($command, __DIR__, [
    ...$environment,
    'DROVE_TESTBENCH_PROOF_DIRECTORY' => $guardDirectory,
]);
$guard->setTimeout(180);
$guardExitCode = $guard->run();
$guardOutput = $guard->getOutput().$guard->getErrorOutput();
$guardPassed = $guardExitCode === 1
    && str_contains(
        $guardOutput,
        'requires prepared_schema=true before using RefreshDatabase',
    )
    && ! is_file($guardDirectory.'/boot-pids.log')
    && ! is_file($guardDirectory.'/scope.json')
    && ! is_file($guardDirectory.'/after.json')
    && ! is_file($guardDirectory.'/alpha.json')
    && ! is_file($guardDirectory.'/beta.json');

$process = new Process($command, __DIR__, [
    ...$environment,
    'DROVE_LARAVEL_SQLITE_PREPARED_SCHEMA' => 'true',
    'DROVE_TESTBENCH_PROOF_DIRECTORY' => $proofDirectory,
]);
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
$rootPid = $bootPids[0] ?? null;
$testPids = array_values(array_filter([
    $alpha['pid'] ?? null,
    $beta['pid'] ?? null,
], is_int(...)));
$applicationIds = [
    $scope['application_id'] ?? null,
    $scope['runtime_application_id'] ?? null,
    $after['application_id'] ?? null,
    $after['runtime_application_id'] ?? null,
    $alpha['application_id'] ?? null,
    $alpha['runtime_application_id'] ?? null,
    $beta['application_id'] ?? null,
    $beta['runtime_application_id'] ?? null,
];
$adapters = [
    $scope['adapter'] ?? null,
    $after['adapter'] ?? null,
    $alpha['adapter'] ?? null,
    $beta['adapter'] ?? null,
];
$passed = $exitCode === 0
    && $guardPassed
    && ! file_exists(__DIR__.'/testbench.yaml')
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
    && ($alpha['rows'] ?? null) === ['alpha', 'prepared']
    && ($beta['rows'] ?? null) === ['beta', 'prepared']
    && count(array_unique($testPids)) === 2
    && array_all(
        $testPids,
        static fn (int $pid): bool => $pid !== $rootPid
            && $pid !== ($scope['pid'] ?? null),
    )
    && count(array_unique($applicationIds)) === 1
    && array_unique($adapters) === ['sqlite-memory'];

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'exit_code' => $exitCode,
    'processes' => $processes,
    'runtime_mode' => 'testbench',
    'prepared_schema_guard' => [
        'status' => $guardPassed ? 'passed' : 'failed',
        'exit_code' => $guardExitCode,
        'provider_booted' => is_file($guardDirectory.'/boot-pids.log'),
        'lifecycle_artifacts_created' => array_values(array_filter(
            ['scope', 'after', 'alpha', 'beta'],
            static fn (string $name): bool => is_file(
                $guardDirectory.'/'.$name.'.json',
            ),
        )),
        'stdout' => $guard->getOutput(),
        'stderr' => $guard->getErrorOutput(),
    ],
    'boot_pids' => $bootPids,
    'scope' => $scope,
    'after' => $after,
    'tests' => [
        'alpha' => $alpha,
        'beta' => $beta,
    ],
    'stdout' => $process->getOutput(),
    'stderr' => $process->getErrorOutput(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
