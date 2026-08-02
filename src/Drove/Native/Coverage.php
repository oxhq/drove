<?php

declare(strict_types=1);

namespace Drove\Native;

use InvalidArgumentException;

final class Coverage
{
    /** @return array{kind: 'class'|'function'|'trait', target: string} */
    public static function target(string $target, ?string $kind = null): array
    {
        $kind ??= match (true) {
            class_exists($target) => 'class',
            trait_exists($target) => 'trait',
            function_exists($target) => 'function',
            default => throw new InvalidArgumentException(
                sprintf('No class, trait or method named "%s" has been found.', $target),
            ),
        };

        $resolvedKind = match ($kind) {
            'class' => 'class',
            'function' => 'function',
            'trait' => 'trait',
            default => null,
        };

        if ($resolvedKind === null) {
            throw new InvalidArgumentException(sprintf('Coverage target %s is not a %s.', $target, $kind));
        }

        $valid = match ($resolvedKind) {
            'class' => class_exists($target),
            'function' => function_exists($target),
            'trait' => trait_exists($target),
        };

        if (! $valid) {
            throw new InvalidArgumentException(sprintf('Coverage target %s is not a %s.', $target, $kind));
        }

        return ['kind' => $resolvedKind, 'target' => $target];
    }

    /**
     * @param  array<int, string>|string  ...$targets
     * @return list<array{kind: 'class'|'function'|'trait', target: string}>
     */
    public static function targets(array|string ...$targets): array
    {
        $flattened = [];

        foreach ($targets as $target) {
            array_push($flattened, ...(is_array($target) ? $target : [$target]));
        }

        if ($flattened === []) {
            throw new InvalidArgumentException('Coverage requires at least one target.');
        }

        return array_map(self::target(...), $flattened);
    }
}
