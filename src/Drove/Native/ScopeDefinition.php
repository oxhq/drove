<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;

final class ScopeDefinition extends TestModifierTarget
{
    public function __construct(
        private readonly DeclarationRegistry $registry,
        private readonly string $scopeId,
    ) {
        //
    }

    /** @param Closure(TestDefinition): void $modifier */
    protected function apply(string $name, Closure $modifier): static
    {
        $this->registry->applyScopeModifier($this->scopeId, $name, $modifier);

        return $this;
    }
}
