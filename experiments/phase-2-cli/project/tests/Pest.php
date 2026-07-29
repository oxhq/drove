<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\BeforeClass;
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

abstract class DroveStaticLifecycleTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        droveCompatibilityMarker('set_up_before_class');
    }

    public static function tearDownAfterClass(): void
    {
        droveCompatibilityMarker('tear_down_after_class');

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        droveCompatibilityMarker('static_set_up');
    }

    protected function tearDown(): void
    {
        droveCompatibilityMarker('static_tear_down');

        parent::tearDown();
    }
}

abstract class DroveFailingStaticSetupTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        droveCompatibilityMarker('failing_set_up_before_class');

        throw new RuntimeException('class setup failed');
    }

    public static function tearDownAfterClass(): void
    {
        droveCompatibilityMarker('unexpected_tear_down_after_class');

        parent::tearDownAfterClass();
    }
}

abstract class DroveFailingStaticTeardownTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        droveCompatibilityMarker('teardown_set_up_before_class');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            droveCompatibilityMarker('failing_tear_down_after_class');

            throw new RuntimeException('class teardown failed');
        } finally {
            parent::tearDownAfterClass();
        }
    }
}

abstract class DroveSkippedStaticTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::markTestSkipped('generated Pest class skip proof');
    }
}

abstract class DroveUnsupportedAttributeStaticTestCase extends TestCase
{
    #[BeforeClass]
    public static function customBeforeClassAttribute(): void
    {
        //
    }
}

abstract class DrovePreparedCaseTestCase extends TestCase
{
    public ?string $preparedTestId = null;

    public ?int $preparedPid = null;

    public bool $preparedBeforeSetUp = false;

    protected function setUp(): void
    {
        if ($this->preparedTestId === null || $this->preparedPid !== getmypid()) {
            throw new RuntimeException('The Drove case was not prepared before setUp.');
        }

        $this->preparedBeforeSetUp = true;

        parent::setUp();
    }
}

#[RunTestsInSeparateProcesses]
abstract class DroveUnsupportedProcessIsolationTestCase extends TestCase
{
    //
}

uses(DroveCompatibilityTestCase::class)->in('CompatibilityTest.php');
uses(DroveStaticLifecycleTestCase::class)->in('../unsupported/StaticLifecycleTest.php');
uses(DroveFailingStaticSetupTestCase::class)->in('../unsupported/StaticSetupFailureTest.php');
uses(DroveFailingStaticTeardownTestCase::class)->in('../unsupported/StaticTeardownFailureTest.php');
uses(DroveSkippedStaticTestCase::class)->in('../unsupported/StaticSkippedTest.php');
uses(DroveUnsupportedAttributeStaticTestCase::class)->in('../unsupported/StaticAttributeHookTest.php');
uses(DrovePreparedCaseTestCase::class)->in('../unsupported/PrepareCaseTest.php');
uses(DroveUnsupportedProcessIsolationTestCase::class)->in('../unsupported/ProcessIsolationTest.php');

dataset('drove named rows', [
    ['named', 10],
]);
