<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Drove\Kernel\DroverScheduler;
use Drove\Laravel\DroveLaravelServiceProvider;
use Drove\Laravel\PackageApplicationRuntime;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Illuminate\Foundation\Application;
use Livewire\LivewireServiceProvider;

use function Drove\Native\environment;

const LIVEWIRE_RUNTIME_AUDIT_COMMIT = '9c1450739d30c9b0b223ad6512be2a33f8f62f96';

function runtimeFaultAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function runtimeFaultCopyTree(string $source, string $target): void
{
    runtimeFaultAssert(is_dir($source), 'The runtime-audit application fixture is missing.');
    runtimeFaultAssert(is_dir($target) || mkdir($target, 0700, true), 'Could not create the runtime-audit application copy.');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = $target.'/'.$relative;

        if ($entry->isDir()) {
            runtimeFaultAssert(is_dir($destination) || mkdir($destination, 0700, true), 'Could not copy a runtime-audit directory.');

            continue;
        }

        runtimeFaultAssert(copy($entry->getPathname(), $destination), 'Could not copy a runtime-audit fixture file.');
    }
}

function runtimeFaultRemoveTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($path);
}

/** @param array<string, mixed> $entry */
function runtimeFaultRecord(string $file, array $entry): void
{
    $record = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    runtimeFaultAssert(
        file_put_contents($file, $record, FILE_APPEND | LOCK_EX) === strlen($record),
        'Could not record Livewire runtime-audit fault evidence.',
    );
}

/** @return list<array<string, mixed>> */
function runtimeFaultRows(string $file): array
{
    $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

    if (! is_array($lines)) {
        return [];
    }

    return array_map(
        static fn (string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR),
        $lines,
    );
}

/** @return list<string> */
function runtimeFaultMessages(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $messages = [];

    foreach ($value as $key => $child) {
        if ($key === 'message' && is_string($child)) {
            $messages[] = $child;
        }

        array_push($messages, ...runtimeFaultMessages($child));
    }

    return $messages;
}

/** @param array<string, mixed> $task */
function runtimeFaultAudit(array $task, string $file): void
{
    $taskId = $task['id'] ?? null;
    $taskKind = $task['kind'] ?? null;
    $taskScopeId = $task['scope_id'] ?? null;
    $taskScopes = $task['scopes'] ?? null;
    runtimeFaultAssert(
        is_string($taskId)
            && in_array($taskKind, ['root', 'scope', 'test'], true)
            && is_string($taskScopeId)
            && is_array($taskScopes)
            && array_is_list($taskScopes),
        'DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_TASK_INVALID',
    );
    $classes = array_values(array_filter(
        get_declared_classes(),
        static fn (string $class): bool => str_starts_with($class, 'Pest\\')
            || str_starts_with($class, 'PHPUnit\\')
            || str_starts_with($class, 'Orchestra\\Testbench\\')
            || str_starts_with($class, 'Drove\\Bridge\\')
            || str_starts_with($class, 'Drove\\Pest\\')
            || str_starts_with($class, 'Drove\\Laravel\\TestbenchBridge'),
    ));
    $files = array_values(array_filter(
        array_map(static fn (string $path): string => str_replace('\\', '/', $path), get_included_files()),
        static fn (string $path): bool => preg_match('~/(?:pestphp|phpunit|orchestra/testbench[^/]*)/~i', $path) === 1
            || str_contains($path, '/src/Drove/Bridge/')
            || str_contains($path, '/src/Drove/Pest/')
            || str_ends_with($path, '/src/TestbenchBridge.php'),
    ));
    $pid = getmypid();
    runtimeFaultAssert(is_int($pid), 'Could not resolve a Livewire runtime-audit PID.');
    runtimeFaultRecord($file, [
        'task_id' => $taskId,
        'task_kind' => $taskKind,
        'task_scope_id' => $taskScopeId,
        'task_scopes' => $taskScopes,
        'classes' => $classes,
        'files' => $files,
        'pid' => $pid,
    ]);

    if ($classes !== []) {
        throw new RuntimeException('DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FORBIDDEN_CLASS kind='.$taskKind);
    }

    if ($files !== []) {
        throw new RuntimeException('DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FORBIDDEN_FILE kind='.$taskKind);
    }
}

$vendor = getenv('DROVE_NATIVE_VENDOR');

if (! is_string($vendor) || $vendor === '' || ! is_file($vendor.'/autoload.php')) {
    fwrite(STDERR, "DROVE_NATIVE_VENDOR must identify the clean installed vendor.\n");
    exit(1);
}

require $vendor.'/autoload.php';

$scenario = getenv('DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FAULT') ?: '';
$token = bin2hex(random_bytes(6));
$stage = sys_get_temp_dir().'/drove-native-livewire-runtime-audit-'.$token;
$applicationPath = $stage.'/application';
$storagePath = $stage.'/storage';
$auditFile = $stage.'/audit.jsonl';
$phaseFile = $stage.'/phases.jsonl';
$fixtureFile = $stage.'/source/tests/RuntimeAuditTest.php';
$forbiddenFile = $stage.'/source/src/Drove/Bridge/DeferredRuntime.php';
$summary = null;

