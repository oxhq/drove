<?php

declare(strict_types=1);

namespace Drove\Kernel;

use Closure;
use OutOfBoundsException;

/**
 * @internal
 */
final class ScopeContext
{
    /** @var list<Closure> */
    private array $deferred = [];

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        private readonly mixed $application = null,
        private array $values = [],
        private readonly array $metadata = [],
        private readonly array $state = [],
    ) {
        //
    }

    public function app(): mixed
    {
        return $this->application;
    }

    public function share(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? throw new OutOfBoundsException(sprintf(
            'No value named %s was shared with this Drove scope.',
            $key,
        ));
    }

    public function defer(Closure $cleanup): void
    {
        $this->deferred[] = $cleanup;
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $state
     */
    public function child(array $metadata = [], array $state = []): self
    {
        return new self(
            $this->application,
            $this->values,
            $metadata + $this->metadata,
            $state + $this->state,
        );
    }

    /**
     * @return list<Closure>
     */
    public function drainDeferred(): array
    {
        $deferred = array_reverse($this->deferred);
        $this->deferred = [];

        return $deferred;
    }
}
