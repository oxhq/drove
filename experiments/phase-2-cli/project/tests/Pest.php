<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class DroveCompatibilityTestCase extends TestCase
{
    public int $droveBeforeEach = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        droveCompatibilityMarker('class_before');
    }

    public static function tearDownAfterClass(): void
    {
        droveCompatibilityMarker('class_after');

        parent::tearDownAfterClass();
    }

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

function droveCompatibilityMarker(string $event): void
{
    $path = getenv('DROVE_COMPATIBILITY_MARKER');

    if (! is_string($path) || $path === ''
        || file_put_contents($path, $event.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the Drove compatibility marker.');
    }
}

uses(DroveCompatibilityTestCase::class)->in('CompatibilityTest.php');

dataset('drove named rows', [
    ['named', 10],
]);
