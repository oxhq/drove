<?php

declare(strict_types=1);

namespace Drove\Extension;

use JsonException;

final class Discovery
{
    /**
     * @return list<Manifest>
     */
    public function fromInstalledJson(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ExtensionException(
                Diagnostic::DiscoveryIo,
                'Drove could not read Composer installed package metadata.',
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ExtensionException(
                Diagnostic::DiscoveryIo,
                'Drove could not read Composer installed package metadata.',
            );
        }

        try {
            $installed = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ExtensionException(
                Diagnostic::DiscoveryJson,
                'Composer installed package metadata is not valid JSON.',
                $exception,
            );
        }

        $packages = is_array($installed) ? ($installed['packages'] ?? null) : null;

        if (! is_array($packages) || ! array_is_list($packages)) {
            throw new ExtensionException(
                Diagnostic::DiscoveryJson,
                'Composer installed package metadata must contain a package list.',
            );
        }

        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                throw new ExtensionException(
                    Diagnostic::DiscoveryJson,
                    'Composer installed package metadata contains an invalid package.',
                );
            }
        }

        usort(
            $packages,
            static fn (array $left, array $right): int => strcmp($left['name'], $right['name']),
        );
        $candidates = [];

        foreach ($packages as $package) {
            $extra = $package['extra'] ?? null;

            if (! is_array($extra)) {
                continue;
            }

            if (! array_key_exists('drove', $extra)) {
                continue;
            }

            $drove = $extra['drove'];

            if (! is_array($drove)) {
                throw new ExtensionException(
                    Diagnostic::ManifestInvalid,
                    sprintf('Package %s contains invalid Drove metadata.', $package['name']),
                );
            }

            if (! array_key_exists('extension', $drove)) {
                continue;
            }

            if (isset($candidates[$package['name']])) {
                throw new ExtensionException(
                    Diagnostic::DuplicateId,
                    sprintf('Extension ID %s is declared more than once.', $package['name']),
                );
            }

            $candidates[$package['name']] = $package;
        }

        return array_map(
            Manifest::fromComposerPackage(...),
            array_values($candidates),
        );
    }
}
