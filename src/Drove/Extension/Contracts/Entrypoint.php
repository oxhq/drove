<?php

declare(strict_types=1);

namespace Drove\Extension\Contracts;

use Drove\Extension\Registry;

interface Entrypoint
{
    public function register(Registry $registry): void;
}
