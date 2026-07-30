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
    $processes = filter_var(
        getenv('DROVE_NATIVE_PROCESSES'),
        FILTER_VALIDATE_INT,
        FILTER_NULL_ON_FAILURE,
    );

    if (! in_array($provider, ['sqlite-memory', 'sqlite-copy'], true)
        || ! in_array($processes, [1, 2, 4, 8, 16, 30], true)) {
        throw new RuntimeException(
            'DROVE_LARAVEL_PROVIDER and DROVE_NATIVE_PROCESSES must select a Phase 4 matrix entry.',
        );
    }

    $identity = nativePhaseFourIdentity();
    $gateDirectory = __DIR__;
    $generatedDirectory = $gateDirectory.'/generated';
    $stateDirectory = $gateDirectory.'/state';
    $copyWorkspace = $stateDirectory.'/copies';
    $sourceDatabase = $stateDirectory.'/source.sqlite';
    $lifecycleLog = $stateDirectory.'/lifecycle.jsonl';
    $proofDirectoryName = sprintf('drove-native-phase-four-%s-c%d', $provider, $processes);
    $providerBootDirectory = dirname(__DIR__).'/storage/framework/'.$proofDirectoryName;
    $summary = null;
    $proofStartedNs = hrtime(true);

    nativePhaseFourRemove($generatedDirectory);
    nativePhaseFourRemove($stateDirectory);
    nativePhaseFourRemove($providerBootDirectory);

    try {
        if (! mkdir($generatedDirectory, 0777, true)
            || ! mkdir($copyWorkspace, 0777, true)) {
            throw new RuntimeException('Could not create the native Laravel matrix workspace.');
        }

        if (! touch($sourceDatabase)) {
            throw new RuntimeException('Could not create the native Laravel SQLite source.');
        }

        nativePhaseFourEnvironment([
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $provider === 'sqlite-memory' ? ':memory:' : $sourceDatabase,
            'DROVE_LARAVEL' => '1',
            'DROVE_LARAVEL_PROOF_DIRECTORY' => $proofDirectoryName,
            'DROVE_NATIVE_PHASE4_LOG' => $lifecycleLog,
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ]);

        for ($file = 1; $file <= 30; $file++) {
            $tests = '';

            for ($test = 1; $test <= 10; $test++) {
                $rowId = 10_000 + ($file * 100) + $test;
                $tests .= sprintf(
                    <<<'PHP'

test('case %02d', function (): void {
    $database = $this->app()->make('db')->connection('sqlite');
    expect($database->table('native_phase_four_state')->count())->toBe(2);
    expect($database->table('native_phase_four_state')->where('id', %d)->value('value'))->toBe(%s);
    $database->table('native_phase_four_state')->insert([
        'id' => %d,
        'kind' => 'test',
        'value' => %s,
    ]);
    expect($database->table('native_phase_four_state')->count())->toBe(3);
    $this->defer(function (): void {
        $database = $this->app()->make('db')->connection('sqlite');

        if ($database->table('native_phase_four_state')->count() !== 3
            || $database->table('native_phase_four_state')->where('id', %d)->value('kind') !== 'test') {
            throw new RuntimeException('A native Laravel cleanup observed lost test state.');
        }
    });
    usleep(20_000);
});
PHP,
                    $test,
                    $file,
                    var_export(sprintf('file-%02d', $file), true),
                    $rowId,
                    var_export(sprintf('test-%02d-%02d', $file, $test), true),
                    $rowId,
                );
            }

            $source = sprintf(
                <<<'PHP'
<?php

declare(strict_types=1);

use function Drove\Native\afterAll;
use function Drove\Native\beforeAll;
use function Drove\Native\expect;
use function Drove\Native\test;

beforeAll(function (): void {
    app('db')->connection('sqlite')
        ->table('native_phase_four_state')
        ->insert([
            'id' => %d,
            'kind' => 'file',
            'value' => %s,
        ]);
});

afterAll(function (): void {
    $rows = app('db')->connection('sqlite')
        ->table('native_phase_four_state')
        ->orderBy('id')
        ->get(['id', 'kind', 'value'])
        ->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'kind' => $row->kind,
            'value' => $row->value,
        ])
        ->all();
    $expected = [
        ['id' => 0, 'kind' => 'root', 'value' => 'prepared'],
        ['id' => %d, 'kind' => 'file', 'value' => %s],
    ];

    if ($rows !== $expected) {
        throw new RuntimeException('A native Laravel afterAll observed a sibling test write.');
    }

    $path = getenv('DROVE_NATIVE_PHASE4_LOG');

    if (! is_string($path)
        || file_put_contents(
            $path,
            json_encode(['file' => %d, 'rows' => $rows], JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        ) === false) {
        throw new RuntimeException('Could not record native Laravel afterAll evidence.');
    }
});
%s
PHP,
                $file,
                var_export(sprintf('file-%02d', $file), true),
                $file,
                var_export(sprintf('file-%02d', $file), true),
                $file,
                $tests,
            );
            nativePhaseFourWrite(
                sprintf('%s/File%02dTest.php', $generatedDirectory, $file),
                $source,
            );
        }

        $preparedSourceHash = null;
        $prepareCount = 0;
        $phpunitLoadedBefore = class_exists('PHPUnit\\Framework\\TestCase', false);
        $testbenchLoadedBefore = class_exists('Orchestra\\Testbench\\TestCase', false);
        $inner = $provider === 'sqlite-memory'
            ? new InMemorySqliteDatabaseStateAdapter('sqlite', true)
            : new SqliteCopyDatabaseStateAdapter('sqlite', $copyWorkspace, true);
        $state = new NativePhaseFourStateProvider($inner);
        $planningStartedNs = hrtime(true);
        $registry = Declarations::capture(
            static function () use (
                &$prepareCount,
                &$preparedSourceHash,
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
                        &$prepareCount,
                        &$preparedSourceHash,
                        $provider,
                        $sourceDatabase,
                    ): void {
                        $prepareCount++;
                        $database = $application->make('db')->connection('sqlite');
                        $database->statement(
                            'CREATE TABLE native_phase_four_state ('
                            .'id INTEGER PRIMARY KEY, '
                            .'kind TEXT NOT NULL, '
                            .'value TEXT NOT NULL'
                            .')',
                        );
                        $database->table('native_phase_four_state')->insert([
                            'id' => 0,
                            'kind' => 'root',
                            'value' => 'prepared',
                        ]);

                        if ($provider === 'sqlite-copy') {
                            $preparedSourceHash = hash_file('sha256', $sourceDatabase);
                        }
                    },
                );

                foreach (glob($generatedDirectory.'/*.php') ?: [] as $file) {
                    require $file;
                }
            },
            $gateDirectory,
            'Drove native Laravel Phase 4',
        );
        $plan = $registry->plan();
        $planningMs = round((hrtime(true) - $planningStartedNs) / 1_000_000, 3);
        $executionStartedNs = hrtime(true);
        $run = new Runner(new DroverScheduler(
            sprintf('native-laravel-%s-c%d-%s', $provider, $processes, bin2hex(random_bytes(6))),
            $processes,
        ))->run($registry);
        $executionMs = round((hrtime(true) - $executionStartedNs) / 1_000_000, 3);
        $tests = $run['tests'] ?? null;
        $phpunitLoadedAfter = class_exists('PHPUnit\\Framework\\TestCase', false);
        $testbenchLoadedAfter = class_exists('Orchestra\\Testbench\\TestCase', false);

        if (! is_array($tests)
            || ! array_is_list($tests)
            || ($run['status'] ?? null) !== 'passed'
            || ($run['exit_code'] ?? null) !== 0
            || count($tests) !== 300
            || $phpunitLoadedBefore
            || $testbenchLoadedBefore
            || $phpunitLoadedAfter
            || $testbenchLoadedAfter) {
            throw new RuntimeException('Native Laravel did not return 300 passing terminal results.');
        }

        $assertions = array_sum(array_column($tests, 'assertions'));
        $cleanups = array_sum(array_column($tests, 'cleanups'));
        $executorPids = array_values(array_unique(array_map(
            static fn (array $test): mixed => $test['telemetry']['pid'] ?? null,
            $tests,
        ), SORT_REGULAR));

        if (! array_all(
            $tests,
            static fn (array $test): bool => ($test['status'] ?? null) === 'passed'
                && ($test['assertions'] ?? null) === 3
                && ($test['cleanups'] ?? null) === 1,
        )
            || $assertions !== 900
            || $cleanups !== 300
            || count($executorPids) !== 300
            || in_array(null, $executorPids, true)
            || ($run['observed_concurrency']['global'] ?? null) !== $processes) {
            throw new RuntimeException('Native Laravel assertions, cleanup, PIDs, or lanes diverged.');
        }

        $lifecycleLines = file($lifecycleLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lifecycle = is_array($lifecycleLines)
            ? array_map(
                static fn (string $line): array => json_decode(
                    $line,
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                ),
                $lifecycleLines,
            )
            : [];
        usort(
            $lifecycle,
            static fn (array $left, array $right): int => $left['file'] <=> $right['file'],
        );

        if (count($lifecycle) !== 30
            || array_column($lifecycle, 'file') !== range(1, 30)
            || ! array_all(
                $lifecycle,
                static fn (array $entry): bool => count($entry['rows'] ?? []) === 2,
            )) {
            throw new RuntimeException('Native Laravel afterAll state evidence diverged.');
        }

        $bootLog = $providerBootDirectory.'/boot-pids.log';
        $bootPids = file($bootLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $bootPids = is_array($bootPids)
            ? array_values(array_map('intval', $bootPids))
            : [];

        if ($prepareCount !== 1
            || $state->bootCount !== 1
            || $bootPids !== [getmypid()]) {
            throw new RuntimeException('Native Laravel application, prepare, or adapter booted more than once.');
        }

        $sourceHashAfter = $provider === 'sqlite-copy'
            ? hash_file('sha256', $sourceDatabase)
            : null;

        if ($provider === 'sqlite-copy'
            && (! is_string($preparedSourceHash)
                || ! is_string($sourceHashAfter)
                || ! hash_equals($preparedSourceHash, $sourceHashAfter))) {
            throw new RuntimeException('Native Laravel mutated its prepared SQLite source database.');
        }

        $sqliteFiles = array_values(array_filter(
            nativePhaseFourFiles($stateDirectory),
            static fn (string $path): bool => $path !== str_replace('\\', '/', $sourceDatabase)
                && $path !== str_replace('\\', '/', $lifecycleLog),
        ));
        $workspaceEntries = glob($copyWorkspace.'/*');

        if ($workspaceEntries === false) {
            throw new RuntimeException('Could not scan native Laravel SQLite artifacts.');
        }

        $sqliteArtifacts = array_unique([
            ...$sqliteFiles,
            ...array_map(
                static fn (string $path): string => str_replace('\\', '/', $path),
                $workspaceEntries,
            ),
        ]);

        if ($sqliteArtifacts !== []) {
            throw new RuntimeException('Native Laravel left SQLite copy or sidecar artifacts.');
        }

        $semanticProjection = array_map(
            static fn (array $test): array => [
                'id' => $test['id'] ?? null,
                'name' => $test['name'] ?? null,
                'status' => $test['status'] ?? null,
                'assertions' => $test['assertions'] ?? null,
                'cleanups' => $test['cleanups'] ?? null,
            ],
            $tests,
        );
        $executorMemory = array_values(array_filter(
            array_map(
                static fn (array $test): mixed => $test['telemetry']['memory_peak_bytes'] ?? null,
                $tests,
            ),
            'is_int',
        ));
        $summary = [
            'schema' => 1,
            'ok' => true,
            ...$identity,
            'provider' => $provider,
            'scheduler' => 'drover',
            'processes' => $processes,
            'lanes_requested' => $processes,
            'lanes_observed' => $run['observed_concurrency']['global'],
            'file_count' => 30,
            'test_count' => 300,
            'status_counts' => ['passed' => 300],
            'assertion_count' => $assertions,
            'cleanup_count' => $cleanups,
            'unique_test_pid_count' => count($executorPids),
            'test_pids' => $executorPids,
            'application_boot_count' => count($bootPids),
            'prepare_count' => $prepareCount,
            'adapter_boot_count' => $state->bootCount,
            'phpunit_loaded' => $phpunitLoadedAfter,
            'testbench_loaded' => $testbenchLoadedAfter,
            'after_all_count' => count($lifecycle),
            'after_all_prepared_only_checked' => true,
            'source_hash_before' => $preparedSourceHash,
            'source_hash_after' => $sourceHashAfter,
            'source_hash_unchanged' => $provider === 'sqlite-memory'
                || hash_equals($preparedSourceHash, $sourceHashAfter),
            'sqlite_artifact_count' => count($sqliteArtifacts),
            'planning_ms' => $planningMs,
            'execution_ms' => $executionMs,
            'wall_ms' => $run['duration_ms'],
            'proof_wall_ms' => round((hrtime(true) - $proofStartedNs) / 1_000_000, 3),
            'parent_peak_memory_bytes' => memory_get_peak_usage(true),
            'executor_memory_samples' => count($executorMemory),
            'executor_peak_memory_bytes' => max($executorMemory),
            'plan_hash' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)),
            'case_id_hash' => hash(
                'sha256',
                json_encode(array_column($semanticProjection, 'id'), JSON_THROW_ON_ERROR),
            ),
            'semantic_hash' => hash(
                'sha256',
                json_encode($semanticProjection, JSON_THROW_ON_ERROR),
            ),
            'assertion_hash' => hash(
                'sha256',
                json_encode(array_column($semanticProjection, 'assertions'), JSON_THROW_ON_ERROR),
            ),
            'after_all_hash' => hash(
                'sha256',
                json_encode($lifecycle, JSON_THROW_ON_ERROR),
            ),
        ];
    } finally {
        nativePhaseFourRemove($generatedDirectory);
        nativePhaseFourRemove($stateDirectory);
        nativePhaseFourRemove($providerBootDirectory);
    }

    if (! is_array($summary)
        || nativePhaseFourFiles($generatedDirectory) !== []
        || nativePhaseFourFiles($stateDirectory) !== []) {
        throw new RuntimeException('Native Laravel proof cleanup did not finish.');
    }

    $summary['generated_artifact_count'] = 0;
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
