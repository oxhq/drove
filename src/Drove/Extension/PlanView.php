<?php

declare(strict_types=1);

namespace Drove\Extension;

use InvalidArgumentException;

final readonly class PlanView
{
    public function __construct(
        public string $suiteName,
        public int $testCount,
        public int $scopeCount,
    ) {
        if (trim($suiteName) === '') {
            throw new InvalidArgumentException('A Drove extension plan view requires a suite name.');
        }

        if ($testCount < 0 || $scopeCount < 0) {
            throw new InvalidArgumentException('Drove extension plan counts cannot be negative.');
        }
    }
}
