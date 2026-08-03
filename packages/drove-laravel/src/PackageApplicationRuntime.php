<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Closure;
use Drove\Laravel\Contracts\DatabaseStateProvider;

final class PackageApplicationRuntime
{
    /**
     * @param  array{driver?: mixed, connection?: mixed, prepared_schema?: mixed, workspace?: mixed}|DatabaseStateProvider  $state
     * @param  list<class-string>  $providers
     */
    public static function boot(
        string $applicationPath,
        DatabaseStateProvider|array $state,
        array $providers,
        ?Closure $prepare = null,
    ): ApplicationRuntime {
        return ApplicationRuntime::bootNative(
            $applicationPath,
            $state,
            $providers,
            $prepare,
        );
    }
}
