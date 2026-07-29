<?php

declare(strict_types=1);

namespace DroveBetaProof;

use Drove\Contracts\Plugins\Bootable;
use Drove\Contracts\Plugins\InspectsPlan;
use Drove\Contracts\Plugins\ReportsRun;

final class Observer implements Bootable, InspectsPlan, ReportsRun
{
    public function bootDrove(array $arguments, string $rootPath): void
    {
        $this->record('boot:'.basename($rootPath).':'.count($arguments));
    }

    public function inspectDrovePlan(array $plan): void
    {
        if (getenv('DROVE_PLUGIN_FAIL') === 'plan') {
            throw new \RuntimeException('Drove beta observer failure.');
        }

        $this->record('plan:'.($plan['schema'] ?? 'missing'));

        if (is_array($plan['environment'] ?? null)) {
            $this->record('environment:'.($plan['environment']['schema'] ?? 'missing'));
        }
    }

    public function reportDroveRun(array $run): void
    {
        $this->record('run:'.($run['exit_code'] ?? 'missing'));
    }

    private function record(string $event): void
    {
        $path = getenv('DROVE_PLUGIN_PROOF');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, $event.PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
