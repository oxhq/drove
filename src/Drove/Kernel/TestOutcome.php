<?php

declare(strict_types=1);

namespace Drove\Kernel;

/**
 * @internal
 */
final readonly class TestOutcome
{
    private function __construct(
        public string $status,
        public mixed $value = null,
    ) {
        //
    }

    public static function passed(mixed $value = null): self
    {
        return new self('passed', $value);
    }

    public static function skipped(?string $reason = null): self
    {
        return new self('skipped', $reason);
    }

    public static function todo(?string $reason = null): self
    {
        return new self('todo', $reason);
    }
}
