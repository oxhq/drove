<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;

final readonly class CaseDefinition
{
    /** @var list<mixed> */
    public array $arguments;

    /** @param list<mixed> $arguments */
    public function __construct(
        public Closure $body,
        array $arguments,
        public string $disposition,
        public string $reason,
    ) {
        $this->arguments = $arguments;
    }
}
