<?php

declare(strict_types=1);

namespace Drove\Environment;

use InvalidArgumentException;
use JsonSerializable;

/**
 * @experimental
 */
final readonly class ResourcePlan implements JsonSerializable
{
    /** @var list<ResourceCapability> */
    private array $capabilities;

    /** @var list<string> */
    private array $limitations;

    /**
     * @param  array<array-key, mixed>  $capabilities
     * @param  array<array-key, mixed>  $limitations
     */
    public function __construct(
        public ResourceKind $kind,
        public ?string $provider,
        array $capabilities,
        array $limitations,
    ) {
        if ($provider !== null && trim($provider) === '') {
            throw new InvalidArgumentException(
                'Drove environment provider names must be non-empty or null.',
            );
        }

        if (! array_is_list($capabilities)) {
            throw new InvalidArgumentException(
                'Drove environment resource capabilities must be a list of ResourceCapability values.',
            );
        }

        $byValue = [];

        foreach ($capabilities as $capability) {
            if (! $capability instanceof ResourceCapability) {
                throw new InvalidArgumentException(
                    'Drove environment resource capabilities must be a list of ResourceCapability values.',
                );
            }

            if (isset($byValue[$capability->value])) {
                throw new InvalidArgumentException(
                    'Drove environment resource capabilities must be unique.',
                );
            }

            $byValue[$capability->value] = $capability;
        }

        if ($provider === null && $byValue !== []) {
            throw new InvalidArgumentException(
                'Unmanaged Drove environment resources cannot declare capabilities.',
            );
        }

        if (! array_is_list($limitations)
            || array_any(
                $limitations,
                static fn (mixed $limitation): bool => ! is_string($limitation)
                    || trim($limitation) === '',
            )) {
            throw new InvalidArgumentException(
                'Drove environment resource limitations must be a list of non-empty strings.',
            );
        }

        $limitations = array_values(array_unique($limitations));
        sort($limitations, SORT_STRING);
        $this->limitations = $limitations;
        $this->capabilities = array_values(array_filter(
            ResourceCapability::cases(),
            static fn (ResourceCapability $capability): bool => isset($byValue[$capability->value]),
        ));
    }

    public function supports(ResourceCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * @return list<ResourceCapability>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * @return list<string>
     */
    public function limitations(): array
    {
        return $this->limitations;
    }

    /**
     * @return array{kind: string, provider: ?string, capabilities: list<string>, limitations: list<string>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'provider' => $this->provider,
            'capabilities' => array_map(
                static fn (ResourceCapability $capability): string => $capability->value,
                $this->capabilities,
            ),
            'limitations' => $this->limitations,
        ];
    }

    /**
     * @return array{kind: string, provider: ?string, capabilities: list<string>, limitations: list<string>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
