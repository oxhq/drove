<?php

declare(strict_types=1);

namespace Drove\Extension;

use InvalidArgumentException;

final readonly class CliOptionDefinition
{
    public function __construct(
        public string $longName,
        public string $description,
        public CliOptionType $type,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $longName) !== 1) {
            throw new InvalidArgumentException(
                'A Drove extension CLI option requires a lowercase long name without leading dashes.',
            );
        }

        if (trim($description) === '' || preg_match('//u', $description) !== 1) {
            throw new InvalidArgumentException(
                'A Drove extension CLI option requires a valid UTF-8 description.',
            );
        }
    }
}
