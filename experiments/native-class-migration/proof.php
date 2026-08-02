<?php

declare(strict_types=1);

use Drove\Kernel\Scheduler;
use Drove\Migration\ClassMigrator;
use Drove\Native\ClassFrontend;
use Drove\Native\Declarations;
use Drove\Native\Runner;

$root = dirname(__DIR__, 2);
$checkout = $argv[1] ?? $root.'/.temp/livewire-native-inspect';
$commit = '9c1450739d30c9b0b223ad6512be2a33f8f62f96';
$selected = 'src/Features/SupportEntangle/UnitTest.php';
$selectedBlob = 'ae53e36ea7fc1c83d64dc3bb2336de7a03d5c30b';
$profilePath = $root.'/experiments/phase-3-laravel/native-package/livewire-class-profile.php';
$profile = require $profilePath;
$profileHash = hash_file('sha256', $profilePath);

spl_autoload_register(static function (string $class) use ($root): void {
    if (! str_starts_with($class, 'Drove\\')) {
        return;
    }

    $path = $root.'/src/Drove/'.str_replace('\\', '/', substr($class, 6)).'.php';

    if (is_file($path)) {
        require $path;
    }
});
require $root.'/src/Drove/Native/functions.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$git = static function (string $checkout, string ...$arguments): string {
    $command = 'git -C '.escapeshellarg($checkout).' '.implode(' ', array_map('escapeshellarg', $arguments));
    $output = [];
    $exitCode = 0;
    exec($command.' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException("Git command failed:\n".implode("\n", $output));
    }

    return trim(implode("\n", $output));
};
$read = static function (string $path): string {
    $source = file_get_contents($path);

    if (! is_string($source)) {
        throw new RuntimeException('Source could not be read: '.$path);
    }

    return $source;
};
$assert(is_dir($checkout), 'The pinned Livewire checkout is missing.');
$assert($git($checkout, 'rev-parse', 'HEAD') === $commit, 'The Livewire checkout commit drifted.');
$assert(
    $git($checkout, 'rev-parse', 'HEAD:'.$selected) === $selectedBlob,
    'The selected Livewire git blob drifted.',
);
$assert(
    is_array($profile)
        && is_string($profileHash)
        && $profileHash === 'af0ad68fd7d607fa046d93ad7112fa1a7526139022c2efe947ce3e90ce2c7081',
    'The authoritative Livewire migration profile drifted.',
);

$source = $read($checkout.'/'.$selected);
$assert(
    $git($checkout, 'hash-object', $selected) === $selectedBlob,
    'The selected Livewire worktree source is modified.',
);

$migrator = new ClassMigrator;
$migration = $migrator->migrate($source, $selected);
$assert($migration->blockers === [], 'The selected Livewire source did not migrate without blockers.');
$assert($migration->applied === [
    'add-native-test-class' => 1,
    'map-assertion:assertTrue' => 1,
    'remove-external-test-case-inheritance' => 1,
], 'The selected Livewire migration changed its transform contract.');
$assert(
    ! str_contains($migration->source, 'extends \\Tests\\TestCase')
        && str_contains($migration->source, '#[\\Drove\\Native\\Attributes\\TestClass]')
        && str_contains($migration->source, '\\Drove\\Native\\expect(true)->toBeTrue()'),
    'The selected Livewire migration retained an external test runtime surface.',
);
$secondPass = $migrator->migrate($migration->source, $selected);
$assert($secondPass->source === $migration->source, 'The selected Livewire migration is not idempotent.');
$assert($secondPass->applied === [] && $secondPass->blockers === [], 'The idempotent pass is not clean.');

$attributeSource = <<<'PHP'
<?php

namespace DroveClassMigrationFixture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('livewire')]
class AttributeUnitTest extends TestCase
{
    #[Test]
    #[DataProvider('rows')]
    public function accepts_true(bool $value): void
    {
        $this->assertTrue($value);
    }

    public static function rows(): iterable
    {
        yield 'first' => [true];
        yield 'second' => [true];
    }
}
PHP;
$attributeMigration = $migrator->migrate($attributeSource, 'attribute-fixture.php');
$assert($attributeMigration->blockers === [], 'The native attribute fixture did not migrate cleanly.');

