<?php

declare(strict_types=1);

namespace Drove\Extension;

use InvalidArgumentException;

final readonly class MatchInput
{
    /** @var list<mixed> */
    public array $arguments;

    /** @param array<array-key, mixed> $arguments */
    public function __construct(
        public mixed $actual,
        array $arguments = [],
    ) {
        if (! array_is_list($arguments)) {
            throw new InvalidArgumentException('Drove matcher arguments must be a list.');
        }

        $this->arguments = $arguments;
    }
}
