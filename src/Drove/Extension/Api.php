<?php

declare(strict_types=1);

namespace Drove\Extension;

final class Api
{
    public const int MIN = 1;

    public const int MAX = 1;

    public static function negotiate(int $minimum, int $maximum): ?int
    {
        $minimum = max($minimum, self::MIN);
        $maximum = min($maximum, self::MAX);

        return $minimum <= $maximum ? $maximum : null;
    }
}