foreach (['DataProvider', 'Group', 'Test'] as $attribute) {
    $assert(
        str_contains(
            $attributeMigration->source,
            'use Drove\\Native\\Attributes\\'.$attribute.';',
        ),
        'The '.$attribute.' attribute import was not mapped.',
    );
}
$attributeSecondPass = $migrator->migrate($attributeMigration->source, 'attribute-fixture.php');
$assert(
    $attributeSecondPass->source === $attributeMigration->source
        && $attributeSecondPass->applied === []
        && $attributeSecondPass->blockers === [],
    'The PHPUnit attribute mapping is not idempotent.',
);

$assertionSource = <<<'PHP'
<?php

namespace DroveClassMigrationFixture;

use Tests\TestCase;

class AssertionUnitTest extends TestCase
{
    public function testAssertions(): void
    {
        $this->assertTrue(true);
        $this->assertFalse(false, '');
        $this->assertNull(null);
        $this->assertNotNull('value');
        $this->assertSame('value', 'value');
        $this->assertNotSame(new \stdClass, new \stdClass);
        $this->assertEquals(1, '1');
        $this->assertNotEquals(1, 2);
        $this->assertInstanceOf(\stdClass::class, new \stdClass);
        $this->assertStringContainsString('value', 'a value');
        $this->assertStringNotContainsString('missing', 'a value');
        $this->assertMatchesRegularExpression('/^a value$/', 'a value');
        $this->assertLessThan(2, 1);
        $this->assertFileExists(__FILE__);
        $this->assertIsArray([]);
        $this->assertStringStartsWith('a', 'a value');
        $this->assertContains('value', ['value']);
        $this->assertCount(2, [1, 2]);
    }
}
PHP;
$assertionMethods = [
    'assertTrue',
    'assertFalse',
    'assertNull',
    'assertNotNull',
    'assertSame',
    'assertNotSame',
    'assertEquals',
    'assertNotEquals',
    'assertInstanceOf',
    'assertStringContainsString',
    'assertStringNotContainsString',
    'assertMatchesRegularExpression',
    'assertLessThan',
    'assertFileExists',
    'assertIsArray',
    'assertStringStartsWith',
    'assertContains',
    'assertCount',
];
$assertionMigration = $migrator->migrate($assertionSource, 'assertion-fixture.php');
$assert($assertionMigration->blockers === [], 'The PHPUnit assertion fixture did not migrate cleanly.');

foreach ($assertionMethods as $method) {
    $assert(
        ($assertionMigration->applied['map-assertion:'.$method] ?? 0) === 1,
        'The '.$method.' assertion was not mapped exactly once.',
    );
}
$assertionSecondPass = $migrator->migrate($assertionMigration->source, 'assertion-fixture.php');
$assert(
    $assertionSecondPass->source === $assertionMigration->source
        && $assertionSecondPass->applied === []
        && $assertionSecondPass->blockers === [],
    'The PHPUnit assertion mapping is not idempotent.',
);

$messageMigration = $migrator->migrate(<<<'PHP'
<?php

namespace DroveClassMigrationFixture;

use Tests\TestCase;

class MessageUnitTest extends TestCase
{
    public function testMessage(): void
    {
        $this->assertTrue(true, 'custom failure');
    }
}
PHP, 'message-fixture.php');
$assert(
    str_contains($messageMigration->source, "->toBeTrue('custom failure')")
        && $messageMigration->blockers === [],
    'A PHPUnit assertion lost its representable custom failure message.',
);

$gatewayMessageMigration = $migrator->migrate(str_replace(
    'assertTrue(true',
    "assertContains('value', ['value']",
    <<<'PHP'
<?php

namespace DroveClassMigrationFixture;

use Tests\TestCase;

class UnsupportedMessageUnitTest extends TestCase
{
    public function testMessage(): void
    {
        $this->assertTrue(true, 'custom failure');
    }
}
PHP,
), 'unsupported-message-fixture.php');
$assert(
    str_contains(
        $gatewayMessageMigration->source,
        "\\Drove\\Native\\Assert::assertContains('value', ['value'], 'custom failure')",
    ) && $gatewayMessageMigration->blockers === [],
    'A PHPUnit containment assertion lost its custom failure message.',
);

