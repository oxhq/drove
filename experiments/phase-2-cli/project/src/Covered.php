<?php

declare(strict_types=1);

namespace DroveBetaProof\Subject;

final class Covered
{
    public static function classify(int $value): string
    {
        if ($value < 0) {
            return 'negative';
        }

        if ($value === 0) {
            return 'zero';
        }

        return 'positive';
    }
}
