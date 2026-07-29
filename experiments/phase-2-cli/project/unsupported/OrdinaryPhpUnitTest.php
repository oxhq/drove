<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OrdinaryPhpUnitTest extends TestCase
{
    public function test_ordinary_php_unit_case(): void
    {
        self::fail('An unsupported PHPUnit case must never be silently skipped.');
    }
}
