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

    public static function skipped(mixed $value = null): self
    {
        return new self('skipped', $value);
    }

    public static function todo(mixed $value = null): self
    {
        return new self('todo', $value);
    }

    public static function incomplete(mixed $value = null): self
    {
        return new self('incomplete', $value);
    }

    public static function risky(mixed $value = null): self
    {
        return new self('risky', $value);
    }
}
