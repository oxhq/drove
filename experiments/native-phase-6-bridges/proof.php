<?php

declare(strict_types=1);

use Drove\Bridge\CompatibilityRegistry;
use Drove\Bridge\CompatibilityStatus;
use Drove\Bridge\Loader;
use Drove\Bridge\Pest\Bridge as PestBridge;
use Drove\Bridge\PhpUnit\Bridge as PhpUnitBridge;
use Drove\Laravel\TestbenchBridge;
use Drove\Migration\CodemodOptions;
use Drove\Migration\Finding;
use Drove\Migration\Migrator;
use Drove\Migration\Scanner;
use Drove\Native\Surface\DependencyGuard;
use Drove\Pest\ScopeCompiler;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;

$rootPath = dirname(__DIR__, 2);

require $rootPath.'/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$revision = getenv('DROVE_EXPECTED_REVISION');
$revision = $revision === false || $revision === '' ? null : $revision;
$assert(
    $revision === null || preg_match('/^[0-9a-f]{40}$/D', $revision) === 1,
    'The Phase 6 evidence revision is not an exact Git commit.',
);

final class PhaseSixPhpUnitCase extends TestCase
{
    public function test_bridge_lowering(): void
    {
        self::assertTrue(isset($_SERVER['argv']));
    }
}

$registry = CompatibilityRegistry::load();
$manifest = $registry->manifest();
$publicRegistry = Drove\CompatibilityRegistry::read();
$assert(
    ($publicRegistry['surface_registry'] ?? null) === $manifest,
    'The public compatibility output did not embed the versioned surface registry.',
);
$statuses = [];

foreach ($manifest['surfaces'] as $id => $surface) {
    $assert(is_string($id) && is_array($surface), 'The bridge registry emitted an invalid surface.');
    $statuses[] = $surface['status'] ?? null;
}

$assert(
    array_diff(array_unique($statuses), array_column(CompatibilityStatus::cases(), 'value')) === [],
    'The public bridge registry exposes a status outside the declared three-state model.',
);
$assert(
    array_keys($manifest['bridges']) === ['pest', 'phpunit', 'testbench'],
    'The bridge registry does not expose the three Phase 6 bridges deterministically.',
);

$loader = new Loader($registry);
$assert(! ScopeCompiler::isActive(), 'Bridge discovery mutated compiler state.');
$assert(
    ! class_exists(PestBridge::class, false)
        && ! class_exists(PhpUnitBridge::class, false)
        && ! class_exists(TestbenchBridge::class, false),
    'A bridge entrypoint loaded before it was requested.',
);
$pestEntrypoint = $loader->load('pest');
$pestVersionCheck = new ReflectionMethod(PestBridge::class, 'supportsPhpUnitVersion');
$assert(
    $pestVersionCheck->invoke(null, '13.2.5') === false,
    'The Pest bridge accepted PHPUnit outside its declared compatibility surface.',
);
$assert(
    ! class_exists(PhpUnitBridge::class, false)
        && ! class_exists(TestbenchBridge::class, false),
    'Loading Pest eagerly loaded another compatibility bridge.',
);
$phpunitEntrypoint = $loader->load('phpunit');
$versionCheck = new ReflectionMethod(PhpUnitBridge::class, 'supportsVersion');
$assert(
    $versionCheck->invoke(null, '13.2.5') === false,
    'The PHPUnit bridge accepted a version outside its declared compatibility surface.',
);

$missingEntrypointRejected = false;

try {
    $loader->load('testbench', requireDependencies: false);
} catch (RuntimeException $exception) {
    $missingEntrypointRejected = str_contains(
        $exception->getMessage(),
        'entrypoint Drove\\Laravel\\TestbenchBridge is not installed',
    );
}

$assert(
    $missingEntrypointRejected,
    'The bridge loader did not reject an entrypoint before its package was loaded.',
);
require_once $rootPath.'/packages/drove-laravel/src/TestbenchBridge.php';
$testbenchEntrypoint = $loader->load('testbench', requireDependencies: false);
$assert(! ScopeCompiler::isActive(), 'Loading a bridge activated the compatibility compiler.');

