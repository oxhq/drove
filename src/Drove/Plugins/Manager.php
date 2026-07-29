<?php

declare(strict_types=1);

namespace Drove\Plugins;

use Drove\Contracts\Plugins\Bootable;
use Drove\Contracts\Plugins\InspectsPlan;
use Drove\Contracts\Plugins\ReportsRun;
use Pest\Plugin\Loader;
use RuntimeException;

/**
 * Uses the already-installed Pest plugin cache while Drove's package
 * extraction is in progress. Plugin classes opt into Drove hooks by
 * implementing the interfaces above.
 *
 * @internal
 */
final class Manager
{
    /**
     * @param  list<string>  $arguments
     */
    public function boot(array $arguments, string $rootPath): void
    {
        foreach (Loader::getPlugins(Bootable::class) as $plugin) {
            if (! $plugin instanceof Bootable) {
                throw new RuntimeException('Drove loaded an invalid boot observer.');
            }

            $plugin->bootDrove($arguments, $rootPath);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function inspectPlan(array $plan): void
    {
        foreach (Loader::getPlugins(InspectsPlan::class) as $plugin) {
            if (! $plugin instanceof InspectsPlan) {
                throw new RuntimeException('Drove loaded an invalid plan observer.');
            }

            $plugin->inspectDrovePlan($plan);
        }
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function reportRun(array $run): void
    {
        foreach (Loader::getPlugins(ReportsRun::class) as $plugin) {
            if (! $plugin instanceof ReportsRun) {
                throw new RuntimeException('Drove loaded an invalid run observer.');
            }

            $plugin->reportDroveRun($run);
        }
    }
}
