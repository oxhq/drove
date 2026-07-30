<?php

declare(strict_types=1);

namespace Drove\Native;

use Drove\Extension\ExtensionSet;
use LogicException;

final class Declarations
{
    private static ?DeclarationRegistry $active = null;

    public static function capture(
        callable $declarations,
        string $rootPath,
        string $suiteName = 'Drove Native',
        ?ExtensionSet $extensions = null,
    ): DeclarationRegistry {
        $previous = self::$active;
        $registry = new DeclarationRegistry($rootPath, $suiteName, $extensions);
        self::$active = $registry;

        try {
            $declarations();
        } finally {
            self::$active = $previous;
        }

        return $registry;
    }

    public static function current(): DeclarationRegistry
    {
        return self::$active ?? throw new LogicException(
            'Native declarations may only be registered inside Declarations::capture().',
        );
    }

    public static function isCapturing(): bool
    {
        return self::$active instanceof DeclarationRegistry;
    }
}
