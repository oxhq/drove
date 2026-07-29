<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class NativeRiskyTest extends TestCase
{
    public function test_without_assertions_is_risky(): void
    {
        //
    }

    #[DoesNotPerformAssertions]
    public function test_explicitly_without_assertions_is_not_risky(): void
    {
        //
    }
}