if ($testbenchEntrypoint::available()) {
    $loader->load('testbench');
} else {
    $missingTestbenchRejected = false;

    try {
        $loader->load('testbench');
    } catch (RuntimeException $exception) {
        $missingTestbenchRejected = str_starts_with(
            $exception->getMessage(),
            'DROVE_BRIDGE_TESTBENCH_DEPENDENCY_MISSING:',
        );
    }

    $assert(
        $missingTestbenchRejected,
        'The optional Testbench bridge did not reject unavailable dependencies stably.',
    );
}

$installedCommand = sprintf(
    '%s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg(__DIR__.'/installed-testbench.php'),
);
exec($installedCommand, $installedOutput, $installedExit);
$assert(
    $installedExit === 0,
    'The Testbench bridge did not become available with both optional classes installed.',
);

$guard = (new DependencyGuard)->inspect(
    $rootPath.'/src/Drove/Native',
    [$pestEntrypoint, $phpunitEntrypoint, $testbenchEntrypoint],
);
$assert($guard['violations'] === [], 'Loaded bridges contaminated the native module boundary.');
$assert(
    count($guard['loaded_entrypoints']) === 3,
    'The native dependency guard did not inspect every loaded bridge.',
);

$suite = TestSuite::empty('phase-six-phpunit');
$suite->addTest(new PhaseSixPhpUnitCase('test_bridge_lowering'));
$phpunitLowered = PhpUnitBridge::lower($rootPath, $suite);
$phpunitTests = [];

$collectTests = static function (array $node) use (&$collectTests, &$phpunitTests): void {
    foreach ($node['tests'] ?? [] as $test) {
        $phpunitTests[] = $test['id'];
    }

    foreach ($node['children'] ?? [] as $child) {
        $collectTests($child);
    }
};
$collectTests($phpunitLowered->scopeIr['root']);
$assert(count($phpunitTests) === 1, 'The PHPUnit bridge did not lower exactly one case.');
$assert(
    $phpunitLowered->resolveTest($phpunitTests[0])['runtime'] instanceof PhaseSixPhpUnitCase,
    'The PHPUnit bridge lost its runtime resolver.',
);

$pestCompiler = PestBridge::activate($rootPath);
$pestLowered = PestBridge::lower($pestCompiler, $suite);
$assert(
    ($pestLowered->scopeIr['schema'] ?? null) === 1,
    'The Pest bridge did not emit Scope IR schema 1.',
);

$scanner = new Scanner($registry);
$migrator = new Migrator($scanner);
$path = 'tests/Feature/PortableTest.php';
$source = <<<'PHP'
<?php

use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->value = 1;
});

test('portable', function (): void {
    expect($this->value)->toBe(1);
    expect($this->value)->toBeUuid('v4');
})->group('unit');

uses(ScopedCase::class)->in('Feature');
expect()->extend('toBeUuid', function (): void {
    $this->toBeString();
});

