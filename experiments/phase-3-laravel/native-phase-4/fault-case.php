<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Drove\Kernel\DroverScheduler;
use Drove\Laravel\ApplicationRuntime;
use Drove\Laravel\DroveLaravelServiceProvider;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Laravel\State\SqliteCopyDatabaseStateAdapter;
use Drove\Laravel\State\TransactionalDatabaseStateAdapter;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Illuminate\Foundation\Application;

use function Drove\Laravel\laravel;
use function Drove\Native\environment;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/support.php';

function nativePhaseFourMysqlPdo(): PDO
{
    $required = static function (string $name, bool $allowEmpty = false): string {
        $value = getenv($name);

        if (! is_string($value) || (! $allowEmpty && $value === '')) {
            throw new RuntimeException($name.' is required for transaction fault injection.');
        }

        return $value;
    };
    $host = $required('DB_HOST');
    $port = filter_var(
        $required('DB_PORT'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65_535]],
    );

    if (! is_int($port)) {
        throw new RuntimeException('DB_PORT is invalid for transaction fault injection.');
    }

    $database = $required('DB_DATABASE');
    $username = $required('DB_USERNAME');
    $password = $required('DB_PASSWORD', true);
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
        'The transaction fault database did not become ready.',
        previous: $failure,
    );
}

function nativePhaseFourMysqlTable(string $table): string
{
    if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $table) !== 1) {
        throw new RuntimeException('The transaction fault table is invalid.');
    }

    return '`'.$table.'`';
}

function nativePhaseFourMysqlTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables '
        .'WHERE table_schema = DATABASE() AND table_name = ?',
    );
    $statement->execute([$table]);

    return (int) $statement->fetchColumn() !== 0;
}

/** @return list<string> */
function nativePhaseFourMysqlRows(PDO $pdo, string $table): array
{
    $statement = $pdo->query(
        'SELECT value FROM '.nativePhaseFourMysqlTable($table).' ORDER BY id',
    );

    if ($statement === false) {
        throw new RuntimeException('Could not read the transaction fault table.');
    }

    return array_values(array_map(
        static fn (array $row): string => (string) ($row['value'] ?? ''),
        $statement->fetchAll(),
    ));
}

function nativePhaseFourMysqlDropTable(PDO $pdo, string $table): void
{
    $pdo->exec('DROP TABLE IF EXISTS '.nativePhaseFourMysqlTable($table));
}

