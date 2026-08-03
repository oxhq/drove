<?php

declare(strict_types=1);

use Drove\Compatibility\Registry;
use Drove\Laravel\LaravelMigrationProof;
use Drove\Migration\CodemodOptions;
use Drove\Migration\LaravelMigrator;
use Drove\Migration\Migrator;
use Drove\Migration\Scanner;

const INVOICESHELF_COMMIT = '403a4d67225a153838ec126c484339abf60229d1';
const AUTH_PATH = 'tests/Feature/Customer/AuthTest.php';
const AUTH_BLOB = 'e9bb9a5d3f578666360aabe251c6932152d5c9da';
const AUTH_SOURCE_SHA256 = 'fdb63adccde9842264cda898269794a4cc787a53fd14edab13b5f8b9e7653a44';
const AUTH_PORTABLE_SHA256 = '607a19fb80e777f09a816985a71ebeedc23c80562104f0e9574b4ed3f204ce7a';
const AUTH_RESULT_SHA256 = '1c5510fa3bdb2c8cc96532a7fb2167d0f77ce6d83ec64b6e1197fcf25c6a806f';
const SELECTION_SHA256 = 'f76fc760bd524e1394341d77ee9d1c9f30d28733ab0c6e83978b57beab698cc9';
const EQUIVALENT_SOURCE_SHA256 = '487edfac80877339fc2635051d6af4f3b1a6b13887d8ad394bc63690cbfbb226';
const EQUIVALENT_RESULT_SHA256 = '3ebd209ccf9129d2b0817ecaa9e8f9b7a97424ec296d758af3bcfa44c3e73748';
const TEST_CASE_BLOB = '0faafe0dab5513fe1d50ba89348a6806c56f3148';
const TEST_CASE_SHA256 = 'e696166de4ca4b23de65b34cb6f69f7cb38b506a1b277370b46c3d12e85674f7';
const PEST_BOOTSTRAP_BLOB = 'efe351cc1167ee20014729a0ff901b46a5a134d0';
const PEST_BOOTSTRAP_SHA256 = 'c9f76fdc7afa0e75b9a0132ef7e2d0d1c81d01bb304b3783839061e11c6acdc1';

$root = dirname(__DIR__, 2);
$checkout = $argv[1] ?? $root.'/.temp/native-corpus-invoiceshelf';

spl_autoload_register(static function (string $class) use ($root): void {
    if (! str_starts_with($class, 'Drove\\')) {
        return;
    }

    $path = $root.'/src/'.str_replace('\\', '/', $class).'.php';
    if (is_file($path)) {
        require $path;
    }
});

/** @param list<string> $command */
function nativeLaravelMigrationCommand(array $command): string
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, options: ['bypass_shell' => true]);

    if (! is_resource($process)) {
        throw new RuntimeException('DROVE_NATIVE_LARAVEL_MIGRATION_COMMAND_START');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0 || ! is_string($stdout) || ! is_string($stderr)) {
        throw new RuntimeException(sprintf(
            'DROVE_NATIVE_LARAVEL_MIGRATION_COMMAND_FAILED: %s',
            trim(is_string($stderr) ? $stderr : ''),
        ));
    }

    return $stdout;
}

function nativeLaravelMigrationBlob(string $checkout, string $path): string
{
    return nativeLaravelMigrationCommand([
        'git', '-C', $checkout, 'show', INVOICESHELF_COMMIT.':'.$path,
    ]);
}

function nativeLaravelMigrationObject(string $checkout, string $path): string
{
    return trim(nativeLaravelMigrationCommand([
        'git', '-C', $checkout, 'rev-parse', INVOICESHELF_COMMIT.':'.$path,
    ]));
}

function nativeLaravelMigrationAssert(bool $condition, string $diagnostic): void
{
    if (! $condition) {
        throw new RuntimeException($diagnostic);
    }
}

$head = trim(nativeLaravelMigrationCommand(['git', '-C', $checkout, 'rev-parse', 'HEAD']));
nativeLaravelMigrationAssert(
    $head === INVOICESHELF_COMMIT,
    'DROVE_NATIVE_LARAVEL_MIGRATION_COMMIT_MISMATCH',
);

$selection = preg_split('/\R/', trim(nativeLaravelMigrationCommand([
    'git', '-C', $checkout, 'ls-tree', '-r', '--name-only', INVOICESHELF_COMMIT,
    '--', 'tests/Unit', 'tests/Feature/Customer',
]))) ?: [];
$selection = array_values(array_filter(
    $selection,
    static fn (string $path): bool => str_ends_with($path, '.php'),
));
sort($selection, SORT_STRING);
nativeLaravelMigrationAssert(
    count($selection) === 47 && count(array_unique($selection)) === 47,
    'DROVE_NATIVE_LARAVEL_MIGRATION_SELECTION_MISMATCH',
);

