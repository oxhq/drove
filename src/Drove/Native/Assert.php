<?php

declare(strict_types=1);

namespace Drove\Native;

use Countable;
use Drove\Kernel\AssertionFailed;

final class Assert
{
    public static function assertTrue(mixed $actual, string $message = ''): void
    {
        new Expectation($actual)->toBeTrue($message);
    }

    public static function assertFalse(mixed $actual, string $message = ''): void
    {
        new Expectation($actual)->toBeFalse($message);
    }

    public static function assertNull(mixed $actual, string $message = ''): void
    {
        new Expectation($actual)->toBeNull($message);
    }

    public static function assertNotNull(mixed $actual, string $message = ''): void
    {
        new Expectation($actual)->not()->toBeNull($message);
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        new Expectation($actual)->toBe($expected, $message);
    }

    public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        new Expectation($actual)->not()->toBe($expected, $message);
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        new Expectation($actual)->toEqual($expected, $message);
    }

    public static function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        new Expectation($actual)->not()->toEqual($expected, $message);
    }

    /** @param class-string $expected */
    public static function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        new Expectation($actual)->toBeInstanceOf($expected, $message);
    }

    public static function assertStringContainsString(
        string $expected,
        string $actual,
        string $message = '',
    ): void {
        self::assertContains($expected, $actual, $message);
    }

    public static function assertStringNotContainsString(
        string $expected,
        string $actual,
        string $message = '',
    ): void {
        TestContext::recordAssertion();

        if (str_contains($actual, $expected)) {
            throw new AssertionFailed($message !== ''
                ? $message
                : sprintf('Failed asserting that %s does not contain %s.', var_export($actual, true), var_export($expected, true)));
        }
    }

    public static function assertContains(mixed $expected, mixed $actual, string $message = ''): void
    {
        TestContext::recordAssertion();
        $contains = is_string($actual) && is_string($expected)
            ? str_contains($actual, $expected)
            : (is_iterable($actual) && array_any(
                is_array($actual) ? $actual : iterator_to_array($actual),
                static fn (mixed $value): bool => $value === $expected,
            ));

        if (! $contains) {
            throw new AssertionFailed($message !== ''
                ? $message
                : sprintf('Failed asserting that %s contains %s.', var_export($actual, true), var_export($expected, true)));
        }
    }

    /** @param Countable|iterable<mixed> $actual */
    public static function assertCount(int $expected, Countable|iterable $actual, string $message = ''): void
    {
        new Expectation($actual)->toHaveCount($expected, $message);
    }
}
