<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FirstMultipleNativeTest extends TestCase
{
    public function test_first_native_class(): void
    {
        self::assertTrue(true);
    }
}

final class SecondMultipleNativeTest extends TestCase
{
    public function test_second_native_class(): void
    {
        self::assertTrue(true);
    }
}
