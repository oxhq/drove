<?php

declare(strict_types=1);

namespace Drove\Extension;

use Drove\Extension\Contracts\Entrypoint;
use Throwable;
use TypeError;

final class Loader
{
    /**
     * @param  array<array-key, mixed>  $manifests
     * @param  array<array-key, mixed>  $configurationById
     */
    public static function load(
        array $manifests,
        array $configurationById = [],
    ): ExtensionSet {
        $byId = [];

        foreach ($manifests as $manifest) {
            if (! $manifest instanceof Manifest) {
                throw new ExtensionException(
                    Diagnostic::ManifestInvalid,
                    'The Drove extension loader requires Manifest values.',
                );
            }

            if (isset($byId[$manifest->id])) {
                throw new ExtensionException(
                    Diagnostic::DuplicateId,
                    sprintf('Duplicate extension id %s.', $manifest->id),
                );
            }

            $byId[$manifest->id] = $manifest;
        }

        foreach ($configurationById as $id => $configuration) {
            if (! is_string($id) || ! isset($byId[$id]) || ! is_array($configuration)) {
                throw new ExtensionException(
                    Diagnostic::ConfigurationInvalid,
                    'Extension configuration must map installed extension ids to configuration objects.',
                );
            }
        }

        ksort($byId, SORT_STRING);
        $configuration = [];

        foreach ($byId as $manifest) {
            $negotiated = Api::negotiate($manifest->apiMinimum, $manifest->apiMaximum);

            if ($negotiated === null || $negotiated !== $manifest->apiVersion) {
                throw new ExtensionException(
                    Diagnostic::ApiIncompatible,
                    sprintf('Extension %s does not support this Drove extension API.', $manifest->id),
                );
            }

            $configuration[$manifest->id] = $manifest->validateConfiguration(
                $configurationById[$manifest->id] ?? [],
            );
        }

        $extensions = [];

        foreach ($byId as $manifest) {
            $entrypointClass = $manifest->entrypoint;

            try {
                if (! class_exists($entrypointClass)) {
                    throw new ExtensionException(
                        Diagnostic::EntrypointInvalid,
                        sprintf('Extension %s entrypoint %s does not exist.', $manifest->id, $entrypointClass),
                    );
                }

                $entrypoint = new $entrypointClass;
            } catch (ExtensionException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                throw new ExtensionException(
                    Diagnostic::EntrypointInvalid,
                    sprintf('Extension %s entrypoint could not be created: %s', $manifest->id, $throwable->getMessage()),
                    previous: $throwable,
                );
            }

            if (! $entrypoint instanceof Entrypoint) {
                throw new ExtensionException(
                    Diagnostic::EntrypointInvalid,
                    sprintf('Extension %s entrypoint must implement %s.', $manifest->id, Entrypoint::class),
                );
            }

            $registry = new Registry($manifest, $configuration[$manifest->id]);

            try {
                $entrypoint->register($registry);
                $extensions[] = $registry->freeze();
            } catch (ExtensionException $exception) {
                throw $exception;
            } catch (TypeError $exception) {
                $untypedContribution = str_starts_with(
                    $exception->getMessage(),
                    Registry::class.'::register',
                );

                throw new ExtensionException(
                    $untypedContribution
                        ? Diagnostic::UntypedContribution
                        : Diagnostic::RegistrationFailed,
                    sprintf(
                        'Extension %s %s: %s',
                        $manifest->id,
                        $untypedContribution
                            ? 'registered an untyped contribution'
                            : 'registration failed',
                        $exception->getMessage(),
                    ),
                    previous: $exception,
                );
            } catch (Throwable $throwable) {
                throw new ExtensionException(
                    Diagnostic::RegistrationFailed,
                    sprintf('Extension %s registration failed: %s', $manifest->id, $throwable->getMessage()),
                    previous: $throwable,
                );
            }
        }

        return new ExtensionSet($extensions);
    }
}
