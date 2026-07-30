<?php

declare(strict_types=1);

namespace Drove\Native;

use Drove\Kernel\AssertionFailed;

final readonly class Expectation
{
    public function __construct(
        private mixed $value,
    ) {
        //
    }

    public function toBe(mixed $expected): self
    {
        if ($this->value !== $expected) {
            throw new AssertionFailed(sprintf(
                'Failed asserting that %s is identical to %s.',
                $this->export($this->value),
                $this->export($expected),
            ));
        }

        return $this;
    }

    public function toEqual(mixed $expected): self
    {
        if ($this->value != $expected) {
            throw new AssertionFailed(sprintf(
                'Failed asserting that %s equals %s.',
                $this->export($this->value),
                $this->export($expected),
            ));
        }

        return $this;
    }

    private function export(mixed $value): string
    {
        if (is_object($value)) {
            return $value::class;
        }

        if (is_resource($value)) {
            return get_debug_type($value);
        }

        return var_export($value, true);
    }
}
