<?php

declare(strict_types=1);

namespace Drove\Extension;

use InvalidArgumentException;

final readonly class MatchResult
{
    public function __construct(
        public bool $passed,
        public ?string $failureMessage = null,
    ) {
        if ($passed && $failureMessage !== null) {
            throw new InvalidArgumentException('A passing Drove matcher cannot have a failure message.');
        }

        if (! $passed && ($failureMessage === null || trim($failureMessage) === '')) {
            throw new InvalidArgumentException('A failing Drove matcher requires a failure message.');
        }

        if ($failureMessage !== null && preg_match('//u', $failureMessage) !== 1) {
            throw new InvalidArgumentException('A Drove matcher failure message must be valid UTF-8.');
        }
    }

    public static function pass(): self
    {
        return new self(true);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
