<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Closure;
use Drove\Laravel\Contracts\DatabaseStateProvider;

use function Drove\Native\environment;

/**
 * @param  array{driver?: mixed, connection?: mixed, prepared_schema?: mixed, workspace?: mixed}|DatabaseStateProvider  $state
 * @param  list<class-string>  $providers
 */
function laravel(
    string $basePath,
    DatabaseStateProvider|array $state,
    array $providers = [],
    ?Closure $prepare = null,
): void {
    environment(
        'laravel',
        static fn (): ApplicationRuntime => ApplicationRuntime::bootNative(
            $basePath,
            $state,
            $providers,
            $prepare,
        ),
    );
}
