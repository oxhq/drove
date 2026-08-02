<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Drove\Kernel\DroverScheduler;
use Drove\Laravel\ApplicationRuntime;
use Drove\Laravel\DroveLaravelServiceProvider;
use Drove\Laravel\PackageApplicationRuntime;
use Drove\Laravel\Proof\PinnedGitCheckout;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Migration\ClassMigrator;
use Drove\Native\ClassFrontend;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Illuminate\Foundation\Application;
use Livewire\LivewireServiceProvider;

use function Drove\Native\environment;

const LIVEWIRE_COHORT_COMMIT = '9c1450739d30c9b0b223ad6512be2a33f8f62f96';

function cohortAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param  array<string, mixed>  $task
 * @return array{
 *     task_id: string,
 *     task_kind: 'root'|'scope'|'test',
 *     task_scope_id: string,
 *     task_scopes: list<string>,
 *     classes: list<string>,
 *     files: list<string>,
 *     pid: int
 * }
 */
function cohortRuntimeEvidence(array $task, ?string $file = null): array
{
    $taskId = $task['id'] ?? null;
    $taskKind = $task['kind'] ?? null;
    $taskScopeId = $task['scope_id'] ?? null;
    $taskScopes = $task['scopes'] ?? null;
    cohortAssert(
        is_string($taskId)
            && in_array($taskKind, ['root', 'scope', 'test'], true)
            && is_string($taskScopeId)
            && is_array($taskScopes)
            && array_is_list($taskScopes)
            && array_all($taskScopes, static fn (mixed $scope): bool => is_string($scope)),
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
        array_map(
            static fn (string $path): string => str_replace('\\', '/', $path),
            get_included_files(),
        ),
        static fn (string $path): bool => preg_match(
            '~/(?:pestphp|phpunit|orchestra/testbench[^/]*)/~i',
            $path,
        ) === 1
            || str_contains($path, '/src/Drove/Bridge/')
            || str_contains($path, '/src/Drove/Pest/')
            || str_ends_with($path, '/src/TestbenchBridge.php'),
    ));
    $pid = getmypid();

    if (! is_int($pid)) {
        throw new RuntimeException('Could not resolve the native Livewire descendant PID.');
    }

    $evidence = [
        'task_id' => $taskId,
        'task_kind' => $taskKind,
        'task_scope_id' => $taskScopeId,
        'task_scopes' => $taskScopes,
        'classes' => $classes,
        'files' => $files,
        'pid' => $pid,
    ];

    if ($classes !== [] || $files !== []) {
        throw new RuntimeException('DROVE_NATIVE_LIVEWIRE_EXTERNAL_TEST_RUNTIME_LOADED');
    }

    if ($file !== null) {
        $record = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if (file_put_contents($file, $record, FILE_APPEND | LOCK_EX) !== strlen($record)) {
            throw new RuntimeException('Unable to record native Livewire descendant evidence.');
        }
    }

    return $evidence;
}

function cohortSaturationBarrier(string $file, int $expected): void
{
    $pid = getmypid();
    cohortAssert(is_int($pid), 'Could not resolve the native Livewire barrier PID.');
    $record = $pid.PHP_EOL;
    cohortAssert(
        file_put_contents($file, $record, FILE_APPEND | LOCK_EX) === strlen($record),
        'Could not enter the native Livewire saturation barrier.',
    );
    $deadline = hrtime(true) + 30_000_000_000;

    do {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $participants = is_array($lines)
            ? array_values(array_unique(array_map('intval', $lines)))
            : [];

        if (count($participants) >= $expected) {
            return;
        }

        usleep(1_000);
    } while (hrtime(true) < $deadline);

    throw new RuntimeException(sprintf(
        'Native Livewire saturation barrier reached %d of %d requested lanes.',
        count($participants),
        $expected,
    ));
}

function cohortRemoveTree(string $path): void
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

/** @param list<array<string, mixed>> $tests */
function cohortObservedLanes(array $tests): int
{
    $events = [];

    foreach ($tests as $test) {
        $telemetry = $test['telemetry'] ?? null;
        $started = is_array($telemetry) ? ($telemetry['started_ns'] ?? null) : null;
        $finished = is_array($telemetry) ? ($telemetry['finished_ns'] ?? null) : null;
        cohortAssert(
            is_int($started) && is_int($finished) && $finished >= $started,
            'Native Livewire emitted invalid executor timing telemetry.',
        );
        $events[] = [$started, 1];
        $events[] = [$finished, -1];
    }

    usort(
        $events,
        static fn (array $left, array $right): int => $left[0] <=> $right[0]
            ?: $left[1] <=> $right[1],
    );
    $active = 0;
    $peak = 0;

    foreach ($events as [, $delta]) {
        $active += $delta;
        $peak = max($peak, $active);
    }

    return $peak;
}