test('dependency', fn (): bool => true)->group('unsafe')->depends('portable');
expect($this->value)->toBe(1)->toMatchSnapshot();
\Pest\it('qualified', fn (): bool => true);
PHP;
$options = new CodemodOptions(
    environments: [
        'Tests\TestCase' => [
            'name' => 'application',
            'factory' => 'static fn () => AppEnvironment::create()',
            'declaration_path' => $path,
        ],
    ],
    matchers: [
        'toBeUuid' => ['owner' => 'acme.uuid', 'matcher' => 'uuid'],
    ],
);
$first = $migrator->migrate($source, $path, $options);
$second = $migrator->migrate($first->source, $path, $options);
$assert($first->changed(), 'The migration codemods made no progress.');
$assert(! $second->changed(), 'The migration codemods are not idempotent.');
$assert($second->applied === [], 'An idempotent migration reported repeated edits.');
$assert(
    str_contains(
        $first->source,
        "\\Drove\\Native\\environment('application', static fn () => AppEnvironment::create());",
    ),
    'The explicit uses-to-environment mapping was not applied.',
);
$aliased = $migrator->migrate(
    <<<'PHP'
<?php

use Tests\TestCase as ApplicationCase;

uses(ApplicationCase::class);
PHP,
    $path,
    $options,
);
$assert(
    str_contains(
        $aliased->source,
        "\\Drove\\Native\\environment('application', static fn () => AppEnvironment::create());",
    ),
    'The uses-to-environment mapping did not resolve a class import alias.',
);
$assert(
    str_contains(
        $first->source,
        "\$this->assertWith('acme.uuid', 'uuid', \$this->value, 'v4');",
    ),
    'The explicit custom matcher mapping was not applied.',
);
$assert(
    str_contains($first->source, "\\Drove\\Native\\test('portable'")
        && str_contains($first->source, '\\Drove\\Native\\expect($this->value)->toBe(1)')
        && str_contains($first->source, "\\Drove\\Native\\it('qualified'"),
    'Portable Pest-like calls were not qualified to the native frontend.',
);

$blockerDiagnostics = array_values(array_unique(array_map(
    static fn (Finding $finding): string => $finding->diagnostic,
    $first->blockers,
)));
sort($blockerDiagnostics, SORT_STRING);

foreach ([
    'DROVE_MIGRATION_BRIDGE_CUSTOM_EXPECTATION_DEFINITION',
    'DROVE_MIGRATION_BRIDGE_SCOPED_ENVIRONMENT',
    'DROVE_MIGRATION_UNSUPPORTED_DEPENDENCY',
    'DROVE_MIGRATION_UNSUPPORTED_SNAPSHOT',
] as $diagnostic) {
    $assert(
        in_array($diagnostic, $blockerDiagnostics, true),
        sprintf('The migration scanner lost blocker %s.', $diagnostic),
    );
}

$ambiguous = <<<'PHP'
<?php

function test(string $name): void {}
test('local');
PHP;
$ambiguousResult = $migrator->migrate($ambiguous, 'tests/Ambiguous.php');
$assert(! $ambiguousResult->changed(), 'The codemod rewrote an ambiguous local function.');
$assert(
    ($ambiguousResult->blockers[0]->diagnostic ?? null) === 'DROVE_MIGRATION_AMBIGUOUS_FUNCTION',
    'The scanner did not classify an ambiguous function stably.',
);

$imported = <<<'PHP'
<?php

use function Vendor\test;

test('imported');
PHP;
$importedResult = $migrator->migrate($imported, 'tests/Imported.php');
$assert(! $importedResult->changed(), 'The codemod rewrote an imported function alias.');
$assert(
    ($importedResult->blockers[0]->diagnostic ?? null) === 'DROVE_MIGRATION_AMBIGUOUS_FUNCTION',
    'The scanner did not classify an imported function alias stably.',
);

$unrelatedNamespaced = <<<'PHP'
<?php

App\helper();
Domain\itinerary();
PHP;
$assert(
    $scanner->scan($unrelatedNamespaced, 'tests/NamespacedHelpers.php') === [],
    'The scanner treated unrelated namespaced functions as Pest syntax.',
);

$namespacedPestSurface = <<<'PHP'
<?php

App\test('ambiguous Pest-like surface');
PHP;
$assert(
    ($scanner->scan(
        $namespacedPestSurface,
        'tests/NamespacedPestSurface.php',
    )[0]->diagnostic ?? null) === 'DROVE_MIGRATION_AMBIGUOUS_FUNCTION',
    'The scanner stopped rejecting an ambiguous namespaced Pest surface.',
);

$namespacedDefinition = <<<'PHP'
<?php

namespace App;

function test(string $name): void {}
PHP;
$namespacedUse = <<<'PHP'
<?php

namespace App;

