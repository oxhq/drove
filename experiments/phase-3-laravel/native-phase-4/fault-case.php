<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Drove\Kernel\DroverScheduler;
use Drove\Laravel\DroveLaravelServiceProvider;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Laravel\State\SqliteCopyDatabaseStateAdapter;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Illuminate\Foundation\Application;

use function Drove\Laravel\laravel;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/support.php';

try {
    $provider = getenv('DROVE_LARAVEL_PROVIDER') ?: '';
    $fault = getenv('DROVE_LARAVEL_FAULT') ?: '';

    if (! in_array($provider, ['sqlite-memory', 'sqlite-copy'], true)
        || ! in_array($fault, [
            'prepare',
            'enter',
            'leave',
            'cleanup',
        ], true)) {
        throw new RuntimeException('Unknown native Laravel fault case.');
    }

    $identity = nativePhaseFourIdentity();
    $gateDirectory = __DIR__;
    $generatedDirectory = $gateDirectory.'/fault-generated';
    $stateDirectory = $gateDirectory.'/fault-state';
    $copyWorkspace = $stateDirectory.'/copies';
    $sourceDatabase = $stateDirectory.'/source.sqlite';
    $faultLog = $stateDirectory.'/fault.log';
    $proofDirectoryName = 'drove-native-phase-four-fault-'
        .$provider.'-'.str_replace('_', '-', $fault);
    $providerBootDirectory = dirname(__DIR__).'/storage/framework/'.$proofDirectoryName;
    $summary = null;

    nativePhaseFourRemove($generatedDirectory);
    nativePhaseFourRemove($stateDirectory);
    nativePhaseFourRemove($providerBootDirectory);

    try {
        if (! mkdir($generatedDirectory, 0777, true)
            || ! mkdir($copyWorkspace, 0777, true)
            || ! touch($sourceDatabase)) {
            throw new RuntimeException('Could not create the native Laravel fault workspace.');
        }

        nativePhaseFourWrite(
            $generatedDirectory.'/FaultTest.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use function Drove\Native\expect;
use function Drove\Native\test;

test('fault sentinel', function (): void {
    $database = $this->app()->make('db')->connection('sqlite');
    expect($database->table('native_phase_four_fault')->count())->toBe(1);
    $database->table('native_phase_four_fault')->insert([
        'id' => 2,
        'value' => 'private',
    ]);
    $this->defer(static function (): void {
        //
    });

    if (getenv('DROVE_LARAVEL_FAULT') === 'leave') {
        $path = getenv('DROVE_LARAVEL_FAULT_LOG');

        if (! is_string($path)
            || file_put_contents($path, 'leave '.getmypid().PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not record the native Laravel leave fault.');
        }

        $database->beginTransaction();
        $database->table('native_phase_four_fault')->insert([
            'id' => 3,
            'value' => 'uncommitted',
        ]);
    }
});
PHP,
        );
        nativePhaseFourEnvironment([
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $provider === 'sqlite-memory' ? ':memory:' : $sourceDatabase,
            'DROVE_LARAVEL' => '1',
            'DROVE_LARAVEL_FAULT_LOG' => $faultLog,
            'DROVE_LARAVEL_PROOF_DIRECTORY' => $proofDirectoryName,
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ]);
        $preparedSourceHash = null;
        $inner = $provider === 'sqlite-memory'
            ? new InMemorySqliteDatabaseStateAdapter('sqlite', true)
            : new SqliteCopyDatabaseStateAdapter('sqlite', $copyWorkspace, true);
        $state = new NativePhaseFourStateProvider(
            $inner,
            $fault === 'prepare' ? null : $fault,
            $faultLog,
            $copyWorkspace,
            getmypid(),
        );
        $registry = Declarations::capture(
            static function () use (
                &$preparedSourceHash,
                $fault,
                $generatedDirectory,
                $provider,
                $sourceDatabase,
                $state,
            ): void {
                laravel(
                    dirname(__DIR__),
                    $state,
                    [
                        AppServiceProvider::class,
                        DroveLaravelServiceProvider::class,
                    ],
                    static function (Application $application) use (
                        &$preparedSourceHash,
                        $fault,
                        $provider,
                        $sourceDatabase,
                    ): void {
                        $database = $application->make('db')->connection('sqlite');
                        $database->statement(
                            'CREATE TABLE native_phase_four_fault ('
                            .'id INTEGER PRIMARY KEY, value TEXT NOT NULL'
                            .')',
                        );
                        $database->table('native_phase_four_fault')->insert([
                            'id' => 1,
                            'value' => 'prepared',
                        ]);

                        if ($provider === 'sqlite-copy') {
                            $preparedSourceHash = hash_file('sha256', $sourceDatabase);
                        }

                        if ($fault === 'prepare') {
                            throw new RuntimeException('native-phase-4 fault prepare');
                        }
                    },
                );
                require $generatedDirectory.'/FaultTest.php';
            },
            $gateDirectory,
            'Drove native Laravel Phase 4 fault',
        );
        $throwable = null;
        $run = null;

        try {
            $run = new Runner(new DroverScheduler(
                'native-laravel-fault-'.bin2hex(random_bytes(6)),
                2,
            ))->run($registry);
        } catch (Throwable $caught) {
            $throwable = $caught;
        }

        $faultLines = is_file($faultLog)
            ? file($faultLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            : [];
        $faultLines = is_array($faultLines) ? array_values($faultLines) : [];
        $faultObserved = $fault === 'prepare'
            ? $throwable instanceof RuntimeException
                && $throwable->getMessage() === 'native-phase-4 fault prepare'
            : $faultLines !== []
                && array_all(
                    $faultLines,
                    static fn (string $line): bool => str_starts_with($line, $fault.' '),
                );
        $failed = $throwable instanceof Throwable
            || (is_array($run)
                && (($run['status'] ?? null) === 'failed'
                    || ($run['exit_code'] ?? null) !== 0));
        $failurePayload = json_encode($run, JSON_THROW_ON_ERROR);
        $failureFragment = match ($fault) {
            'prepare' => 'native-phase-4 fault prepare',
            'enter' => $provider === 'sqlite-memory'
                ? 'PDO was replaced or disconnected during enterDescendant'
                : 'file is not a database',
            'leave' => 'left transaction depth 1',
            'cleanup' => $provider === 'sqlite-memory'
                ? 'PDO was replaced or disconnected during afterDispatch'
                : 'invalid artifact in its SQLite copy workspace',
        };
        $failureFragmentChecked = $fault === 'prepare'
            ? $throwable?->getMessage() === $failureFragment
            : str_contains($failurePayload, $failureFragment);
        $sourceHashAfter = $provider === 'sqlite-copy'
            ? hash_file('sha256', $sourceDatabase)
            : null;
        $sqliteFiles = array_values(array_filter(
            nativePhaseFourFiles($stateDirectory),
            static fn (string $path): bool => ! in_array(
                $path,
                [
                    str_replace('\\', '/', $sourceDatabase),
                    str_replace('\\', '/', $faultLog),
                ],
                true,
            ),
        ));
        $workspaceEntries = glob($copyWorkspace.'/*');

        if ($workspaceEntries === false) {
            throw new RuntimeException('Could not scan native Laravel fault residue.');
        }

        $residueCount = count(array_unique([
            ...$sqliteFiles,
            ...array_map(
                static fn (string $path): string => str_replace('\\', '/', $path),
                $workspaceEntries,
            ),
        ]));
        $expectedResidueCount = $provider === 'sqlite-copy' && $fault === 'cleanup'
            ? 1
            : 0;

        if (! $faultObserved
            || ! $failed
            || ! $failureFragmentChecked
            || $residueCount !== $expectedResidueCount
            || ($provider === 'sqlite-copy'
                    && (! is_string($preparedSourceHash)
                        || ! is_string($sourceHashAfter)
                        || ! hash_equals($preparedSourceHash, $sourceHashAfter)))
            || class_exists('PHPUnit\\Framework\\TestCase', false)
            || class_exists('Orchestra\\Testbench\\TestCase', false)) {
            throw new RuntimeException(
                'Native Laravel fault cleanup proof failed: '.json_encode([
                    'fault_observed' => $faultObserved,
                    'failed' => $failed,
                    'failure_fragment' => $failureFragment,
                    'failure_payload' => $failurePayload,
                    'run_status' => is_array($run) ? ($run['status'] ?? null) : null,
                    'run_exit_code' => is_array($run) ? ($run['exit_code'] ?? null) : null,
                    'throwable' => $throwable?->getMessage(),
                    'fault_lines' => $faultLines,
                    'sqlite_files' => $sqliteFiles,
                    'workspace_entries' => $workspaceEntries,
                    'expected_residue_count' => $expectedResidueCount,
                    'source_hash_before' => $preparedSourceHash,
                    'source_hash_after' => $sourceHashAfter,
                    'phpunit_loaded' => class_exists('PHPUnit\\Framework\\TestCase', false),
                    'testbench_loaded' => class_exists('Orchestra\\Testbench\\TestCase', false),
                ], JSON_THROW_ON_ERROR),
            );
        }

        $summary = [
            'schema' => 1,
            'ok' => true,
            ...$identity,
            'provider' => $provider,
            'fault' => $fault,
            'injection_kind' => match ($fault) {
                'prepare' => 'prepare-closure',
                'enter' => 'before-provider-enter',
                'leave' => 'open-transaction-before-provider-leave',
                'cleanup' => 'before-provider-cleanup',
            },
            'fault_scope' => $fault === 'prepare'
                ? 'prepare-closure-before-provider-boot'
                : 'provider-internal-precondition',
            'internal_partial_provider_failure_checked' => $fault !== 'prepare',
            'fault_observed' => true,
            'failure_observed' => true,
            'failure_fragment' => $failureFragment,
            'failure_fragment_checked' => true,
            'fault_injection_count' => $fault === 'prepare' ? 1 : count($faultLines),
            'adapter_boot_count' => $state->bootCount,
            'source_hash_unchanged' => $provider === 'sqlite-memory'
                || hash_equals($preparedSourceHash, $sourceHashAfter),
            'provider_residue_count_before_harness_cleanup' => $residueCount,
            'harness_cleanup_required' => $expectedResidueCount === 1,
            'phpunit_loaded' => false,
            'testbench_loaded' => false,
            'throwable_class' => $throwable?->getMessage() === null
                ? null
                : $throwable::class,
            'run_status' => is_array($run) ? ($run['status'] ?? null) : null,
        ];
    } finally {
        nativePhaseFourRemove($generatedDirectory);
        nativePhaseFourRemove($stateDirectory);
        nativePhaseFourRemove($providerBootDirectory);
    }

    if (! is_array($summary)
        || nativePhaseFourFiles($generatedDirectory) !== []
        || nativePhaseFourFiles($stateDirectory) !== []) {
        throw new RuntimeException('Native Laravel fault proof cleanup did not finish.');
    }

    $summary['generated_artifact_count'] = 0;
    $summary['sqlite_artifact_count'] = 0;
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
