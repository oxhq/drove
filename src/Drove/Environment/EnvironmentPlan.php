<?php

declare(strict_types=1);

namespace Drove\Environment;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/**
 * @experimental
 */
final readonly class EnvironmentPlan implements JsonSerializable
{
    /** @var list<ResourcePlan> */
    private array $resources;

    /** @param array<array-key, mixed> $resources */
    public function __construct(
        array $resources,
        public CoordinationGuarantee $coordination,
    ) {
        if (! array_is_list($resources)) {
            throw new InvalidArgumentException(
                'Drove environment plans require a list of ResourcePlan values.',
            );
        }

        $byKind = [];

        foreach ($resources as $resource) {
            if (! $resource instanceof ResourcePlan) {
                throw new InvalidArgumentException(
                    'Drove environment plans require a list of ResourcePlan values.',
                );
            }

            if (isset($byKind[$resource->kind->value])) {
                throw new InvalidArgumentException(sprintf(
                    'Drove environment plans contain duplicate %s resources.',
                    $resource->kind->value,
                ));
            }

            $byKind[$resource->kind->value] = $resource;
        }

        $missing = [];
        $ordered = [];

        foreach (ResourceKind::cases() as $kind) {
            if (! isset($byKind[$kind->value])) {
                $missing[] = $kind;

                continue;
            }

            $ordered[] = $byKind[$kind->value];
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Drove environment plans must declare every resource kind; missing [%s].',
                implode(', ', array_map(
                    static fn (ResourceKind $kind): string => $kind->value,
                    $missing,
                )),
            ));
        }

        $this->resources = $ordered;
    }

    /**
     * @return list<ResourcePlan>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    public function resource(ResourceKind $kind): ResourcePlan
    {
        foreach ($this->resources as $resource) {
            if ($resource->kind === $kind) {
                return $resource;
            }
        }

        throw new LogicException('Drove encountered an unknown environment resource kind.');
    }

    /**
     * @param  array<string, mixed>  $suitePlan
     */
    public function assertSuiteSupported(array $suitePlan): void
    {
        $root = $suitePlan['root'] ?? $suitePlan;

        if (! is_array($root) || ($root['type'] ?? null) !== 'suite') {
            throw new InvalidArgumentException(
                'Drove environment plans require a suite-root Scope IR plan.',
            );
        }

        $this->assertScopeSupported($root);
    }

    /** @param array<array-key, mixed> $tasks */
    public function assertDispatchSupported(array $tasks): void
    {
        if (! array_is_list($tasks)) {
            throw new InvalidArgumentException(
                'Drove environment dispatch tasks must be a list.',
            );
        }

        $scopeTasks = 0;
        $ids = [];

        foreach ($tasks as $task) {
            if (! is_array($task)
                || ! is_string($task['id'] ?? null)
                || $task['id'] === ''
                || ! in_array($task['kind'] ?? null, ['scope', 'test'], true)) {
                throw new InvalidArgumentException(
                    'Drove environment plans received an invalid descendant task.',
                );
            }

            if (isset($ids[$task['id']])) {
                throw new InvalidArgumentException(sprintf(
                    'Drove environment plans received duplicate task %s.',
                    $task['id'],
                ));
            }

            $ids[$task['id']] = true;
            $scopeTasks += $task['kind'] === 'scope' ? 1 : 0;
        }

        if ($tasks === []) {
            return;
        }

        foreach ($this->resources as $resource) {
            if ($resource->provider === null) {
                continue;
            }

            if ($resource->supports(ResourceCapability::SharedReadOnly)) {
                continue;
            }

            if ($resource->supports(ResourceCapability::ScopeIsolated)) {
                continue;
            }

            if ($scopeTasks === 1 && count($tasks) === 1) {
                continue;
            }

            if ($scopeTasks === 0
                && $resource->supports(ResourceCapability::LeafIsolated)) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Environment resource %s (%s) cannot isolate sibling or mixed scope dispatches.',
                $resource->kind->value,
                $resource->provider,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function assertScopeSupported(array $node): void
    {
        $tasks = [];
        $children = $node['children'] ?? [];
        $tests = $node['tests'] ?? [];

        if (! is_array($children) || ! array_is_list($children)
            || ! is_array($tests) || ! array_is_list($tests)) {
            throw new InvalidArgumentException(
                'Drove environment plans received invalid Scope IR descendants.',
            );
        }

        foreach ($tests as $test) {
            if (! is_array($test) || ! is_string($test['id'] ?? null)) {
                throw new InvalidArgumentException(
                    'Drove environment plans received an invalid Scope IR test.',
                );
            }

            $tasks[] = ['id' => $test['id'], 'kind' => 'test'];
        }

        foreach ($children as $child) {
            if (! is_array($child) || ! is_string($child['id'] ?? null)) {
                throw new InvalidArgumentException(
                    'Drove environment plans received an invalid Scope IR scope.',
                );
            }

            $tasks[] = ['id' => $child['id'], 'kind' => 'scope'];
        }

        $this->assertDispatchSupported($tasks);

        foreach ($children as $child) {
            $this->assertScopeSupported($child);
        }
    }

    /**
     * @return array{schema: 1, coordination: string, resources: array<string, array{kind: string, provider: ?string, capabilities: list<string>, limitations: list<string>}>}
     */
    public function toArray(): array
    {
        $resources = [];

        foreach ($this->resources as $resource) {
            $resources[$resource->kind->value] = $resource->toArray();
        }

        return [
            'schema' => 1,
            'coordination' => $this->coordination->value,
            'resources' => $resources,
        ];
    }

    /**
     * @return array{schema: 1, coordination: string, resources: array<string, array{kind: string, provider: ?string, capabilities: list<string>, limitations: list<string>}>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
