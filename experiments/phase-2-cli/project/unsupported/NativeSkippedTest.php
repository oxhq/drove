<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NativeSkippedTest extends TestCase
{
    public function test_skipped(): void
    {
        self::markTestSkipped('native skip proof');
    }
}
