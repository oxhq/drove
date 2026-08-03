<?php

declare(strict_types=1);

namespace Drove;

use Drove\Compatibility\Registry as SurfaceRegistry;
use JsonException;
use RuntimeException;

/**
 * @internal
 */
final class CompatibilityRegistry
{
    /**
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        $path = dirname(__DIR__, 2).'/resources/drove-compatibility.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Drove could not read its compatibility registry.');
        }

        try {
            $registry = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Drove contains an invalid compatibility registry.',
                $exception->getCode(),
                previous: $exception,
            );
        }

        if (! is_array($registry)
            || ($registry['schema'] ?? null) !== 1
            || ! is_array($registry['platforms'] ?? null)
            || ! is_array($registry['frontends'] ?? null)
            || ! is_array($registry['capabilities'] ?? null)
            || ! is_array($registry['environment_contract'] ?? null)
            || ($registry['environment_contract']['schema'] ?? null) !== 1
            || ! is_array($registry['environment_contract']['coordination_guarantees'] ?? null)
            || ! is_array($registry['environment_contract']['resource_kinds'] ?? null)
            || ! is_array($registry['environment_contract']['resource_capabilities'] ?? null)) {
            throw new RuntimeException('Drove contains an invalid compatibility registry.');
        }

        $registry['drove_version'] = Version::current();
        $registry['surface_registry'] = SurfaceRegistry::load()->manifest();

        return $registry;
    }

    public static function json(): string
    {
        return json_encode(
            self::read(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
    }
}