$failMigration = $migrator->migrate(<<<'PHP'
<?php

namespace DroveClassMigrationFixture;

use Tests\TestCase;

class FailUnitTest extends TestCase
{
    public function testFail(): void
    {
        $this->fail('explicit failure');
    }
}
PHP, 'fail-fixture.php');
$assert(
    str_contains($failMigration->source, "\\Drove\\Native\\TestContext::fail('explicit failure')")
        && $failMigration->blockers === [],
    'The explicit failure helper did not migrate to the native context.',
);

$incompleteMigration = $migrator->migrate(<<<'PHP'
<?php

namespace DroveClassMigrationFixture;

use Tests\TestCase;

class IncompleteUnitTest extends TestCase
{
    public function testIncomplete(): void
    {
        $this->markTestIncomplete('native incomplete reason');
        $GLOBALS['drove_native_incomplete_continued'] = true;
    }
}
PHP, 'incomplete-fixture.php');
$assert(
    $incompleteMigration->blockers === []
        && ($incompleteMigration->applied['map-incomplete-outcome'] ?? 0) === 1
        && ! str_contains($incompleteMigration->source, 'testIncomplete(): void')
        && str_contains(
            $incompleteMigration->source,
            "return \\Drove\\Kernel\\TestOutcome::incomplete('native incomplete reason');",
        )
        && str_contains($incompleteMigration->source, 'drove_native_incomplete_continued'),
    'A PHPUnit incomplete result was not lowered to a terminating native outcome.',
);
$incompleteSecondPass = $migrator->migrate($incompleteMigration->source, 'incomplete-fixture.php');
$assert(
    $incompleteSecondPass->source === $incompleteMigration->source
        && $incompleteSecondPass->applied === []
        && $incompleteSecondPass->blockers === [],
    'The native incomplete outcome mapping is not idempotent.',
);

$temporary = sys_get_temp_dir().'/drove-native-class-migration-'.bin2hex(random_bytes(8));
$assert(mkdir($temporary, 0700), 'The native class migration temporary directory could not be created.');
$actualPath = $temporary.'/LivewireUnitTest.php';
$attributePath = $temporary.'/AttributeUnitTest.php';
$assertionPath = $temporary.'/AssertionUnitTest.php';
$incompletePath = $temporary.'/IncompleteUnitTest.php';
$assert(file_put_contents($actualPath, $migration->source) !== false, 'The migrated Livewire source could not be staged.');
$assert(file_put_contents($attributePath, $attributeMigration->source) !== false, 'The migrated attribute fixture could not be staged.');
$assert(file_put_contents($assertionPath, $assertionMigration->source) !== false, 'The migrated assertion fixture could not be staged.');
$assert(file_put_contents($incompletePath, $incompleteMigration->source) !== false, 'The migrated incomplete fixture could not be staged.');