try {
    runtimeFaultAssert(
        in_array($scenario, ['body', 'defer', 'after-all'], true),
        'Unknown Livewire runtime-audit fault scenario.',
    );
    runtimeFaultAssert(mkdir($stage, 0700), 'Could not create the Livewire runtime-audit stage.');
    runtimeFaultCopyTree(__DIR__.'/application', $applicationPath);
    runtimeFaultAssert(mkdir($storagePath.'/framework/views', 0700, true), 'Could not create isolated runtime-audit storage.');
    runtimeFaultAssert(mkdir(dirname($forbiddenFile), 0700, true), 'Could not create the forbidden runtime fixture directory.');
    runtimeFaultAssert(mkdir(dirname($fixtureFile), 0700, true), 'Could not create the runtime-audit test fixture directory.');
    $forbiddenSource = "<?php\n\ndeclare(strict_types=1);\n";
    runtimeFaultAssert(
        file_put_contents($forbiddenFile, $forbiddenSource) === strlen($forbiddenSource),
        'Could not write the forbidden runtime fixture.',
    );

    foreach ([
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:Hupx3yAySikrM2/edkZQNQHslgDWYfiBfCuSThJ5SK8=',
        'DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FORBIDDEN_FILE' => $forbiddenFile,
        'DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_PHASE_FILE' => $phaseFile,
        'DROVE_NATIVE_PACKAGE_STORAGE' => $storagePath,
    ] as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    runtimeFaultAssert(
        InstalledVersions::getReference('livewire/livewire') === LIVEWIRE_RUNTIME_AUDIT_COMMIT,
        'The installed Livewire runtime-audit reference drifted.',
    );
    $externalPackages = array_values(array_filter(
        InstalledVersions::getInstalledPackages(),
        static fn (string $package): bool => is_string(InstalledVersions::getInstallPath($package))
            && preg_match('~\A(?:pestphp/|phpunit/|orchestra/testbench)~i', $package) === 1,
    ));
    runtimeFaultAssert($externalPackages === [], 'The runtime-audit proof installed an external test runtime.');
    $fixtureSource = <<<'PHP'
<?php

declare(strict_types=1);

use function Drove\Native\afterAll;
use function Drove\Native\beforeAll;
use function Drove\Native\expect;
use function Drove\Native\test;

$scenario = getenv('DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FAULT');
$phaseFile = getenv('DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_PHASE_FILE');
$forbiddenFile = getenv('DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FORBIDDEN_FILE');
runtimeFaultAssert(
    is_string($scenario)
        && is_string($phaseFile)
        && is_string($forbiddenFile),
    'Runtime-audit fixture environment is unavailable.',
);

beforeAll(static function () use ($phaseFile): void {
    $database = app('db')->connection('testbench');
    $rows = $database->table('native_livewire_runtime_audit')->orderBy('id')->pluck('value')->all();
    runtimeFaultAssert($rows === ['prepared'], 'Runtime-audit beforeAll did not inherit prepared state.');
    $database->table('native_livewire_runtime_audit')->insert(['id' => 2, 'value' => 'scope']);
    runtimeFaultRecord($phaseFile, ['phase' => 'before-all', 'pid' => getmypid(), 'rows' => $rows]);
});

test('audits the complete native lifecycle', function () use (
    $forbiddenFile,
    $phaseFile,
    $scenario,
): void {
    $database = $this->app()->make('db')->connection('testbench');
    $rows = $database->table('native_livewire_runtime_audit')->orderBy('id')->pluck('value')->all();
    expect($rows)->toBe(['prepared', 'scope']);
    $database->table('native_livewire_runtime_audit')->insert(['id' => 3, 'value' => 'test']);

    if ($scenario === 'defer') {
        $this->defer(function () use ($database, $forbiddenFile, $phaseFile): void {
            $rows = $database->table('native_livewire_runtime_audit')->orderBy('id')->pluck('value')->all();
            runtimeFaultAssert($rows === ['prepared', 'scope', 'test'], 'Runtime-audit defer lost test state.');
            runtimeFaultRecord($phaseFile, ['phase' => 'defer', 'pid' => getmypid(), 'rows' => $rows]);
            require $forbiddenFile;
        });

        return;
    }

    runtimeFaultRecord($phaseFile, ['phase' => 'body', 'pid' => getmypid(), 'rows' => ['prepared', 'scope', 'test']]);

    if ($scenario === 'body') {
        eval('namespace Drove\\Bridge\\Fault; final class BodyRuntime {}');
    }
});

afterAll(static function () use ($phaseFile, $scenario): void {
    $rows = app('db')->connection('testbench')
        ->table('native_livewire_runtime_audit')
        ->orderBy('id')
        ->pluck('value')
        ->all();
    runtimeFaultAssert($rows === ['prepared', 'scope'], 'Runtime-audit afterAll observed test-local state.');
    runtimeFaultRecord($phaseFile, ['phase' => 'after-all', 'pid' => getmypid(), 'rows' => $rows]);

    if ($scenario === 'after-all') {
        eval('namespace Drove\\Bridge\\Fault; final class AfterAllRuntime {}');
    }
});
PHP;
    runtimeFaultAssert(
        file_put_contents($fixtureFile, $fixtureSource) === strlen($fixtureSource),
        'Could not write the runtime-audit test fixture.',
    );

    $registry = Declarations::capture(
        static function () use (
            $applicationPath,
            $fixtureFile,
        ): void {
            environment(
                'laravel-livewire-runtime-audit-fault',
                static fn () => PackageApplicationRuntime::boot(
                    $applicationPath,
                    new InMemorySqliteDatabaseStateAdapter('testbench', true),
                    [DroveLaravelServiceProvider::class, LivewireServiceProvider::class],
                    static function (Application $application): void {
                        $application->make('config')->set('database.default', 'testbench');
                        $database = $application->make('db')->connection('testbench');
                        $database->statement(
                            'CREATE TABLE native_livewire_runtime_audit ('
                            .'id INTEGER PRIMARY KEY, value TEXT NOT NULL)',
                        );
                        $database->table('native_livewire_runtime_audit')->insert([
                            'id' => 1,
                            'value' => 'prepared',
                        ]);
                    },
                ),
            );
            require $fixtureFile;
        },
        $stage.'/source',
        'Pinned Livewire runtime-audit lifecycle fault',
    );
    $scheduler = new DroverScheduler('native-livewire-runtime-audit-'.$token, 2);
    $run = (new Runner(
        $scheduler,
        static function (array $task) use ($auditFile): void {
            runtimeFaultAudit($task, $auditFile);
        },
    ))->run($registry);
    $expectedKind = $scenario === 'after-all' ? 'scope' : 'test';
    $expectedCategory = $scenario === 'defer' ? 'FILE' : 'CLASS';
    $expectedDiagnostic = 'DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FORBIDDEN_'
        .$expectedCategory.' kind='.$expectedKind;
    $auditRows = runtimeFaultRows($auditFile);
    $phaseRows = runtimeFaultRows($phaseFile);
    $forbiddenRows = array_values(array_filter(
        $auditRows,
        static fn (array $row): bool => ($row['classes'] ?? []) !== [] || ($row['files'] ?? []) !== [],
    ));
    $expectedPhase = $scenario;
    $matchingPhases = array_values(array_filter(
        $phaseRows,
        static fn (array $row): bool => ($row['phase'] ?? null) === $expectedPhase,
    ));
    $taskKinds = array_column($auditRows, 'task_kind');
    sort($taskKinds, SORT_STRING);
    runtimeFaultAssert(($run['status'] ?? null) === 'failed' && ($run['exit_code'] ?? null) === 1, 'Runtime audit did not fail the Livewire run.');
    runtimeFaultAssert(in_array($expectedDiagnostic, runtimeFaultMessages($run), true), 'Runtime-audit failure diagnostic drifted.');
    runtimeFaultAssert(count($auditRows) === 3 && $taskKinds === ['root', 'scope', 'test'], 'Runtime audit did not cover the test, stateful scope, and root.');
    runtimeFaultAssert(count($forbiddenRows) === 1, 'Runtime audit did not isolate exactly one forbidden task.');
    runtimeFaultAssert(
        ($forbiddenRows[0]['task_kind'] ?? null) === $expectedKind,
        'Runtime audit attributed the forbidden runtime to the wrong scheduler task kind.',
    );
    runtimeFaultAssert(count($matchingPhases) === 1, 'The expected lifecycle fault phase did not execute exactly once.');
    runtimeFaultAssert(
        ($matchingPhases[0]['pid'] ?? null) === ($forbiddenRows[0]['pid'] ?? null),
        'Runtime audit did not bind the fault to the lifecycle executor PID.',
    );

    $summary = [
        'ok' => true,
        'scenario' => $scenario,
        'lifecycle_phase' => $expectedPhase,
        'diagnostic' => $expectedDiagnostic,
        'task_kind' => $expectedKind,
        'audit_rows' => count($auditRows),
        'audit_task_kinds' => $taskKinds,
        'fault_pid_bound_to_phase' => true,
        'state_rows' => $matchingPhases[0]['rows'] ?? null,
        'run_status' => $run['status'],
        'external_test_runtime_packages' => count($externalPackages),
    ];
} catch (Throwable $throwable) {
    fwrite(STDERR, 'DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FAULT_FAILED '.$throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
} finally {
    runtimeFaultRemoveTree($stage);
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
