<?php

declare(strict_types=1);

namespace Drove\Native;

use InvalidArgumentException;

final class InvalidExpectationValue extends InvalidArgumentException
{
    public static function expected(string $type): never
    {
        throw new self(sprintf('Invalid expectation value type. Expected [%s].', $type));
    }
}
