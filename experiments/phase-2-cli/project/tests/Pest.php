<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

abstract class DroveCompatibilityTestCase extends TestCase
{
    public int $droveBeforeEach = 0;

    protected function setUp(): void
    {
        parent::setUp();

        droveCompatibilityMarker('set_up');
    }

    protected function tearDown(): void
    {
        droveCompatibilityMarker('tear_down');

        parent::tearDown();
    }

    public function bindingMarker(): string
    {
        return 'custom-test-case';
    }
}

abstract class DroveUnsupportedStaticLifecycleTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        //
    }
}

#[RunTestsInSeparateProcesses]
abstract class DroveUnsupportedProcessIsolationTestCase extends TestCase
{
    //
}

function droveCompatibilityMarker(string $event): void
{
    $path = getenv('DROVE_COMPATIBILITY_MARKER');

    if (! is_string($path) || $path === ''
        || file_put_contents($path, $event.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the Drove compatibility marker.');
    }
}

uses(DroveCompatibilityTestCase::class)->in('CompatibilityTest.php');
uses(DroveUnsupportedStaticLifecycleTestCase::class)->in('../unsupported/StaticLifecycleTest.php');
uses(DroveUnsupportedProcessIsolationTestCase::class)->in('../unsupported/ProcessIsolationTest.php');

dataset('drove named rows', [
    ['named', 10],
]);
