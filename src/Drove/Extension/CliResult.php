<?php

declare(strict_types=1);

namespace Drove\Extension;

use InvalidArgumentException;

final readonly class CliResult
{
    public function __construct(
        public string $output,
    ) {
        if (preg_match('//u', $output) !== 1) {
            throw new InvalidArgumentException('Drove extension CLI output must be valid UTF-8.');
        }
    }
}
