<?php

declare(strict_types=1);

use Drove\Compatibility\Registry;
use Drove\Kernel\AssertionFailed;
use Drove\Migration\Finding;
use Drove\Migration\Migrator;
use Drove\Migration\Scanner;
use Drove\Native\CaseDefinition;
use Drove\Native\Expectation;
use Drove\Native\Surface\SupportedSurface;
use Drove\Native\TestContext;

$rootPath = dirname(__DIR__, 2);

require $rootPath.'/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$case = static function (Closure $body, int $expectedAssertions) use ($assert): void {
    static $ordinal = 0;

    $token = 'native-expectation-catalog-'.++$ordinal;
    $context = new TestContext(metricsToken: $token);
    $definition = new CaseDefinition($body, [], 'run', '');
    ob_start();

    try {
        $outcome = ($context->caseClosure($definition))();
        $output = ob_get_clean();
    } catch (Throwable $throwable) {
        ob_end_clean();
        throw $throwable;
    }

    if (! is_string($output)) {
        throw new RuntimeException('Could not capture native expectation metrics.');
    }

    $assert(
        ! str_contains($output, "\x1eDROVE_NATIVE_STATE:".$token.':'),
        'Default native runtime state emitted a redundant result frame.',
    );
    $metrics = TestContext::extractMetrics($output, $token);
    $assert($outcome->status === 'passed', 'The native expectation proof case did not pass.');
    $assert(
        $metrics['assertions'] === $expectedAssertions,
        sprintf(
            'The native expectation proof counted %s assertions instead of %d.',
            var_export($metrics['assertions'], true),
            $expectedAssertions,
        ),
    );
};

$temporaryDirectory = sys_get_temp_dir().'/drove-expectation-'.bin2hex(random_bytes(6));

if (! mkdir($temporaryDirectory) || file_put_contents($temporaryDirectory.'/proof.txt', 'drove') === false) {
    throw new RuntimeException('Could not prepare the native expectation filesystem proof.');
}

$temporaryFile = $temporaryDirectory.'/proof.txt';
$resource = fopen($temporaryFile, 'rb');

if (! is_resource($resource)) {
    throw new RuntimeException('Could not prepare the native expectation resource proof.');
}

