<?php

declare(strict_types=1);

namespace Drove\Extension;

use InvalidArgumentException;

final readonly class CliInput
{
    public function __construct(
        public CliOptionDefinition $definition,
        public bool|string|int $value,
    ) {
        $valid = match ($definition->type) {
            CliOptionType::Boolean => is_bool($value),
            CliOptionType::String => is_string($value),
            CliOptionType::Integer => is_int($value),
        };

        if (! $valid) {
            throw new InvalidArgumentException(sprintf(
                'Drove extension CLI option --%s requires a %s value.',
                $definition->longName,
                $definition->type->value,
            ));
        }
    }
}
