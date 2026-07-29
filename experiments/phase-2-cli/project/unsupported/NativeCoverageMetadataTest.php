<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversClass(stdClass::class)]
#[CoversNothing]
final class NativeCoverageMetadataTest extends TestCase
{
    public function test_body(): void
    {
        self::assertTrue(true);
    }
}
