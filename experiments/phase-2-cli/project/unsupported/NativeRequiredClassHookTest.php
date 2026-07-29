<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class NativeRequiredClassHookTest extends TestCase
{
    #[BeforeClass(priority: 1)]
    #[RequiresPhpExtension('drove_missing_extension')]
    public static function unavailableClassHook(): void
    {
        throw new RuntimeException('unavailable native class hook ran');
    }

    #[BeforeClass]
    public static function laterClassHook(): void
    {
        throw new RuntimeException('later native class hook ran');
    }

    public function test_body_is_not_run(): void
    {
        throw new RuntimeException('required native class hook body ran');
    }
}
