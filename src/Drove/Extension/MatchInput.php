<?php

declare(strict_types=1);

namespace Drove\Extension;

use InvalidArgumentException;

final readonly class MatchInput
{
    /** @var list<mixed> */
    public array $arguments;

    /**
     * @var array{id: string, name: ?string, source: array{path: string, line: int}|null, dataset: array{key: int|string, label: string}|null, groups: list<string>}|array{}
     */
    public array $case;

    /**
     * @param  array<array-key, mixed>  $arguments
     * @param  array<string, mixed>  $case
     */
    public function __construct(
        public mixed $actual,
        array $arguments = [],
        array $case = [],
    ) {
        if (! array_is_list($arguments)) {
            throw new InvalidArgumentException('Drove matcher arguments must be a list.');
        }

        $this->arguments = $arguments;
        $this->case = $this->validatedCase($case);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{id: string, name: ?string, source: array{path: string, line: int}|null, dataset: array{key: int|string, label: string}|null, groups: list<string>}|array{}
     */
    private function validatedCase(array $case): array
    {
        if ($case === []) {
            return [];
        }

        $id = $case['id'] ?? null;
        $name = $case['name'] ?? null;
        $source = $case['source'] ?? null;
        $dataset = $case['dataset'] ?? null;
        $groups = $case['groups'] ?? null;

        if (! is_string($id) || $id === '' || ($name !== null && ! is_string($name))) {
            throw new InvalidArgumentException('Drove matcher case metadata is invalid.');
        }

        if ($source !== null) {
            if (
                ! is_array($source)
                || ! is_string($source['path'] ?? null)
                || ! is_int($source['line'] ?? null)
            ) {
                throw new InvalidArgumentException('Drove matcher case metadata is invalid.');
            }

            $source = ['path' => $source['path'], 'line' => $source['line']];
        }

        if ($dataset !== null) {
            $key = is_array($dataset) ? ($dataset['key'] ?? null) : null;
            $label = is_array($dataset) ? ($dataset['label'] ?? null) : null;

            if ((! is_int($key) && ! is_string($key)) || ! is_string($label)) {
                throw new InvalidArgumentException('Drove matcher case metadata is invalid.');
            }

            $dataset = ['key' => $key, 'label' => $label];
        }

        if (! is_array($groups) || ! array_is_list($groups)) {
            throw new InvalidArgumentException('Drove matcher case metadata is invalid.');
        }

        foreach ($groups as $group) {
            if (! is_string($group)) {
                throw new InvalidArgumentException('Drove matcher case metadata is invalid.');
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'source' => $source,
            'dataset' => $dataset,
            'groups' => $groups,
        ];
    }
}