try {
    $case(static function () use ($temporaryFile): void {
        new Expectation('alpha')->toMatch('/^alp/')
            ->and(2)->toBeLessThan(3)
            ->and($temporaryFile)->toBeFile()
            ->and([])->toBeArray()
            ->and('')->toBeEmpty()
            ->and(2)->toBeGreaterThan(1)
            ->and(2)->toBeGreaterThanOrEqual(2)
            ->and(2)->toBeLessThanOrEqual(2)
            ->and('alpha')->toBeString()
            ->and('alpha')->toStartWith('al')
            ->and(['alpha' => 1])->toMatchArray(['alpha' => 1])
            ->and('value')->not()->toBeNull()
            ->and('alpha')->not()->toBe('beta');
    }, 16);

    $case(static function () use ($resource): void {
        new Expectation([1])->toBeArray()
            ->and([1])->toBeList()
            ->and(true)->toBeBool()
            ->and(static fn (): null => null)->toBeCallable()
            ->and(1.5)->toBeFloat()
            ->and(1)->toBeInt()
            ->and(new ArrayIterator([1]))->toBeIterable()
            ->and('42')->toBeNumeric()
            ->and('42')->toBeDigits()
            ->and(new stdClass)->toBeObject()
            ->and($resource)->toBeResource()
            ->and(1)->toBeScalar()
            ->and('drove')->toBeString()
            ->and('{"drove":true}')->toBeJson()
            ->and(NAN)->toBeNan()
            ->and(INF)->toBeInfinite()
            ->and(null)->toBeNull()
            ->and(true)->toBeTrue()
            ->and(1)->toBeTruthy()
            ->and(false)->toBeFalse()
            ->and(0)->toBeFalsy()
            ->and(new RuntimeException)->toBeInstanceOf(Throwable::class);
    }, 23);

    $case(static function (): void {
        new Expectation('ABC')->toBeUppercase()
            ->and('abc')->toBeLowercase()
            ->and('abc123')->toBeAlphaNumeric()
            ->and('abc')->toBeAlpha()
            ->and('snake_case')->toBeSnakeCase()
            ->and('kebab-case')->toBeKebabCase()
            ->and('camelCase')->toBeCamelCase()
            ->and('StudlyCase')->toBeStudlyCase()
            ->and('ca0a8228-cdf6-41db-b34b-c2f31485796c')->toBeUuid()
            ->and('01ARZ3NDEKTSV4RRFFQ69G5FAV')->toBeUlid()
            ->and('user@example.com')->toBeEmail()
            ->and('https://example.com')->toBeUrl()
            ->and('This is a slug')->toBeSlug()
            ->and('127.0.0.1')->toBeIpAddress()
            ->and('00:11:22:33:44:55')->toBeMacAddress()
            ->and('example')->toBeHostname()
            ->and('example.com')->toBeDomain()
            ->and('Zm9v')->toBeBase64()
            ->and('deadbeef')->toBeHexadecimal();
    }, 19);

    $case(static function () use ($temporaryDirectory, $temporaryFile): void {
        $object = (object) ['name' => 'Drove', 'version' => 1];

        new Expectation(1)->toBe(1)
            ->and(1)->toEqual('1')
            ->and([])->toBeEmpty()
            ->and(3)->toBeGreaterThan(2)
            ->and(3)->toBeGreaterThanOrEqual(3)
            ->and(2)->toBeLessThan(3)
            ->and(2)->toBeLessThanOrEqual(2)
            ->and(2)->toBeBetween(1, 3)
            ->and(['a', 'b'])->toContain('a', 'b')
            ->and([1, 2])->toContainEqual('1', '2')
            ->and('drove')->toStartWith('dr')
            ->and('drove')->toEndWith('ve')
            ->and('drove')->toHaveLength(5)
            ->and([1, 2])->toHaveCount(2)
            ->and([1, 2])->toHaveSameSize(['a', 'b'])
            ->and($object)->toHaveProperty('name')
            ->toHaveProperty('version', 1)
            ->toHaveProperties(['name', 'version' => 1])
            ->and([3, 2, 1])->toEqualCanonicalizing([1, 2, 3])
            ->and(1.01)->toEqualWithDelta(1.0, 0.02)
            ->and('b')->toBeIn(['a', 'b'])
            ->and(['nested' => ['value' => 1]])->toHaveKey('nested.value', 1)
            ->toHaveKeys(['nested' => ['value']])
            ->and(['snake_key' => 1])->toHaveSnakeCaseKeys()
            ->and(['kebab-key' => 1])->toHaveKebabCaseKeys()
            ->and(['camelKey' => 1])->toHaveCamelCaseKeys()
            ->and(['StudlyKey' => 1])->toHaveStudlyCaseKeys()
            ->and($object)->toMatchObject(['name' => 'Drove'])
            ->and([new stdClass, new stdClass])->toContainOnlyInstancesOf(stdClass::class)
            ->and($temporaryDirectory)->toBeDirectory()
            ->toBeReadableDirectory()
            ->toBeWritableDirectory()
            ->and($temporaryFile)->toBeReadableFile()
            ->toBeWritableFile()
            ->and(static fn (): never => throw new RuntimeException('catalog'))
            ->toThrow(RuntimeException::class, 'catalog');
    }, 56);

    $case(static function (): void {
        new Expectation([1])->not()->toContain(1, 2);

        try {
            new Expectation([1, 2])->not()->toContain(1, 2);
        } catch (AssertionFailed $failure) {
            new Expectation($failure->getMessage())->toContain('does not contain');
        }

        try {
            new Expectation('value')->toBeInt('catalog custom message');
        } catch (AssertionFailed $failure) {
            new Expectation($failure->getMessage())->toBe('catalog custom message');
        }
    }, 7);

    $case(static function (): void {
        new Expectation([1, 2])->each(
            static fn (Expectation $item): Expectation => $item->toBeInt(),
        );
        new Expectation([1, 2, 1])->sequence(1, 2);
        new Expectation('{"name":"Drove"}')->json()->property('name')->toBe('Drove');
        new Expectation((object) ['runtime' => 'native'])->property('runtime')->toBe('native');
    }, 10);
} finally {
    fclose($resource);
    unlink($temporaryFile);
    rmdir($temporaryDirectory);
}

