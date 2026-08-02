<?php

declare(strict_types=1);

namespace Drove\Compatibility;

enum Status: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case BridgeOnly = 'bridge-only';
}
