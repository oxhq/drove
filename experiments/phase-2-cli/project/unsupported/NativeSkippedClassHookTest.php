<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NativeSkippedClassHookTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::markTestSkipped('native class skip proof');
    }

    public function test_body_is_not_run(): void
    {
        throw new RuntimeException('native class skip body ran');
    }
}
