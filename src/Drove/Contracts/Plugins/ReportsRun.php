<?php

declare(strict_types=1);

namespace Drove\Contracts\Plugins;

interface ReportsRun
{
    /**
     * The result is observational in the alpha API. Mutating this value has no
     * effect on Drove's exit policy or renderer. Throwing fails the run.
     *
     * @param  array<string, mixed>  $run
     */
    public function reportDroveRun(array $run): void;
}