$migrator = new LaravelMigrator(new Migrator(new Scanner(
    Registry::load($root.'/resources/drove-bridge-compatibility.json'),
)));
$codemodConfig = require $root.'/experiments/native-invoiceshelf-corpus/codemod-options.php';
nativeLaravelMigrationAssert(
    is_array($codemodConfig)
    && array_keys($codemodConfig) === ['trustedFunctions', 'imports', 'subjects']
    && is_array($codemodConfig['trustedFunctions'])
    && is_array($codemodConfig['imports'])
    && is_array($codemodConfig['subjects']),
    'DROVE_NATIVE_LARAVEL_MIGRATION_CODEMOD_OPTIONS_INVALID',
);
$codemodOptions = new CodemodOptions(
    trustedFunctions: $codemodConfig['trustedFunctions'],
    imports: $codemodConfig['imports'],
    subjects: $codemodConfig['subjects'],
);
$selectionIdentity = [];
$inventory = [];
$applied = [];
$changedFiles = [];
$nativeReadyFiles = [];
$portableBlockers = [];
$laravelBlockers = [];
$auth = null;

foreach ($selection as $path) {
    $source = nativeLaravelMigrationBlob($checkout, $path);
    $blob = nativeLaravelMigrationObject($checkout, $path);
    $selectionIdentity[] = [
        'path' => $path,
        'blob' => $blob,
        'sha256' => hash('sha256', $source),
    ];
    $first = $migrator->migrate($source, $path, $codemodOptions);
    $second = $migrator->migrate($first->source, $path, $codemodOptions);

    nativeLaravelMigrationAssert(
        $second->source === $first->source
        && $second->resultHash === $first->resultHash
        && $second->applied === []
        && $second->nativeReady() === $first->nativeReady()
        && json_encode($second->portableBlockers, JSON_THROW_ON_ERROR)
            === json_encode($first->portableBlockers, JSON_THROW_ON_ERROR)
        && $second->blockers === $first->blockers,
        'DROVE_NATIVE_LARAVEL_MIGRATION_NOT_IDEMPOTENT:'.$path.':'.json_encode([
            'first_applied' => $first->applied,
            'second_applied' => $second->applied,
            'first_native_ready' => $first->nativeReady(),
            'second_native_ready' => $second->nativeReady(),
            'first_portable' => $first->portableBlockers,
            'second_portable' => $second->portableBlockers,
            'first_laravel' => $first->blockers,
            'second_laravel' => $second->blockers,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    foreach ($first->applied as $codemod => $count) {
        $applied[$codemod] = ($applied[$codemod] ?? 0) + $count;
    }
    foreach ($first->portableBlockers as $blocker) {
        $portableBlockers[$blocker->surface] = ($portableBlockers[$blocker->surface] ?? 0) + 1;
    }
    foreach ($first->blockers as $blocker) {
        $key = $blocker['kind'].':'.$blocker['construct'];
        $laravelBlockers[$key] = ($laravelBlockers[$key] ?? 0) + 1;
    }

    if ($first->changed()) {
        $changedFiles[] = $path;
    }
    if ($first->nativeReady()) {
        $nativeReadyFiles[] = $path;
    }
    if ($first->portableBlockers !== [] || $first->blockers !== []) {
        $inventory[$path] = [
            'portable' => array_values(array_unique(array_map(
                static fn ($finding): string => $finding->surface.':'.$finding->construct,
                $first->portableBlockers,
            ))),
            'laravel' => array_values(array_unique(array_map(
                static fn (array $blocker): string => $blocker['kind'].':'.$blocker['construct'],
                $first->blockers,
            ))),
        ];
    }
    if ($path === AUTH_PATH) {
        $auth = [$source, $blob, $first];
    }
}

ksort($applied, SORT_STRING);
ksort($inventory, SORT_STRING);
ksort($portableBlockers, SORT_STRING);
ksort($laravelBlockers, SORT_STRING);
$selectionHash = hash('sha256', json_encode(
    $selectionIdentity,
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
));
nativeLaravelMigrationAssert(
    $selectionHash === SELECTION_SHA256,
    'DROVE_NATIVE_LARAVEL_MIGRATION_SELECTION_IDENTITY_DRIFT',
);
nativeLaravelMigrationAssert(
    count($nativeReadyFiles) === count($selection) && $inventory === [],
    'DROVE_NATIVE_LARAVEL_MIGRATION_SELECTED_SOURCES_BLOCKED:'.json_encode(
        $inventory,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ),
);

nativeLaravelMigrationAssert(is_array($auth), 'DROVE_NATIVE_LARAVEL_MIGRATION_AUTH_MISSING');
[$authSource, $authBlob, $authResult] = $auth;
nativeLaravelMigrationAssert(
    $authBlob === AUTH_BLOB
    && hash('sha256', $authSource) === AUTH_SOURCE_SHA256
    && $authResult->portable->resultHash === AUTH_PORTABLE_SHA256
    && $authResult->resultHash === AUTH_RESULT_SHA256
    && $authResult->nativeReady()
    && ($authResult->applied['native-qualified-call-v1'] ?? null) === 5
    && ($authResult->applied['laravel-helper-namespace-v1'] ?? null) === 1
    && ($authResult->applied['laravel-explicit-context-v1'] ?? null) === 5
    && ! str_contains($authResult->source, 'Pest\\Laravel')
    && ! str_contains($authResult->source, '$this'),
    'DROVE_NATIVE_LARAVEL_MIGRATION_AUTH_DIVERGED:'.json_encode([
        'blob' => $authBlob,
        'source_sha256' => hash('sha256', $authSource),
        'native_ready' => $authResult->nativeReady(),
        'applied' => $authResult->applied,
        'portable_blockers' => $authResult->portableBlockers,
        'laravel_blockers' => $authResult->blockers,
        'contains_pest_laravel' => str_contains($authResult->source, 'Pest\\Laravel'),
        'contains_this' => str_contains($authResult->source, '$this'),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
);

$fixtureClassSource = nativeLaravelMigrationBlob($checkout, 'tests/Unit/AiToolRegistryTest.php');
$fixtureClassResult = $migrator->migrate(
    $fixtureClassSource,
    'tests/Unit/AiToolRegistryTest.php',
    $codemodOptions,
);
nativeLaravelMigrationAssert(
    $fixtureClassResult->nativeReady()
    && $fixtureClassResult->blockers === []
    && str_contains($fixtureClassResult->source, 'return $this->toolName;')
    && str_contains($fixtureClassResult->source, '$this->lastCompanyId = $companyId;'),
    'DROVE_NATIVE_LARAVEL_MIGRATION_FIXTURE_CLASS_CONTEXT_DIVERGED',
);

$testCaseSource = nativeLaravelMigrationBlob($checkout, 'tests/TestCase.php');
$testCaseBlockers = $migrator->inspectProjectTestCase($testCaseSource, 'tests/TestCase.php');
nativeLaravelMigrationAssert(
    nativeLaravelMigrationObject($checkout, 'tests/TestCase.php') === TEST_CASE_BLOB
    && hash('sha256', $testCaseSource) === TEST_CASE_SHA256
    && array_column($testCaseBlockers, 'kind') === [
        'project-test-case-inheritance',
        'project-test-case-trait',
        'project-test-case-lifecycle',
    ],
    'DROVE_NATIVE_LARAVEL_MIGRATION_TEST_CASE_GUARD_DIVERGED:'.json_encode([
        'blob' => nativeLaravelMigrationObject($checkout, 'tests/TestCase.php'),
        'sha256' => hash('sha256', $testCaseSource),
        'blockers' => $testCaseBlockers,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
);

$pestBootstrap = nativeLaravelMigrationBlob($checkout, 'tests/Pest.php');
nativeLaravelMigrationAssert(
    nativeLaravelMigrationObject($checkout, 'tests/Pest.php') === PEST_BOOTSTRAP_BLOB
    && hash('sha256', $pestBootstrap) === PEST_BOOTSTRAP_SHA256
    && substr_count($pestBootstrap, 'uses(TestCase::class, RefreshDatabase::class)') === 2,
    'DROVE_NATIVE_LARAVEL_MIGRATION_BOOTSTRAP_GUARD_DIVERGED',
);

$equivalentSource = file_get_contents(__DIR__.'/equivalent.fixture');
if (! is_string($equivalentSource)) {
    throw new RuntimeException('DROVE_NATIVE_LARAVEL_MIGRATION_EQUIVALENT_READ');
}
$equivalent = $migrator->migrate($equivalentSource, 'equivalent.php');
$equivalentAgain = $migrator->migrate($equivalent->source, 'equivalent.php');
nativeLaravelMigrationAssert(
    $equivalent->nativeReady()
    && hash('sha256', $equivalentSource) === EQUIVALENT_SOURCE_SHA256
    && $equivalent->resultHash === EQUIVALENT_RESULT_SHA256
    && $equivalentAgain->source === $equivalent->source
    && $equivalentAgain->applied === []
    && $equivalentAgain->nativeReady()
    && $equivalentAgain->portableBlockers === []
    && $equivalentAgain->blockers === []
    && ($equivalent->applied['native-qualified-call-v1'] ?? null) === 1
    && ($equivalent->applied['laravel-helper-namespace-v1'] ?? null) === 1
    && ($equivalent->applied['laravel-explicit-context-v1'] ?? null) === 2,
    'DROVE_NATIVE_LARAVEL_MIGRATION_EQUIVALENT_DIVERGED',
);

require __DIR__.'/stubs.php';
$temporary = tempnam(sys_get_temp_dir(), 'drove-laravel-migration-');
if (! is_string($temporary)) {
    throw new RuntimeException('DROVE_NATIVE_LARAVEL_MIGRATION_TEMP_CREATE');
}

try {
    nativeLaravelMigrationAssert(
        file_put_contents($temporary, $equivalent->source) === strlen($equivalent->source),
        'DROVE_NATIVE_LARAVEL_MIGRATION_TEMP_WRITE',
    );
    require $temporary;
} finally {
    if (is_file($temporary)) {
        unlink($temporary);
    }
}

$expectedCalls = [
    ['test', 'native Laravel migration equivalent'],
    ['getJson', '/customers'],
    ['assertOk', 200],
    ['laravelContext', null],
    ['assertDatabaseHas', ['customers', ['id' => 7]]],
    ['laravelContext', null],
    ['application', null],
    ['mark', 'ready'],
];
nativeLaravelMigrationAssert(
    LaravelMigrationProof::$calls === $expectedCalls,
    'DROVE_NATIVE_LARAVEL_MIGRATION_EQUIVALENT_EXECUTION',
);

$forbiddenClasses = array_values(array_filter(
    get_declared_classes(),
    static fn (string $class): bool => str_starts_with($class, 'Pest\\')
        || str_starts_with($class, 'PHPUnit\\')
        || str_starts_with($class, 'Orchestra\\Testbench\\'),
));
$testbenchBridgeLoaded = class_exists('Drove\\Laravel\\TestbenchBridge', false);
$forbiddenFiles = array_values(array_filter(
    array_map(static fn (string $file): string => str_replace('\\', '/', $file), get_included_files()),
    static fn (string $file): bool => str_contains($file, '/src/Drove/Pest/')
        || str_contains($file, '/vendor/phpunit/phpunit/')
        || str_contains($file, '/vendor/orchestra/testbench/')
        || str_ends_with($file, '/src/Functions.php')
        || str_ends_with($file, '/src/Pest.php'),
));
nativeLaravelMigrationAssert(
    $forbiddenClasses === [] && $forbiddenFiles === [] && ! $testbenchBridgeLoaded,
    'DROVE_NATIVE_LARAVEL_MIGRATION_EXTERNAL_RUNTIME_LOADED',
);

$evidence = [
    'schema' => 1,
    'corpus' => [
        'repository' => 'InvoiceShelf/InvoiceShelf',
        'commit' => INVOICESHELF_COMMIT,
        'selected_files' => count($selection),
        'selection_sha256' => $selectionHash,
    ],
    'actual_file_proof' => [
        'path' => AUTH_PATH,
        'blob' => AUTH_BLOB,
        'source_sha256' => AUTH_SOURCE_SHA256,
        'portable_sha256' => $authResult->portable->resultHash,
        'result_sha256' => $authResult->resultHash,
        'applied' => $authResult->applied,
        'idempotent' => true,
        'native_ready' => true,
    ],
    'inventory' => [
        'changed_files' => count($changedFiles),
        'native_ready_files' => count($nativeReadyFiles),
        'applied' => $applied,
        'portable_blocker_counts' => $portableBlockers,
        'laravel_blocker_counts' => $laravelBlockers,
        'remaining_by_file' => $inventory,
        'project_test_case_source' => [
            'used_by_native_application_cohort' => false,
            'findings' => $testCaseBlockers,
        ],
        'selected_sources_native_ready' => true,
        'suite_native_ready' => true,
    ],
    'executable_equivalent' => [
        'source_sha256' => hash('sha256', $equivalentSource),
        'result_sha256' => $equivalent->resultHash,
        'calls_sha256' => hash('sha256', json_encode(
            $expectedCalls,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )),
        'idempotent' => true,
    ],
    'external_runtime' => [
        'pest' => false,
        'phpunit' => false,
        'testbench' => false,
        'base_test_case' => false,
    ],
];

echo json_encode(
    $evidence,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
), PHP_EOL;