test('may resolve to App\test');
PHP;
$assert(
    $scanner->scan($namespacedDefinition, 'src/AppFunctions.php') === [],
    'A namespaced application function definition was treated as a Pest call.',
);
$namespacedUseResult = $migrator->migrate(
    $namespacedUse,
    'tests/NamespacedUse.php',
);
$assert(
    ! $namespacedUseResult->changed()
        && ($namespacedUseResult->blockers[0]->diagnostic ?? null)
            === 'DROVE_MIGRATION_AMBIGUOUS_FUNCTION',
    'The codemod rewrote a bare namespaced call that may resolve cross-file.',
);

$relativePestUse = <<<'PHP'
<?php

namespace App;

Pest\test('relative namespace');
PHP;
$relativePestResult = $migrator->migrate(
    $relativePestUse,
    'tests/RelativePestUse.php',
);
$assert(
    ! $relativePestResult->changed()
        && ($relativePestResult->blockers[0]->diagnostic ?? null)
            === 'DROVE_MIGRATION_AMBIGUOUS_FUNCTION',
    'The codemod treated namespace-relative Pest syntax as fully qualified.',
);

$relativeNativeUse = <<<'PHP'
<?php

namespace App;

Drove\Native\test('relative native namespace', function (): void {});
PHP;
$relativeNativeResult = $migrator->migrate(
    $relativeNativeUse,
    'tests/RelativeNativeUse.php',
);
$assert(
    ! $relativeNativeResult->changed()
        && ($relativeNativeResult->blockers[0]->diagnostic ?? null)
            === 'DROVE_MIGRATION_AMBIGUOUS_FUNCTION',
    'The scanner treated namespace-relative Drove native syntax as global.',
);
$assert(
    $scanner->scan(
        <<<'PHP'
<?php

namespace App;

\Drove\Native\test('global native namespace', function (): void {});
PHP,
        'tests/GlobalNativeUse.php',
    )[0]->diagnostic === 'DROVE_NATIVE_SUPPORTED_TEST',
    'The scanner stopped recognizing fully qualified Drove native syntax.',
);

$importedPestUse = <<<'PHP'
<?php

namespace App;

use function Pest\test;

test('explicit Pest import', function (): void {});
PHP;
$importedPestResult = $migrator->migrate(
    $importedPestUse,
    'tests/ImportedPestUse.php',
);
$assert(
    str_contains(
        $importedPestResult->source,
        "\\Drove\\Native\\test('explicit Pest import'",
    ),
    'An explicit Pest function import did not enter through the migration frontend.',
);

$topLevelMatcher = <<<'PHP'
<?php

expect($value)->toBeUuid();
PHP;
$topLevelMatcherResult = $migrator->migrate(
    $topLevelMatcher,
    'tests/TopLevelMatcher.php',
    $options,
);
$assert(
    ! str_contains($topLevelMatcherResult->source, '$this->assertWith('),
    'The matcher codemod emitted a TestContext assertion at file scope.',
);

$beforeAllMatcher = <<<'PHP'
<?php

beforeAll(function (): void {
    expect($value)->toBeUuid();
});
PHP;
$beforeAllMatcherResult = $migrator->migrate(
    $beforeAllMatcher,
    'tests/BeforeAllMatcher.php',
    $options,
);
$assert(
    ! str_contains($beforeAllMatcherResult->source, '$this->assertWith('),
    'The matcher codemod emitted a per-test assertion inside beforeAll.',
);

$staticMatcher = <<<'PHP'
<?php

test('static matcher', static function (): void {
    expect($value)->toBeUuid();
});
PHP;
$staticMatcherResult = $migrator->migrate(
    $staticMatcher,
    'tests/StaticMatcher.php',
    $options,
);
$assert(
    ! str_contains($staticMatcherResult->source, '$this->assertWith('),
    'The matcher codemod emitted a TestContext assertion inside a static closure.',
);

$nestedMatcher = <<<'PHP'
<?php

test('nested matcher', function (): void {
    runCallback(function (): void {
        expect($value)->toBeUuid();
    });
});
PHP;
$nestedMatcherResult = $migrator->migrate(
    $nestedMatcher,
    'tests/NestedMatcher.php',
    $options,
);
$assert(
    ! str_contains($nestedMatcherResult->source, '$this->assertWith('),
    'The matcher codemod captured a nested closure that Drove does not bind.',
);

