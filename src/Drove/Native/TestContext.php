<?php

declare(strict_types=1);

namespace Drove\Native;

use LogicException;

final class TestContext
{
    /** @var array<string, mixed> */
    private array $fixtures = [];

    public function &__get(string $name): mixed
    {
        if (! array_key_exists($name, $this->fixtures)) {
            throw new LogicException(sprintf(
                'Undefined test context fixture [%s].',
                $name,
            ));
        }

        return $this->fixtures[$name];
    }

    public function __set(string $name, mixed $value): void
    {
        $this->fixtures[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->fixtures[$name]);
    }
}
