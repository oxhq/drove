<?php

declare(strict_types=1);

namespace Drove\Contracts\Plugins;

interface InspectsPlan
{
    /**
     * The plan is observational in the alpha API. Mutating this value has no
     * effect on execution.
     *
     * @param  array<string, mixed>  $plan
     */
    public function inspectDrovePlan(array $plan): void;
}
