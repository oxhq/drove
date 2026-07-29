<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class NativeProcessIsolationTest extends TestCase
{
    public function test_native_process_isolation_metadata(): void
    {
        self::assertTrue(true);
    }
}
