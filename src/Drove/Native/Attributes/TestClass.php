<?php

declare(strict_types=1);

namespace Drove\Native\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class TestClass
{
    // Marker attribute.
}
