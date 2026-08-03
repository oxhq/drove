<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Drove\Kernel\DroverScheduler;
use Drove\Laravel\DroveLaravelServiceProvider;
use Drove\Laravel\PackageApplicationRuntime;
use Drove\Laravel\Proof\ProofCounter;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Livewire\LivewireManager;
use Livewire\LivewireServiceProvider;

use function Drove\Laravel\getJson;
use function Drove\Laravel\laravelContext;
use function Drove\Native\environment;
use function Drove\Native\expect;
use function Drove\Native\test;

const LIVEWIRE_COMMIT = '9c1450739d30c9b0b223ad6512be2a33f8f62f96';
const LIVEWIRE_PROVIDER_SHA256 = '1c10a083591f434bd80f206a2b4ed71c8adf345d65d9483b2e4b2311cb7aa87a';

$vendor = getenv('DROVE_NATIVE_VENDOR');

if (! is_string($vendor) || $vendor === '' || ! is_file($vendor.'/autoload.php')) {
    fwrite(STDERR, "DROVE_NATIVE_VENDOR must identify the clean installed vendor.\n");
    exit(1);
}

require $vendor.'/autoload.php';
require __DIR__.'/ProofCounter.php';

/** @return array{classes: list<string>, files: list<string>, sources: array<string, string>} */
function packageRuntimeEvidence(string $file): array
{
    $classes = array_values(array_filter(
        get_declared_classes(),
        static fn (string $class): bool => str_starts_with($class, 'Pest\\')
            || str_starts_with($class, 'PHPUnit\\')
            || str_starts_with($class, 'Orchestra\\Testbench\\'),
    ));
    $files = array_values(array_filter(
        array_map(
            static fn (string $path): string => str_replace('\\', '/', $path),
            get_included_files(),
        ),
        static fn (string $path): bool => preg_match(
            '~/(?:pestphp|phpunit|orchestra/testbench[^/]*)/~i',
            $path,
        ) === 1,
    ));
    $sources = [];

    foreach ([
        LivewireServiceProvider::class,
        LivewireManager::class,
        Component::class,
    ] as $class) {
        $source = new ReflectionClass($class)->getFileName();
        $sources[$class] = is_string($source)
            ? str_replace('\\', '/', $source)
            : '';
    }

    $evidence = [
        'pid' => getmypid(),
        'classes' => $classes,
        'files' => $files,
        'sources' => $sources,
    ];
    $record = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

    if (file_put_contents($file, $record, FILE_APPEND | LOCK_EX) !== strlen($record)) {
        throw new RuntimeException('Unable to record native package descendant evidence.');
    }

    if ($classes !== [] || $files !== []) {
        throw new RuntimeException('DROVE_NATIVE_PACKAGE_EXTERNAL_TEST_RUNTIME_LOADED');
    }

    return $evidence;
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$token = bin2hex(random_bytes(6));
$runtimeEvidenceFile = sys_get_temp_dir().'/drove-native-package-'.$token.'.jsonl';
$applicationPath = __DIR__.'/application';

$exitCode = 0;

try {
    $registry = Declarations::capture(
        static function () use ($applicationPath, $runtimeEvidenceFile): void {
            environment(
                'laravel-package',
                static fn () => PackageApplicationRuntime::boot(
                    $applicationPath,
                    new InMemorySqliteDatabaseStateAdapter('sqlite', true),
                    [
                        DroveLaravelServiceProvider::class,
                        LivewireServiceProvider::class,
                    ],
                    static function (Application $application): void {
                        $database = $application->make('db')->connection('sqlite');
                        $database->statement(
                            'CREATE TABLE package_rows ('
                            .'id INTEGER PRIMARY KEY, value TEXT NOT NULL)',
                        );
                        $database->table('package_rows')->insert([
                            'id' => 1,
                            'value' => 'prepared',
                        ]);
                        $application->make('livewire')->component(
                            'drove-proof-counter',
                            ProofCounter::class,
                        );
                        $application->make('router')->get(
                            '/native-package',
                            static fn () => response()->json([
                                'application_booted' => $application->isBooted(),
                                'provider_loaded' => $application->getProvider(
                                    LivewireServiceProvider::class,
                                ) instanceof LivewireServiceProvider,
                                'prepared_rows' => $database->table('package_rows')->count(),
                            ]),
                        );
                    },
                ),
            );

            test('boots the pinned Livewire provider on prepared Laravel state', static function () use ($runtimeEvidenceFile): void {
                getJson('/native-package')
                    ->assertOk()
                    ->assertJsonPath('application_booted', true)
                    ->assertJsonPath('provider_loaded', true)
                    ->assertJsonPath('prepared_rows', 1);

                $component = laravelContext()->application()->make('livewire')
                    ->new('drove-proof-counter');
                expect($component)->toBeInstanceOf(ProofCounter::class);
                LivewireManager::$v4 = false;
                expect(LivewireManager::$v4)->toBeFalse();

                laravelContext()->application()->make('db')->connection('sqlite')
                    ->table('package_rows')->insert(['id' => 2, 'value' => 'child']);
                laravelContext()
                    ->assertDatabaseHas('package_rows', ['id' => 2, 'value' => 'child'])
                    ->assertDatabaseCount('package_rows', 2);
                packageRuntimeEvidence($runtimeEvidenceFile);
            });

            test('inherits the prepared package environment without sibling mutations', static function () use ($runtimeEvidenceFile): void {
                expect(LivewireManager::$v4)->toBeTrue();
                expect(
                    laravelContext()->application()->make('livewire')
                        ->new('drove-proof-counter'),
                )->toBeInstanceOf(ProofCounter::class);
                laravelContext()
                    ->assertDatabaseMissing('package_rows', ['id' => 2])
                    ->assertDatabaseCount('package_rows', 1);
                packageRuntimeEvidence($runtimeEvidenceFile);
            });
        },
        __DIR__,
        'Drove native package runtime proof',
    );
    $registry->plan();
    $runtime = $registry->resolveEnvironment();

    if (getenv('DROVE_NATIVE_PACKAGE_FAULT_AFTER_BOOT') === '1') {
        throw new RuntimeException('DROVE_NATIVE_PACKAGE_INJECTED_POST_BOOT_FAULT');
    }

    $run = new Runner(new DroverScheduler('native-package-'.$token, 2))
        ->run($registry);
    $rootRows = $runtime?->scopeContext()->app()?->make('db')
        ->connection('sqlite')
        ->table('package_rows')
        ->orderBy('id')
        ->pluck('value')
        ->all();
    $lines = is_file($runtimeEvidenceFile)
        ? file($runtimeEvidenceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : false;
    $evidence = is_array($lines)
        ? array_map(
            static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR),
            $lines,
        )
        : [];
    $providerSource = new ReflectionClass(LivewireServiceProvider::class)->getFileName();
    $providerHash = is_string($providerSource)
        ? hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($providerSource)))
        : false;
    $installedCommit = InstalledVersions::getReference('livewire/livewire');
    $externalTestPackages = array_values(array_filter(
        InstalledVersions::getInstalledPackages(),
        static fn (string $package): bool => is_string(
            InstalledVersions::getInstallPath($package),
        ) && preg_match(
            '~\A(?:pestphp/|phpunit/|orchestra/testbench)~i',
            $package,
        ) === 1,
    ));
    $childPids = array_values(array_unique(array_column($evidence, 'pid')));
    $tests = $run['tests'] ?? null;
    $rootEvidence = packageRuntimeEvidence($runtimeEvidenceFile);

    if (($run['status'] ?? null) !== 'passed'
        || ($run['exit_code'] ?? null) !== 0
        || ! is_array($tests)
        || count($tests) !== 2
        || array_column($tests, 'status') !== ['passed', 'passed']
        || array_sum(array_column($tests, 'assertions')) < 12
        || $rootRows !== ['prepared']
        || LivewireManager::$v4 !== true
        || $installedCommit !== LIVEWIRE_COMMIT
        || $providerHash !== LIVEWIRE_PROVIDER_SHA256
        || $externalTestPackages !== []
        || count($evidence) !== 2
        || count($childPids) !== 2
        || in_array(getmypid(), $childPids, true)
        || ! array_all($evidence, static fn (array $record): bool => ($record['classes'] ?? null) === []
            && ($record['files'] ?? null) === []
            && count($record['sources'] ?? []) === 3
            && array_all(
                $record['sources'] ?? [],
                static fn (string $source): bool => str_contains(
                    $source,
                    '/livewire/livewire/src/',
                ),
            ))
        || $rootEvidence['classes'] !== []
        || $rootEvidence['files'] !== []) {
        throw new RuntimeException('Native package runtime proof diverged: '.json_encode([
            'run' => $run,
            'root_rows' => $rootRows,
            'root_v4' => LivewireManager::$v4,
            'installed_commit' => $installedCommit,
            'provider_hash' => $providerHash,
            'external_test_packages' => $externalTestPackages,
            'evidence' => $evidence,
            'root_evidence' => $rootEvidence,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    echo json_encode([
        'ok' => true,
        'corpus' => 'livewire/livewire',
        'commit' => $installedCommit,
        'provider_sha256' => $providerHash,
        'tests' => count($tests),
        'assertions' => array_sum(array_column($tests, 'assertions')),
        'processes' => 2,
        'child_pids' => count($childPids),
        'prepared_root_rows' => $rootRows,
        'external_test_runtime_packages' => count($externalTestPackages),
        'external_test_runtime_files' => 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, 'DROVE_NATIVE_PACKAGE_PROOF_FAILED '.$throwable::class.': '.$throwable->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_file($runtimeEvidenceFile)) {
        unlink($runtimeEvidenceFile);
    }
}

exit($exitCode);
