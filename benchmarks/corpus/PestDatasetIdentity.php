<?php

declare(strict_types=1);

final class NativePestDatasetIdentity
{
    /** @return array{declaration: string, suffix: ?string} */
    public static function split(string $name): array
    {
        $offset = strrpos($name, ' with data set ');

        return $offset === false
            ? ['declaration' => $name, 'suffix' => null]
            : [
                'declaration' => substr($name, 0, $offset),
                'suffix' => substr($name, $offset + strlen(' with data set ')),
            ];
    }

    public static function key(?string $suffix, int $numericIndex): ?string
    {
        if ($suffix === null) {
            return null;
        }

        $payload = str_starts_with($suffix, '"') && str_ends_with($suffix, '"')
            ? substr($suffix, 1, -1)
            : $suffix;

        if (preg_match('/^dataset "(.*)"$/usD', $payload, $match) === 1) {
            return 'name:'.rawurlencode($match[1]);
        }

        if (str_starts_with($payload, 'dataset "')) {
            return 'name:'.rawurlencode($payload);
        }

        return 'index:'.$numericIndex;
    }
}
