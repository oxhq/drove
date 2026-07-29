<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\TestCase;

final class NativeNonPublicClassHookTest extends TestCase
{
    #[BeforeClass]
    protected static function nonPublicClassHook(): void
    {
        //
    }

    public function test_body(): void
    {
        self::assertTrue(true);
    }
}