$proofStartedNs = hrtime(true);
$vendor = getenv('DROVE_NATIVE_VENDOR');

if (! is_string($vendor) || $vendor === '' || ! is_file($vendor.'/autoload.php')) {
    fwrite(STDERR, "DROVE_NATIVE_VENDOR must identify the clean installed vendor.\n");
    exit(1);
}

require $vendor.'/autoload.php';
require __DIR__.'/PinnedGitCheckout.php';

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:Hupx3yAySikrM2/edkZQNQHslgDWYfiBfCuSThJ5SK8=',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$processes = (int) (getenv('DROVE_NATIVE_LIVEWIRE_PROCESSES') ?: 8);
$token = bin2hex(random_bytes(6));
$stage = sys_get_temp_dir().'/drove-native-livewire-'.$token;
$evidenceFile = sys_get_temp_dir().'/drove-native-livewire-'.$token.'.jsonl';
$barrierFile = sys_get_temp_dir().'/drove-native-livewire-'.$token.'.barrier';
$baseline = json_decode(
    (string) file_get_contents(__DIR__.'/livewire-parallel-baseline.json'),
    true,
    8,
    JSON_THROW_ON_ERROR,
);
$profilePath = __DIR__.'/livewire-class-profile.php';
$profile = require $profilePath;
$profileHash = hash_file('sha256', $profilePath);

$exitCode = 0;
/** @var ArrayObject<string, int|float> $phaseClock */
$phaseClock = new ArrayObject([
    'preparation_ns' => 0,
    'planning_ns' => 0,
    'planning_started_ns' => -1,
    'execution_started_ns' => -1,
]);

