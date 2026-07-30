<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StrictOutputTest extends TestCase
{
    public function test_unexpected_output_is_risky(): void
    {
        echo 'unexpected output';

        self::assertTrue(true);
    }

    public function test_expected_output_is_not_risky(): void
    {
        $this->expectOutputString('expected output');

        echo 'expected output';
    }
}
