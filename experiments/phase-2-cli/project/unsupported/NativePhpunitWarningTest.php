<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NativePhpunitWarningTest extends TestCase
{
    public function test_duplicate_output_expectations(): void
    {
        $this->expectOutputString('alpha');
        $this->expectOutputRegex('/alpha/');

        echo 'alpha';

        self::assertTrue(true);
    }
}
