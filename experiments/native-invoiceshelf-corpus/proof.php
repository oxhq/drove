<?php

declare(strict_types=1);

use Drove\Compatibility\Registry;
use Drove\Kernel\DroverScheduler;
use Drove\Laravel\ApplicationRuntime;
use Drove\Laravel\LaravelTestContext;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Migration\CodemodOptions;
use Drove\Migration\LaravelMigrator;
use Drove\Migration\Migrator;
use Drove\Migration\Scanner;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use function Drove\Native\environment;

require_once __DIR__.'/CaseSemantics.php';

const NATIVE_INVOICESHELF_COMMIT = '403a4d67225a153838ec126c484339abf60229d1';
const NATIVE_INVOICESHELF_SELECTION_FILES = 47;
const NATIVE_INVOICESHELF_SELECTION_CASES = 202;
const NATIVE_INVOICESHELF_SELECTION_ASSERTIONS = 328;
const NATIVE_INVOICESHELF_PREPARATION_TIME = '2000-01-01 00:00:00';
const NATIVE_INVOICESHELF_DATABASE_SHA256 = 'a0045ae4d7b5c7f0124843b5b1d14d12e62cf153823498bf2cfd8940cf0b606e';
const NATIVE_INVOICESHELF_MUTATED_DATABASE_SHA256 = '4a5ae9eed139bf78d7d6a1c332241af02081c2dc8ef43838bcd29afceeaf7bf6';

/** @param list<string> $command */
function nativeInvoiceShelfCommand(array $command, ?string $cwd = null): string
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd, options: ['bypass_shell' => true]);

    if (! is_resource($process)) {
        throw new RuntimeException('DROVE_NATIVE_INVOICESHELF_COMMAND_START');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0 || ! is_string($stdout) || ! is_string($stderr)) {
        throw new RuntimeException(sprintf(
            'DROVE_NATIVE_INVOICESHELF_COMMAND_FAILED:%s',
            trim(is_string($stderr) ? $stderr : ''),
        ));
    }

    return $stdout;
}

function nativeInvoiceShelfAssert(bool $condition, string $diagnostic): void
{
    if (! $condition) {
        throw new RuntimeException($diagnostic);
    }
}

function nativeInvoiceShelfBlob(string $checkout, string $path): string
{
    return nativeInvoiceShelfCommand([
        'git', '-C', $checkout, 'show', NATIVE_INVOICESHELF_COMMIT.':'.$path,
    ]);
}

function nativeInvoiceShelfObject(string $checkout, string $path): string
{
    return trim(nativeInvoiceShelfCommand([
        'git', '-C', $checkout, 'rev-parse', NATIVE_INVOICESHELF_COMMIT.':'.$path,
    ]));
}

/** @return list<string> */
function nativeInvoiceShelfSelection(string $checkout): array
{
    $paths = preg_split('/\R/', trim(nativeInvoiceShelfCommand([
        'git', '-C', $checkout, 'ls-tree', '-r', '--name-only',
        NATIVE_INVOICESHELF_COMMIT, '--', 'tests/Unit', 'tests/Feature/Customer',
    ]))) ?: [];
    $paths = array_values(array_filter(
        $paths,
        static fn (string $path): bool => str_ends_with($path, '.php'),
    ));
    sort($paths, SORT_STRING);

    return $paths;
}

/** @return array{schema: int, corpus: string, commit: string, runner: string, files: list<string>, cases: int, assertions: int, case_names_sha256: string} */
function nativeInvoiceShelfBaseline(): array
{
    $source = file_get_contents(__DIR__.'/baseline.json');
    $baseline = is_string($source)
        ? json_decode($source, true, 16, JSON_THROW_ON_ERROR)
        : null;

    if (! is_array($baseline)
        || ($baseline['schema'] ?? null) !== 1
        || ($baseline['corpus'] ?? null) !== 'InvoiceShelf/InvoiceShelf'
        || ($baseline['commit'] ?? null) !== NATIVE_INVOICESHELF_COMMIT
        || ! is_string($baseline['runner'] ?? null)
        || ! is_array($baseline['files'] ?? null)
        || ! array_is_list($baseline['files'])
        || ! is_int($baseline['cases'] ?? null)
        || ! is_int($baseline['assertions'] ?? null)
        || ! is_string($baseline['case_names_sha256'] ?? null)) {
        throw new RuntimeException('DROVE_NATIVE_INVOICESHELF_BASELINE_INVALID');
    }

    /** @var array{schema: int, corpus: string, commit: string, runner: string, files: list<string>, cases: int, assertions: int, case_names_sha256: string} $baseline */
    return $baseline;
}

