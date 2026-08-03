<?php

declare(strict_types=1);

namespace Drove\Migration;

use JsonSerializable;

final readonly class LaravelMigrationResult implements JsonSerializable
{
    /**
     * @param  array<string, int>  $applied
     * @param  list<Finding>  $portableBlockers
     * @param  list<array{kind: string, construct: string, path: string, line: int, column: int, diagnostic: string}>  $blockers
     */
    public function __construct(
        public MigrationResult $portable,
        public string $source,
        public string $resultHash,
        public array $applied,
        public array $portableBlockers,
        public array $blockers,
    ) {}

    public function changed(): bool
    {
        return $this->portable->originalHash !== $this->resultHash;
    }

    public function nativeReady(): bool
    {
        return $this->portableBlockers === [] && $this->blockers === [];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'changed' => $this->changed(),
            'native_ready' => $this->nativeReady(),
            'original_hash' => $this->portable->originalHash,
            'portable_hash' => $this->portable->resultHash,
            'result_hash' => $this->resultHash,
            'applied' => $this->applied,
            'portable_blockers' => array_map(
                static fn (Finding $finding): array => $finding->jsonSerialize(),
                $this->portableBlockers,
            ),
            'laravel_blockers' => $this->blockers,
        ];
    }
}
