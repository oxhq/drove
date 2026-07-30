<?php

declare(strict_types=1);

namespace Drove\Bridge\Pest;

use Composer\InstalledVersions;
use NunoMaduro\Collision\Provider;
use ParaTest\Options;
use Pest\Plugin\Loader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Termwind\Termwind;

final class Bootstrap
{
    private const array DEPENDENCIES = [
        'brianium/paratest' => ['^7.23.0', Options::class, '7.23.0', '8.0.0'],
        'nunomaduro/collision' => ['^8.9.5', Provider::class, '8.9.5', '9.0.0'],
        'nunomaduro/termwind' => ['^2.4.0', Termwind::class, '2.4.0', '3.0.0'],
        'pestphp/pest-plugin' => ['^5.0.0', Loader::class, '5.0.0', '6.0.0'],
        'phpunit/phpunit' => ['13.2.4', TestCase::class, '13.2.4', '13.2.5'],
        'symfony/process' => ['^8.1.0', Process::class, '8.1.0', '9.0.0'],
    ];

    public static function boot(): void
    {
        $missing = [];

        foreach (self::DEPENDENCIES as $package => [$constraint, $class, $minimum, $maximum]) {
            $version = InstalledVersions::isInstalled($package)
                ? InstalledVersions::getVersion($package)
                : null;

            if (! is_string($version)
                || version_compare($version, $minimum, '<')
                || version_compare($version, $maximum, '>=')
                || ! class_exists($class)) {
                $missing[] = $package.':'.$constraint;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'DROVE_PEST_BRIDGE_MISSING_DEPENDENCIES: install the explicit bridge with '
                .'composer require --dev '.implode(' ', $missing),
            );
        }

        $source = dirname(__DIR__, 3);

        spl_autoload_register(static function (string $class) use ($source): void {
            if (! str_starts_with($class, 'Pest\\')) {
                return;
            }

            $path = $source.'/'.str_replace('\\', '/', substr($class, strlen('Pest\\'))).'.php';

            if (is_file($path)) {
                require_once $path;
            }
        });

        require_once $source.'/Functions.php';
        require_once $source.'/Pest.php';
    }
}
