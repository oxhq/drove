<?php

declare(strict_types=1);

namespace Drove\Extension;

enum CliOptionType: string
{
    case Boolean = 'boolean';
    case String = 'string';
    case Integer = 'integer';
}
