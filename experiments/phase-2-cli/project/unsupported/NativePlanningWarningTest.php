<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class NativePlanningWarningTest extends TestCase
{
    #[DataProvider('rows')]
    #[TestWith(['ignored'])]
    public function test_mixed_data_sources(string $value): void
    {
        self::assertSame('provider', $value);
    }

    public static function rows(): iterable
    {
        yield ['provider'];
    }
}
