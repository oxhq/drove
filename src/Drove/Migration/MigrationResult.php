<?php

declare(strict_types=1);

namespace Drove\Migration;

use JsonSerializable;

final readonly class MigrationResult implements JsonSerializable
{
    /**
     * @param  array<string, int>  $applied
     * @param  list<Finding>  $blockers
     */
    public function __construct(
        public string $source,
        public string $originalHash,
        public string $resultHash,
        public array $applied,
        public array $blockers,
    ) {
        //
    }

    public function changed(): bool
    {
        return $this->originalHash !== $this->resultHash;
    }

    /**
     * @return array{
     *     schema: 1,
     *     changed: bool,
     *     original_hash: string,
     *     result_hash: string,
     *     applied: array<string, int>,
     *     blockers: list<array<string, mixed>>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'changed' => $this->changed(),
            'original_hash' => $this->originalHash,
            'result_hash' => $this->resultHash,
            'applied' => $this->applied,
            'blockers' => array_map(
                static fn (Finding $finding): array => $finding->jsonSerialize(),
                $this->blockers,
            ),
        ];
    }
}
