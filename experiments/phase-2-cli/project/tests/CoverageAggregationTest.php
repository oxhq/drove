<?php

declare(strict_types=1);

use DroveBetaProof\Subject\Covered;
use DroveBetaProof\Subject\First\Thing as FirstThing;
use DroveBetaProof\Subject\Second\Thing as SecondThing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoverageAggregationTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function values(): iterable
    {
        yield 'negative' => [-1, 'negative'];
        yield 'zero' => [0, 'zero'];
        yield 'positive' => [1, 'positive'];
    }

    #[DataProvider('values')]
    public function test_it_classifies(int $value, string $expected): void
    {
        self::assertSame($expected, Covered::classify($value));
    }

    public function test_it_covers_the_first_same_basename_source(): void
    {
        self::assertSame('first', FirstThing::value());
    }

    public function test_it_covers_the_second_same_basename_source(): void
    {
        self::assertSame('second', SecondThing::value());
    }
}