$beforeEachMatcher = <<<'PHP'
<?php

beforeEach(function (): void {
    expect($value)->toBeUuid();
});
PHP;
$beforeEachMatcherResult = $migrator->migrate(
    $beforeEachMatcher,
    'tests/BeforeEachMatcher.php',
    $options,
);
$assert(
    str_contains($beforeEachMatcherResult->source, '$this->assertWith('),
    'The matcher codemod stopped converting a bound beforeEach closure.',
);

$multipleUses = <<<'PHP'
<?php

uses(FirstCase::class);
uses(SecondCase::class);
PHP;
$multipleUsesResult = $migrator->migrate(
    $multipleUses,
    'tests/MultipleUses.php',
    new CodemodOptions(environments: [
        'FirstCase' => [
            'name' => 'first',
            'factory' => 'static fn () => FirstEnvironment::create()',
            'declaration_path' => 'tests/MultipleUses.php',
        ],
        'SecondCase' => [
            'name' => 'second',
            'factory' => 'static fn () => SecondEnvironment::create()',
            'declaration_path' => 'tests/MultipleUses.php',
        ],
    ]),
);
$assert(
    ! $multipleUsesResult->changed(),
    'The codemod guessed between multiple suite-wide environment declarations.',
);

$multipleNamespaces = <<<'PHP'
<?php

namespace First;

use Tests\TestCase as ApplicationCase;

\Pest\uses(ApplicationCase::class);

namespace Second;

use Other\TestCase as ApplicationCase;
PHP;
$multipleNamespacesResult = $migrator->migrate(
    $multipleNamespaces,
    'tests/MultipleNamespaces.php',
    new CodemodOptions(environments: [
        'Tests\TestCase' => [
            'name' => 'first',
            'factory' => 'static fn () => FirstEnvironment::create()',
            'declaration_path' => 'tests/MultipleNamespaces.php',
        ],
        'Other\TestCase' => [
            'name' => 'second',
            'factory' => 'static fn () => SecondEnvironment::create()',
            'declaration_path' => 'tests/MultipleNamespaces.php',
        ],
    ]),
);
$assert(
    ! $multipleNamespacesResult->changed()
        && ($multipleNamespacesResult->blockers[0]->diagnostic ?? null)
            === 'DROVE_MIGRATION_BRIDGE_USES',
    'The codemod resolved a class import across multiple namespace contexts.',
);

$invalidFactoryRejected = false;

try {
    new CodemodOptions(environments: [
        'InvalidCase' => [
            'name' => 'invalid',
            'factory' => 'FirstEnvironment::create(); SecondEnvironment::create()',
            'declaration_path' => 'tests/Invalid.php',
        ],
    ]);
} catch (InvalidArgumentException) {
    $invalidFactoryRejected = true;
}

$assert(
    $invalidFactoryRejected,
    'The environment codemod accepted more than one PHP expression.',
);

$summary = [
    'schema' => 1,
    'revision' => $revision,
    'platform' => [
        'os_family' => PHP_OS_FAMILY,
        'architecture' => php_uname('m'),
        'php' => PHP_VERSION,
    ],
    'registry' => [
        'version' => $manifest['registry_version'],
        'hash' => $registry->hash(),
        'statuses' => array_values(array_unique($statuses)),
    ],
    'bridges' => [
        'loaded' => array_column($guard['loaded_entrypoints'], 'class'),
        'phpunit_lowered_cases' => count($phpunitTests),
        'pest_scope_ir_schema' => $pestLowered->scopeIr['schema'],
        'testbench_dependencies_available' => $testbenchEntrypoint::available(),
    ],
    'dependency_guard' => [
        'inspected_files' => $guard['inspected_files'],
        'violations' => count($guard['violations']),
    ],
    'migration' => [
        'applied' => $first->applied,
        'blockers' => $blockerDiagnostics,
        'idempotent_hash' => $second->resultHash,
    ],
];

echo json_encode(
    $summary,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
