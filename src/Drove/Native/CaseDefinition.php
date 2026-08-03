<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;

final readonly class CaseDefinition
{
    /**
     * @param  list<mixed>  $arguments
     * @param  array{class: string|null, message: string|null, code: int|null}|null  $expectedException
     */
    public function __construct(
        public Closure $body,
        public array $arguments,
        public string $disposition,
        public string $reason,
        public ?array $expectedException = null,
    ) {}
}
