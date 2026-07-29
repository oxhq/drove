<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvalidDataProviderTest extends TestCase
{
    #[DataProvider('invalidRows')]
    public function test_invalid_provider(string $value): void
    {
        self::assertSame('never', $value);
    }

    public function test_valid_sibling(): void
    {
        self::assertTrue(true);
    }

    public static function invalidRows(): iterable
    {
        throw new RuntimeException('invalid provider proof');
    }
}
