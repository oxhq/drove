<?php

declare(strict_types=1);

namespace Drove\Extension;

use JsonSerializable;

final readonly class Manifest implements JsonSerializable
{
    public const int SCHEMA = 1;

    /**
     * @param  list<ContributionKind>  $contributions
     * @param  array<string, array{type: string, required: bool}>  $configurationSchema
     */
    private function __construct(
        public string $id,
        public string $packageVersion,
        public string $entrypoint,
        public int $apiMinimum,
        public int $apiMaximum,
        public int $apiVersion,
        private array $contributions,
        private array $configurationSchema,
    ) {
        //
    }

    /**
     * @param  array<array-key, mixed>  $package
     */
    public static function fromComposerPackage(array $package): self
    {
        $id = $package['name'] ?? null;
        $packageVersion = $package['version'] ?? null;
        $type = $package['type'] ?? null;

        if (! is_string($id)
            || preg_match(
                '~^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$~D',
                $id,
            ) !== 1
            || ! is_string($packageVersion)
            || trim($packageVersion) === ''
            || preg_match('//u', $packageVersion) !== 1
            || ! is_string($type)
            || trim($type) === '') {
            self::invalid('Composer package identity, version, or type is invalid.');
        }

        if ($type === 'composer-plugin') {
            throw new ExtensionException(
                Diagnostic::ComposerPluginForbidden,
                sprintf('Extension %s must not be a Composer plugin.', $id),
            );
        }

        $autoload = $package['autoload'] ?? [];

        if (! is_array($autoload)) {
            self::invalid(sprintf('Extension %s contains invalid Composer autoload metadata.', $id));
        }

        $autoloadFiles = $autoload['files'] ?? [];

        if (! is_array($autoloadFiles) || ! array_is_list($autoloadFiles)) {
            self::invalid(sprintf('Extension %s contains invalid Composer autoload files.', $id));
        }

        if ($autoloadFiles !== []) {
            throw new ExtensionException(
                Diagnostic::AutoloadFilesForbidden,
                sprintf('Extension %s must not declare Composer autoload files.', $id),
            );
        }

        $extra = $package['extra'] ?? null;
        $drove = is_array($extra) ? ($extra['drove'] ?? null) : null;
        $manifest = is_array($drove) ? ($drove['extension'] ?? null) : null;

        if (! is_array($manifest)) {
            self::invalid(sprintf('Extension %s is missing its data-only manifest.', $id));
        }

        self::assertExactKeys(
            $manifest,
            ['api', 'configuration', 'contributions', 'entrypoint', 'id', 'schema'],
            sprintf('Extension %s manifest', $id),
        );

        if (($manifest['schema'] ?? null) !== self::SCHEMA
            || ($manifest['id'] ?? null) !== $id) {
            self::invalid(sprintf(
                'Extension %s must use manifest schema %d and its Composer package name as its ID.',
                $id,
                self::SCHEMA,
            ));
        }

        $entrypoint = $manifest['entrypoint'] ?? null;

        if (! is_string($entrypoint)
            || preg_match(
                '~^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$~D',
                $entrypoint,
            ) !== 1) {
            self::invalid(sprintf('Extension %s contains an invalid entrypoint class.', $id));
        }

        $api = $manifest['api'] ?? null;

        if (! is_array($api)) {
            self::invalid(sprintf('Extension %s contains an invalid API range.', $id));
        }

        self::assertExactKeys($api, ['max', 'min'], sprintf('Extension %s API range', $id));
        $apiMinimum = $api['min'] ?? null;
        $apiMaximum = $api['max'] ?? null;

        if (! is_int($apiMinimum)
            || ! is_int($apiMaximum)
            || $apiMinimum < 1
            || $apiMinimum > $apiMaximum) {
            self::invalid(sprintf('Extension %s contains an invalid API range.', $id));
        }

        $apiVersion = Api::negotiate($apiMinimum, $apiMaximum);

        if ($apiVersion === null) {
            throw new ExtensionException(
                Diagnostic::ApiIncompatible,
                sprintf(
                    'Extension %s requires Drove extension API %d-%d; this runtime supports %d-%d.',
                    $id,
                    $apiMinimum,
                    $apiMaximum,
                    Api::MIN,
                    Api::MAX,
                ),
            );
        }

        $contributions = self::normalizeContributions(
            $manifest['contributions'] ?? null,
            $id,
        );
        $configurationSchema = self::normalizeConfigurationSchema(
            $manifest['configuration'] ?? null,
            $id,
        );

        return new self(
            $id,
            $packageVersion,
            $entrypoint,
            $apiMinimum,
            $apiMaximum,
            $apiVersion,
            $contributions,
            $configurationSchema,
        );
    }

    /**
     * @return list<ContributionKind>
     */
    public function contributions(): array
    {
        return $this->contributions;
    }

    /**
     * @return array<string, array{type: string, required: bool}>
     */
    public function configurationSchema(): array
    {
        return $this->configurationSchema;
    }

    /**
     * @param  array<array-key, mixed>  $configuration
     * @return array<string, bool|float|int|string>
     */
    public function validateConfiguration(array $configuration): array
    {
        $validated = [];

        foreach ($configuration as $key => $value) {
            if (! is_string($key) || ! isset($this->configurationSchema[$key])) {
                throw new ExtensionException(
                    Diagnostic::ConfigurationInvalid,
                    sprintf('Extension %s received unknown configuration key %s.', $this->id, (string) $key),
                );
            }

            $type = $this->configurationSchema[$key]['type'];
            $valid = match ($type) {
                'string' => is_string($value) && preg_match('//u', $value) === 1,
                'integer' => is_int($value),
                'number' => is_int($value) || (is_float($value) && is_finite($value)),
                'boolean' => is_bool($value),
                default => throw new ExtensionException(
                    Diagnostic::ConfigurationInvalid,
                    sprintf('Extension %s contains an unknown configuration type.', $this->id),
                ),
            };

            if (! $valid) {
                throw new ExtensionException(
                    Diagnostic::ConfigurationInvalid,
                    sprintf('Extension %s configuration key %s must be %s.', $this->id, $key, $type),
                );
            }

            /** @var bool|float|int|string $value */
            $validated[$key] = $value;
        }

        foreach ($this->configurationSchema as $key => $definition) {
            if ($definition['required'] && ! array_key_exists($key, $configuration)) {
                throw new ExtensionException(
                    Diagnostic::ConfigurationInvalid,
                    sprintf('Extension %s requires configuration key %s.', $this->id, $key),
                );
            }
        }

        ksort($validated, SORT_STRING);

        return $validated;
    }

    /**
     * @return array{
     *     schema: 1,
     *     id: string,
     *     package_version: string,
     *     entrypoint: string,
     *     api: array{min: int, max: int, negotiated: int},
     *     contributions: list<string>,
     *     configuration: array<string, array{type: string, required: bool}>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'id' => $this->id,
            'package_version' => $this->packageVersion,
            'entrypoint' => $this->entrypoint,
            'api' => [
                'min' => $this->apiMinimum,
                'max' => $this->apiMaximum,
                'negotiated' => $this->apiVersion,
            ],
            'contributions' => array_map(
                static fn (ContributionKind $kind): string => $kind->value,
                $this->contributions,
            ),
            'configuration' => $this->configurationSchema,
        ];
    }

    /**
     * @return array{
     *     schema: 1,
     *     id: string,
     *     package_version: string,
     *     entrypoint: string,
     *     api: array{min: int, max: int, negotiated: int},
     *     contributions: list<string>,
     *     configuration: array<string, array{type: string, required: bool}>
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return list<ContributionKind>
     */
    private static function normalizeContributions(mixed $value, string $id): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            self::invalid(sprintf('Extension %s must declare a non-empty contribution list.', $id));
        }

        $declared = [];

        foreach ($value as $kind) {
            if (! is_string($kind) || ContributionKind::tryFrom($kind) === null) {
                self::invalid(sprintf('Extension %s declares an unknown contribution kind.', $id));
            }

            if (isset($declared[$kind])) {
                self::invalid(sprintf('Extension %s declares a duplicate contribution kind.', $id));
            }

            $declared[$kind] = true;
        }

        return array_values(array_filter(
            ContributionKind::cases(),
            static fn (ContributionKind $kind): bool => isset($declared[$kind->value]),
        ));
    }

    /**
     * @return array<string, array{type: string, required: bool}>
     */
    private static function normalizeConfigurationSchema(mixed $value, string $id): array
    {
        if (! is_array($value)) {
            self::invalid(sprintf('Extension %s contains an invalid configuration schema.', $id));
        }

        $schema = [];

        foreach ($value as $key => $definition) {
            if (! is_string($key)
                || preg_match('~^[a-z][a-z0-9_.-]*$~D', $key) !== 1
                || ! is_array($definition)) {
                self::invalid(sprintf('Extension %s contains an invalid configuration schema.', $id));
            }

            self::assertExactKeys(
                $definition,
                ['required', 'type'],
                sprintf('Extension %s configuration key %s', $id, $key),
            );
            $type = $definition['type'] ?? null;
            $required = $definition['required'] ?? null;

            if (! in_array($type, ['string', 'integer', 'number', 'boolean'], true)
                || ! is_bool($required)) {
                self::invalid(sprintf('Extension %s contains an invalid configuration schema.', $id));
            }

            $schema[$key] = ['type' => $type, 'required' => $required];
        }

        ksort($schema, SORT_STRING);

        return $schema;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $expected
     */
    private static function assertExactKeys(array $value, array $expected, string $owner): void
    {
        $keys = array_keys($value);

        if (array_any($keys, static fn (mixed $key): bool => ! is_string($key))) {
            self::invalid(sprintf('%s contains invalid keys.', $owner));
        }

        sort($keys, SORT_STRING);

        if ($keys !== $expected) {
            self::invalid(sprintf('%s contains missing or unknown keys.', $owner));
        }
    }

    private static function invalid(string $message): never
    {
        throw new ExtensionException(Diagnostic::ManifestInvalid, $message);
    }
}
