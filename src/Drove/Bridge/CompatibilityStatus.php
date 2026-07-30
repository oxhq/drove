<?php

declare(strict_types=1);

namespace Drove\Bridge;

enum CompatibilityStatus: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case BridgeOnly = 'bridge-only';
}