try {
    $frameworkClasses = [
        'PHPUnit\\Framework\\TestCase',
        'Orchestra\\Testbench\\TestCase',
        'Pest\\Kernel',
    ];

    foreach ($frameworkClasses as $frameworkClass) {
        $assert(! class_exists($frameworkClass, false), 'An external runtime was loaded before the native proof.');
    }

    require $actualPath;
    require $attributePath;
    require $assertionPath;
    require $incompletePath;
    $GLOBALS['drove_native_incomplete_continued'] = false;
    $registry = Declarations::capture(
        static function () use ($actualPath, $assertionPath, $attributePath, $incompletePath): void {
            (new ClassFrontend)->declareFiles([$actualPath, $attributePath, $assertionPath, $incompletePath]);
        },
        $temporary,
        'Pinned Livewire native class migration',
    );
    $scheduler = new class implements Scheduler
    {
        public function runId(): string
        {
            return 'native-class-migration-inline';
        }

        public function map(array $tasks, Closure $execute): array
        {
            $results = [];
            $completionOrder = [];

            foreach ($tasks as $ordinal => $task) {
                $started = hrtime(true);
                ob_start();

                try {
                    $value = $execute($task);
                    $stdout = ob_get_clean();
                } catch (Throwable $throwable) {
                    ob_end_clean();

                    throw $throwable;
                }

                $finished = hrtime(true);
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
                    'events' => [],
                    'telemetry' => [
                        'pid' => getmypid(),
                        'pgid' => getmypid(),
                        'started_ns' => $started,
                        'finished_ns' => $finished,
                        'duration_ms' => ($finished - $started) / 1_000_000,
                        'exit_code' => 0,
                        'signal' => null,
                    ],
                ];
                $completionOrder[] = $task['id'];
            }

            return ['results' => $results, 'completion_order' => $completionOrder];
        }

        public function withPermit(array $scopes, Closure $work): mixed
        {
            return $work();
        }
    };
    $run = (new Runner($scheduler))->run($registry);

    $assert(
        $run['status'] === 'passed',
        'The migrated native class proof failed: '.json_encode(
            array_column($run['tests'], 'failure', 'id'),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
    );
    $assert(count($run['tests']) === 5, 'The migrated native class proof lost a dataset or incomplete case.');
    $assert(
        array_sum(array_column($run['tests'], 'assertions')) === 21,
        'The migrated native class proof lost assertion accounting.',
    );
    $incomplete = array_values(array_filter(
        $run['tests'],
        static fn (array $test): bool => ($test['status'] ?? null) === 'incomplete',
    ));
    $assert(
        count($incomplete) === 1
            && ($incomplete[0]['value'] ?? null) === 'native incomplete reason'
            && $GLOBALS['drove_native_incomplete_continued'] === false,
        'The migrated incomplete method did not terminate with its exact native reason.',
    );

    foreach ($frameworkClasses as $frameworkClass) {
        $assert(! class_exists($frameworkClass, false), 'The native proof loaded an external test runtime.');
    }
} finally {
    @unlink($actualPath);
    @unlink($attributePath);
    @unlink($assertionPath);
    @unlink($incompletePath);
    @rmdir($temporary);
}

$tracked = preg_split('/\R/', $git($checkout, 'ls-files'));
$files = array_values(array_filter(
    is_array($tracked) ? $tracked : [],
    static fn (string $path): bool => preg_match('/^src\/.+UnitTest\.php$/', $path) === 1,
));
sort($files, SORT_STRING);
$files = array_slice($files, 0, 20);
$assert(count($files) === 20, 'The pinned Livewire 20-file selection drifted.');

$remaining = [];
$newAssertionTransforms = array_map(
    static fn (string $method): string => 'map-assertion:'.$method,
    array_values(array_diff($assertionMethods, ['assertTrue'])),
);

foreach ($files as $path) {
    $fileSource = $read($checkout.'/'.$path);
    $blob = $git($checkout, 'rev-parse', 'HEAD:'.$path);
    $assert(
        $git($checkout, 'hash-object', $path) === $blob,
        'A selected Livewire worktree source is modified: '.$path,
    );
    $defaultResult = $migrator->migrate($fileSource, $path);
    $result = $migrator->migrate($fileSource, $path, 'Tests\\TestCase', $profile);
    $secondProfilePass = $migrator->migrate($result->source, $path, 'Tests\\TestCase', $profile);
    $assert(
        $secondProfilePass->source === $result->source
            && $secondProfilePass->applied === []
            && $secondProfilePass->blockers === [],
        'The authoritative Livewire profile is not idempotent for '.$path.'.',
    );
    $grouped = [];

    foreach ($result->blockers as $blocker) {
        $key = $blocker->surface.'|'.$blocker->construct;
        $grouped[$key] ??= [
            'code' => $blocker->surface,
            'diagnostic' => $blocker->diagnostic,
            'symbol' => $blocker->construct,
            'lines' => [],
        ];
        $grouped[$key]['lines'][] = $blocker->line;
    }
    $defaultGrouped = [];

    foreach ($defaultResult->blockers as $blocker) {
        $key = $blocker->surface.'|'.$blocker->construct;
        $defaultGrouped[$key] ??= [
            'code' => $blocker->surface,
            'diagnostic' => $blocker->diagnostic,
            'symbol' => $blocker->construct,
            'lines' => [],
        ];
        $defaultGrouped[$key]['lines'][] = $blocker->line;
    }

    $remaining[] = [
        'source' => $path,
        'blob' => $blob,
        'default_source_ready_before_assertion_lowering' => $defaultResult->blockers === []
            && array_intersect(array_keys($defaultResult->applied), $newAssertionTransforms) === [],
        'default_source_ready_after_assertion_lowering' => $defaultResult->blockers === [],
        'source_ready' => $result->blockers === [],
        'applied_transforms' => $result->applied,
        'remaining_transforms' => array_values($grouped),
        'default_remaining_transforms' => array_values($defaultGrouped),
    ];
}

