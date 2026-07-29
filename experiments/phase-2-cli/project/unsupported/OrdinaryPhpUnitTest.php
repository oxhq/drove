<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('native')]
final class OrdinaryPhpUnitTest extends TestCase
{
    #[BeforeClass(20)]
    public static function nativeBeforeClassHighPriority(): void
    {
        droveCompatibilityMarker('native_before_class_high');
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        droveCompatibilityMarker('native_set_up_before_class');
    }

    #[BeforeClass(-10)]
    public static function nativeBeforeClassLowPriority(): void
    {
        droveCompatibilityMarker('native_before_class_low');
    }

    #[AfterClass(20)]
    public static function nativeAfterClassHighPriority(): void
    {
        droveCompatibilityMarker('native_after_class_high');
    }

    public static function tearDownAfterClass(): void
    {
        droveCompatibilityMarker('native_tear_down_after_class');

        parent::tearDownAfterClass();
    }

    #[AfterClass(-10)]
    public static function nativeAfterClassLowPriority(): void
    {
        droveCompatibilityMarker('native_after_class_low');
    }

    protected function setUp(): void
    {
        parent::setUp();

        droveCompatibilityMarker('native_set_up');
    }

    protected function tearDown(): void
    {
        droveCompatibilityMarker('native_tear_down');

        parent::tearDown();
    }

    #[DataProvider('nativeRows')]
    public function test_native_dataset(string $label, int $value): void
    {
        droveCompatibilityMarker('native_body_'.$label);

        echo 'native-output:'.$label;

        self::assertGreaterThan(0, $value);
    }

    public function test_native_incomplete(): void
    {
        droveCompatibilityMarker('native_body_incomplete');

        self::markTestIncomplete('native incomplete proof');
    }

    public static function nativeRows(): iterable
    {
        yield 'named row' => ['named', 10];
        yield ['positional', 20];
    }
}