$surface = SupportedSurface::load();
$manifest = $surface->manifest();
$methods = $manifest['methods']['expectation'] ?? null;
$assert(is_array($methods), 'The native supported-surface manifest lost its expectation catalog.');

foreach ($methods as $method) {
    $assert(
        is_string($method) && method_exists(Expectation::class, $method),
        sprintf('The native manifest advertises missing expectation method %s.', var_export($method, true)),
    );
}

foreach ([
    'getColumns',
    'getValue',
    'toHtml',
    'toMatchSnapshot',
] as $excluded) {
    $assert(! in_array($excluded, $methods, true), sprintf('Excluded expectation %s entered the native catalog.', $excluded));
}

$chain = '\\Pest\\expect(null)';

foreach ($methods as $method) {
    $chain .= $method === 'not' ? '->not' : sprintf('->%s()', $method);
}

$source = sprintf(
    "<?php\n\\Pest\\test('catalog', function (): void {\n    %s;\n});\n",
    $chain,
);
$scanner = new Scanner(Registry::load());
$migrator = new Migrator($scanner);
$first = $migrator->migrate($source, 'tests/NativeExpectationCatalog.php');
$second = $migrator->migrate($first->source, 'tests/NativeExpectationCatalog.php');
$assert($first->blockers === [], 'A supported expectation still produced a migration blocker.');
$assert($first->changed(), 'The catalog migration made no progress.');
$assert(! $second->changed() && $second->applied === [], 'The catalog migration is not idempotent.');
$assert($surface->scan($first->source, 'tests/NativeExpectationCatalog.php') === [], 'Migrated catalog source failed the native surface scan.');

$excludedSource = <<<'PHP'
<?php

expect(null)->toHtml()->getColumns()->toMatchSnapshot();
PHP;
$excludedDiagnostics = array_values(array_unique(array_map(
    static fn (Finding $finding): string => $finding->diagnostic,
    $scanner->scan($excludedSource, 'tests/ExcludedExpectations.php'),
)));
$assert(
    in_array('DROVE_MIGRATION_BRIDGE_CUSTOM_EXPECTATION_CALL', $excludedDiagnostics, true)
        && in_array('DROVE_MIGRATION_UNSUPPORTED_SNAPSHOT', $excludedDiagnostics, true),
    'Excluded expectation surfaces did not remain fail-closed.',
);
$nativeExcluded = $surface->scan(
    str_replace('expect(', '\\Drove\\Native\\expect(', $excludedSource),
    'tests/ExcludedNativeExpectations.php',
);
$nativeExcludedCodes = array_column($nativeExcluded, 'code');
$assert(
    in_array('DROVE_NATIVE_UNSUPPORTED_EXPECTATION', $nativeExcludedCodes, true)
        && in_array('DROVE_NATIVE_UNSUPPORTED_SNAPSHOT', $nativeExcludedCodes, true),
    'The native surface did not diagnose excluded expectations stably.',
);

fwrite(STDOUT, json_encode([
    'status' => 'pass',
    'supported_methods' => count($methods),
    'excluded_methods' => 4,
    'migration_blockers' => count($first->blockers),
    'catalog_assertions' => 118,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
