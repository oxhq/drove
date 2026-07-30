<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Drove\Kernel\StateAdapterException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use JsonException;
use ReflectionProperty;

final class NativeProviderManifest
{
    private const string CLASS_NAME = '[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*';

    /** @param array<array-key, mixed> $allowed */
    public static function assertAllowed(string $basePath, array $allowed): void
    {
        self::assertPackageDiscoveryDisabled($basePath);
        self::assertFixedConfigCacheAbsent($basePath);

        if (! array_is_list($allowed)
            || array_any(
                $allowed,
                static fn (mixed $provider): bool => ! is_string($provider)
                    || preg_match('/\A'.self::CLASS_NAME.'\z/D', $provider) !== 1,
            )
            || count($allowed) !== count(array_unique($allowed))) {
            throw new InvalidArgumentException(
                'Native Laravel providers must be a unique list of class strings.',
            );
        }

        $unexpected = array_values(array_diff(
            self::providers($basePath),
            $allowed,
        ));

        if ($unexpected !== []) {
            throw new StateAdapterException(sprintf(
                'Native Laravel provider preflight rejected unapproved providers [%s] before application bootstrap.',
                implode(', ', $unexpected),
            ));
        }
    }

    /**
     * @param  list<class-string>  $allowed
     */
    public static function assertConfigured(
        string $basePath,
        array $allowed,
    ): void {
        $merge = new ReflectionProperty(RegisterProviders::class, 'merge')
            ->getValue();
        $path = new ReflectionProperty(
            RegisterProviders::class,
            'bootstrapProviderPath',
        )->getValue();
        $expected = realpath($basePath.'/bootstrap/providers.php');
        $configured = is_string($path) ? realpath($path) : false;

        if (! is_string($expected)
            || ! is_string($configured)
            || $configured !== $expected) {
            throw new StateAdapterException(
                'Native Laravel requires bootstrap/app.php to retain its bootstrap/providers.php path.',
            );
        }

        if (! is_array($merge)
            || ! array_is_list($merge)
            || array_any(
                $merge,
                static fn (mixed $provider): bool => ! is_string($provider)
                    || preg_match(
                        '/\A'.self::CLASS_NAME.'\z/D',
                        $provider,
                    ) !== 1,
            )) {
            throw new StateAdapterException(
                'Native Laravel bootstrap/app.php declared an invalid additional provider list.',
            );
        }

        $unexpected = array_values(array_diff(
            $merge,
            $allowed,
        ));

        if ($unexpected !== []) {
            throw new StateAdapterException(sprintf(
                'Native Laravel provider preflight rejected unapproved bootstrap/app.php providers [%s] before provider bootstrap.',
                implode(', ', $unexpected),
            ));
        }
    }

    /**
     * @param  list<class-string>  $allowed
     */
    public static function assertLoadedConfiguration(
        Application $application,
        array $allowed,
    ): void {
        $providers = $application->make('config')->get('app.providers')
            ?? ServiceProvider::defaultProviders()->toArray();

        if (! is_array($providers)
            || ! array_is_list($providers)
            || array_any(
                $providers,
                static fn (mixed $provider): bool => ! is_string($provider)
                    || preg_match(
                        '/\A'.self::CLASS_NAME.'\z/D',
                        $provider,
                    ) !== 1,
            )
            || count($providers) !== count(array_unique($providers))) {
            throw new StateAdapterException(
                'Native Laravel config app.providers must be a unique list of class strings.',
            );
        }

        $unexpected = array_values(array_diff(
            $providers,
            [
                ...ServiceProvider::defaultProviders()->toArray(),
                ...$allowed,
            ],
        ));

        if ($unexpected !== []) {
            throw new StateAdapterException(sprintf(
                'Native Laravel provider preflight rejected unapproved config/app.php providers [%s] before provider bootstrap.',
                implode(', ', $unexpected),
            ));
        }
    }

    private static function assertPackageDiscoveryDisabled(
        string $basePath,
    ): void {
        $path = $basePath.'/composer.json';
        $source = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($source)) {
            throw new StateAdapterException(
                'Native Laravel requires a readable project composer.json.',
            );
        }

        try {
            $composer = json_decode(
                $source,
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $error) {
            throw new StateAdapterException(
                'Native Laravel requires a valid project composer.json.',
                previous: $error,
            );
        }

        $disabled = is_array($composer)
            ? ($composer['extra']['laravel']['dont-discover'] ?? null)
            : null;

        if (! is_array($disabled)
            || ! array_is_list($disabled)
            || ! in_array('*', $disabled, true)) {
            throw new StateAdapterException(
                'Native Laravel requires composer extra.laravel.dont-discover to contain "*" before application bootstrap.',
            );
        }
    }

    private static function assertFixedConfigCacheAbsent(string $basePath): void
    {
        if (file_exists($basePath.'/bootstrap/cache/config.php')) {
            throw new StateAdapterException(
                'Native Laravel rejects bootstrap/cache/config.php because it can hide providers from preflight.',
            );
        }
    }

    /** @return list<string> */
    private static function providers(string $basePath): array
    {
        $path = $basePath.'/bootstrap/providers.php';
        $source = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($source)) {
            throw new StateAdapterException(sprintf(
                'Native Laravel provider manifest %s does not exist or is unreadable.',
                $path,
            ));
        }

        $pattern = '~\A<\?php\s*'
            .'(?:declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*)?'
            .'(?<imports>(?:use\s+'.self::CLASS_NAME
            .'(?:\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?\s*;\s*)*)'
            .'return\s*\[\s*'
            .'(?<entries>(?:[A-Za-z_][A-Za-z0-9_]*\s*::\s*class\s*,\s*)*)'
            .'\]\s*;\s*\z~D';

        if (preg_match($pattern, $source, $manifest) !== 1) {
            throw new StateAdapterException(
                'Native Laravel provider manifests must be a data-only list of imported Provider::class entries.',
            );
        }

        preg_match_all(
            '~use\s+('.self::CLASS_NAME.')'
                .'(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;~',
            $manifest['imports'],
            $matches,
            PREG_SET_ORDER,
        );
        $imports = [];

        foreach ($matches as $match) {
            $class = $match[1];
            $separator = strrpos($class, '\\');
            $alias = ($match[2] ?? '') !== ''
                ? $match[2]
                : ($separator === false ? $class : substr($class, $separator + 1));

            if (isset($imports[$alias])) {
                throw new StateAdapterException(
                    'Native Laravel provider manifest imports must be unique.',
                );
            }

            $imports[$alias] = $class;
        }

        preg_match_all(
            '~([A-Za-z_][A-Za-z0-9_]*)\s*::\s*class~',
            $manifest['entries'],
            $entries,
        );
        $providers = [];

        foreach ($entries[1] as $alias) {
            $provider = $imports[$alias] ?? throw new StateAdapterException(sprintf(
                'Native Laravel provider manifest entry %s::class must be imported.',
                $alias,
            ));

            if (in_array($provider, $providers, true)) {
                throw new StateAdapterException(
                    'Native Laravel provider manifest entries must be unique.',
                );
            }

            $providers[] = $provider;
        }

        return $providers;
    }
}
