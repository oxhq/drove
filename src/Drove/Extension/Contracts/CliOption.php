<?php

declare(strict_types=1);

namespace Drove\Extension\Contracts;

use Drove\Extension\CliInput;
use Drove\Extension\CliOptionDefinition;
use Drove\Extension\CliResult;

interface CliOption
{
    public function definition(): CliOptionDefinition;

    public function execute(CliInput $input): CliResult;
}
