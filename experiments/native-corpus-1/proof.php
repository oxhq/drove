<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Drove\Compatibility\Registry;
use Drove\Extension\ExtensionSet;
use Drove\Extension\Manifest;
use Drove\Extension\Registry as ExtensionRegistry;
use Drove\Kernel\DroverScheduler;
use Drove\Kernel\Scheduler;
use Drove\Migration\CodemodOptions;
use Drove\Migration\Migrator;
use Drove\Migration\Scanner;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use DroveNativePestCorpus\PestCorpusCodemod;
use DroveNativePestCorpus\PestCorpusEntrypoint;

$droveRoot = dirname(__DIR__, 2);
$pestRoot = $argv[1] ?? getenv('DROVE_PEST_CORPUS') ?: $droveRoot.'/.temp/native-corpus-pest';
$pestRoot = realpath($pestRoot);
$dependencyVendor = getenv('DROVE_NATIVE_CORPUS_VENDOR');
$dependencyVendor = is_string($dependencyVendor) ? realpath($dependencyVendor) : false;

$fail = static fn (string $message): never => throw new RuntimeException($message);
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (! $condition) {
        $fail($message);
    }
};

if (! is_string($pestRoot) || ! is_dir($pestRoot)) {
    $fail('The pinned Pest checkout does not exist.');
}

if (! is_string($dependencyVendor) || ! is_dir($dependencyVendor)) {
    $fail('DROVE_NATIVE_CORPUS_VENDOR must identify the installed clean dependency vendor.');
}

$rootVendor = realpath($droveRoot.'/vendor');

if (is_string($rootVendor) && $dependencyVendor === $rootVendor) {
    $fail('The native Pest corpus may not load dependencies from Drove root dev vendor.');
}

$dependencyComposer = $dependencyVendor.'/composer';
$dependencyRoot = dirname($dependencyVendor);

foreach (['ClassLoader.php', 'autoload_psr4.php', 'autoload_classmap.php'] as $dependencyMetadata) {
    if (! is_file($dependencyComposer.'/'.$dependencyMetadata)) {
        $fail('The clean native Pest dependency vendor is incomplete.');
    }
}

foreach (['composer.json', 'composer.lock'] as $dependencyDefinition) {
    if (! is_file($dependencyRoot.'/'.$dependencyDefinition)) {
        $fail('The clean native Pest dependency definition is incomplete.');
    }
}

$expectedDependencyHashes = [
    'composer.json' => hash_file('sha256', $droveRoot.'/benchmarks/corpus/locks/pest-native.composer.json'),
    'composer.lock' => hash_file('sha256', $droveRoot.'/benchmarks/corpus/locks/pest-native.lock'),
];

foreach ($expectedDependencyHashes as $dependencyDefinition => $expectedHash) {
    $actualHash = hash_file('sha256', $dependencyRoot.'/'.$dependencyDefinition);

    if (! is_string($expectedHash)
        || ! is_string($actualHash)
        || ! hash_equals($expectedHash, $actualHash)) {
        $fail(sprintf(
            'The clean native Pest dependency %s does not match the committed lock identity.',
            $dependencyDefinition,
        ));
    }
}

spl_autoload_register(static function (string $class) use ($droveRoot, $pestRoot): void {
    $roots = [
        'Drove\\' => $droveRoot.'/src/Drove/',
        'Pest\\' => $pestRoot.'/src/',
        'Tests\\' => $pestRoot.'/tests/',
    ];

    foreach ($roots as $prefix => $root) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $root.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});

if (! class_exists(ClassLoader::class, false)) {
    require_once $dependencyComposer.'/ClassLoader.php';
}

$dependencyLoader = new ClassLoader;
$dependencyPrefixes = require $dependencyComposer.'/autoload_psr4.php';
$excludedDependencyPrefixes = [
    'Drove\\',
    'Orchestra\\Testbench\\',
    'Pest\\',
    'PHPUnit\\',
    'Tests\\',
];

foreach ($dependencyPrefixes as $prefix => $directories) {
    if (array_any(
        $excludedDependencyPrefixes,
        static fn (string $excluded): bool => str_starts_with($prefix, $excluded),
    )) {
        continue;
    }

    $dependencyLoader->addPsr4($prefix, $directories);
}

$dependencyClassmap = require $dependencyComposer.'/autoload_classmap.php';
$dependencyClassmap = array_filter(
    $dependencyClassmap,
    static fn (string $file, string $class): bool => ! array_any(
        $excludedDependencyPrefixes,
        static fn (string $excluded): bool => str_starts_with($class, $excluded),
    )
        && ! str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $droveRoot).'/src/')
        && ! str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $droveRoot).'/tests/'),
    ARRAY_FILTER_USE_BOTH,
);
$dependencyLoader->addClassMap($dependencyClassmap);
$dependencyLoader->register();

require $droveRoot.'/src/Drove/Native/functions.php';
require_once __DIR__.'/PestCorpusCodemod.php';
require_once __DIR__.'/fixtures.php';

/** @return list<string> */
function droveNativeCorpusSubjects(): array
{
    $encoded = getenv('DROVE_NATIVE_CORPUS_SUBJECTS');

    if (! is_string($encoded)) {
        return [];
    }

    $subjects = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);

    if (! is_array($subjects) || ! array_is_list($subjects)
        || ! array_all($subjects, static fn (mixed $subject): bool => is_string($subject))) {
        throw new RuntimeException('The native corpus production-subject guard is invalid.');
    }

    return $subjects;
}

/**
 * @return array{
 *     production_class_loaded: bool,
 *     allowed_subjects: list<array{class: string, file: string}>,
 *     forbidden_classes: list<string>,
 *     forbidden_files: list<string>,
 *     pid: int
 * }
 */
function droveNativeCorpusRuntimeInspection(string $drove, string $pest): array
{
    $subjects = array_fill_keys(array_map('strtolower', droveNativeCorpusSubjects()), true);
    $declaredSymbols = [
        ...get_declared_classes(),
        ...get_declared_interfaces(),
        ...get_declared_traits(),
    ];
    $allowedSubjects = [];

    foreach ($declaredSymbols as $class) {
        if (! isset($subjects[strtolower($class)])) {
            continue;
        }

        $file = (new ReflectionClass($class))->getFileName();
        $allowedSubjects[] = [
            'class' => $class,
            'file' => is_string($file) ? str_replace('\\', '/', $file) : '',
        ];
    }

    $forbiddenClasses = array_values(array_filter(
        $declaredSymbols,
        static fn (string $class): bool => ! isset($subjects[strtolower($class)]) && preg_match(
            '/^(?:PHPUnit\\\\|Orchestra\\\\Testbench\\\\|Drove\\\\Pest\\\\|Drove\\\\Bridge\\\\|Pest\\\\)/',
            $class,
        ) === 1,
    ));
    $includedFiles = array_map(static fn (string $path): string => str_replace('\\', '/', $path), get_included_files());
    $forbiddenFiles = array_values(array_filter(
        $includedFiles,
        static fn (string $path): bool => str_starts_with($path, $drove.'/src/Drove/Pest/')
            || str_starts_with($path, $drove.'/src/Drove/Bridge/')
            || $path === $drove.'/packages/drove-laravel/src/TestbenchBridge.php'
            || in_array($path, [
                $pest.'/src/Functions.php',
                $pest.'/src/Pest.php',
                $pest.'/src/Kernel.php',
                $pest.'/src/TestSuite.php',
            ], true),
    ));

    $productionClassLoaded = $allowedSubjects !== [];

    $pid = getmypid();

    if (! is_int($pid)) {
        throw new RuntimeException('The native corpus runtime guard could not resolve its process ID.');
    }

    return [
        'production_class_loaded' => $productionClassLoaded,
        'allowed_subjects' => $allowedSubjects,
        'forbidden_classes' => $forbiddenClasses,
        'forbidden_files' => $forbiddenFiles,
        'pid' => $pid,
    ];
}