try {
    $provider = getenv('DROVE_LARAVEL_PROVIDER') ?: '';
    $fault = getenv('DROVE_LARAVEL_FAULT') ?: '';

    if (! in_array($provider, ['sqlite-memory', 'sqlite-copy', 'transaction'], true)
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
    $table = $provider === 'transaction'
        ? 'drove_native_phase_four_fault'
        : 'native_phase_four_fault';
    $proofDirectoryName = 'drove-native-phase-four-fault-'
        .$provider.'-'.str_replace('_', '-', $fault);
    $providerBootDirectory = dirname(__DIR__).'/storage/framework/'.$proofDirectoryName;
    $transactionDatabase = $provider === 'transaction' ? getenv('DB_DATABASE') : null;

    if ($provider === 'transaction'
        && (! is_string($transactionDatabase) || $transactionDatabase === '')) {
        throw new RuntimeException('DB_DATABASE is required for transaction fault injection.');
    }

    $mysql = $provider === 'transaction' ? nativePhaseFourMysqlPdo() : null;
    $runtimeApplication = null;
    $tablePresentBeforeFixtureTeardown = null;
    $tablePresentAfterFixtureTeardown = null;
    $rowsBeforeFixtureTeardown = null;
    $rootTransactionDepthBeforeFixtureTeardown = null;
    $summary = null;

    nativePhaseFourRemove($generatedDirectory);
    nativePhaseFourRemove($stateDirectory);
    nativePhaseFourRemove($providerBootDirectory);

    if ($mysql instanceof PDO) {
        nativePhaseFourMysqlDropTable($mysql, $table);
    }

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
    $table = getenv('DROVE_LARAVEL_FAULT_TABLE');

    if (! is_string($table) || $table === '') {
        throw new RuntimeException('The native Laravel fault table is missing.');
    }

    $database = $this->app()->make('db')->connection();
    expect($database->table($table)->count())->toBe(1);
    $database->table($table)->insert([
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
        $database->table($table)->insert([
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
            'DB_CONNECTION' => $provider === 'transaction' ? 'mysql' : 'sqlite',
            'DB_DATABASE' => $provider === 'sqlite-memory' ? ':memory:' : $sourceDatabase,
            'DROVE_LARAVEL' => '1',
            'DROVE_LARAVEL_FAULT_LOG' => $faultLog,
            'DROVE_LARAVEL_FAULT_TABLE' => $table,
            'DROVE_LARAVEL_PROOF_DIRECTORY' => $proofDirectoryName,
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ]);

        if ($provider === 'transaction') {
            nativePhaseFourEnvironment([
                'DB_DATABASE' => $transactionDatabase,
            ]);
        }

        $preparedSourceHash = null;
        $inner = match ($provider) {
            'sqlite-memory' => new InMemorySqliteDatabaseStateAdapter('sqlite', true),
            'sqlite-copy' => new SqliteCopyDatabaseStateAdapter('sqlite', $copyWorkspace, true),
            'transaction' => new TransactionalDatabaseStateAdapter('mysql'),
        };
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
                &$runtimeApplication,
                $fault,
                $generatedDirectory,
                $provider,
                $sourceDatabase,
                $state,
                $table,
            ): void {
                $prepare = static function (Application $application) use (
                    &$preparedSourceHash,
                    $fault,
                    $provider,
                    $sourceDatabase,
                    $table,
                ): void {
                    $database = $application->make('db')->connection();
                    $database->statement($provider === 'transaction'
                        ? 'CREATE TABLE `'.$table.'` ('
                            .'id INTEGER PRIMARY KEY, value VARCHAR(64) NOT NULL'
                            .') ENGINE=InnoDB'
                        : 'CREATE TABLE '.$table.' ('
                            .'id INTEGER PRIMARY KEY, value TEXT NOT NULL'
                            .')');
                    $database->table($table)->insert([
                        'id' => 1,
                        'value' => 'prepared',
                    ]);

                    if ($provider === 'sqlite-copy') {
                        $preparedSourceHash = hash_file('sha256', $sourceDatabase);
                    }

                    if ($fault === 'prepare') {
                        throw new RuntimeException('native-phase-4 fault prepare');
                    }
                };

                if ($provider === 'transaction') {
                    environment(
                        'laravel',
                        static function () use (
                            &$runtimeApplication,
                            $prepare,
                            $state,
                        ): ApplicationRuntime {
                            $application = require dirname(__DIR__).'/bootstrap/app.php';

                            if (! $application instanceof Application) {
                                throw new RuntimeException(
                                    'The transaction fault bootstrap did not return Laravel.',
                                );
                            }

                            $runtimeApplication = $application;

                            return ApplicationRuntime::fromApplication(
                                $application,
                                $state,
                                $prepare,
                            );
                        },
                    );
                } else {
                    laravel(
                        dirname(__DIR__),
                        $state,
                        [
                            AppServiceProvider::class,
                            DroveLaravelServiceProvider::class,
                        ],
                        $prepare,
                    );
                }

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
            'enter' => match ($provider) {
                'sqlite-memory' => 'PDO was replaced or disconnected during enterDescendant',
                'sqlite-copy' => 'file is not a database',
                'transaction' => 'Database connection mysql has an open transaction during enterDescendant.',
            },
            'leave' => $provider === 'transaction'
                ? 'left transaction depth 2'
                : 'left transaction depth 1',
            'cleanup' => match ($provider) {
                'sqlite-memory' => 'PDO was replaced or disconnected during afterDispatch',
                'sqlite-copy' => 'invalid artifact in its SQLite copy workspace',
                'transaction' => 'Database connection mysql has an open transaction during afterDispatch.',
            },
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
            'source_hash_unchanged' => $provider !== 'sqlite-copy'
                || hash_equals($preparedSourceHash, $sourceHashAfter),
            'provider_residue_count_before_harness_cleanup' => $residueCount,
            'phpunit_loaded' => false,
            'testbench_loaded' => false,
            'throwable_class' => $throwable?->getMessage() === null
                ? null
                : $throwable::class,
            'run_status' => is_array($run) ? ($run['status'] ?? null) : null,
        ];
    } finally {
        if ($provider === 'transaction' && $runtimeApplication instanceof Application) {
            $database = $runtimeApplication->make('db');

            try {
                $connection = $database->connection('mysql');
                $rootTransactionDepthBeforeFixtureTeardown = $connection->transactionLevel();

                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } finally {
                $database->disconnect('mysql');
            }
        }

        if ($mysql instanceof PDO) {
            $tablePresentBeforeFixtureTeardown = nativePhaseFourMysqlTableExists(
                $mysql,
                $table,
            );
            $rowsBeforeFixtureTeardown = $tablePresentBeforeFixtureTeardown
                ? nativePhaseFourMysqlRows($mysql, $table)
                : [];
            nativePhaseFourMysqlDropTable($mysql, $table);
            $tablePresentAfterFixtureTeardown = nativePhaseFourMysqlTableExists(
                $mysql,
                $table,
            );
        }

        nativePhaseFourRemove($generatedDirectory);
        nativePhaseFourRemove($stateDirectory);
        nativePhaseFourRemove($providerBootDirectory);
    }

    if (! is_array($summary)
        || nativePhaseFourFiles($generatedDirectory) !== []
        || nativePhaseFourFiles($stateDirectory) !== []
        || ($provider === 'transaction'
            && ($rootTransactionDepthBeforeFixtureTeardown !== ($fault === 'cleanup' ? 1 : 0)
                || $tablePresentBeforeFixtureTeardown !== true
                || $rowsBeforeFixtureTeardown !== ['prepared']
                || $tablePresentAfterFixtureTeardown !== false))) {
        throw new RuntimeException('Native Laravel fault proof cleanup did not finish.');
    }

    $summary['prepared_database_rows_before_fixture_teardown'] = $rowsBeforeFixtureTeardown;
    $summary['prepared_table_present_before_fixture_teardown'] = $tablePresentBeforeFixtureTeardown;
    $summary['prepared_table_present_after_fixture_teardown'] = $tablePresentAfterFixtureTeardown;
    $summary['fixture_teardown_checked'] = $provider === 'transaction';
    $summary['root_transaction_depth_before_fixture_teardown'] = $rootTransactionDepthBeforeFixtureTeardown;
    $summary['harness_cleanup_required'] = $provider === 'transaction'
        ? $rootTransactionDepthBeforeFixtureTeardown > 0
        : ($summary['provider_residue_count_before_harness_cleanup'] ?? 0) > 0;

    $summary['generated_artifact_count'] = 0;
    $summary['sqlite_artifact_count'] = 0;
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