/** @return array<string, mixed> */
function nativeInvoiceShelfDetailedBaseline(): array
{
    $source = file_get_contents(__DIR__.'/detailed-baseline.json');
    $baseline = is_string($source)
        ? json_decode($source, true, 64, JSON_THROW_ON_ERROR)
        : null;
    $cases = is_array($baseline) ? ($baseline['cases'] ?? null) : null;

    if (! is_array($baseline)
        || ($baseline['schema'] ?? null) !== 1
        || ($baseline['corpus'] ?? null) !== 'InvoiceShelf/InvoiceShelf'
        || ($baseline['commit'] ?? null) !== NATIVE_INVOICESHELF_COMMIT
        || ($baseline['manifest_sha256'] ?? null) !== 'd698b896b72569a943689d79e1e42852ecd8accfd4072f655e02286bdb32fb00'
        || ($baseline['lock_sha256'] ?? null) !== 'd208f91d6c45de03bb2684b76051db6de28a6983b1c454bbc999e9c171df0342'
        || ($baseline['tests'] ?? null) !== NATIVE_INVOICESHELF_SELECTION_CASES
        || ($baseline['passed'] ?? null) !== NATIVE_INVOICESHELF_SELECTION_CASES
        || ($baseline['assertions'] ?? null) !== NATIVE_INVOICESHELF_SELECTION_ASSERTIONS
        || ! is_array($cases)
        || ! array_is_list($cases)
        || ($baseline['case_semantics_sha256'] ?? null) !== hash(
            'sha256',
            json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        )) {
        throw new RuntimeException('DROVE_NATIVE_INVOICESHELF_DETAILED_BASELINE_INVALID');
    }

    return $baseline;
}

function nativeInvoiceShelfOptions(): CodemodOptions
{
    $config = require __DIR__.'/codemod-options.php';

    if (! is_array($config)
        || array_keys($config) !== ['trustedFunctions', 'imports', 'subjects']
        || ! is_array($config['trustedFunctions'])
        || ! is_array($config['imports'])
        || ! is_array($config['subjects'])) {
        throw new RuntimeException('DROVE_NATIVE_INVOICESHELF_CODEMOD_OPTIONS_INVALID');
    }

    return new CodemodOptions(
        trustedFunctions: $config['trustedFunctions'],
        imports: $config['imports'],
        subjects: $config['subjects'],
    );
}

/**
 * @param  array<string, mixed>  $task
 * @return array{classes: list<string>, files: list<string>}
 */
function nativeInvoiceShelfRuntimeGuard(string $evidenceFile, array $task): array
{
    $classes = array_values(array_filter(
        get_declared_classes(),
        static fn (string $class): bool => str_starts_with($class, 'Drove\\Bridge\\')
            || str_starts_with($class, 'Drove\\Pest\\')
            || $class === 'Drove\\Laravel\\TestbenchBridge'
            || str_starts_with($class, 'Pest\\')
            || str_starts_with($class, 'PHPUnit\\')
            || str_starts_with($class, 'Orchestra\\Testbench\\')
            || strcasecmp($class, 'Tests\\TestCase') === 0,
    ));
    $files = array_values(array_filter(
        array_map(
            static fn (string $path): string => str_replace('\\', '/', $path),
            get_included_files(),
        ),
        static fn (string $path): bool => preg_match(
            '~/(?:pestphp|phpunit|orchestra/testbench[^/]*)/~i',
            $path,
        ) === 1
            || str_ends_with($path, '/tests/TestCase.php')
            || str_contains($path, '/src/Drove/Bridge/')
            || str_contains($path, '/src/Drove/Pest/')
            || str_ends_with($path, '/packages/drove-laravel/src/TestbenchBridge.php'),
    ));
    $evidence = [
        'pid' => getmypid(),
        'task' => $task,
        'classes' => $classes,
        'files' => $files,
    ];
    $record = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

    if (file_put_contents($evidenceFile, $record, FILE_APPEND | LOCK_EX) !== strlen($record)) {
        throw new RuntimeException('DROVE_NATIVE_INVOICESHELF_EVIDENCE_WRITE');
    }

    if ($classes !== [] || $files !== []) {
        throw new RuntimeException('DROVE_NATIVE_INVOICESHELF_EXTERNAL_TEST_RUNTIME_LOADED');
    }

    return ['classes' => $classes, 'files' => $files];
}

/**
 * @return array{
 *     format: string,
 *     sha256: string,
 *     tables: int,
 *     columns: int,
 *     rows: int,
 *     migrations: int,
 *     addresses: int
 * }
 */
