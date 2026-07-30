<?php

declare(strict_types=1);

namespace Drove\Native;

use Drove\Extension\RunSummary;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\Scheduler;

final readonly class Runner
{
    public function __construct(
        private Scheduler $scheduler,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function run(DeclarationRegistry $declarations): array
    {
        $extensions = $declarations->extensions();
        $run = new LifecycleExecutor(
            $this->scheduler,
            fn (string $id): \Closure => $declarations->resolveHook($id),
            fn (string $id): array => [
                'closure' => $declarations->resolveTest($id),
                'runtime' => new TestContext($extensions),
            ],
        )->run($declarations->plan());

        if (! $extensions->isEmpty()) {
            $counts = [];

            foreach ($run['tests'] as $test) {
                $status = is_string($test['status'] ?? null) ? $test['status'] : 'failed';
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }

            $run['extension_reports'] = $extensions->reports(new RunSummary(
                $run['status'],
                $run['exit_code'],
                $counts,
            ));
        }

        return $run;
    }
}
