<?php

declare(strict_types=1);

namespace Drove\Bridge;

use Closure;
use InvalidArgumentException;
use OutOfBoundsException;

final readonly class LoweredSuite
{
    /** @var array<string, mixed> */
    public array $scopeIr;

    /** @var array<string, array{closure: Closure, runtime: object}> */
    private array $testResolvers;

    /**
     * @param  array<string, mixed>  $scopeIr
     * @param  array<array-key, mixed>  $testResolvers
     * @param  Closure(string): Closure  $hookResolver
     */
    public function __construct(
        array $scopeIr,
        array $testResolvers,
        private Closure $hookResolver,
    ) {
        if (($scopeIr['schema'] ?? null) !== 1
            || ! is_array($scopeIr['root'] ?? null)
            || ($scopeIr['root']['type'] ?? null) !== 'suite') {
            throw new InvalidArgumentException(
                'A compatibility bridge must lower into Scope IR schema 1.',
            );
        }

        $testResolvers = $this->normalizeResolvers($testResolvers);
        $ids = [];
        $this->collectTestIds($scopeIr['root'], $ids);
        $resolverIds = array_keys($testResolvers);
        sort($ids, SORT_STRING);
        sort($resolverIds, SORT_STRING);

        if ($ids !== $resolverIds) {
            throw new InvalidArgumentException(
                'A compatibility bridge must resolve every lowered test exactly once.',
            );
        }

        $this->scopeIr = $scopeIr;
        $this->testResolvers = $testResolvers;
    }

    /**
     * @param  array<array-key, mixed>  $resolvers
     * @return array<string, array{closure: Closure, runtime: object}>
     */
    private function normalizeResolvers(array $resolvers): array
    {
        $validated = [];

        foreach ($resolvers as $id => $resolver) {
            $closure = is_array($resolver) ? ($resolver['closure'] ?? null) : null;
            $runtime = is_array($resolver) ? ($resolver['runtime'] ?? null) : null;

            if (! is_string($id)
                || ! $closure instanceof Closure
                || ! is_object($runtime)) {
                throw new InvalidArgumentException(
                    'A compatibility bridge emitted an invalid test resolver.',
                );
            }

            $validated[$id] = ['closure' => $closure, 'runtime' => $runtime];
        }

        return $validated;
    }

    /**
     * @return array{closure: Closure, runtime: object}
     */
    public function resolveTest(string $id): array
    {
        return $this->testResolvers[$id] ?? throw new OutOfBoundsException(sprintf(
            'No compatibility bridge test resolver exists for %s.',
            $id,
        ));
    }

    public function resolveHook(string $id): Closure
    {
        return ($this->hookResolver)($id);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $ids
     */
    private function collectTestIds(array $node, array &$ids): void
    {
        $tests = $node['tests'] ?? null;
        $children = $node['children'] ?? null;

        if (! is_array($tests) || ! is_array($children)) {
            throw new InvalidArgumentException(
                'A compatibility bridge emitted an invalid Scope IR node.',
            );
        }

        foreach ($tests as $test) {
            $id = is_array($test) ? ($test['id'] ?? null) : null;

            if (! is_string($id) || $id === '' || in_array($id, $ids, true)) {
                throw new InvalidArgumentException(
                    'A compatibility bridge emitted an invalid or duplicate test ID.',
                );
            }

            $ids[] = $id;
        }

        foreach ($children as $child) {
            if (! is_array($child)) {
                throw new InvalidArgumentException(
                    'A compatibility bridge emitted an invalid child scope.',
                );
            }

            $this->collectTestIds($child, $ids);
        }
    }
}