function nativeInvoiceShelfDatabaseEvidence(Application $application): array
{
    $connection = $application->make('db')->connection('sqlite');
    $tableRecords = $connection->select(
        "SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name COLLATE BINARY",
    );
    $tables = [];
    $columnCount = 0;
    $rowCount = 0;

    foreach ($tableRecords as $tableRecord) {
        $tableName = $tableRecord->name ?? null;
        nativeInvoiceShelfAssert(
            is_string($tableName) && $tableName !== '' && ! str_contains($tableName, "\0"),
            'DROVE_NATIVE_INVOICESHELF_DATABASE_TABLE_INVALID',
        );
        $quotedTable = '"'.str_replace('"', '""', $tableName).'"';
        $columnRecords = $connection->select('PRAGMA table_xinfo('.$quotedTable.')');
        $columns = [];

        foreach ($columnRecords as $columnRecord) {
            $column = (array) $columnRecord;
            $name = $column['name'] ?? null;
            nativeInvoiceShelfAssert(
                is_string($name) && $name !== '' && ! str_contains($name, "\0"),
                'DROVE_NATIVE_INVOICESHELF_DATABASE_COLUMN_INVALID',
            );
            $columns[] = [
                'cid' => (int) ($column['cid'] ?? -1),
                'name' => $name,
                'declared_type' => (string) ($column['type'] ?? ''),
                'not_null' => (int) ($column['notnull'] ?? 0),
                'default_sql' => $column['dflt_value'] === null ? null : (string) $column['dflt_value'],
                'primary_key_position' => (int) ($column['pk'] ?? 0),
                'hidden' => (int) ($column['hidden'] ?? 0),
            ];
        }

        nativeInvoiceShelfAssert($columns !== [], 'DROVE_NATIVE_INVOICESHELF_DATABASE_COLUMNS_EMPTY');
        usort($columns, static fn (array $left, array $right): int => $left['cid'] <=> $right['cid']);
        $columnCount += count($columns);
        $projection = [];

        foreach ($columns as $index => $column) {
            $quotedColumn = '"'.str_replace('"', '""', $column['name']).'"';
            $projection[] = sprintf(
                '%s AS "__drove_value_%d", typeof(%s) AS "__drove_type_%d"',
                $quotedColumn,
                $index,
                $quotedColumn,
                $index,
            );
        }

        $logicalRows = [];

        foreach ($connection->select('SELECT '.implode(', ', $projection).' FROM '.$quotedTable) as $storedRow) {
            $stored = (array) $storedRow;
            $logicalRow = [];

            foreach ($columns as $index => $_column) {
                $type = $stored['__drove_type_'.$index] ?? null;
                $value = $stored['__drove_value_'.$index] ?? null;
                nativeInvoiceShelfAssert(
                    is_string($type) && in_array($type, ['null', 'integer', 'real', 'text', 'blob'], true),
                    'DROVE_NATIVE_INVOICESHELF_DATABASE_STORAGE_TYPE_INVALID',
                );
                nativeInvoiceShelfAssert(
                    $type !== 'null' || $value === null,
                    'DROVE_NATIVE_INVOICESHELF_DATABASE_NULL_VALUE_INVALID',
                );
                $logicalRow[] = [
                    'type' => $type,
                    'value' => match ($type) {
                        'null' => null,
                        'integer' => (string) $value,
                        'real' => bin2hex(pack('E', (float) $value)),
                        'text', 'blob' => base64_encode((string) $value),
                        default => throw new RuntimeException(
                            'DROVE_NATIVE_INVOICESHELF_DATABASE_STORAGE_TYPE_UNHANDLED',
                        ),
                    },
                ];
            }

            $encoded = json_encode(
                $logicalRow,
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
            $logicalRows[] = ['sort' => $encoded, 'cells' => $logicalRow];
        }

        usort($logicalRows, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);
        $rows = array_column($logicalRows, 'cells');
        $rowCount += count($rows);
        $tables[] = [
            'name' => $tableName,
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    $logicalState = [
        'format' => 'sqlite-logical-v1',
        'tables' => $tables,
    ];
    $encodedState = json_encode(
        $logicalState,
        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
    );

    return [
        'format' => $logicalState['format'],
        'sha256' => hash('sha256', $encodedState),
        'tables' => count($tables),
        'columns' => $columnCount,
        'rows' => $rowCount,
        'migrations' => (int) $connection->table('migrations')->count(),
        'addresses' => (int) $connection->table('addresses')->count(),
    ];
}

/**
 * @param  array{format: string, sha256: string, tables: int, columns: int, rows: int, migrations: int, addresses: int}  $prepared
 * @return array{table: string, before_sha256: string, mutated_sha256: string, restored_sha256: string, detected: true, rollback_restored: true}
 */
function nativeInvoiceShelfDatabaseMutationEvidence(Application $application, array $prepared): array
{
    $connection = $application->make('db')->connection('sqlite');
    nativeInvoiceShelfAssert(
        $connection->transactionLevel() === 0,
        'DROVE_NATIVE_INVOICESHELF_DATABASE_MUTATION_TRANSACTION_OPEN',
    );
    $mutated = null;
    $connection->beginTransaction();

    try {
        nativeInvoiceShelfAssert(
            $connection->table('settings')->insert([
                'option' => '__drove_logical_digest_probe__',
                'value' => 'type-preserved-row',
            ]),
            'DROVE_NATIVE_INVOICESHELF_DATABASE_MUTATION_INSERT',
        );
        $mutated = nativeInvoiceShelfDatabaseEvidence($application);
    } finally {
        $connection->rollBack();
    }

    $restored = nativeInvoiceShelfDatabaseEvidence($application);
    nativeInvoiceShelfAssert(
        $mutated['sha256'] !== $prepared['sha256']
            && $mutated['tables'] === $prepared['tables']
            && $mutated['columns'] === $prepared['columns']
            && $mutated['rows'] === $prepared['rows'] + 1
            && $mutated['migrations'] === $prepared['migrations']
            && $mutated['addresses'] === $prepared['addresses']
            && $restored === $prepared,
        'DROVE_NATIVE_INVOICESHELF_DATABASE_MUTATION_UNDETECTED',
    );

    return [
        'table' => 'settings',
        'before_sha256' => $prepared['sha256'],
        'mutated_sha256' => $mutated['sha256'],
        'restored_sha256' => $restored['sha256'],
        'detected' => true,
        'rollback_restored' => true,
    ];
}

/**
 * @param  list<string>  $paths
 * @return list<array{path: string, blob: string, source_sha256: string, result_sha256: string}>
 */
function nativeInvoiceShelfStage(
    string $stage,
    string $checkout,
    LaravelMigrator $migrator,
    CodemodOptions $options,
    array $paths,
): array {
    $identity = [];

    foreach ($paths as $path) {
        $source = nativeInvoiceShelfBlob($checkout, $path);
        $result = $migrator->migrate($source, $path, $options);
        nativeInvoiceShelfAssert(
            $result->nativeReady(),
            'DROVE_NATIVE_INVOICESHELF_BASELINE_FILE_BLOCKED:'.$path.':'.json_encode([
                'portable' => $result->portableBlockers,
                'laravel' => $result->blockers,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $target = $stage.'/'.$path;
        $directory = dirname($target);
        nativeInvoiceShelfAssert(
            is_dir($directory) || mkdir($directory, 0777, true),
            'DROVE_NATIVE_INVOICESHELF_STAGE_DIRECTORY',
        );
        $instrumented = $result->source;
        nativeInvoiceShelfAssert(
            file_put_contents($target, $instrumented) === strlen($instrumented),
            'DROVE_NATIVE_INVOICESHELF_STAGE_WRITE',
        );
        $identity[] = [
            'path' => $path,
            'blob' => nativeInvoiceShelfObject($checkout, $path),
            'source_sha256' => hash('sha256', $source),
            'result_sha256' => $result->resultHash,
        ];
    }

    return $identity;
}

try {
    (static function (array $arguments): void {
        $proofStartedNs = hrtime(true);
        $checkout = $arguments[1] ?? null;
        $droveRoot = $arguments[2] ?? dirname(__DIR__, 2);
        $processes = filter_var(
            getenv('DROVE_NATIVE_INVOICESHELF_PROCESSES') ?: '2',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 30]],
        );

        if (! is_string($checkout)
            || ! is_file($checkout.'/vendor/autoload.php')
            || ! is_file($checkout.'/bootstrap/app.php')
            || ! is_file($droveRoot.'/resources/drove-bridge-compatibility.json')
            || ! is_int($processes)) {
            fwrite(STDERR, "Usage: proof.php /pinned/invoiceshelf [/drove] with processes 1..30.\n");
            exit(2);
        }

        require $checkout.'/vendor/autoload.php';

        if (! function_exists('Drove\\Native\\environment')) {
            require $checkout.'/vendor/oxhq/drove/src/Drove/Native/functions.php';
        }
        if (! function_exists('Drove\\Laravel\\laravelContext')) {
            require $checkout.'/vendor/oxhq/drove-laravel/src/functions.php';
        }

        nativeInvoiceShelfAssert(
            trim(nativeInvoiceShelfCommand(['git', '-C', $checkout, 'rev-parse', 'HEAD']))
                === NATIVE_INVOICESHELF_COMMIT,
            'DROVE_NATIVE_INVOICESHELF_COMMIT_MISMATCH',
        );

        if (getenv('DROVE_NATIVE_INVOICESHELF_FAULT') === 'runtime-guard') {
            eval('namespace Drove\\Bridge\\Pest; final class InjectedRuntime {}');
            $guardEvidence = sys_get_temp_dir().'/drove-native-invoiceshelf-guard-'.bin2hex(random_bytes(8));

            try {
                nativeInvoiceShelfRuntimeGuard($guardEvidence, [
                    'id' => 'runtime-guard',
                    'kind' => 'fault',
                ]);
            } finally {
                if (is_file($guardEvidence)) {
                    unlink($guardEvidence);
                }
            }
        }

        if (getenv('DROVE_NATIVE_INVOICESHELF_FAULT') === 'after-bootstrap') {
            $application = require $checkout.'/bootstrap/app.php';
            nativeInvoiceShelfAssert(
                $application instanceof Application,
                'DROVE_NATIVE_INVOICESHELF_FAULT_BOOTSTRAP',
            );
            $application->make(ConsoleKernel::class)->bootstrap();

            throw new RuntimeException('DROVE_NATIVE_INVOICESHELF_INJECTED_FAILURE');
        }

        $selection = nativeInvoiceShelfSelection($checkout);
        nativeInvoiceShelfAssert(
            count($selection) === NATIVE_INVOICESHELF_SELECTION_FILES,
            'DROVE_NATIVE_INVOICESHELF_SELECTION_MISMATCH',
        );
        $baseline = nativeInvoiceShelfBaseline();
        nativeInvoiceShelfAssert(
            count($baseline['files']) === count(array_unique($baseline['files']))
                && array_diff($baseline['files'], $selection) === [],
            'DROVE_NATIVE_INVOICESHELF_BASELINE_FILES_INVALID',
        );

        $migrator = new LaravelMigrator(new Migrator(new Scanner(
            Registry::load($droveRoot.'/resources/drove-bridge-compatibility.json'),
        )));
        $options = nativeInvoiceShelfOptions();
        $blocked = [];

        foreach ($selection as $path) {
            $result = $migrator->migrate(nativeInvoiceShelfBlob($checkout, $path), $path, $options);

            if (! $result->nativeReady()) {
                $blocked[$path] = [
                    'portable' => array_values(array_unique(array_map(
                        static fn ($finding): string => $finding->diagnostic,
                        $result->portableBlockers,
                    ))),
                    'laravel' => array_values(array_unique(array_column($result->blockers, 'diagnostic'))),
                ];
            }
        }

        nativeInvoiceShelfAssert(
            array_values(array_diff($selection, array_keys($blocked))) === $baseline['files'],
            'DROVE_NATIVE_INVOICESHELF_READY_SET_DRIFT:'.json_encode([
                'actual' => array_values(array_diff($selection, array_keys($blocked))),
                'expected' => $baseline['files'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        foreach ([
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:x5m/qWTRn07o2rXmBGZap8zkqzrPybJGqGbi2f8I7Pc=',
            'APP_URL' => 'http://localhost',
            'BCRYPT_ROUNDS' => '4',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'MAIL_MAILER' => 'array',
            'NIGHTWATCH_ENABLED' => 'false',
            'PULSE_ENABLED' => 'false',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'TELESCOPE_ENABLED' => 'false',
        ] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $token = bin2hex(random_bytes(8));
        $stage = sys_get_temp_dir().'/drove-native-invoiceshelf-'.$token;
        $evidenceFile = sys_get_temp_dir().'/drove-native-invoiceshelf-'.$token.'.jsonl';
        $prepared = null;
        $databaseMutation = null;
        /** @var ArrayObject<string, int|float> $phaseClock */
        $phaseClock = new ArrayObject([
            'preparation_ns' => 0,
            'planning_ns' => 0,
            'planning_started_ns' => -1,
            'execution_started_ns' => -1,
        ]);

        try {
            nativeInvoiceShelfAssert(mkdir($stage, 0777, true), 'DROVE_NATIVE_INVOICESHELF_STAGE_CREATE');
            $compiledViews = $stage.'/compiled-views';
            nativeInvoiceShelfAssert(
                mkdir($compiledViews, 0777, true),
                'DROVE_NATIVE_INVOICESHELF_VIEW_CACHE_CREATE',
            );
            putenv('VIEW_COMPILED_PATH='.$compiledViews);
            $_ENV['VIEW_COMPILED_PATH'] = $compiledViews;
            $_SERVER['VIEW_COMPILED_PATH'] = $compiledViews;
            $identity = nativeInvoiceShelfStage(
                $stage,
                $checkout,
                $migrator,
                $options,
                $baseline['files'],
            );
            $registry = Declarations::capture(
                static function () use (
                    $checkout,
                    $stage,
                    $baseline,
                    &$databaseMutation,
                    &$prepared,
                    $phaseClock,
                ): void {
                    environment(
                        'invoiceshelf-native-application',
                        static function () use (
                            $checkout,
                            &$databaseMutation,
                            &$prepared,
                            $phaseClock,
                        ): ApplicationRuntime {
                            $planningStartedNs = $phaseClock['planning_started_ns'];

                            if ($planningStartedNs < 0) {
                                throw new RuntimeException('DROVE_NATIVE_INVOICESHELF_PLANNING_CLOCK_MISSING');
                            }
                            $environmentStartedNs = hrtime(true);
                            $phaseClock['planning_ns'] = $environmentStartedNs - $planningStartedNs;
                            $application = require $checkout.'/bootstrap/app.php';
                            nativeInvoiceShelfAssert(
                                $application instanceof Application,
                                'DROVE_NATIVE_INVOICESHELF_APPLICATION_BOOTSTRAP',
                            );

                            $runtime = ApplicationRuntime::fromApplication(
                                $application,
                                new InMemorySqliteDatabaseStateAdapter('sqlite', true),
                                static function (Application $application) use (&$databaseMutation, &$prepared): void {
                                    Carbon::setTestNow(NATIVE_INVOICESHELF_PREPARATION_TIME);

                                    try {
                                        $exit = $application->make(ConsoleKernel::class)->call(
                                            'migrate:fresh',
                                            ['--force' => true],
                                        );
                                    } finally {
                                        Carbon::setTestNow();
                                    }
                                    nativeInvoiceShelfAssert(
                                        $exit === 0,
                                        'DROVE_NATIVE_INVOICESHELF_MIGRATION_FAILED',
                                    );
                                    (new LaravelTestContext($application))->withoutVite();
                                    Factory::guessFactoryNamesUsing(
                                        static function (string $modelName): string {
                                            /** @var class-string<Factory<Model>> $factory */
                                            $factory = 'Database\\Factories\\'.Str::afterLast($modelName, '\\').'Factory';

                                            return $factory;
                                        },
                                    );
                                    $prepared = nativeInvoiceShelfDatabaseEvidence($application);
                                    $databaseMutation = nativeInvoiceShelfDatabaseMutationEvidence(
                                        $application,
                                        $prepared,
                                    );
                                },
                            );
                            $environmentPreparedNs = hrtime(true);
                            $phaseClock['preparation_ns'] += $environmentPreparedNs - $environmentStartedNs;
                            $phaseClock['execution_started_ns'] = $environmentPreparedNs;

                            return $runtime;
                        },
                    );

                    foreach ($baseline['files'] as $path) {
                        require $stage.'/'.$path;
                    }
                },
                $stage,
                'InvoiceShelf native corpus',
            );

            if (getenv('DROVE_NATIVE_INVOICESHELF_FAULT') === 'after-capture') {
                throw new RuntimeException('DROVE_NATIVE_INVOICESHELF_INJECTED_FAILURE_AFTER_CAPTURE');
            }

            $scheduler = new DroverScheduler(
                'native-invoiceshelf-'.$token,
                $processes,
                [],
                60_000,
            );
            $runner = new Runner(
                $scheduler,
                static function (array $task) use ($evidenceFile): void {
                    nativeInvoiceShelfRuntimeGuard($evidenceFile, $task);
                },
            );
            $planningStartedNs = hrtime(true);
            $phaseClock['planning_started_ns'] = $planningStartedNs;
            $phaseClock['preparation_ns'] += $planningStartedNs - $proofStartedNs;
            $run = $runner->run($registry);
            $runnerFinishedNs = hrtime(true);
            $executionStartedNs = $phaseClock['execution_started_ns'];

            if ($executionStartedNs < 0 || $executionStartedNs > $runnerFinishedNs) {
                throw new RuntimeException('DROVE_NATIVE_INVOICESHELF_EXECUTION_CLOCK_MISSING');
            }
            $executionNs = $runnerFinishedNs - $executionStartedNs;
            $verificationStartedNs = $runnerFinishedNs;
            $tests = $run['tests'] ?? null;
            $caseNames = is_array($tests)
                ? array_map(
                    static function (array $test): string {
                        $name = (string) ($test['name'] ?? '');
                        $dataset = $test['dataset'] ?? null;

                        if (is_array($dataset) && is_string($dataset['label'] ?? null)) {
                            $suffix = ' ['.$dataset['label'].']';
                            $name = str_ends_with($name, $suffix)
                                ? substr($name, 0, -strlen($suffix))
                                : $name;
                        }

                        return (string) ($test['source']['path'] ?? '').'::'.$name;
                    },
                    $tests,
                )
                : [];
            sort($caseNames, SORT_STRING);
            $caseNamesFile = getenv('DROVE_NATIVE_INVOICESHELF_CASES_FILE');

            if (is_string($caseNamesFile) && $caseNamesFile !== '') {
                $encodedCaseNames = json_encode(
                    $caseNames,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ).PHP_EOL;
                nativeInvoiceShelfAssert(
                    file_put_contents($caseNamesFile, $encodedCaseNames) === strlen($encodedCaseNames),
                    'DROVE_NATIVE_INVOICESHELF_CASE_NAMES_WRITE',
                );
            }
            $assertions = is_array($tests)
                ? array_sum(array_map(static fn (array $test): int => (int) ($test['assertions'] ?? 0), $tests))
                : -1;
            $caseSemantics = [];

            if (is_array($tests)) {
                nativeInvoiceShelfAssert(
                    array_is_list($tests)
                        && array_filter($tests, static fn (mixed $test): bool => ! is_array($test)) === [],
                    'DROVE_NATIVE_INVOICESHELF_RESULTS_INVALID',
                );
                /** @var list<array<string, mixed>> $tests */
                $caseSemantics = NativeInvoiceShelfCaseSemantics::fromNative($tests);
            }
            $detailedBaseline = nativeInvoiceShelfDetailedBaseline();
            $lines = is_file($evidenceFile)
                ? file($evidenceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
                : false;
            $evidence = is_array($lines)
                ? array_map(
                    static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR),
                    $lines,
                )
                : [];
            $runtimeAuditPids = array_values(array_unique(array_column($evidence, 'pid')));
            $executorPids = [];
            $laneEvents = [];
            $telemetryCases = 0;

            foreach (is_array($tests) ? $tests : [] as $test) {
                $telemetry = $test['telemetry'] ?? null;

                if (! is_array($telemetry)) {
                    continue;
                }

                $pid = $telemetry['pid'] ?? null;
                $startedNs = $telemetry['started_ns'] ?? null;
                $finishedNs = $telemetry['finished_ns'] ?? null;

                if (! is_int($pid) || ! is_int($startedNs) || ! is_int($finishedNs) || $finishedNs < $startedNs) {
                    continue;
                }

                $executorPids[$pid] = true;
                $laneEvents[] = ['at' => $startedNs, 'delta' => 1];
                $laneEvents[] = ['at' => $finishedNs, 'delta' => -1];
                $telemetryCases++;
            }

            usort($laneEvents, static fn (array $left, array $right): int => [
                $left['at'],
                $left['delta'],
            ] <=> [
                $right['at'],
                $right['delta'],
            ]);
            $activeLanes = 0;
            $observedPeakLanes = 0;

            foreach ($laneEvents as $event) {
                $activeLanes += $event['delta'];
                $observedPeakLanes = max($observedPeakLanes, $activeLanes);
            }
            $topology = $scheduler->topologyTelemetry();
            $testAuditPids = array_values(array_map(
                static fn (array $row): int => $row['pid'],
                array_filter(
                    $evidence,
                    static fn (array $row): bool => ($row['task']['kind'] ?? null) === 'test',
                ),
            ));
            sort($testAuditPids, SORT_NUMERIC);
            $expectedExecutorPids = array_keys($executorPids);
            sort($expectedExecutorPids, SORT_NUMERIC);
            $scopeAudits = array_filter(
                $evidence,
                static fn (array $row): bool => ($row['task']['kind'] ?? null) === 'scope',
            );
            $rootAudits = array_values(array_filter(
                $evidence,
                static fn (array $row): bool => ($row['task']['kind'] ?? null) === 'root',
            ));
            $descendantAudits = array_values(array_filter(
                $evidence,
                static fn (array $row): bool => ($row['task']['kind'] ?? null) !== 'root',
            ));
            $descendantPids = array_values(array_unique(array_column($descendantAudits, 'pid')));

            $runtime = $registry->resolveEnvironment();
            $after = $runtime instanceof ApplicationRuntime
                ? nativeInvoiceShelfDatabaseEvidence($runtime->application())
                : null;
            $actual = [
                'files' => count($baseline['files']),
                'cases' => is_array($tests) ? count($tests) : -1,
                'assertions' => $assertions,
                'case_names_sha256' => hash('sha256', json_encode($caseNames, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ];

            nativeInvoiceShelfAssert(
                ($run['status'] ?? null) === 'passed'
                    && ($run['exit_code'] ?? null) === 0
                    && is_array($tests)
                    && array_column($tests, 'status') === array_fill(0, count($tests), 'passed')
                    && $actual['cases'] === $baseline['cases']
                    && $actual['assertions'] === $baseline['assertions']
                    && $actual['case_names_sha256'] === $baseline['case_names_sha256']
                    && $caseSemantics === $detailedBaseline['cases']
                    && $topology['executor_workers'] === $actual['cases']
                    && $topology['forks'] === $topology['executor_workers'] + $topology['scope_workers']
                    && $topology['process_anchors'] === 0
                    && count($evidence) === $topology['forks'] + 1
                    && count($runtimeAuditPids) === $topology['forks'] + 1
                    && count($descendantAudits) === $topology['forks']
                    && count($descendantPids) === $topology['forks']
                    && count($scopeAudits) === $topology['scope_workers']
                    && count($rootAudits) === 1
                    && ($rootAudits[0]['pid'] ?? null) === getmypid()
                    && $testAuditPids === $expectedExecutorPids
                    && $telemetryCases === $actual['cases']
                    && count($executorPids) === $actual['cases']
                    && is_int($observedPeakLanes)
                    && $observedPeakLanes >= 1
                    && $observedPeakLanes <= min($processes, $actual['cases'])
                    && array_all($evidence, static fn (array $row): bool => ($row['classes'] ?? null) === []
                        && ($row['files'] ?? null) === [])
                    && $prepared === [
                        'format' => 'sqlite-logical-v1',
                        'sha256' => NATIVE_INVOICESHELF_DATABASE_SHA256,
                        'tables' => 48,
                        'columns' => 563,
                        'rows' => 161,
                        'migrations' => 155,
                        'addresses' => 0,
                    ]
                    && $prepared === $after
                    && $databaseMutation === [
                        'table' => 'settings',
                        'before_sha256' => NATIVE_INVOICESHELF_DATABASE_SHA256,
                        'mutated_sha256' => NATIVE_INVOICESHELF_MUTATED_DATABASE_SHA256,
                        'restored_sha256' => NATIVE_INVOICESHELF_DATABASE_SHA256,
                        'detected' => true,
                        'rollback_restored' => true,
                    ],
                'DROVE_NATIVE_INVOICESHELF_PARITY_DIVERGED:'.json_encode([
                    'actual' => $actual,
                    'expected' => [
                        'cases' => $baseline['cases'],
                        'assertions' => $baseline['assertions'],
                        'case_names_sha256' => $baseline['case_names_sha256'],
                    ],
                    'status' => $run['status'] ?? null,
                    'exit_code' => $run['exit_code'] ?? null,
                    'failures' => array_slice(array_values(array_map(
                        static fn (array $test): array => [
                            'id' => $test['id'] ?? null,
                            'status' => $test['status'] ?? null,
                            'failure' => $test['failure'] ?? null,
                        ],
                        array_filter(
                            is_array($tests) ? $tests : [],
                            static fn (array $test): bool => ($test['status'] ?? null) !== 'passed',
                        ),
                    )), 0, 5),
                    'evidence_count' => count($evidence),
                    'topology' => $topology,
                    'telemetry_cases' => $telemetryCases,
                    'unique_executor_pids' => count($executorPids),
                    'observed_peak_lanes' => $observedPeakLanes,
                    'forbidden_evidence' => array_slice(array_values(array_filter(
                        $evidence,
                        static fn (array $row): bool => ($row['classes'] ?? []) !== []
                            || ($row['files'] ?? []) !== [],
                    )), 0, 3),
                    'prepared' => $prepared,
                    'after' => $after,
                    'database_mutation' => $databaseMutation,
                    'case_semantics_sha256' => hash(
                        'sha256',
                        json_encode($caseSemantics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ),
                    'expected_case_semantics_sha256' => $detailedBaseline['case_semantics_sha256'],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
            $verificationNs = hrtime(true) - $verificationStartedNs;
            $phasesMs = [
                'preparation' => round($phaseClock['preparation_ns'] / 1_000_000, 3),
                'planning' => round($phaseClock['planning_ns'] / 1_000_000, 3),
                'execution' => round($executionNs / 1_000_000, 3),
                'verification' => round($verificationNs / 1_000_000, 3),
            ];
            nativeInvoiceShelfAssert(
                array_all($phasesMs, static fn (float $milliseconds): bool => $milliseconds >= 0),
                'DROVE_NATIVE_INVOICESHELF_PHASE_TELEMETRY_INVALID',
            );

            echo json_encode([
                'ok' => true,
                'corpus' => $baseline['corpus'],
                'commit' => NATIVE_INVOICESHELF_COMMIT,
                'selection' => [
                    'files' => NATIVE_INVOICESHELF_SELECTION_FILES,
                    'cases' => NATIVE_INVOICESHELF_SELECTION_CASES,
                    'assertions' => NATIVE_INVOICESHELF_SELECTION_ASSERTIONS,
                ],
                'native' => $actual,
                'case_semantics' => [
                    'sha256' => hash(
                        'sha256',
                        json_encode($caseSemantics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ),
                    'cases' => $caseSemantics,
                ],
                'processes' => $processes,
                'phases_ms' => $phasesMs,
                'scheduler' => [
                    'requested_processes' => $processes,
                    'runnable_cases' => $actual['cases'],
                    'telemetry_cases' => $telemetryCases,
                    'unique_executor_pids' => count($executorPids),
                    'one_child_pid_per_case' => true,
                    'observed_peak_lanes' => $observedPeakLanes,
                    'topology' => $topology,
                ],
                'descendant_checks' => count($descendantAudits),
                'distinct_descendant_pids' => count($descendantPids),
                'runtime_audits' => [
                    'checks' => count($evidence),
                    'unique_pids' => count($runtimeAuditPids),
                    'test_workers' => count($testAuditPids),
                    'scope_workers' => count($scopeAudits),
                    'root_workers' => count($rootAudits),
                ],
                'prepared_database' => $prepared,
                'database_preparation_clock' => NATIVE_INVOICESHELF_PREPARATION_TIME.' UTC',
                'database_digest_mutation' => $databaseMutation,
                'source_identity_sha256' => hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'blocked_files' => $blocked,
                'external_test_runtime' => [
                    'pest' => false,
                    'phpunit' => false,
                    'testbench' => false,
                    'project_base_test_case' => false,
                ],
                'application_bootstrap' => 'pinned InvoiceShelf bootstrap/app.php via ApplicationRuntime::fromApplication',
                'provider_manifest_preflight' => 'not claimed: the pinned app intentionally keeps Composer package discovery enabled',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        } finally {
            putenv('VIEW_COMPILED_PATH');
            unset($_ENV['VIEW_COMPILED_PATH'], $_SERVER['VIEW_COMPILED_PATH']);

            if (is_file($evidenceFile)) {
                unlink($evidenceFile);
            }
            if (is_dir($stage)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );

                foreach ($iterator as $item) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }

                rmdir($stage);
            }
        }
    })($argv);
} catch (Throwable $failure) {
    $diagnostic = json_encode([
        'exception' => $failure::class,
        'message' => $failure->getMessage(),
    ], JSON_UNESCAPED_SLASHES);

    fwrite(
        STDERR,
        'DROVE_NATIVE_INVOICESHELF_PROOF_FAILED:'.(
            is_string($diagnostic) ? $diagnostic : $failure::class
        ).PHP_EOL,
    );
    exit(1);
}
