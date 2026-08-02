<?php

declare(strict_types=1);

namespace Drove\Native\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Test
{
    // Marker attribute.
}
