<?php

declare(strict_types=1);

namespace Drove\Native;

final readonly class HookExpectation
{
    public function __construct(
        private DeclarationRegistry $registry,
        private string $hookId,
        private mixed $value,
    ) {
        //
    }

    public function toBe(mixed $expected, string $message = ''): self
    {
        $value = $this->value;
        $this->registry->addHookAction(
            $this->hookId,
            static fn (): Expectation => new Expectation($value)->toBe($expected, $message),
        );

        return $this;
    }

    public function toBeTrue(string $message = ''): self
    {
        $value = $this->value;
        $this->registry->addHookAction(
            $this->hookId,
            static fn (): Expectation => new Expectation($value)->toBeTrue($message),
        );

        return $this;
    }
}