try {
    $checkout = PinnedGitCheckout::fromEnvironment(
        'DROVE_NATIVE_LIVEWIRE_CHECKOUT',
        LIVEWIRE_COHORT_COMMIT,
    );
    $sourceMtimeEpoch = $baseline['staging_source_mtime_epoch'] ?? null;
    cohortAssert($processes >= 1 && $processes <= 30, 'Native Livewire processes must be C1-C30.');
    cohortAssert(($baseline['commit'] ?? null) === LIVEWIRE_COHORT_COMMIT, 'The Livewire baseline commit drifted.');
    cohortAssert(
        is_int($sourceMtimeEpoch) && $sourceMtimeEpoch === $checkout->commitTimestamp(),
        'The Livewire staging clock does not match the pinned commit timestamp.',
    );
    cohortAssert(
        is_array($profile)
            && array_keys($profile) === [
                'method_receivers',
                'property_replacements',
                'parent_receivers',
                'fluent_assertions',
            ]
            && is_string($profileHash)
            && $profileHash === ($baseline['migration_profile_sha256'] ?? null),
        'The pinned Livewire class migration profile is invalid or drifted.',
    );
    cohortAssert(mkdir($stage, 0700), 'The native Livewire stage could not be created.');

    $livewire = InstalledVersions::getInstallPath('livewire/livewire');
    $installedCommit = InstalledVersions::getReference('livewire/livewire');
    cohortAssert(is_string($livewire), 'The pinned Livewire package is not installed.');
    cohortAssert($installedCommit === LIVEWIRE_COHORT_COMMIT, 'The installed Livewire commit drifted.');

    $externalPackages = array_values(array_filter(
        InstalledVersions::getInstalledPackages(),
        static fn (string $package): bool => is_string(InstalledVersions::getInstallPath($package))
            && preg_match('~\A(?:pestphp/|phpunit/|orchestra/testbench)~i', $package) === 1,
    ));
    cohortAssert($externalPackages === [], 'The clean package environment installed an external test runtime.');

    $migrator = new ClassMigrator;
    $staged = [];
    $files = $baseline['files'] ?? null;
    cohortAssert(is_array($files) && count($files) === 7, 'The native Livewire file cohort drifted.');

    foreach ($files as $relative => $expectedBlob) {
        cohortAssert(is_string($relative) && is_string($expectedBlob), 'The Livewire baseline contains an invalid file entry.');
        $source = $checkout->source($relative, $expectedBlob);

        $migration = $migrator->migrate($source, $relative, 'Tests\\TestCase', $profile);
        cohortAssert($migration->blockers === [], 'Native migration blocked '.$relative.'.');
        $secondPass = $migrator->migrate($migration->source, $relative, 'Tests\\TestCase', $profile);
        cohortAssert(
            $secondPass->source === $migration->source
                && $secondPass->applied === []
                && $secondPass->blockers === [],
            'Native migration is not idempotent for '.$relative.'.',
        );
        cohortAssert(
            preg_match('~(?:Pest\\\\|PHPUnit\\\\|Orchestra\\\\Testbench|Tests\\\\TestCase|extends\\s+\\\\?TestCase)~', $migration->source) !== 1,
            'Migrated source retained an external test runtime reference in '.$relative.'.',
        );

        $target = $stage.'/'.$relative;
        $directory = dirname($target);
        cohortAssert(is_dir($directory) || mkdir($directory, 0700, true), 'Could not create '.$directory.'.');
        cohortAssert(file_put_contents($target, $migration->source) === strlen($migration->source), 'Could not stage '.$relative.'.');
        cohortAssert(touch($target, $sourceMtimeEpoch), 'Could not normalize the staging clock for '.$relative.'.');
        clearstatcache(true, $target);
        cohortAssert(filemtime($target) === $sourceMtimeEpoch, 'The staging clock drifted for '.$relative.'.');
        $staged[] = $target;
    }

    foreach ($staged as $source) {
        require $source;
    }

    $applicationPath = __DIR__.'/application';
    $declared = 0;
    $preparedRoutes = -1;
    $registry = Declarations::capture(
        static function () use (
            $applicationPath,
            &$declared,
            $barrierFile,
            $processes,
            $staged,
            &$preparedRoutes,
            $phaseClock,
        ): void {
            environment(
                'laravel-livewire-native-cohort',
                static function () use (
                    $applicationPath,
                    &$preparedRoutes,
                    $phaseClock,
                ): ApplicationRuntime {
                    $planningStartedNs = $phaseClock['planning_started_ns'];

                    if ($planningStartedNs < 0) {
                        throw new RuntimeException('DROVE_NATIVE_LIVEWIRE_COHORT_PLANNING_CLOCK_MISSING');
                    }

                    $environmentStartedNs = hrtime(true);
                    $phaseClock['planning_ns'] = $environmentStartedNs - $planningStartedNs;
                    $runtime = PackageApplicationRuntime::boot(
                        $applicationPath,
                        new InMemorySqliteDatabaseStateAdapter('testbench', true),
                        [
                            DroveLaravelServiceProvider::class,
                            LivewireServiceProvider::class,
                        ],
                        static function (Application $application): void {
                            $application->make('config')->set('database.default', 'testbench');
                            $database = $application->make('db')->connection('testbench');
                            $database->statement(
                                'CREATE TABLE native_livewire_rows ('
                                .'id INTEGER PRIMARY KEY, value TEXT NOT NULL)',
                            );
                            $database->table('native_livewire_rows')->insert([
                                'id' => 1,
                                'value' => 'prepared',
                            ]);
                        },
                    );
                    $application = $runtime->scopeContext()->app();

                    if (! $application instanceof Application) {
                        throw new RuntimeException('Native Livewire application runtime did not boot.');
                    }

                    $preparedRoutes = $application->make('router')->getRoutes()->count();

                    if (getenv('DROVE_NATIVE_PACKAGE_FAULT_AFTER_BOOT') === '1') {
                        throw new RuntimeException('DROVE_NATIVE_PACKAGE_INJECTED_POST_BOOT_FAULT');
                    }

                    $environmentPreparedNs = hrtime(true);
                    $phaseClock['preparation_ns'] += $environmentPreparedNs - $environmentStartedNs;
                    $phaseClock['execution_started_ns'] = $environmentPreparedNs;

                    return $runtime;
                },
            );

            foreach ($staged as $source) {
                Declarations::current()->declareHook(
                    'before_each',
                    static fn () => cohortSaturationBarrier($barrierFile, $processes),
                    $source,
                    1,
                );
            }

            $declared = (new ClassFrontend)->declareFiles($staged);
        },
        $stage,
        'Pinned Livewire native package cohort',
    );
    $scheduler = new DroverScheduler('native-livewire-'.$token, $processes);
    $runner = new Runner(
        $scheduler,
        static function (array $task) use ($evidenceFile): void {
            cohortRuntimeEvidence($task, $evidenceFile);
        },
    );
    $planningStartedNs = hrtime(true);
    $phaseClock['planning_started_ns'] = $planningStartedNs;
    $phaseClock['preparation_ns'] += $planningStartedNs - $proofStartedNs;
    $run = $runner->run($registry);
    $runnerFinishedNs = hrtime(true);
    $executionStartedNs = $phaseClock['execution_started_ns'];

    if ($executionStartedNs < 0 || $executionStartedNs > $runnerFinishedNs) {
        throw new RuntimeException('DROVE_NATIVE_LIVEWIRE_COHORT_EXECUTION_CLOCK_MISSING');
    }

    $executionNs = $runnerFinishedNs - $executionStartedNs;
    $verificationStartedNs = $runnerFinishedNs;
    $runtime = $registry->resolveEnvironment();
    $application = $runtime?->scopeContext()->app();

    if (! $application instanceof Application) {
        throw new RuntimeException('Native Livewire application runtime did not boot.');
    }

    $rootRows = $application->make('db')->connection('testbench')
        ->table('native_livewire_rows')
        ->pluck('value')
        ->all();
    $rootRoutes = $application->make('router')->getRoutes()->count();
    $lines = is_file($evidenceFile)
        ? file($evidenceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : false;
    $evidence = is_array($lines)
        ? array_map(
            static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR),
            $lines,
        )
        : [];
    $tests = $run['tests'] ?? null;
    cohortAssert(is_array($tests), 'Native Livewire execution did not return test results.');
    $barrierLines = is_file($barrierFile)
        ? file($barrierFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : false;
    $barrierPids = is_array($barrierLines)
        ? array_values(array_unique(array_map('intval', $barrierLines)))
        : [];

    $actual = array_map(
        static fn (array $test): array => [
            'id' => $test['id'] ?? null,
            'status' => $test['status'] ?? null,
            'assertions' => $test['assertions'] ?? null,
        ],
        $tests,
    );
    $expected = $baseline['cases'] ?? null;
    cohortAssert(is_array($expected), 'The Livewire case baseline is invalid.');
    usort($actual, static fn (array $left, array $right): int => ($left['id'] ?? '') <=> ($right['id'] ?? ''));
    usort($expected, static fn (array $left, array $right): int => ($left['id'] ?? '') <=> ($right['id'] ?? ''));
    $semanticHash = hash('sha256', json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $expectedSemanticHash = hash('sha256', json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $testPids = array_map(
        static fn (array $test): mixed => $test['telemetry']['pid'] ?? null,
        $tests,
    );
    $topology = $scheduler->topologyTelemetry();
    $observedLanes = cohortObservedLanes($tests);
    $testEvidence = [];
    $rootEvidence = [];

    foreach ($evidence as $row) {
        $kind = $row['task_kind'] ?? null;
        $id = $row['task_id'] ?? null;
        cohortAssert(
            in_array($kind, ['root', 'scope', 'test'], true) && is_string($id),
            'Native Livewire emitted an invalid runtime-audit identity.',
        );

        if ($kind === 'root') {
            $rootEvidence[] = $row;

            continue;
        }

        cohortAssert($kind === 'test', 'Native Livewire unexpectedly dispatched a scope worker.');
        cohortAssert(! isset($testEvidence[$id]), 'Native Livewire duplicated a runtime-audit task row.');
        $testEvidence[$id] = $row;
    }

    cohortAssert(($run['status'] ?? null) === 'passed' && ($run['exit_code'] ?? null) === 0, 'Native Livewire execution failed.');
    cohortAssert($declared === 7, 'The native class frontend did not declare seven Livewire classes.');
    cohortAssert($actual === $expected, 'Native Livewire IDs, statuses, or assertion counts diverged.');
    cohortAssert($semanticHash === $expectedSemanticHash, 'Native Livewire semantic hash diverged.');
    cohortAssert(array_sum(array_column($tests, 'assertions')) === 68, 'Native Livewire lost the 68-assertion baseline.');
    cohortAssert($rootRows === ['prepared'], 'Native Livewire mutated the prepared root database.');
    cohortAssert(
        is_int($preparedRoutes) && $rootRoutes === $preparedRoutes,
        sprintf('Native Livewire mutated the prepared root routes (%s -> %s).', $preparedRoutes, $rootRoutes),
    );
    cohortAssert(count($evidence) === 37, 'Native Livewire did not audit every task and the root exactly once.');
    cohortAssert(count($testEvidence) === 36 && count($rootEvidence) === 1, 'Native Livewire runtime-audit task kinds drifted.');
    cohortAssert(count(array_unique(array_column($testEvidence, 'pid'))) === 36, 'Native Livewire reused a child process across tests.');
    cohortAssert(
        array_all($testPids, static fn (mixed $pid): bool => is_int($pid))
            && count(array_unique($testPids)) === 36,
        'Native Livewire batched test bodies.',
    );
    foreach ($tests as $test) {
        $id = $test['id'] ?? null;
        $row = is_string($id) ? ($testEvidence[$id] ?? null) : null;
        cohortAssert(
            is_array($row)
                && ($row['task_kind'] ?? null) === 'test'
                && ($row['pid'] ?? null) === ($test['telemetry']['pid'] ?? null)
                && ($row['task_scope_id'] ?? null) === ($test['scope_id'] ?? null)
                && ($row['task_scopes'] ?? null) === ($test['scopes'] ?? null),
            'Native Livewire runtime audit did not bind an exact scheduler task identity to telemetry.',
        );
    }
    sort($barrierPids, SORT_NUMERIC);
    $sortedTestPids = $testPids;
    sort($sortedTestPids, SORT_NUMERIC);
    cohortAssert(
        count($barrierPids) === 36 && $barrierPids === $sortedTestPids,
        'Native Livewire saturation barrier did not cover every executor PID.',
    );
    $expectedTopology = [
        'schema' => 1,
        'forks' => 36,
        'scope_workers' => 0,
        'executor_workers' => 36,
        'process_anchors' => 0,
        'peak_live_pids' => min(2 * $processes, 36),
        'peak_outstanding_tasks' => min(2 * $processes, 36),
        'outstanding_task_limit' => 2 * $processes,
    ];
    cohortAssert(
        $topology === $expectedTopology,
        'Native Livewire violated the one-fork-per-case topology: '.json_encode(
            ['actual' => $topology, 'expected' => $expectedTopology],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
    );
    cohortAssert(
        $observedLanes === $processes,
        'Native Livewire did not saturate every requested lane.',
    );
    $rootEvidenceRow = $rootEvidence[0];
    cohortAssert(
        ($rootEvidenceRow['task_id'] ?? null) === 'suite:root'
            && ($rootEvidenceRow['task_scope_id'] ?? null) === 'suite:root'
            && ($rootEvidenceRow['task_scopes'] ?? null) === ['suite:root']
            && ($rootEvidenceRow['pid'] ?? null) === getmypid()
            && ! in_array($rootEvidenceRow['pid'], $testPids, true),
        'Native Livewire root runtime audit was not bound to the root process.',
    );
    cohortAssert($rootEvidenceRow['classes'] === [] && $rootEvidenceRow['files'] === [], 'The root loaded an external test runtime.');
    $checkout->assertExactAndClean();
    $verificationNs = hrtime(true) - $verificationStartedNs;
    $phasesMs = [
        'preparation' => round($phaseClock['preparation_ns'] / 1_000_000, 3),
        'planning' => round($phaseClock['planning_ns'] / 1_000_000, 3),
        'execution' => round($executionNs / 1_000_000, 3),
        'verification' => round($verificationNs / 1_000_000, 3),
    ];
    cohortAssert(
        array_all($phasesMs, static fn (float $milliseconds): bool => $milliseconds >= 0),
        'DROVE_NATIVE_LIVEWIRE_COHORT_PHASE_TELEMETRY_INVALID',
    );

    echo json_encode([
        'ok' => true,
        'corpus' => 'livewire/livewire',
        'commit' => $installedCommit,
        'source_mode' => 'read-only-git-checkout',
        'source_blobs' => count($files),
        'source_migrated' => count($staged),
        'migration_profile_sha256' => $profileHash,
        'staging_source_mtime_epoch' => $sourceMtimeEpoch,
        'classes' => $declared,
        'cases' => count($tests),
        'assertions' => array_sum(array_column($tests, 'assertions')),
        'processes' => $processes,
        'phases_ms' => $phasesMs,
        'observed_lanes' => $observedLanes,
        'child_pids' => count(array_unique($testPids)),
        'forks' => $topology['forks'],
        'runtime_audit' => [
            'rows' => count($evidence),
            'test_tasks' => count($testEvidence),
            'root_tasks' => count($rootEvidence),
            'task_ids_bound_to_telemetry' => count($testEvidence),
        ],
        'saturation_barrier' => [
            'requested_lanes' => $processes,
            'executor_pids' => count($barrierPids),
            'pid_set_matches_telemetry' => true,
        ],
        'topology' => $topology,
        'semantic_hash' => $semanticHash,
        'prepared_root_rows' => $rootRows,
        'prepared_root_routes' => $rootRoutes,
        'external_test_runtime_packages' => count($externalPackages),
        'external_test_runtime_files' => 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, 'DROVE_NATIVE_LIVEWIRE_COHORT_FAILED '.$throwable::class.': '.$throwable->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    cohortRemoveTree($stage);

    if (is_file($evidenceFile)) {
        unlink($evidenceFile);
    }

    if (is_file($barrierFile)) {
        unlink($barrierFile);
    }
}

exit($exitCode);
