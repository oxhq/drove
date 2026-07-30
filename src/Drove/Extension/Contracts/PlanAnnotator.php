<?php

declare(strict_types=1);

namespace Drove\Extension\Contracts;

use Drove\Extension\PlanView;

interface PlanAnnotator
{
    public function value(PlanView $plan): string|int|float|bool|null;
}
