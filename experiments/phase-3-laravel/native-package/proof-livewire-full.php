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
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Routing\RouteCollection;
use Livewire\LivewireServiceProvider;

use function Drove\Laravel\Proof\livewirePackageContext;
use function Drove\Native\environment;

const LIVEWIRE_FULL_COMMIT = '9c1450739d30c9b0b223ad6512be2a33f8f62f96';

function fullAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function fullGitBlob(string $source): string
{
    return sha1('blob '.strlen($source)."\0".$source);
}

function fullPinnedSource(PinnedGitCheckout $checkout, string $path, string $blob): string
{
    return $checkout->source($path, $blob);
}

function fullTransformMakesAssertions(string $source): string
{
    $originalBlob = '8d9e62e4acd9f03f7f99c0ca9f0eca329cd66069';
    $original = 'use PHPUnit\\Framework\\Assert as PHPUnit;';
    $replacement = 'use Drove\\Native\\Assert as PHPUnit;';

    if (fullGitBlob($source) === $originalBlob) {
        fullAssert(substr_count($source, $original) === 1, 'Livewire MakesAssertions transform anchor drifted.');

        return str_replace($original, $replacement, $source);
    }

    $restored = str_replace($replacement, $original, $source, $count);
    fullAssert($count === 1 && fullGitBlob($restored) === $originalBlob, 'Livewire MakesAssertions transform is not exact or idempotent.');

    return $source;
}

function fullTransformRequestBroker(string $source): string
{
    $originalBlob = 'bee4594d3cee1175c645d82d7819306b88a4a6d0';
    $anchor = "\n}\n";
    $replacement = <<<'PHP'

    protected function createTestResponse($response, $request)
    {
        return new \Drove\Laravel\LaravelResponse($response, $request);
    }
}
PHP;
    $replacement .= "\n";

    if (fullGitBlob($source) === $originalBlob) {
        fullAssert(str_ends_with($source, $anchor), 'Livewire RequestBroker transform anchor drifted.');

        return substr($source, 0, -strlen($anchor)).$replacement;
    }

    fullAssert(str_ends_with($source, $replacement), 'Livewire RequestBroker transform is not exact.');
    $restored = substr($source, 0, -strlen($replacement)).$anchor;
    fullAssert(fullGitBlob($restored) === $originalBlob, 'Livewire RequestBroker transform is not idempotent.');

    return $source;
}

function fullStage(string $root, string $path, string $contents, int $modifiedAt): string
{
    $target = $root.'/'.$path;
    $directory = dirname($target);
    fullAssert(is_dir($directory) || mkdir($directory, 0700, true), 'Could not create staging directory '.$directory.'.');
    fullAssert(file_put_contents($target, $contents) === strlen($contents), 'Could not stage '.$path.'.');
    fullAssert(touch($target, $modifiedAt), 'Could not normalize the staging clock for '.$path.'.');
    clearstatcache(true, $target);
    fullAssert(filemtime($target) === $modifiedAt, 'The staging clock drifted for '.$path.'.');

    return $target;
}

function fullCopyTree(string $source, string $target): void
{
    fullAssert(is_dir($source), 'Application template does not exist: '.$source.'.');
    fullAssert(is_dir($target) || mkdir($target, 0700, true), 'Could not create application stage.');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = $target.'/'.$relative;

        if ($entry->isDir()) {
            fullAssert(is_dir($destination) || mkdir($destination, 0700, true), 'Could not copy application directory '.$relative.'.');

            continue;
        }

        fullAssert(copy($entry->getPathname(), $destination), 'Could not copy application file '.$relative.'.');
    }
}

function fullRemoveTree(string $path): void
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

/** @return array<string, string> */
function fullTreeManifest(string $path): array
{
    if (! is_dir($path)) {
        return ['.' => 'missing'];
    }

    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($path) + 1));
        if ($entry->isDir()) {
            $entries[$relative] = 'directory';

            continue;
        }

        $hash = hash_file('sha256', $entry->getPathname());

        if (! is_string($hash)) {
            throw new RuntimeException('Could not hash tree entry '.$entry->getPathname().'.');
        }

        $entries[$relative] = $hash;
    }

    ksort($entries, SORT_STRING);

    return $entries;
}

