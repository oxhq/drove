<?php

declare(strict_types=1);

namespace Drove;

use Composer\InstalledVersions;

/**
 * @internal
 */
final class Version
{
    public const string FALLBACK = '0.4.0-alpha.1-dev';

    public static function current(): string
    {
        if (! class_exists(InstalledVersions::class)
            || ! InstalledVersions::isInstalled('oxhq/drove')) {
            return self::FALLBACK;
        }

        return InstalledVersions::getPrettyVersion('oxhq/drove')
            ?? InstalledVersions::getVersion('oxhq/drove')
            ?? self::FALLBACK;
    }
}
