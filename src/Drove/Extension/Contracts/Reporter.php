<?php

declare(strict_types=1);

namespace Drove\Extension\Contracts;

use Drove\Extension\RunSummary;

interface Reporter
{
    public function report(RunSummary $run): string;
}