function fullTreeHash(string $path): string
{
    return hash('sha256', json_encode(fullTreeManifest($path), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** @return array{schema: list<array<string, mixed>>, rows: list<array<string, mixed>>} */
function fullDatabaseManifest(Connection $connection): array
{
    return [
        'schema' => array_values(array_map(
            static fn (object $row): array => get_object_vars($row),
            $connection->select(
                'SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name',
            ),
        )),
        'rows' => array_values(array_map(
            static fn (object $row): array => get_object_vars($row),
            $connection->select(
                'SELECT id, value FROM native_livewire_rows ORDER BY id',
            ),
        )),
    ];
}

function fullDatabaseHash(Connection $connection): string
{
    return hash('sha256', json_encode(
        fullDatabaseManifest($connection),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ));
}

/** @return list<array{methods: list<string>, uri: string, domain: ?string, name: ?string, action: string}> */
function fullRouteManifest(RouteCollection $routes): array
{
    $manifest = [];

    foreach ($routes as $route) {
        $methods = $route->methods();
        sort($methods, SORT_STRING);
        $manifest[] = [
            'methods' => $methods,
            'uri' => $route->uri(),
            'domain' => $route->getDomain(),
            'name' => $route->getName(),
            'action' => $route->getActionName(),
        ];
    }

    usort(
        $manifest,
        static fn (array $left, array $right): int => json_encode($left, JSON_THROW_ON_ERROR)
            <=> json_encode($right, JSON_THROW_ON_ERROR),
    );

    return $manifest;
}

function fullRouteHash(RouteCollection $routes): string
{
    return hash('sha256', json_encode(
        fullRouteManifest($routes),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ));
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
function fullRuntimeEvidence(array $task, ?string $file = null): array
{
    $taskId = $task['id'] ?? null;
    $taskKind = $task['kind'] ?? null;
    $taskScopeId = $task['scope_id'] ?? null;
    $taskScopes = $task['scopes'] ?? null;
    fullAssert(
        is_string($taskId)
            && in_array($taskKind, ['root', 'scope', 'test'], true)
            && is_string($taskScopeId)
            && is_array($taskScopes)
            && array_is_list($taskScopes)
            && array_all($taskScopes, static fn (mixed $scope): bool => is_string($scope)),
        'DROVE_NATIVE_LIVEWIRE_FULL_RUNTIME_AUDIT_TASK_INVALID',
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

    if (! is_int($pid)) {
        throw new RuntimeException('Could not resolve the full native Livewire descendant PID.');
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

    fullAssert($classes === [], 'DROVE_NATIVE_LIVEWIRE_FULL_EXTERNAL_TEST_RUNTIME_CLASS_LOADED');
    fullAssert($files === [], 'DROVE_NATIVE_LIVEWIRE_FULL_EXTERNAL_TEST_RUNTIME_FILE_LOADED');

    if ($file !== null) {
        $record = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        fullAssert(
            file_put_contents($file, $record, FILE_APPEND | LOCK_EX) === strlen($record),
            'Could not record full Livewire descendant evidence.',
        );
    }

    return $evidence;
}

$proofStartedNs = hrtime(true);
$vendor = getenv('DROVE_NATIVE_VENDOR');

if (! is_string($vendor) || $vendor === '' || ! is_file($vendor.'/autoload.php')) {
    fwrite(STDERR, "DROVE_NATIVE_VENDOR must identify the clean installed vendor.\n");
    exit(1);
}

require $vendor.'/autoload.php';
require __DIR__.'/LivewirePackageContext.php';
require __DIR__.'/PinnedGitCheckout.php';

$runtimeEvidenceFault = getenv('DROVE_NATIVE_LIVEWIRE_RUNTIME_EVIDENCE_FAULT');

if (is_string($runtimeEvidenceFault) && $runtimeEvidenceFault !== '') {
    $faultStage = null;

    try {
        fullAssert(
            in_array($runtimeEvidenceFault, ['class', 'file'], true),
            'DROVE_NATIVE_LIVEWIRE_RUNTIME_EVIDENCE_FAULT_INVALID',
        );

        if ($runtimeEvidenceFault === 'class') {
            eval('namespace Drove\\Bridge\\Pest; final class RuntimeEvidenceFault {}');
        } else {
            $faultStage = sys_get_temp_dir().'/drove-native-livewire-runtime-fault-'.bin2hex(random_bytes(6));
            $faultFile = $faultStage.'/vendor/phpunit/phpunit/RuntimeEvidenceFault.php';
            fullAssert(mkdir(dirname($faultFile), 0700, true), 'Could not create runtime-evidence fault stage.');
            $source = "<?php\n\ndeclare(strict_types=1);\n";
            fullAssert(file_put_contents($faultFile, $source) === strlen($source), 'Could not write runtime-evidence fault file.');
            require $faultFile;
        }

        fullRuntimeEvidence([
            'id' => 'runtime-evidence-self-test',
            'kind' => 'root',
            'scope_id' => 'runtime-evidence-self-test',
            'scopes' => ['runtime-evidence-self-test'],
        ]);
        throw new RuntimeException('DROVE_NATIVE_LIVEWIRE_RUNTIME_EVIDENCE_FAULT_NOT_DETECTED');
    } catch (Throwable $throwable) {
        fwrite(STDERR, 'DROVE_NATIVE_LIVEWIRE_FULL_FAILED '.$throwable::class.': '.$throwable->getMessage().PHP_EOL);
    } finally {
        if (is_string($faultStage)) {
            fullRemoveTree($faultStage);
        }
    }

    exit(1);
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:Hupx3yAySikrM2/edkZQNQHslgDWYfiBfCuSThJ5SK8=',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$token = bin2hex(random_bytes(6));
$stage = sys_get_temp_dir().'/drove-native-livewire-full-'.$token;
$sourceRoot = $stage.'/source';
$applicationPath = $stage.'/application';
$evidenceFile = sys_get_temp_dir().'/drove-native-livewire-full-'.$token.'.jsonl';
$autoload = null;
$baseline = json_decode(
    (string) file_get_contents(__DIR__.'/livewire-full-baseline.json'),
    true,
    16,
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
        LIVEWIRE_FULL_COMMIT,
    );
    $sourceMtimeEpoch = $baseline['staging_source_mtime_epoch'] ?? null;
    fullAssert(mkdir($stage, 0700), 'Could not create the full Livewire staging root.');
    fullAssert(($baseline['commit'] ?? null) === LIVEWIRE_FULL_COMMIT, 'Full Livewire baseline commit drifted.');
    fullAssert(
        is_int($sourceMtimeEpoch) && $sourceMtimeEpoch === $checkout->commitTimestamp(),
        'Full Livewire staging clock does not match the pinned commit timestamp.',
    );
    fullAssert(
        is_array($profile)
            && array_keys($profile) === ['method_receivers', 'property_replacements', 'parent_receivers', 'fluent_assertions']
            && is_string($profileHash)
            && $profileHash === ($baseline['migration_profile_sha256'] ?? null),
        'Full Livewire migration profile is invalid or drifted.',
    );
    fullCopyTree(__DIR__.'/application', $applicationPath);

    foreach ([
        $applicationPath.'/app',
        $applicationPath.'/resources/views/layouts',
        $applicationPath.'/resources/views/pages',
        $applicationPath.'/tests/Feature',
    ] as $directory) {
        fullAssert(is_dir($directory) || mkdir($directory, 0700, true), 'Could not prepare application directory '.$directory.'.');
    }

    $livewire = InstalledVersions::getInstallPath('livewire/livewire');
    $installedCommit = InstalledVersions::getReference('livewire/livewire');
    fullAssert(is_string($livewire), 'Pinned Livewire package is not installed.');
    fullAssert($installedCommit === LIVEWIRE_FULL_COMMIT, 'Installed Livewire reference drifted.');
    fullAssert(InstalledVersions::getVersion('calebporzio/sushi') === '2.5.4.0', 'Pinned Sushi version drifted.');
    $externalPackages = array_values(array_filter(
        InstalledVersions::getInstalledPackages(),
        static fn (string $package): bool => is_string(InstalledVersions::getInstallPath($package))
            && preg_match('~\A(?:pestphp/|phpunit/|orchestra/testbench)~i', $package) === 1,
    ));
    fullAssert($externalPackages === [], 'Clean full Livewire environment installed an external test runtime.');

    $files = $baseline['files'] ?? null;
    $helpers = $baseline['production_helpers'] ?? null;
    $support = $baseline['support_files'] ?? null;
    fullAssert(is_array($files) && count($files) === 20, 'Full Livewire file selection drifted.');
    fullAssert(is_array($helpers) && count($helpers) === 2, 'Full Livewire helper selection drifted.');
    fullAssert(is_array($support) && count($support) === 29, 'Full Livewire support selection drifted.');

    foreach ($support as $path => $blob) {
        fullAssert(is_string($path) && is_string($blob), 'Full Livewire support entry is invalid.');
        fullStage($sourceRoot, $path, fullPinnedSource($checkout, $path, $blob), $sourceMtimeEpoch);
    }

    foreach ($helpers as $path => $blob) {
        fullAssert(is_string($path) && is_string($blob), 'Full Livewire helper entry is invalid.');
        $source = fullPinnedSource($checkout, $path, $blob);
        $source = match ($path) {
            'src/Features/SupportTesting/MakesAssertions.php' => fullTransformMakesAssertions($source),
            'src/Features/SupportTesting/RequestBroker.php' => fullTransformRequestBroker($source),
            default => throw new RuntimeException('Unknown full Livewire production helper '.$path.'.'),
        };
        $second = match ($path) {
            'src/Features/SupportTesting/MakesAssertions.php' => fullTransformMakesAssertions($source),
            'src/Features/SupportTesting/RequestBroker.php' => fullTransformRequestBroker($source),
        };
        fullAssert($second === $source, 'Full Livewire production helper transform is not idempotent: '.$path.'.');
        fullAssert(! str_contains($source, 'PHPUnit\\Framework\\Assert'), 'Full Livewire helper retained PHPUnit Assert: '.$path.'.');
        fullStage($sourceRoot, $path, $source, $sourceMtimeEpoch);
    }

    $migrator = new ClassMigrator;
    $stagedTests = [];

    foreach ($files as $path => $blob) {
        fullAssert(is_string($path) && is_string($blob), 'Full Livewire file entry is invalid.');
        $source = fullPinnedSource($checkout, $path, $blob);
        $migration = $migrator->migrate($source, $path, 'Tests\\TestCase', $profile);
        fullAssert($migration->blockers === [], 'Full native migration blocked '.$path.'.');
        $second = $migrator->migrate($migration->source, $path, 'Tests\\TestCase', $profile);
        fullAssert(
            $second->source === $migration->source && $second->applied === [] && $second->blockers === [],
            'Full native migration is not idempotent: '.$path.'.',
        );
        fullAssert(
            preg_match('~(?:Pest\\\\|PHPUnit\\\\|Orchestra\\\\Testbench|Tests\\\\TestCase|extends\s+\\\\?TestCase)~', $migration->source) !== 1,
            'Full migrated source retained an external test runtime: '.$path.'.',
        );
        $stagedTests[] = fullStage($sourceRoot, $path, $migration->source, $sourceMtimeEpoch);
    }

    $autoload = static function (string $class) use ($sourceRoot): void {
        $path = null;

        if ($class === 'Tests\\TestComponent') {
            $path = $sourceRoot.'/tests/TestComponent.php';
        } elseif (str_starts_with($class, 'Livewire\\')) {
            $path = $sourceRoot.'/src/'.str_replace('\\', '/', substr($class, 9)).'.php';
        }

        if (is_string($path) && is_file($path)) {
            require $path;
        }
    };
    spl_autoload_register($autoload, true, true);
    fullAssert(class_exists('Tests\\TestComponent'), 'Staged Livewire TestComponent did not load.');

    foreach ($stagedTests as $source) {
        require $source;
    }

    $declared = 0;
    $preparedRoutes = -1;
    $databaseHash = '';
    $routeHash = '';
    $sourceHash = '';
    $applicationManifest = [];
    $applicationHash = '';
    $storagePath = '';
    $storageManifest = [];
    $storageHash = '';
    $registry = Declarations::capture(
        static function () use (
            $applicationPath,
            &$declared,
            $sourceRoot,
            $stagedTests,
            &$preparedRoutes,
            &$databaseHash,
            &$routeHash,
            &$sourceHash,
            &$applicationManifest,
            &$applicationHash,
            &$storagePath,
            &$storageManifest,
            &$storageHash,
            $phaseClock,
        ): void {
            environment(
                'laravel-livewire-native-full',
                static function () use (
                    $applicationPath,
                    $sourceRoot,
                    &$preparedRoutes,
                    &$databaseHash,
                    &$routeHash,
                    &$sourceHash,
                    &$applicationManifest,
                    &$applicationHash,
                    &$storagePath,
                    &$storageManifest,
                    &$storageHash,
                    $phaseClock,
                ): ApplicationRuntime {
                    $planningStartedNs = $phaseClock['planning_started_ns'];

                    if ($planningStartedNs < 0) {
                        throw new RuntimeException('DROVE_NATIVE_LIVEWIRE_FULL_PLANNING_CLOCK_MISSING');
                    }

                    $environmentStartedNs = hrtime(true);
                    $phaseClock['planning_ns'] = $environmentStartedNs - $planningStartedNs;
                    $runtime = PackageApplicationRuntime::boot(
                        $applicationPath,
                        new InMemorySqliteDatabaseStateAdapter('testbench', true),
                        [DroveLaravelServiceProvider::class, LivewireServiceProvider::class],
                        static function (Application $application) use ($sourceRoot): void {
                            $application->singleton(
                                ExceptionHandler::class,
                                static fn (Application $application): Handler => new Handler($application),
                            );
                            $application->make('config')->set('database.default', 'testbench');
                            $application->make('config')->set('view.paths', [
                                $sourceRoot.'/tests/views',
                                $application->resourcePath('views'),
                            ]);
                            $viewPaths = [
                                $sourceRoot.'/tests/views',
                                $application->resourcePath('views'),
                            ];
                            $application->make('view.finder')->setPaths($viewPaths);
                            $application->make('view')->getFinder()->setPaths($viewPaths);
                            $application->make('config')->set('filesystems.disks.unit-downloads', [
                                'driver' => 'local',
                                'root' => $sourceRoot.'/tests/fixtures',
                            ]);
                            $application->make('view')->addNamespace('layouts', $sourceRoot.'/tests/views/layouts');
                            $application->make('view')->addNamespace('pages', $sourceRoot.'/tests/views/pages');
                            $database = $application->make('db')->connection('testbench');
                            $database->statement('CREATE TABLE native_livewire_rows (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
                            $database->table('native_livewire_rows')->insert(['id' => 1, 'value' => 'prepared']);
                        },
                    );
                    $application = $runtime->scopeContext()->app();

                    if (! $application instanceof Application) {
                        throw new RuntimeException('Full Livewire application runtime did not boot.');
                    }

                    fullAssert(is_file($sourceRoot.'/tests/views/show-name-with-this.blade.php'), 'Full Livewire route fixture was not staged.');
                    fullAssert($application->make('view')->exists('show-name-with-this'), 'Full Livewire route fixture was not registered with the view finder.');
                    $router = $application->make('router');
                    $connection = $application->make('db')->connection('testbench');
                    $routes = $router->getRoutes();

                    if (! $routes instanceof RouteCollection) {
                        throw new RuntimeException('Full Livewire route collection is unavailable.');
                    }

                    if (! $connection instanceof Connection) {
                        throw new RuntimeException('Full Livewire database connection is unavailable.');
                    }

                    $preparedRoutes = $routes->count();
                    $databaseHash = fullDatabaseHash($connection);
                    $routeHash = fullRouteHash($routes);
                    $preparedViewCache = $application->storagePath('framework/views');
                    fullAssert(
                        is_dir($preparedViewCache) || mkdir($preparedViewCache, 0700, true),
                        'Full Livewire could not prepare its compiled-view storage.',
                    );
                    $sourceHash = fullTreeHash($sourceRoot);
                    $applicationManifest = fullTreeManifest($applicationPath);
                    $applicationHash = fullTreeHash($applicationPath);
                    $storagePath = $application->storagePath();
                    $storageManifest = fullTreeManifest($storagePath);
                    $storageHash = fullTreeHash($storagePath);

                    if (getenv('DROVE_NATIVE_PACKAGE_FAULT_AFTER_BOOT') === '1') {
                        throw new RuntimeException('DROVE_NATIVE_PACKAGE_INJECTED_POST_BOOT_FAULT');
                    }

                    $environmentPreparedNs = hrtime(true);
                    $phaseClock['preparation_ns'] += $environmentPreparedNs - $environmentStartedNs;
                    $phaseClock['execution_started_ns'] = $environmentPreparedNs;

                    return $runtime;
                },
            );

            foreach ($stagedTests as $source) {
                Declarations::current()->declareHook(
                    'before_each',
                    static fn () => livewirePackageContext()->makeACleanSlate(),
                    $source,
                    1,
                );
                Declarations::current()->declareHook(
                    'after_each',
                    static fn () => livewirePackageContext()->makeACleanSlate(),
                    $source,
                    1,
                );
            }

            $declared = (new ClassFrontend)->declareFiles($stagedTests);
        },
        $sourceRoot,
        'Pinned Livewire full native package cohort',
    );
    $scheduler = new DroverScheduler('native-livewire-full-'.$token, 1);
    $runner = new Runner(
        $scheduler,
        static function (array $task) use ($evidenceFile): void {
            fullRuntimeEvidence($task, $evidenceFile);
        },
    );
    $planningStartedNs = hrtime(true);
    $phaseClock['planning_started_ns'] = $planningStartedNs;
    $phaseClock['preparation_ns'] += $planningStartedNs - $proofStartedNs;
    $run = $runner->run($registry);
    $runnerFinishedNs = hrtime(true);
    $executionStartedNs = $phaseClock['execution_started_ns'];

    if ($executionStartedNs < 0 || $executionStartedNs > $runnerFinishedNs) {
        throw new RuntimeException('DROVE_NATIVE_LIVEWIRE_FULL_EXECUTION_CLOCK_MISSING');
    }

    $executionNs = $runnerFinishedNs - $executionStartedNs;
    $verificationStartedNs = $runnerFinishedNs;
    $runtime = $registry->resolveEnvironment();
    $application = $runtime?->scopeContext()->app();

    if (! $application instanceof Application) {
        throw new RuntimeException('Full Livewire application runtime did not boot.');
    }

    $router = $application->make('router');
    $connection = $application->make('db')->connection('testbench');
    $routes = $router->getRoutes();

    if (! $routes instanceof RouteCollection || ! $connection instanceof Connection) {
        throw new RuntimeException('Full Livewire prepared runtime became unavailable.');
    }

    $tests = $run['tests'] ?? null;
    fullAssert(is_array($tests), 'Full Livewire execution did not return test results.');
    $actual = array_map(
        static fn (array $test): array => [
            'id' => $test['id'] ?? null,
            'status' => $test['status'] ?? null,
            'assertions' => $test['assertions'] ?? null,
            'stdout' => $test['stdout'] ?? null,
            'stderr' => $test['stderr'] ?? null,
        ],
        $tests,
    );
    $expected = $baseline['cases'] ?? null;
    fullAssert(is_array($expected), 'Full Livewire case baseline is invalid.');
    usort($actual, static fn (array $left, array $right): int => ($left['id'] ?? '') <=> ($right['id'] ?? ''));
    usort($expected, static fn (array $left, array $right): int => ($left['id'] ?? '') <=> ($right['id'] ?? ''));
    $lines = is_file($evidenceFile) ? file($evidenceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    $evidence = is_array($lines)
        ? array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines)
        : [];
    $testPids = array_column(array_column($tests, 'telemetry'), 'pid');
    $testEvidence = [];
    $rootEvidence = [];

    foreach ($evidence as $row) {
        $kind = $row['task_kind'] ?? null;
        $id = $row['task_id'] ?? null;
        fullAssert(
            in_array($kind, ['root', 'scope', 'test'], true) && is_string($id),
            'Full native Livewire emitted an invalid runtime-audit identity.',
        );

        if ($kind === 'root') {
            $rootEvidence[] = $row;

            continue;
        }

        fullAssert($kind === 'test', 'Full native Livewire unexpectedly dispatched a scope worker.');
        fullAssert(! isset($testEvidence[$id]), 'Full native Livewire duplicated a runtime-audit task row.');
        $testEvidence[$id] = $row;
    }

    $evidencePids = array_column($testEvidence, 'pid');
    $topology = $scheduler->topologyTelemetry();
    $rootRows = $connection->table('native_livewire_rows')->pluck('value')->all();
    $rootRoutes = $routes->count();
    $rootDatabaseHash = fullDatabaseHash($connection);
    $rootRouteHash = fullRouteHash($routes);
    $incomplete = array_values(array_filter($tests, static fn (array $test): bool => ($test['status'] ?? null) === 'incomplete'));
    fullAssert(
        ($run['status'] ?? null) === 'passed' && ($run['exit_code'] ?? null) === 0,
        'Full Livewire native execution failed: '.json_encode([
            'status' => $run['status'] ?? null,
            'exit_code' => $run['exit_code'] ?? null,
            'counts' => array_count_values(array_column($tests, 'status')),
            'failures' => array_slice(array_values(array_filter(array_map(
                static fn (array $test): ?array => ($test['status'] ?? null) === 'failed'
                    ? [
                        'id' => $test['id'] ?? null,
                        'failure' => $test['failure'] ?? null,
                        'stderr' => $test['stderr'] ?? null,
                    ]
                    : null,
                $tests,
            ))), 0, 40),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
    fullAssert($declared === 20, 'Full native class frontend did not declare 20 Livewire classes.');
    fullAssert($actual === $expected, 'Full native Livewire case rows diverged from the independent baseline.');
    fullAssert(count($tests) === 288, 'Full native Livewire lost the 288-case baseline.');
    fullAssert(array_sum(array_column($tests, 'assertions')) === ($baseline['expected_assertions'] ?? null), 'Full native Livewire lost the 1,034-assertion baseline.');
    fullAssert(count($incomplete) === 3 && array_all($incomplete, static fn (array $test): bool => ($test['value'] ?? null) === 'Test marked incomplete.'), 'Full native Livewire incomplete reasons diverged.');
    fullAssert(count($evidence) === 289, 'Full native Livewire did not audit every task and the root exactly once.');
    fullAssert(count($testEvidence) === 288 && count($rootEvidence) === 1, 'Full native Livewire runtime-audit task kinds drifted.');
    fullAssert(count(array_unique($evidencePids)) === 288, 'Full native Livewire did not execute 288 unique descendants.');
    fullAssert(count($testPids) === 288 && count(array_unique($testPids)) === 288, 'Full native Livewire batched test bodies.');
    foreach ($tests as $test) {
        $id = $test['id'] ?? null;
        $row = is_string($id) ? ($testEvidence[$id] ?? null) : null;
        fullAssert(
            is_array($row)
                && ($row['task_kind'] ?? null) === 'test'
                && ($row['pid'] ?? null) === ($test['telemetry']['pid'] ?? null)
                && ($row['task_scope_id'] ?? null) === ($test['scope_id'] ?? null)
                && ($row['task_scopes'] ?? null) === ($test['scopes'] ?? null),
            'Full native Livewire runtime audit did not bind an exact scheduler task identity to telemetry.',
        );
    }
    $rootEvidenceRow = $rootEvidence[0];
    fullAssert(
        ($rootEvidenceRow['task_id'] ?? null) === 'suite:root'
            && ($rootEvidenceRow['task_scope_id'] ?? null) === 'suite:root'
            && ($rootEvidenceRow['task_scopes'] ?? null) === ['suite:root']
            && ($rootEvidenceRow['pid'] ?? null) === getmypid()
            && ! in_array($rootEvidenceRow['pid'], $testPids, true),
        'Full native Livewire root runtime audit was not bound to the root process.',
    );
    $expectedTopology = [
        'schema' => 1,
        'forks' => 288,
        'scope_workers' => 0,
        'executor_workers' => 288,
        'process_anchors' => 0,
        'peak_live_pids' => 2,
        'peak_outstanding_tasks' => 2,
        'outstanding_task_limit' => 2,
    ];
    fullAssert(
        $topology === $expectedTopology,
        'Full native Livewire violated one-fork-per-case topology: '.json_encode(
            ['actual' => $topology, 'expected' => $expectedTopology],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
    );
    fullAssert($rootRows === ['prepared'], 'Full native Livewire mutated prepared root SQLite state.');
    fullAssert($rootDatabaseHash === $databaseHash, 'Full native Livewire mutated prepared root SQLite schema or rows.');
    fullAssert($rootRouteHash === $routeHash, 'Full native Livewire mutated prepared root route definitions.');
    fullAssert(
        $rootRoutes === $preparedRoutes,
        sprintf(
            'Full native Livewire mutated prepared root routes (prepared=%s, root=%d).',
            var_export($preparedRoutes, true),
            $rootRoutes,
        ),
    );
    fullAssert(fullTreeHash($sourceRoot) === $sourceHash, 'Full native Livewire mutated pinned source or fixtures.');
    $currentApplicationManifest = fullTreeManifest($applicationPath);
    fullAssert(
        fullTreeHash($applicationPath) === $applicationHash,
        'Full native Livewire left application filesystem mutations: '.json_encode([
            'added_or_changed' => array_diff_assoc($currentApplicationManifest, $applicationManifest),
            'removed_or_changed' => array_diff_assoc($applicationManifest, $currentApplicationManifest),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
    $currentStorageManifest = fullTreeManifest($storagePath);
    fullAssert(
        fullTreeHash($storagePath) === $storageHash,
        'Full native Livewire left storage filesystem mutations: '.json_encode([
            'added_or_changed' => array_diff_assoc($currentStorageManifest, $storageManifest),
            'removed_or_changed' => array_diff_assoc($storageManifest, $currentStorageManifest),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
    fullAssert($rootEvidenceRow['classes'] === [] && $rootEvidenceRow['files'] === [], 'Full native Livewire root loaded an external test runtime.');
    $checkout->assertExactAndClean();
    $verificationNs = hrtime(true) - $verificationStartedNs;
    $phasesMs = [
        'preparation' => round($phaseClock['preparation_ns'] / 1_000_000, 3),
        'planning' => round($phaseClock['planning_ns'] / 1_000_000, 3),
        'execution' => round($executionNs / 1_000_000, 3),
        'verification' => round($verificationNs / 1_000_000, 3),
    ];
    fullAssert(
        array_all($phasesMs, static fn (float $milliseconds): bool => $milliseconds >= 0),
        'DROVE_NATIVE_LIVEWIRE_FULL_PHASE_TELEMETRY_INVALID',
    );

    echo json_encode([
        'ok' => true,
        'corpus' => 'livewire/livewire',
        'commit' => $installedCommit,
        'source_mode' => 'read-only-git-checkout',
        'files' => count($files),
        'classes' => $declared,
        'cases' => count($tests),
        'passed' => count(array_filter($tests, static fn (array $test): bool => ($test['status'] ?? null) === 'passed')),
        'incomplete' => count($incomplete),
        'assertions' => array_sum(array_column($tests, 'assertions')),
        'processes' => 1,
        'capacity_proof' => ['enabled' => false],
        'phases_ms' => $phasesMs,
        'child_pids' => count(array_unique($testPids)),
        'forks' => $topology['forks'],
        'case_rows_sha256' => hash('sha256', json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'runtime_evidence' => [
            'rows' => count($evidence),
            'root_processes' => 1,
            'descendant_processes' => count($evidencePids),
            'total_processes' => count($evidencePids) + 1,
            'task_ids_bound_to_telemetry' => count($testEvidence),
            'forbidden_classes' => 0,
            'forbidden_files' => 0,
        ],
        'topology' => $topology,
        'migration_profile_sha256' => $profileHash,
        'staging_source_mtime_epoch' => $sourceMtimeEpoch,
        'sushi' => InstalledVersions::getPrettyVersion('calebporzio/sushi'),
        'source_integrity' => $sourceHash,
        'application_integrity' => $applicationHash,
        'storage_integrity' => $storageHash,
        'database_integrity' => $databaseHash,
        'route_integrity' => $routeHash,
        'external_test_runtime_packages' => count($externalPackages),
        'external_test_runtime_files' => 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, 'DROVE_NATIVE_LIVEWIRE_FULL_FAILED '.$throwable::class.': '.$throwable->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    if ($autoload instanceof Closure) {
        spl_autoload_unregister($autoload);
    }

    fullRemoveTree($stage);

    if (is_file($evidenceFile)) {
        unlink($evidenceFile);
    }
}

exit($exitCode);
