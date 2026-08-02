<?php

declare(strict_types=1);

namespace Drove\Native\Attributes;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class DataProvider
{
    public function __construct(public string $method)
    {
        if (trim($method) === '') {
            throw new InvalidArgumentException('A native class data provider requires a method name.');
        }
    }
}