/** @param array<string, mixed> $task */
function droveNativeCorpusAssertRuntime(array $task): void
{
    $drove = getenv('DROVE_NATIVE_CORPUS_DROVE_ROOT');
    $pest = getenv('DROVE_NATIVE_CORPUS_PEST_ROOT');
    $guardFile = getenv('DROVE_NATIVE_CORPUS_GUARD_FILE');

    if (! is_string($drove) || ! is_string($pest) || ! is_string($guardFile)) {
        throw new RuntimeException('The native corpus runtime guard is not configured.');
    }

    $inspection = droveNativeCorpusRuntimeInspection($drove, $pest);
    $inspection['task'] = $task;

    if ($inspection['forbidden_classes'] !== []
        || $inspection['forbidden_files'] !== []) {
        throw new RuntimeException('DROVE_NATIVE_CORPUS_FORBIDDEN_RUNTIME');
    }

    $encoded = json_encode($inspection, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

    if (file_put_contents($guardFile, $encoded, FILE_APPEND | LOCK_EX) !== strlen($encoded)) {
        throw new RuntimeException('The native corpus runtime guard could not record descendant evidence.');
    }
}

/** @return array{stdout: string, stderr: string, exit_code: int} */
function droveNativeCorpusDatasetDiagnostic(string $scenario): array
{
    if (! in_array($scenario, ['missing-only', 'missing-with-pass', 'closure-throws'], true)) {
        throw new InvalidArgumentException('Unknown native Pest corpus dataset diagnostic scenario.');
    }

    $process = proc_open(
        [PHP_BINARY, __DIR__.'/dataset-diagnostic-command.php', $scenario],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        options: ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('The native dataset diagnostic command could not start.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if (! is_string($stdout) || ! is_string($stderr) || $exitCode < 0) {
        throw new RuntimeException('The native dataset diagnostic command returned invalid process evidence.');
    }

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit_code' => $exitCode,
    ];
}

$git = /** @param list<string> $arguments */ static function (array $arguments, ?string $root = null) use ($pestRoot, $fail): string {
    $command = ['git', '-C', $root ?? $pestRoot];
    array_push($command, ...$arguments);
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        options: ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        $fail('Git could not inspect the pinned Pest checkout.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0 || ! is_string($stdout)) {
        $fail('Git failed to inspect the pinned Pest checkout: '.trim((string) $stderr));
    }

    return $stdout;
};

$expectedCommit = '6b2cd358e8a9d6d1abb93804b70e1c659bbc411b';
$baselineContents = file_get_contents(__DIR__.'/baseline.json');

if (! is_string($baselineContents)) {
    $fail('The normalized Pest baseline is missing.');
}

$baseline = json_decode($baselineContents, true, 64, JSON_THROW_ON_ERROR);
$baselineLockHash = hash_file('sha256', $droveRoot.'/benchmarks/corpus/locks/pest-baseline.lock');
$assert(
    is_array($baseline)
        && ($baseline['schema'] ?? null) === 2
        && ($baseline['commit'] ?? null) === $expectedCommit
        && is_string($baselineLockHash)
        && ($baseline['dependency_lock_sha256'] ?? null) === $baselineLockHash,
    'The normalized Pest baseline identity diverged.',
);
$cohortConfiguration = require __DIR__.'/cohort.php';
$baselineFiles = $baseline['files'] ?? null;
$baselineCases = $baseline['cases'] ?? null;
$assert(
    is_array($cohortConfiguration)
    && array_is_list($cohortConfiguration)
    && array_all($cohortConfiguration, static fn (mixed $path): bool => is_string($path))
    && is_array($baselineFiles)
    && array_keys($baselineFiles) === $cohortConfiguration
    && is_array($baselineCases)
    && array_is_list($baselineCases)
    && count($baselineCases) === ($baseline['tests'] ?? null)
    && array_all(
        $baselineCases,
        static fn (mixed $case): bool => is_array($case)
            && array_keys($case) === ['id', 'status', 'assertions', 'stdout', 'stderr']
            && is_string($case['id'])
            && is_string($case['status'])
            && is_int($case['assertions'])
            && is_string($case['stdout'])
            && is_string($case['stderr']),
    ),
    'The normalized Pest file baseline diverged from the pinned cohort.',
);
$sourcePaths = [];

foreach ($cohortConfiguration as $sourcePath) {
    $file = $baselineFiles[$sourcePath] ?? null;
    $assert(
        is_array($file)
            && is_int($file['tests'] ?? null)
            && is_int($file['assertions'] ?? null)
            && is_int($file['passed'] ?? 0)
            && is_int($file['skipped'] ?? 0)
            && ($file['passed'] ?? 0) + ($file['skipped'] ?? 0) === $file['tests'],
        'A normalized Pest file count is invalid: '.$sourcePath,
    );
    $sourcePaths[$sourcePath] = [
        'tests' => $file['tests'],
        'passed' => $file['passed'] ?? 0,
        'skipped' => $file['skipped'] ?? 0,
        'assertions' => $file['assertions'],
    ];
}
$startedNs = hrtime(true);
$commit = trim($git(['rev-parse', 'HEAD']));
$assert($commit === $expectedCommit, 'The Pest checkout is not at the pinned v5.0.1 commit.');
$droveRevision = getenv('DROVE_EXPECTED_REVISION');
$droveRevision = $droveRevision === false || $droveRevision === '' ? null : $droveRevision;
$assert(
    $droveRevision === null || preg_match('/^[0-9a-f]{40}$/D', $droveRevision) === 1,
    'DROVE_EXPECTED_REVISION must be an exact Drove commit.',
);

if (is_string($droveRevision)) {
    $droveHead = trim($git(['rev-parse', 'HEAD'], $droveRoot));
    $droveStatus = $git(['status', '--porcelain=v1', '--untracked-files=all'], $droveRoot);
    $assert($droveHead === $droveRevision && trim($droveStatus) === '', 'The final native Pest evidence is not bound to a clean Drove revision.');
}
$sources = [];

foreach ($cohortConfiguration as $sourcePath) {
    $sources[$sourcePath] = $git(['show', 'HEAD:'.$sourcePath]);
    $assert($sources[$sourcePath] !== '', 'A pinned Pest source is empty: '.$sourcePath);
    $assert(
        hash('sha256', $sources[$sourcePath]) === ($baseline['sources'][$sourcePath] ?? null),
        'A pinned Pest source diverged from its independent baseline: '.$sourcePath,
    );
}

$afterAllSource = str_replace(["\r\n", "\r"], "\n", $sources['tests/Features/AfterAll.php']);
$assert(
    PestCorpusCodemod::migrate($afterAllSource, 'tests/Features/AfterAll.php')
        === PestCorpusCodemod::migrate(str_replace("\n", "\r\n", $afterAllSource), 'tests/Features/AfterAll.php'),
    'The pinned Pest corpus codemod depends on checkout line endings.',
);

$declaredExclusions = require __DIR__.'/exclusions.php';
$inventoryContents = file_get_contents($droveRoot.'/benchmarks/corpus/native-surface-inventory.json');

if (! is_string($inventoryContents)) {
    $fail('The native surface inventory is missing.');
}

$inventory = json_decode($inventoryContents, true, 512, JSON_THROW_ON_ERROR);
$pestInventory = array_find(
    $inventory['corpora'] ?? [],
    static fn (mixed $corpus): bool => is_array($corpus) && ($corpus['id'] ?? null) === 'pest',
);
$selectedPaths = is_array($pestInventory) ? ($pestInventory['selection']['paths'] ?? null) : null;
$selectedCaseCount = is_array($pestInventory) ? ($pestInventory['selection']['case_count'] ?? null) : null;
$assert(
    is_array($declaredExclusions)
        && ! array_is_list($declaredExclusions)
        && count($declaredExclusions) === 15
        && array_all(array_keys($declaredExclusions), static fn (mixed $path): bool => is_string($path))
        && array_all($declaredExclusions, static fn (mixed $diagnostic): bool => is_string($diagnostic))
        && is_array($selectedPaths)
        && array_is_list($selectedPaths)
        && count($selectedPaths) === 143
        && $selectedCaseCount === 797
        && ($baseline['tests'] ?? null) === 686,
    'The selected Pest corpus or its declared exclusion contract is invalid.',
);
$classifiedPaths = [...$cohortConfiguration, ...array_keys($declaredExclusions)];
$sortedClassifiedPaths = $classifiedPaths;
$sortedSelectedPaths = $selectedPaths;
sort($sortedClassifiedPaths, SORT_STRING);
sort($sortedSelectedPaths, SORT_STRING);
$assert(
    count(array_intersect($cohortConfiguration, array_keys($declaredExclusions))) === 0
        && $sortedClassifiedPaths === $sortedSelectedPaths,
    'The pinned Pest selection contains an unclassified or multiply classified source.',
);
$excludedSources = [];

foreach ($declaredExclusions as $sourcePath => $_diagnostic) {
    $excludedSources[$sourcePath] = $git(['show', 'HEAD:'.$sourcePath]);
    $assert($excludedSources[$sourcePath] !== '', 'A declared Pest exclusion source is empty: '.$sourcePath);
}

$productionAssets = [];

foreach ([
    'bin/pest-tia-vite-deps.mjs' => '031a7cdb6e0efd7ee408ca51e1813696bb72ef66',
] as $assetPath => $blob) {
    $assert(trim($git(['rev-parse', 'HEAD:'.$assetPath])) === $blob, 'A Pest production asset drifted: '.$assetPath);
    $contents = $git(['show', 'HEAD:'.$assetPath]);
    $productionAssets[$assetPath] = [
        'blob' => $blob,
        'sha256' => hash('sha256', $contents),
        'contents' => $contents,
    ];
}

$runtimeRejectionSources = [
    'tests/Unit/Support/DatasetInfo.php' => '4b67fa2221bb953e5dbb60b740dd62047481f0fe',
    'src/Support/DatasetInfo.php' => '70ca6df573404ef4b9198a92b865af2d3ead60ca',
    'src/Pest.php' => '206952dbb986f9e75a665772a451ad861dab0e41',
];
$runtimeRejectionContents = [];

foreach ($runtimeRejectionSources as $path => $blob) {
    $assert(trim($git(['rev-parse', 'HEAD:'.$path])) === $blob, 'A Pest runtime rejection input drifted: '.$path);
    $runtimeRejectionContents[$path] = $git(['show', 'HEAD:'.$path]);
}

$assert(
    str_contains($runtimeRejectionContents['src/Support/DatasetInfo.php'], 'use function Pest\\testDirectory;')
        && str_contains($runtimeRejectionContents['src/Pest.php'], 'TestSuite::getInstance()->testPath'),
    'The DatasetInfo runtime rejection no longer reaches Pest TestSuite.',
);
$runtimeRejections = [[
    'source' => 'tests/Unit/Support/DatasetInfo.php',
    'diagnostic' => 'DROVE_NATIVE_CORPUS_PEST_TEST_SUITE_DEPENDENCY',
    'dependency' => 'Pest\\testDirectory() -> Pest\\TestSuite',
    'blobs' => $runtimeRejectionSources,
]];

$discoveryMs = (hrtime(true) - $startedNs) / 1_000_000;

$startedNs = hrtime(true);
$registry = Registry::load();
$migration = new Migrator(new Scanner($registry));
$codemodConfiguration = require __DIR__.'/codemod-options.php';
$assert(
    is_array($codemodConfiguration)
        && is_array($codemodConfiguration['matchers'] ?? null)
        && is_array($codemodConfiguration['trustedFunctions'] ?? null)
        && is_array($codemodConfiguration['imports'] ?? null)
        && is_array($codemodConfiguration['subjects'] ?? null),
    'The Pest corpus codemod configuration is invalid.',
);
$codemodOptions = new CodemodOptions(
    matchers: $codemodConfiguration['matchers'],
    trustedFunctions: $codemodConfiguration['trustedFunctions'],
    imports: $codemodConfiguration['imports'],
    subjects: $codemodConfiguration['subjects'],
);
$extensionManifest = Manifest::fromComposerPackage([
    'name' => 'drove/native-pest-corpus',
    'version' => '1.0.0',
    'type' => 'library',
    'autoload' => ['psr-4' => ['DroveNativePestCorpus\\' => 'src/']],
    'extra' => [
        'drove' => [
            'extension' => [
                'schema' => 1,
                'id' => 'drove/native-pest-corpus',
                'entrypoint' => 'DroveNativePestCorpus\\PestCorpusEntrypoint',
                'api' => ['min' => 1, 'max' => 1],
                'contributions' => ['matcher'],
                'configuration' => [],
            ],
        ],
    ],
]);
$extensionRegistry = new ExtensionRegistry(
    $extensionManifest,
    $extensionManifest->validateConfiguration([]),
);
new PestCorpusEntrypoint()->register($extensionRegistry);
$extensions = new ExtensionSet([$extensionRegistry->freeze()]);
$productionSubjects = array_values(array_unique(array_merge(...array_values($codemodConfiguration['subjects']))));
sort($productionSubjects, SORT_STRING);
$contractPath = 'tests/Namespaced.php';
$contractSource = <<<'PHP'
<?php

namespace Corpus;

use PHPUnit\Framework\ExpectationFailedException;
use Pest\Plugins\Tia\ContentHash as ContentHashSubject;

beforeEach(fn (): null => null);
test('portable', fn () => expect(ContentHashSubject::class)->toBeString());
PHP;
$contractOptions = new CodemodOptions(
    trustedFunctions: [$contractPath => ['beforeEach', 'expect', 'test']],
    imports: [$contractPath => [
        'PHPUnit\Framework\ExpectationFailedException' => 'Drove\Kernel\AssertionFailed',
    ]],
    subjects: [$contractPath => ['Pest\Plugins\Tia\ContentHash']],
);
$contractMigration = $migration->migrate($contractSource, $contractPath, $contractOptions);
$assert(
    $contractMigration->blockers === []
        && ($contractMigration->applied['trusted-import-map-v1'] ?? null) === 1
        && ($contractMigration->applied['native-qualified-call-v1'] ?? null) === 3
        && str_contains($contractMigration->source, 'use Pest\Plugins\Tia\ContentHash as ContentHashSubject;'),
    'Exact path-owned function, import, and production-subject mappings did not migrate.',
);
$wrongPathDiagnostics = array_map(
    static fn ($finding): string => $finding->diagnostic,
    $migration->migrate($contractSource, 'tests/Wrong.php', $contractOptions)->blockers,
);
$wrongFunctionOptions = new CodemodOptions(
    trustedFunctions: [$contractPath => ['expect', 'test']],
    imports: [$contractPath => [
        'PHPUnit\Framework\ExpectationFailedException' => 'Drove\Kernel\AssertionFailed',
    ]],
);
$wrongFunctionDiagnostics = array_map(
    static fn ($finding): string => $finding->diagnostic,
    $migration->migrate($contractSource, $contractPath, $wrongFunctionOptions)->blockers,
);
$unmappedImportOptions = new CodemodOptions(
    trustedFunctions: [$contractPath => ['beforeEach', 'expect', 'test']],
);
$unmappedImportDiagnostics = array_map(
    static fn ($finding): string => $finding->diagnostic,
    $migration->migrate($contractSource, $contractPath, $unmappedImportOptions)->blockers,
);
$assert(
    in_array('DROVE_MIGRATION_AMBIGUOUS_FUNCTION', $wrongPathDiagnostics, true)
        && in_array('DROVE_MIGRATION_BRIDGE_PHPUNIT_ASSERTION_EXCEPTION', $wrongPathDiagnostics, true)
        && in_array('DROVE_MIGRATION_UNSUPPORTED_SELF_TEST_RUNTIME_IMPORT', $wrongPathDiagnostics, true)
        && in_array('DROVE_MIGRATION_AMBIGUOUS_FUNCTION', $wrongFunctionDiagnostics, true)
        && in_array('DROVE_MIGRATION_BRIDGE_PHPUNIT_ASSERTION_EXCEPTION', $unmappedImportDiagnostics, true),
    'A wrong path, wrong function, unmapped import, or unowned subject did not fail closed.',
);
$forbiddenSubjectRejected = false;

try {
    new CodemodOptions(subjects: [$contractPath => ['Pest\TestSuite']]);
} catch (InvalidArgumentException) {
    $forbiddenSubjectRejected = true;
}

$assert($forbiddenSubjectRejected, 'A frontend or runner class was accepted as a production subject.');
$supportedHookMigration = $migration->migrate(
    '<?php beforeEach()->expect(true)->toBeTrue(); beforeEach()->skip();',
    'tests/Hook.php',
);
$hookDiagnostics = $migration->migrate('<?php beforeEach()->with([1]);', 'tests/Hook.php')->blockers;
$runtimeImportDiagnostics = $migration->migrate(
    '<?php use Pest\\TestSuite;',
    'tests/Runtime.php',
)->blockers;
$shutdownDiagnostics = $migration->migrate(
    '<?php register_shutdown_function(static fn (): null => null);',
    'tests/Shutdown.php',
)->blockers;
$assert(
    $supportedHookMigration->blockers === []
        && str_contains($supportedHookMigration->source, '\\Drove\\Native\\beforeEach()')
        && ($hookDiagnostics[0]->diagnostic ?? null) === 'DROVE_MIGRATION_UNSUPPORTED_HOOK_PROXY'
        && ($runtimeImportDiagnostics[0]->diagnostic ?? null) === 'DROVE_MIGRATION_UNSUPPORTED_SELF_TEST_RUNTIME_IMPORT'
        && ($shutdownDiagnostics[0]->diagnostic ?? null) === 'DROVE_NATIVE_UNSUPPORTED_SHUTDOWN_CALLBACK',
    'Supported hook modifiers or unsupported hook/runtime diagnostics are unstable.',
);
$migrations = [];
$corpusCodemods = [];

foreach ($sources as $sourcePath => $source) {
    $adapted = PestCorpusCodemod::migrate($source, $sourcePath);
    $first = $migration->migrate($adapted, $sourcePath, $codemodOptions);
    $secondAdapted = PestCorpusCodemod::migrate($first->source, $sourcePath);
    $second = $migration->migrate($secondAdapted, $sourcePath, $codemodOptions);
    $assert($adapted !== $source || $first->changed(), 'A pinned Pest source was not migrated: '.$sourcePath);
    $assert($first->blockers === [], 'A pinned Pest source retained migration blockers: '.$sourcePath);
    $assert(
        $secondAdapted === $first->source && ! $second->changed() && $second->applied === [],
        'The Pest codemod is not idempotent: '.$sourcePath,
    );
    $migrations[$sourcePath] = $first;

    if ($adapted !== $source) {
        $corpusCodemods[$sourcePath] = [
            'input_sha256' => hash('sha256', $source),
            'output_sha256' => hash('sha256', $adapted),
            'codemod' => 'native-pest-corpus-v1',
        ];
    }
}

$declaredExclusionEvidence = [];

foreach ($excludedSources as $sourcePath => $source) {
    $result = $migration->migrate($source, $sourcePath, $codemodOptions);
    $diagnostics = array_values(array_unique(array_map(
        static fn ($finding): string => $finding->diagnostic,
        $result->blockers,
    )));
    sort($diagnostics, SORT_STRING);
    $expectedDiagnostic = $declaredExclusions[$sourcePath];
    $assert(
        in_array($expectedDiagnostic, $diagnostics, true),
        'A declared Pest exclusion lost its stable diagnostic: '.$sourcePath,
    );
    $declaredExclusionEvidence[] = [
        'path' => $sourcePath,
        'sha256' => hash('sha256', $source),
        'diagnostic' => $expectedDiagnostic,
        'observed_diagnostics' => $diagnostics,
    ];
}

$runtimeExclusions = [];

if (PHP_OS_FAMILY === 'Windows') {
    $runtimeExclusions['tests/Unit/Plugins/Tia/ViteDepsHelper.php'] = [
        'diagnostic' => 'DROVE_NATIVE_CORPUS_PLATFORM_REJECTION',
        'reason' => 'upstream-node-esm-windows-absolute-path',
        'tests' => $sourcePaths['tests/Unit/Plugins/Tia/ViteDepsHelper.php']['tests'],
        'assertions' => $sourcePaths['tests/Unit/Plugins/Tia/ViteDepsHelper.php']['assertions'],
    ];
}

$runtimeMigrations = array_diff_key($migrations, $runtimeExclusions);
$runtimeSourcePaths = array_diff_key($sourcePaths, $runtimeExclusions);

$codemodMs = (hrtime(true) - $startedNs) / 1_000_000;
$assert(array_all(
    $migrations,
    static fn ($result): bool => ($result->applied['native-qualified-call-v1'] ?? 0) > 0,
), 'A Pest cohort file did not qualify any native declarations or expectations.');

$suiteRoot = sys_get_temp_dir().'/drove-native-pest-'.bin2hex(random_bytes(8));
$guardFile = $suiteRoot.'/runtime-guard.jsonl';
putenv('DROVE_NATIVE_CORPUS_DROVE_ROOT='.str_replace('\\', '/', $droveRoot));
putenv('DROVE_NATIVE_CORPUS_PEST_ROOT='.str_replace('\\', '/', $pestRoot));
putenv('DROVE_NATIVE_CORPUS_GUARD_FILE='.$guardFile);
putenv('DROVE_NATIVE_CORPUS_SUBJECTS='.json_encode(
    $productionSubjects,
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
));
$suiteFiles = [];
$assetFiles = [];
$suiteDirectories = [];

foreach ($productionAssets as $assetPath => $asset) {
    $assetFile = $suiteRoot.'/'.$assetPath;
    $assetDirectory = dirname($assetFile);

    if (! is_dir($assetDirectory)) {
        $assert(mkdir($assetDirectory, 0700, true), 'A native Pest production-asset directory could not be created.');
    }

    $assert(file_put_contents($assetFile, $asset['contents']) === strlen($asset['contents']), 'A native Pest production asset could not be written.');
    $assetFiles[] = $assetFile;

    for ($directory = $assetDirectory; $directory !== $suiteRoot; $directory = dirname($directory)) {
        $suiteDirectories[$directory] = true;
    }
}

foreach ($runtimeMigrations as $sourcePath => $result) {
    $suiteFile = $suiteRoot.'/'.$sourcePath;
    $suiteDirectory = dirname($suiteFile);

    if (! is_dir($suiteDirectory)) {
        $assert(mkdir($suiteDirectory, 0700, true), 'A native Pest suite directory could not be created.');
    }

    $instrumentedSource = $result->source;
    $assert(
        ! str_contains(strtolower($instrumentedSource), 'register_shutdown_function'),
        'A migrated Pest source retained unsupported PHP shutdown semantics: '.$sourcePath,
    );
    $assert(file_put_contents($suiteFile, $instrumentedSource) === strlen($instrumentedSource), 'A migrated Pest source could not be written.');
    $suiteFiles[$sourcePath] = $suiteFile;

    for ($directory = $suiteDirectory; $directory !== $suiteRoot; $directory = dirname($directory)) {
        $suiteDirectories[$directory] = true;
    }
}

$afterAllResidue = $suiteRoot.'/tests/Features/after-all-test';
$cleanup = static function () use ($afterAllResidue, $assetFiles, $guardFile, $suiteRoot, $suiteDirectories, $suiteFiles): void {
    if (is_file($afterAllResidue) && ! unlink($afterAllResidue)) {
        throw new RuntimeException('The native Pest afterAll residue could not be removed.');
    }

    if (is_file($guardFile)) {
        if (! unlink($guardFile)) {
            throw new RuntimeException('The native Pest runtime evidence could not be removed.');
        }
    }

    foreach ($suiteFiles as $suiteFile) {
        if (is_file($suiteFile) && ! unlink($suiteFile)) {
            throw new RuntimeException('A migrated native Pest source could not be removed.');
        }
    }

    foreach ($assetFiles as $assetFile) {
        if (is_file($assetFile) && ! unlink($assetFile)) {
            throw new RuntimeException('A native Pest production asset could not be removed.');
        }
    }

    $directories = array_keys($suiteDirectories);
    usort($directories, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

    foreach ($directories as $directory) {
        if (is_dir($directory) && ! rmdir($directory)) {
            throw new RuntimeException('A native Pest staging directory could not be removed: '.$directory);
        }
    }

    if (is_dir($suiteRoot) && ! rmdir($suiteRoot)) {
        throw new RuntimeException('The native Pest staging root could not be removed.');
    }
};

$serialSourceFiles = [
    'tests/Features/Expect/toBeFile.php',
    'tests/Features/Expect/toBeReadableFile.php',
    'tests/Features/Expect/toBeWritableFile.php',
];
$serialSourceSet = array_fill_keys($serialSourceFiles, true);
$serialRuntimeSourcePaths = array_intersect_key($runtimeSourcePaths, $serialSourceSet);
$nonserialRuntimeSourcePaths = array_diff_key($runtimeSourcePaths, $serialSourceSet);
$assert(
    array_sum(array_column($serialRuntimeSourcePaths, 'tests')) === 12,
    'The pinned Pest serial resource cohort diverged.',
);
$schedulerName = getenv('DROVE_NATIVE_CORPUS_SCHEDULER') ?: 'auto';

if ($schedulerName === 'auto') {
    $schedulerName = getenv('DROVER_LIBRARY') ? 'drover' : 'inline';
}

$assert(in_array($schedulerName, ['drover', 'inline'], true), 'Unknown native corpus scheduler.');
$processes = (int) (getenv('DROVE_NATIVE_CORPUS_PROCESSES') ?: 1);
$assert($processes >= 1 && $processes <= 30, 'Native corpus processes must be between 1 and 30.');
$scheduler = static function (string $name, int $lanes) use ($schedulerName): Scheduler {
    if ($schedulerName === 'drover') {
        return new DroverScheduler('native-corpus-pest-'.$name.'-'.$lanes, $lanes);
    }

    return new class($name) implements Scheduler
    {
        public function __construct(private string $name) {}

        public function runId(): string
        {
            return 'native-corpus-pest-inline-'.$this->name;
        }

        public function map(array $tasks, Closure $execute): array
        {
            $results = [];

            foreach ($tasks as $ordinal => $task) {
                $startedNs = hrtime(true);
                ob_start();

                try {
                    $value = $execute($task);
                    $stdout = ob_get_clean();
                } catch (Throwable $throwable) {
                    ob_end_clean();

                    throw $throwable;
                }

                $finishedNs = hrtime(true);
                $results[] = [
                    'id' => $task['id'],
                    'kind' => $task['kind'],
                    'scope_id' => $task['scope_id'],
                    'ordinal' => $ordinal,
                    'status' => 'passed',
                    'failure' => null,
                    'value' => $value,
                    'stdout' => is_string($stdout) ? $stdout : '',
                    'stderr' => '',
                    'memory_peak_bytes' => memory_get_peak_usage(true),
                    'events' => [],
                    'telemetry' => [
                        'pid' => getmypid(),
                        'pgid' => getmypid(),
                        'started_ns' => $startedNs,
                        'finished_ns' => $finishedNs,
                        'duration_ms' => ($finishedNs - $startedNs) / 1_000_000,
                        'exit_code' => 0,
                        'signal' => null,
                    ],
                ];
            }

            return [
                'results' => $results,
                'completion_order' => array_column($tasks, 'id'),
            ];
        }

        public function withPermit(array $scopes, Closure $work): mixed
        {
            return $work();
        }
    };
};
$dispatches = [
    'nonserial' => [
        'files' => array_intersect_key($suiteFiles, $nonserialRuntimeSourcePaths),
        'processes' => $processes,
    ],
    'serial' => [
        'files' => array_intersect_key($suiteFiles, $serialRuntimeSourcePaths),
        'processes' => 1,
    ],
];
$plans = [];
$dispatchRuns = [];
$dispatchSchedulers = [];
$planningMs = 0.0;
$executionMs = 0.0;

try {
    $GLOBALS['__PEST_INTERNAL_TEST_SUITE'] = true;

    foreach ($dispatches as $name => $dispatch) {
        $startedNs = hrtime(true);
        $declarations = Declarations::capture(
            static function () use ($dispatch): void {
                foreach ($dispatch['files'] as $suiteFile) {
                    require $suiteFile;
                }
            },
            $suiteRoot,
            'Pinned Pest native '.$name.' cohort',
            $extensions,
        );
        $plans[$name] = $declarations->plan();
        $planningMs += (hrtime(true) - $startedNs) / 1_000_000;
        $startedNs = hrtime(true);
        $dispatchScheduler = $scheduler($name, $dispatch['processes']);
        $dispatchSchedulers[$name] = $dispatchScheduler;
        $dispatchRuns[$name] = new Runner(
            $dispatchScheduler,
            static function (array $task) use ($name): void {
                droveNativeCorpusAssertRuntime(['dispatch' => $name, ...$task]);
            },
        )->run($declarations);
        $executionMs += (hrtime(true) - $startedNs) / 1_000_000;
    }

    $run = [
        'tests' => [...$dispatchRuns['nonserial']['tests'], ...$dispatchRuns['serial']['tests']],
        'exit_code' => max($dispatchRuns['nonserial']['exit_code'], $dispatchRuns['serial']['exit_code']),
    ];
    $assert(! file_exists($afterAllResidue), 'The native Pest afterAll hook left filesystem residue.');
    $plan = $plans;
    $guardContents = is_file($guardFile) ? file_get_contents($guardFile) : false;

    if (! is_string($guardContents)) {
        $fail('The descendant runtime guard produced no evidence.');
    }

    $runtimeChecks = array_map(
        static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR),
        preg_split('/\R/', trim($guardContents)) ?: [],
    );
} finally {
    unset($GLOBALS['__PEST_INTERNAL_TEST_SUITE']);
    $cleanup();
}

$tests = $run['tests'];
$expectedTests = array_sum(array_column($runtimeSourcePaths, 'tests'));
$expectedPassed = array_sum(array_column($runtimeSourcePaths, 'passed'));
$expectedSkipped = array_sum(array_column($runtimeSourcePaths, 'skipped'));
$expectedRunnable = $expectedPassed;
$expectedAssertions = array_sum(array_column($runtimeSourcePaths, 'assertions'));
$assert(count($tests) === $expectedTests, 'The native Pest cohort case count diverged.');
$assert(
    ($run['exit_code'] ?? null) === 0,
    'The native Pest cohort returned a non-zero exit code: '.json_encode(
        array_values(array_map(
            static fn (array $test): array => [
                'name' => $test['name'],
                'status' => $test['status'],
                'failure' => $test['failure'],
            ],
            array_filter(
                $tests,
                static fn (array $test): bool => $test['status'] !== 'passed',
            ),
        )),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ),
);
$statusCounts = array_count_values(array_column($tests, 'status'));
$assert(
    ($statusCounts['passed'] ?? 0) === $expectedPassed
        && ($statusCounts['skipped'] ?? 0) === $expectedSkipped
        && array_sum($statusCounts) === $expectedTests,
    'The native Pest status counts diverged from Pest.',
);
$actualAssertions = array_sum(array_column($tests, 'assertions'));
$assertionDivergences = [];

foreach ($runtimeSourcePaths as $sourcePath => $expectation) {
    $observed = array_sum(array_map(
        static fn (array $test): int => $test['source']['path'] === $sourcePath
            ? $test['assertions']
            : 0,
        $tests,
    ));

    if ($observed !== $expectation['assertions']) {
        $assertionDivergences[$sourcePath] = [
            'expected' => $expectation['assertions'],
            'observed' => $observed,
        ];
    }
}
$assert(
    $actualAssertions === $expectedAssertions,
    sprintf(
        'The native Pest assertion count diverged from Pest: expected %d, observed %d, files %s.',
        $expectedAssertions,
        $actualAssertions,
        json_encode($assertionDivergences, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ),
);
$assert(count(array_unique(array_column($tests, 'id'))) === $expectedTests, 'The native Pest cohort produced duplicate case identities.');

$indexBySourceLine = static function (array $items, string $kind) use ($fail): array {
    $indexed = [];

    foreach ($items as $item) {
        $source = $item['source'] ?? null;

        if (! is_array($source)
            || ! is_string($source['path'] ?? null)
            || ! is_int($source['line'] ?? null)) {
            $fail(sprintf('A native Pest %s has no stable source location.', $kind));
        }

        $key = $source['path'].':'.$source['line'];

        $indexed[$key][] = $item;
    }

    return $indexed;
};
$collectPlanTests = static function (array $scope) use (&$collectPlanTests): array {
    $tests = is_array($scope['tests'] ?? null) ? $scope['tests'] : [];

    foreach (is_array($scope['children'] ?? null) ? $scope['children'] : [] as $child) {
        if (is_array($child)) {
            array_push($tests, ...$collectPlanTests($child));
        }
    }

    return $tests;
};
$plannedTests = [];

foreach ($plans as $plannedDispatch) {
    $plannedRoot = $plannedDispatch['root'] ?? null;

    if (! is_array($plannedRoot)) {
        $fail('A native Pest dispatch plan has no root scope.');
    }

    array_push($plannedTests, ...$collectPlanTests($plannedRoot));
}

$plannedBySourceLine = $indexBySourceLine($plannedTests, 'planned test');
$resultsBySourceLine = $indexBySourceLine($tests, 'result');
$singleAt = static function (array $indexed, string $sourceLine, string $kind) use ($fail): array {
    $matches = $indexed[$sourceLine] ?? [];

    if (count($matches) !== 1 || ! is_array($matches[0] ?? null)) {
        $fail(sprintf('Native Pest %s source location %s is not unique.', $kind, $sourceLine));
    }

    return $matches[0];
};
$expectedPlanMetadata = [
    'tests/Features/Assignee.php:7' => ['assignees' => ['nunomaduro', 'taylorotwell']],
    'tests/Features/Assignee.php:12' => [
        'assignees' => ['nunomaduro', 'taylorotwell', 'jamesbrooks', 'joedixon'],
        'notes' => ['an note between an the assignee'],
    ],
    'tests/Features/Issue.php:7' => ['issues' => [1, 2]],
    'tests/Features/Issue.php:12' => [
        'issues' => [1, 3, 4, 5, 6],
        'notes' => ['an note between an the issue'],
    ],
    'tests/Features/Pr.php:7' => ['prs' => [1, 2]],
    'tests/Features/Pr.php:12' => [
        'prs' => [1, 3, 4, 5, 6],
        'notes' => ['an note between an the pr'],
    ],
    'tests/Features/Ticket.php:7' => ['issues' => [1, 2]],
    'tests/Features/Ticket.php:12' => [
        'issues' => [1, 3, 4, 5, 6],
        'notes' => ['an note between an the ticket'],
    ],
    'tests/Features/Note.php:7' => [
        'notes' => ['This is before each static note', 'This is a note'],
    ],
    'tests/Features/Note.php:11' => [
        'notes' => ['This is before each static note'],
    ],
    'tests/Features/Note.php:17' => [
        'notes' => ['This is before each static note', 'This is a static note'],
    ],
    'tests/Features/Note.php:28' => [
        'notes' => [
            'This is before each static note',
            'This is before each describe static note',
            'This is a static note within describe',
            'This is describe static note',
        ],
    ],
    'tests/Features/Note.php:39' => [
        'notes' => [
            'This is before each static note',
            'This is before each describe static note',
            'This is before each nested describe static note',
            'This is a static note within a nested describe',
            'This is a nested describe static note',
            'This is describe static note',
        ],
    ],
    'tests/Features/Note.php:57' => [
        'notes' => [
            'This is before each static note',
            'This is before each matching describe static note',
            'This is a static note within a matching describe',
            'This is a nested matching static note',
        ],
    ],
    'tests/Features/Note.php:69' => [
        'notes' => [
            'This is before each static note',
            'This is before each matching describe static note',
            'This is before each matching describe static note, and should not contain the matching describe notes',
            'This is a static note within a matching describe, and should not contain the matching describe notes',
            'This is a nested matching static note, and should not contain the matching describe notes',
        ],
    ],
    'tests/Features/Note.php:77' => [
        'notes' => ['This is before each static note'],
    ],
    'tests/Features/ThrowsNoExceptions.php:9' => ['allows_no_assertions' => true],
    'tests/Features/ThrowsNoExceptions.php:14' => ['allows_no_assertions' => true],
];

foreach ($expectedPlanMetadata as $sourceLine => $metadata) {
    $plannedTest = $singleAt($plannedBySourceLine, $sourceLine, 'planned test');
    $assert(
        ($plannedTest['metadata'] ?? null) === $metadata,
        'Native Pest plan metadata diverged at '.$sourceLine.': '.json_encode(
            $plannedTest['metadata'] ?? null,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
    );
}

$coverageMetadata = [];

foreach ($plannedTests as $plannedTest) {
    $sourcePath = $plannedTest['source']['path'] ?? null;

    if (! is_string($sourcePath)
        || ! str_starts_with($sourcePath, 'tests/Features/Covers/')
        || ! isset($plannedTest['metadata']['coverage'])) {
        continue;
    }

    $coverageMetadata[$sourcePath] = $plannedTest['metadata']['coverage'];
}

$assert($coverageMetadata === [
    'tests/Features/Covers/ClassCoverage.php' => [[
        'kind' => 'class',
        'target' => 'Tests\\Fixtures\\Covers\\CoversClass1',
    ]],
    'tests/Features/Covers/FunctionCoverage.php' => [[
        'kind' => 'function',
        'target' => 'testCoversFunction',
    ]],
    'tests/Features/Covers/GuessCoverage.php' => [
        ['kind' => 'class', 'target' => 'Tests\\Fixtures\\Covers\\CoversClass3'],
        ['kind' => 'function', 'target' => 'testCoversFunction2'],
    ],
    'tests/Features/Covers/TraitCoverage.php' => [[
        'kind' => 'trait',
        'target' => 'Tests\\Fixtures\\Covers\\CoversTrait',
    ]],
], 'Native coverage targets did not survive planning.');

$expectedRuntimeNotes = [
    'tests/Features/Note.php:7' => ['This is before each runtime note'],
    'tests/Features/Note.php:11' => ['This is before each runtime note', 'This is a runtime note'],
    'tests/Features/Note.php:17' => ['This is before each runtime note', 'This is a runtime note'],
    'tests/Features/Note.php:28' => [
        'This is before each runtime note',
        'This is before each describe runtime note',
        'This is a runtime note within describe',
    ],
    'tests/Features/Note.php:39' => [
        'This is before each runtime note',
        'This is before each describe runtime note',
        'This is before each nested describe runtime note',
        'This is a runtime note within a nested describe',
    ],
    'tests/Features/Note.php:57' => [
        'This is before each runtime note',
        'This is before each matching describe runtime note',
        'This is a runtime note within a matching describe',
    ],
    'tests/Features/Note.php:69' => [
        'This is before each runtime note',
        'This is before each matching describe runtime note',
        'This is before each matching describe runtime note, and should not contain the matching describe notes',
        'This is a runtime note within a matching describe, and should not contain the matching describe notes',
    ],
    'tests/Features/Note.php:77' => [
        'This is before each runtime note',
        'This is a runtime note',
        'This is another runtime note',
    ],
];

foreach ($expectedRuntimeNotes as $sourceLine => $notes) {
    $result = $singleAt($resultsBySourceLine, $sourceLine, 'result');
    $assert(
        ($result['notes'] ?? null) === $notes,
        'Native Pest runtime notes diverged at '.$sourceLine.'.',
    );
}

$runtimeNoAssertionResult = $singleAt(
    $resultsBySourceLine,
    'tests/Features/ThrowsNoExceptions.php:3',
    'result',
);
$assert(
    ($runtimeNoAssertionResult['allows_no_assertions'] ?? false) === true,
    'Native Pest expectNotToPerformAssertions did not reach the runtime result.',
);

foreach ([7, 11, 15] as $line) {
    $expectationResult = $singleAt(
        $resultsBySourceLine,
        'tests/Features/BeforeEachProxiesToTestCallWithExpectations.php:'.$line,
        'result',
    );
    $skipResult = $singleAt(
        $resultsBySourceLine,
        'tests/Features/BeforeEachProxiesToTestCallWithSkip.php:'.$line,
        'result',
    );
    $assert(
        ($expectationResult['assertions'] ?? null) === 1,
        'A native beforeEach expectation action did not execute for every descendant.',
    );
    $assert(
        ($skipResult['status'] ?? null) === 'skipped',
        'A native beforeEach skip modifier did not apply to every descendant.',
    );
}

$nativeCaseSemantics = [];

foreach ($tests as $test) {
    $nativeCaseSemantics[$test['id']] = [
        'id' => $test['id'],
        'status' => $test['status'],
        'assertions' => $test['assertions'],
        'stdout' => $test['stdout'],
        'stderr' => $test['stderr'],
    ];
}

$boundaryAssertionCounts = [
    'test:file:tests/Features/Expect/toBeBetween.php::passes%20with%20int' => 4,
    'test:file:tests/Features/Expect/toBeBetween.php::passes%20with%20float' => 4,
    'test:file:tests/Features/Expect/toBeBetween.php::passes%20with%20float%20and%20int' => 4,
    'test:file:tests/Features/Expect/toBeBetween.php::passes%20with%20DateTime' => 4,
    'test:file:tests/Features/Expect/toBeBetween.php::failure%20with%20int' => 5,
    'test:file:tests/Features/Expect/toBeBetween.php::failure%20with%20float' => 5,
    'test:file:tests/Features/Expect/toBeBetween.php::failure%20with%20float%20and%20int' => 5,
    'test:file:tests/Features/Expect/toBeBetween.php::failure%20with%20DateTime' => 3,
    'test:file:tests/Features/Expect/toBeBetween.php::failures%20with%20custom%20message' => 6,
    'test:file:tests/Features/Expect/toBeBetween.php::not%20failures' => 5,
    'test:file:tests/Features/Expect/toBeGreaterThanOrEqual.php::passes' => 4,
    'test:file:tests/Features/Expect/toBeGreaterThanOrEqual.php::passes%20with%20DateTime%20and%20DateTimeImmutable' => 6,
    'test:file:tests/Features/Expect/toBeGreaterThanOrEqual.php::passes%20with%20strings' => 4,
    'test:file:tests/Features/Expect/toBeGreaterThanOrEqual.php::failures' => 3,
    'test:file:tests/Features/Expect/toBeGreaterThanOrEqual.php::failures%20with%20custom%20message' => 4,
    'test:file:tests/Features/Expect/toBeGreaterThanOrEqual.php::not%20failures' => 3,
    'test:file:tests/Features/Expect/toBeLessThanOrEqual.php::passes' => 4,
    'test:file:tests/Features/Expect/toBeLessThanOrEqual.php::passes%20with%20DateTime%20and%20DateTimeImmutable' => 6,
    'test:file:tests/Features/Expect/toBeLessThanOrEqual.php::passes%20with%20strings' => 4,
    'test:file:tests/Features/Expect/toBeLessThanOrEqual.php::failures' => 3,
    'test:file:tests/Features/Expect/toBeLessThanOrEqual.php::failures%20with%20custom%20message' => 4,
    'test:file:tests/Features/Expect/toBeLessThanOrEqual.php::not%20failures' => 3,
];

foreach ($boundaryAssertionCounts as $caseId => $assertions) {
    $assert(
        ($nativeCaseSemantics[$caseId]['assertions'] ?? null) === $assertions,
        'A multi-check boundary matcher assertion count diverged: '.$caseId,
    );
}

$baselineCaseSemantics = [];
$excludedCasePrefixes = array_map(
    static fn (string $path): string => 'test:file:'.$path.'::',
    array_keys($runtimeExclusions),
);

foreach ($baselineCases as $case) {
    if (array_any(
        $excludedCasePrefixes,
        static fn (string $prefix): bool => str_starts_with($case['id'], $prefix),
    )) {
        continue;
    }

    $baselineCaseSemantics[$case['id']] = [
        'id' => $case['id'],
        'status' => $case['status'],
        'assertions' => $case['assertions'],
        'stdout' => $case['stdout'],
        'stderr' => $case['stderr'],
    ];
}

ksort($nativeCaseSemantics, SORT_STRING);
ksort($baselineCaseSemantics, SORT_STRING);
$assert(
    $nativeCaseSemantics === $baselineCaseSemantics,
    'The native Pest case identity, status, assertions, stdout, or stderr diverged from Pest.',
);
$sourceCounts = array_count_values(array_column(array_column($tests, 'source'), 'path'));
$expectedSourceCounts = array_map(static fn (array $expectation): int => $expectation['tests'], $runtimeSourcePaths);
ksort($sourceCounts, SORT_STRING);
ksort($expectedSourceCounts, SORT_STRING);
$assert($sourceCounts === $expectedSourceCounts, 'The native Pest source identity distribution diverged.');
$strTests = array_values(array_filter(
    $tests,
    static fn (array $test): bool => $test['source']['path'] === 'tests/Unit/Support/Str.php',
));
$assert(array_column($strTests, 'name') === [
    'it evaluates the code [#0]',
    'it evaluates the code [#1]',
    'it evaluates the code [#2]',
], 'The native Pest dataset case names diverged.');
$assert(array_column($strTests, 'dataset') === [
    ['key' => 0, 'label' => '#0'],
    ['key' => 1, 'label' => '#1'],
    ['key' => 2, 'label' => '#2'],
], 'The native Pest dataset identity diverged.');

$parentInspection = droveNativeCorpusRuntimeInspection(
    str_replace('\\', '/', $droveRoot),
    str_replace('\\', '/', $pestRoot),
);
$assert($parentInspection['forbidden_classes'] === [], 'The native Pest parent loaded a forbidden runtime class.');
$assert($parentInspection['forbidden_files'] === [], 'The native Pest parent loaded a forbidden runtime file.');
$assert(array_all($runtimeChecks, static fn (array $check): bool => ($check['forbidden_classes'] ?? null) === []
    && ($check['forbidden_files'] ?? null) === []), 'A native Pest descendant loaded a forbidden runtime.');
$subjectEvidence = [$parentInspection, ...$runtimeChecks];
$loadedSubjects = [];

foreach ($subjectEvidence as $inspection) {
    $allowedSubjects = $inspection['allowed_subjects'] ?? null;
    $assert(is_array($allowedSubjects), 'A native Pest runtime inspection omitted production-subject evidence.');

    foreach ($allowedSubjects as $subject) {
        $class = $subject['class'] ?? null;
        $file = $subject['file'] ?? null;
        $assert(
            is_string($class)
                && in_array($class, $productionSubjects, true)
                && is_string($file)
                && str_starts_with($file, str_replace('\\', '/', $pestRoot).'/src/'),
            'A production subject resolved outside its exact pinned Pest owner.',
        );
        $loadedSubjects[$class."\0".$file] = ['class' => $class, 'file' => $file];
    }
}

ksort($loadedSubjects, SORT_STRING);
$dispatchConcurrency = [];
$telemetryCases = 0;
$allExecutorPids = [];

foreach ($dispatchRuns as $name => $dispatchRun) {
    $executorPids = [];
    $laneEvents = [];
    $dispatchTelemetryCases = 0;
    $dispatchTests = $dispatchRun['tests'] ?? null;
    $assert(is_array($dispatchTests), 'A native Pest dispatch omitted its tests.');

    foreach ($dispatchTests as $test) {
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
        $allExecutorPids[$pid] = true;
        $laneEvents[] = ['at' => $startedNs, 'delta' => 1];
        $laneEvents[] = ['at' => $finishedNs, 'delta' => -1];
        $dispatchTelemetryCases++;
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

    $requestedProcesses = $dispatches[$name]['processes'];
    $dispatchCaseCount = count(array_filter(
        $dispatchTests,
        static fn (array $test): bool => $test['status'] === 'passed',
    ));
    $topology = null;
    $scopeAuditPids = [];

    if ($schedulerName === 'drover') {
        $dispatchScheduler = $dispatchSchedulers[$name];

        if (! $dispatchScheduler instanceof DroverScheduler) {
            $fail('The Pest dispatch lost its Drover scheduler.');
        }

        $topology = $dispatchScheduler->topologyTelemetry();
        $assert($dispatchTelemetryCases === $dispatchCaseCount, 'Drover omitted per-case executor telemetry.');
        $assert(count($executorPids) === $dispatchCaseCount, 'Drover reused an executor PID instead of forking once per case.');
        $assert(
            $topology['executor_workers'] <= $dispatchCaseCount
                && $topology['forks'] === $topology['executor_workers'] + $topology['scope_workers']
                && $topology['process_anchors'] === 0,
            'Drover reported invalid parent-map topology.',
        );
        $assert(
            $observedPeakLanes >= 1
                && $observedPeakLanes <= min($requestedProcesses, $dispatchCaseCount),
            'Drover reported impossible native corpus lane telemetry.',
        );
        $dispatchAudits = array_values(array_filter(
            $runtimeChecks,
            static fn (array $check): bool => ($check['task']['dispatch'] ?? null) === $name,
        ));
        $testAuditPids = array_values(array_map(
            static fn (array $check): int => $check['pid'],
            array_filter(
                $dispatchAudits,
                static fn (array $check): bool => ($check['task']['kind'] ?? null) === 'test',
            ),
        ));
        $scopeAuditPids = array_values(array_map(
            static fn (array $check): int => $check['pid'],
            array_filter(
                $dispatchAudits,
                static fn (array $check): bool => ($check['task']['kind'] ?? null) === 'scope',
            ),
        ));
        $rootAuditPids = array_values(array_map(
            static fn (array $check): int => $check['pid'],
            array_filter(
                $dispatchAudits,
                static fn (array $check): bool => ($check['task']['kind'] ?? null) === 'root',
            ),
        ));
        sort($testAuditPids, SORT_NUMERIC);
        sort($scopeAuditPids, SORT_NUMERIC);
        sort($rootAuditPids, SORT_NUMERIC);
        $expectedExecutorPids = array_keys($executorPids);
        sort($expectedExecutorPids, SORT_NUMERIC);
        $assert(
            count($dispatchAudits) === $dispatchCaseCount + count($scopeAuditPids) + 1
                && count($scopeAuditPids) >= $topology['scope_workers']
                && count(array_unique($scopeAuditPids)) === count($scopeAuditPids)
                && count($rootAuditPids) === 1
                && $testAuditPids === $expectedExecutorPids,
            'The native Pest runtime audit is not bound to every scheduler worker.',
        );
        $assert(
            array_intersect($testAuditPids, [...$scopeAuditPids, ...$rootAuditPids]) === []
                && array_intersect($scopeAuditPids, $rootAuditPids) === [],
            'A native Pest test ran inside a scope or root worker instead of its own executor fork.',
        );
    }

    $dispatchConcurrency[$name] = [
        'requested_processes' => $requestedProcesses,
        'runnable_cases' => $dispatchCaseCount,
        'telemetry_cases' => $dispatchTelemetryCases,
        'unique_executor_pids' => count($executorPids),
        'one_child_pid_per_case' => $schedulerName === 'drover',
        'effective_forks' => $schedulerName === 'drover'
            ? $dispatchCaseCount + count($scopeAuditPids)
            : null,
        'observed_peak_lanes' => $observedPeakLanes,
        'topology' => $topology,
    ];
    $telemetryCases += $dispatchTelemetryCases;
}

if ($schedulerName === 'drover') {
    $assert(count($allExecutorPids) === $expectedRunnable, 'Drover reused an executor PID across native corpus dispatches.');
}

$cases = array_map(static fn (array $test): array => [
    'id' => $test['id'],
    'name' => $test['name'],
    'source' => $test['source'],
    'dataset' => $test['dataset'],
    'status' => $test['status'],
    'assertions' => $test['assertions'],
    'stdout' => $test['stdout'],
    'stderr' => $test['stderr'],
], $tests);
$semantic = [
    'baseline' => [
        'tests' => $expectedTests,
        'passed' => $expectedPassed,
        'skipped' => $expectedSkipped,
        'assertions' => $expectedAssertions,
        'exit_code' => 0,
    ],
    'native' => $cases,
    'exit_code' => $run['exit_code'],
];
$evidence = [
    'schema' => 1,
    'corpus' => 'pest',
    'commit' => $commit,
    'drove_revision' => $droveRevision,
    'sources' => array_map(
        static fn (string $source, string $path): array => [
            'path' => $path,
            'sha256' => hash('sha256', $source),
        ],
        $sources,
        array_keys($sources),
    ),
    'migrations' => array_map(
        static fn ($result): array => $result->jsonSerialize(),
        $migrations,
    ),
    'corpus_codemods' => $corpusCodemods,
    'selection_classification' => [
        'selected_files' => count($selectedPaths),
        'executed_files' => count($cohortConfiguration),
        'excluded_files' => count($declaredExclusions),
        'unclassified_files' => 0,
        'classified_cases' => $selectedCaseCount,
        'native_executed_cases' => $baseline['tests'],
        'excluded_cases' => $selectedCaseCount - $baseline['tests'],
        'unclassified_cases' => 0,
    ],
    'declared_exclusions' => $declaredExclusionEvidence,
    'runtime_rejections' => $runtimeRejections,
    'runtime_exclusions' => $runtimeExclusions,
    'compatibility_semantics' => [
        'plan_metadata_cases' => count($expectedPlanMetadata),
        'runtime_note_cases' => count($expectedRuntimeNotes),
        'hook_expectation_cases' => 3,
        'hook_skip_cases' => 3,
        'runtime_no_assertion_cases' => 1,
        'planned_no_assertion_cases' => 2,
        'coverage_metadata_cases' => count($coverageMetadata),
        'boundary_assertion_cases' => count($boundaryAssertionCounts),
    ],
    'baseline_sha256' => hash('sha256', $baselineContents),
    'plan_sha256' => hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
    'semantic_sha256' => hash('sha256', json_encode($semantic, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
    'baseline' => $semantic['baseline'],
    'native' => [
        'scheduler' => $schedulerName,
        'requested_processes' => $processes,
        'tests' => $expectedTests,
        'passed' => $expectedPassed,
        'skipped' => $expectedSkipped,
        'assertions' => $expectedAssertions,
        'exit_code' => 0,
        'concurrency' => [
            'runnable_cases' => $expectedRunnable,
            'telemetry_cases' => $telemetryCases,
            'unique_executor_pids' => count($allExecutorPids),
            'one_child_pid_per_case' => $schedulerName === 'drover',
            'observed_peak_lanes' => $dispatchConcurrency['nonserial']['observed_peak_lanes'],
            'cohorts' => $dispatchConcurrency,
        ],
        'cases' => $cases,
    ],
    'forbidden_runtime' => [
        'classes' => [],
        'files' => [],
        'descendant_checks' => count($runtimeChecks),
        'production_class_loaded_checks' => count(array_filter(
            $runtimeChecks,
            static fn (array $check): bool => ($check['production_class_loaded'] ?? false) === true,
        )),
    ],
    'production_subjects' => [
        'declared' => $productionSubjects,
        'loaded' => array_values($loadedSubjects),
        'descendant_loaded_checks' => count(array_filter(
            $runtimeChecks,
            static fn (array $check): bool => ($check['allowed_subjects'] ?? []) !== [],
        )),
    ],
    'production_assets' => array_map(
        static fn (array $asset, string $path): array => [
            'path' => $path,
            'blob' => $asset['blob'],
            'sha256' => $asset['sha256'],
        ],
        $productionAssets,
        array_keys($productionAssets),
    ),
    'dependency_loader' => [
        'mode' => 'clean-locked-vendor-selective',
        'status' => 'n5',
        'manifest_sha256' => hash_file('sha256', $dependencyRoot.'/composer.json'),
        'lock_sha256' => hash_file('sha256', $dependencyRoot.'/composer.lock'),
        'excluded_prefixes' => $excludedDependencyPrefixes,
        'root_dev_vendor_rejected' => true,
    ],
    'extension_front_door' => [
        'manifest' => $extensionManifest->toArray(),
        'matcher_count' => 8,
        'runtime_magic' => false,
        'core_matcher_override' => false,
    ],
    'timing_ms' => [
        'discovery' => round($discoveryMs, 3),
        'codemod' => round($codemodMs, 3),
        'planning' => round($planningMs, 3),
        'execution' => round($executionMs, 3),
    ],
    'memory_peak_bytes' => memory_get_peak_usage(true),
];

fwrite(STDOUT, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
