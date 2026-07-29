<?php

declare(strict_types=1);

namespace Drove\Environment;

/** @experimental */
enum CoordinationGuarantee: string
{
    case Atomic = 'atomic';
    case BestEffort = 'best-effort';
}
