<?php

declare(strict_types=1);

namespace Pest\Plugins;

use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Plugins\Concerns\HandleArguments;
use PHPUnit\TextUI\CliArguments\Builder as CliConfigurationBuilder;
use PHPUnit\TextUI\CliArguments\XmlConfigurationFileFinder;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use PHPUnit\TextUI\XmlConfiguration\Loader;

/**
 * @internal
 */
final class Cache implements HandlesArguments
{
    use HandleArguments;

    /**
     * The temporary folder.
     */
    private const string TEMPORARY_FOLDER = __DIR__
        .DIRECTORY_SEPARATOR
        .'..'
        .DIRECTORY_SEPARATOR
        .'..'
        .DIRECTORY_SEPARATOR
        .'.temp';

    /**
     * Handles the arguments, adding the cache directory and the cache result arguments.
     */
    public function handleArguments(array $arguments): array
    {
        if (! $this->hasArgument('--cache-directory', $arguments)) {

            $cliConfiguration = (new CliConfigurationBuilder)->fromParameters([]);
            $configurationFile = (new XmlConfigurationFileFinder)->find($cliConfiguration);
            $xmlConfiguration = DefaultConfiguration::create();

            if (is_string($configurationFile)) {
                $xmlConfiguration = (new Loader)->load($configurationFile);
            }

            if (! $xmlConfiguration->phpunit()->hasCacheDirectory()) {
                $cacheDirectory = realpath(self::TEMPORARY_FOLDER);

                if (! is_string($cacheDirectory)) {
                    $cacheDirectory = self::fallbackCacheDirectory();
                }

                $arguments = $this->pushArgument('--cache-directory', $arguments);
                $arguments = $this->pushArgument($cacheDirectory, $arguments);
            }
        }

        if (! $this->hasArgument('--parallel', $arguments) && ! $this->hasArgument('--do-not-cache-result', $arguments) && ! $this->hasArgument('--cache-result', $arguments)) {
            return $this->pushArgument('--cache-result', $arguments);
        }

        return $arguments;
    }

    private static function fallbackCacheDirectory(): string
    {
        $workingDirectory = getcwd();

        if (! is_string($workingDirectory)) {
            throw new \RuntimeException('DROVE_PEST_CACHE_DIRECTORY_UNSAFE: Drove could not resolve the current working directory.');
        }

        $cacheDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'drove-pest-'.hash('sha256', $workingDirectory);
        $created = @mkdir($cacheDirectory, 0700);

        clearstatcache(true, $cacheDirectory);

        if (! $created && ! file_exists($cacheDirectory) && ! is_link($cacheDirectory)) {
            throw new \RuntimeException('DROVE_PEST_CACHE_DIRECTORY_UNSAFE: Drove could not create the Pest bridge cache directory.');
        }

        $owner = @fileowner($cacheDirectory);
        $permissions = @fileperms($cacheDirectory);

        if (is_link($cacheDirectory)
            || ! is_dir($cacheDirectory)
            || ! is_int($owner)
            || $owner !== posix_geteuid()
            || ! is_int($permissions)
            || ($permissions & 0777) !== 0700) {
            throw new \RuntimeException(
                'DROVE_PEST_CACHE_DIRECTORY_UNSAFE: The Pest bridge cache path must be a private directory owned by the current user with mode 0700.',
            );
        }

        return $cacheDirectory;
    }
}
