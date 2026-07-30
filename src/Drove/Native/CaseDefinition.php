<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;

final readonly class CaseDefinition
{
    /** @param list<mixed> $arguments */
    public function __construct(public Closure $body, public array $arguments, public string $disposition, public string $reason) {}
}
