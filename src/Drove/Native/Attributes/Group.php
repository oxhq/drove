<?php

declare(strict_types=1);

namespace Drove\Native\Attributes;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Group
{
    public function __construct(public string $name)
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A native class group requires a name.');
        }
    }
}