$compiler = $remaining[0] ?? null;
$assert(
    is_array($compiler)
        && $compiler['source'] === 'src/Compiler/UnitTest.php'
        && $compiler['source_ready']
        && ($compiler['applied_transforms']['remove-external-parent-lifecycle'] ?? 0) === 2
        && $compiler['remaining_transforms'] === [],
    'The migration inventory did not close the external parent lifecycle.',
);
$defaultSourceReadyBefore = count(array_filter(
    $remaining,
    static fn (array $entry): bool => $entry['default_source_ready_before_assertion_lowering'],
));
$defaultSourceReadyAfter = count(array_filter(
    $remaining,
    static fn (array $entry): bool => $entry['default_source_ready_after_assertion_lowering'],
));
$profileSourceReady = count(array_filter(
    $remaining,
    static fn (array $entry): bool => $entry['source_ready'],
));
$mappedAssertionCalls = 0;
$newMappedAssertionCalls = 0;

foreach ($remaining as $entry) {
    foreach ($entry['applied_transforms'] as $transform => $count) {
        if (str_starts_with($transform, 'map-assertion:')) {
            $mappedAssertionCalls += $count;
        }

        if (in_array($transform, $newAssertionTransforms, true)) {
            $newMappedAssertionCalls += $count;
        }
    }
}
$remainingCodes = array_values(array_unique(array_merge(...array_map(
    static fn (array $entry): array => array_column($entry['default_remaining_transforms'], 'code'),
    $remaining,
))));

foreach ([
    'class.fluent-assertion.untranslated',
    'class.helper.untranslated',
] as $requiredBlocker) {
    $assert(
        in_array($requiredBlocker, $remainingCodes, true),
        'The pinned Livewire inventory stopped failing closed for '.$requiredBlocker.'.',
    );
}
$assert(
    ! in_array('class.parent-lifecycle.untranslated', $remainingCodes, true),
    'The pinned Livewire inventory retained an external parent lifecycle.',
);
$assert($defaultSourceReadyBefore === 2, 'The pinned Livewire default source-ready baseline drifted: '.$defaultSourceReadyBefore.'.');
$assert($defaultSourceReadyAfter === 7, 'The pinned Livewire default assertion-lowered result drifted: '.$defaultSourceReadyAfter.'.');
$assert($profileSourceReady === 20, 'The authoritative Livewire profile did not close all 20 files: '.$profileSourceReady.'.');
$assert($mappedAssertionCalls === 511, 'The pinned Livewire mapped assertion count drifted: '.$mappedAssertionCalls.'.');
$assert($newMappedAssertionCalls === 342, 'The pinned Livewire newly mapped assertion count drifted: '.$newMappedAssertionCalls.'.');

echo json_encode([
    'schema' => 1,
    'ok' => true,
    'scope' => 'source-transform-only',
    'livewire' => [
        'commit' => $commit,
        'selected_source' => $selected,
        'selected_blob' => $selectedBlob,
        'selected_cases' => 1,
        'selected_assertions' => 1,
        'inventory_files' => count($remaining),
        'authoritative_profile_sha256' => $profileHash,
        'source_ready' => $profileSourceReady,
        'default_source_ready_before_assertion_lowering' => $defaultSourceReadyBefore,
        'default_source_ready_after_assertion_lowering' => $defaultSourceReadyAfter,
        'mapped_assertion_calls' => $mappedAssertionCalls,
        'newly_mapped_assertion_calls' => $newMappedAssertionCalls,
    ],
    'native_execution' => [
        'cases' => count($run['tests']),
        'assertions' => array_sum(array_column($run['tests'], 'assertions')),
        'external_runtime_loaded' => false,
    ],
    'inventory' => $remaining,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
