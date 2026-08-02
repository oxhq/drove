<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;

final class HookDefinition extends TestModifierTarget
{
    public function __construct(
        private readonly DeclarationRegistry $registry,
        private readonly string $scopeId,
        private readonly string $hookId,
    ) {
        //
    }

    public function expect(mixed $value = null): HookExpectation
    {
        return new HookExpectation($this->registry, $this->hookId, $value);
    }

    /** @param Closure(TestDefinition): void $modifier */
    protected function apply(string $name, Closure $modifier): static
    {
        $this->registry->addFutureModifier($this->scopeId, $name, $modifier);

        return $this;
    }
}
