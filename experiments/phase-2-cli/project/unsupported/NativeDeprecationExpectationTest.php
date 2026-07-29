<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

final class NativeDeprecationExpectationTest extends TestCase
{
    public function test_expected_user_deprecation(): void
    {
        $this->expectUserDeprecationMessage('expected native deprecation');

        trigger_error('expected native deprecation', E_USER_DEPRECATED);

        self::assertTrue(true);
    }

    #[WithoutErrorHandler]
    public function test_error_handler_opt_out(): void
    {
        $caught = false;
        set_error_handler(static function () use (&$caught): true {
            $caught = true;

            return true;
        });

        try {
            trigger_error('opted out native deprecation', E_USER_DEPRECATED);
        } finally {
            restore_error_handler();
        }

        self::assertTrue($caught);
    }
}
