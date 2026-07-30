<?php

declare(strict_types=1);

namespace Drove\Native;

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
        return new LifecycleExecutor(
            $this->scheduler,
            fn (string $id): \Closure => $declarations->resolveHook($id),
            fn (string $id): array => [
                'closure' => $declarations->resolveTest($id),
                'runtime' => new TestContext,
            ],
        )->run($declarations->plan());
    }
}
