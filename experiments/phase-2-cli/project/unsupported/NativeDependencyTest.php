<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

final class NativeDependencyTest extends TestCase
{
    public function test_dependency_source(): int
    {
        self::assertTrue(true);

        return 42;
    }

    #[Depends('test_dependency_source')]
    public function test_dependency_consumer(int $value): void
    {
        self::assertSame(42, $value);
    }
}
