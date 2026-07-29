<?php

declare(strict_types=1);

namespace Drove\Kernel;

use Closure;

/**
 * @internal
 */
interface Scheduler
{
    public function runId(): string;

    /**
     * @param  list<array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms?: int, permit?: bool}>  $tasks
     * @return array{results: list<array<string, mixed>>, completion_order: list<string>}
     */
    public function map(array $tasks, Closure $execute): array;

    /**
     * @param  list<string>  $scopes
     */
    public function withPermit(array $scopes, Closure $work): mixed;
}
