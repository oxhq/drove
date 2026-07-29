<?php

declare(strict_types=1);

namespace Drove\Environment;

/** @experimental */
interface ResourceProvider
{
    public function resourcePlan(): ResourcePlan;
}
