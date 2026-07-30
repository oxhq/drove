<?php

declare(strict_types=1);

namespace Drove\Native;

use InvalidArgumentException;

final readonly class Selection
{
    /** @var list<string> */
    public array $includeGroups;

    /** @var list<string> */
    public array $excludeGroups;

    /**
     * @param  array<array-key, mixed>  $includeGroups
     * @param  array<array-key, mixed>  $excludeGroups
     */
    public function __construct(
        public ?string $nameContains = null,
        array $includeGroups = [],
        array $excludeGroups = [],
    ) {
        if ($nameContains !== null && $nameContains === '') {
            throw new InvalidArgumentException('A native name filter cannot be empty.');
        }

        $this->includeGroups = $this->groups($includeGroups);
        $this->excludeGroups = $this->groups($excludeGroups);
    }

    /** @param list<string> $groups */
    public function includes(string $name, array $groups): bool
    {
        if ($this->nameContains !== null && ! str_contains($name, $this->nameContains)) {
            return false;
        }

        if (array_intersect($groups, $this->excludeGroups) !== []) {
            return false;
        }

        return $this->includeGroups === []
            || array_intersect($groups, $this->includeGroups) !== [];
    }

    /**
     * @param  array<array-key, mixed>  $groups
     * @return list<string>
     */
    private function groups(array $groups): array
    {
        if (! array_is_list($groups)
            || array_any(
                $groups,
                static fn (mixed $group): bool => ! is_string($group) || trim($group) === '',
            )) {
            throw new InvalidArgumentException(
                'Native group filters must be lists of non-empty strings.',
            );
        }

        return array_values(array_unique($groups));
    }
}
