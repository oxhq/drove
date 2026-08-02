<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Closure;
use Drove\Laravel\Contracts\DatabaseStateProvider;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use LogicException;

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

function laravelContext(): LaravelTestContext
{
    $application = Container::getInstance();

    if (! $application instanceof Application
        || ! $application->bound(ApplicationRuntime::class)) {
        throw new LogicException(
            'Drove Laravel test helpers require an active native Laravel environment.',
        );
    }

    $runtime = $application->make(ApplicationRuntime::class);

    return $runtime->testContext();
}

/** @param array<string, string> $headers */
function get(string $uri, array $headers = []): LaravelResponse
{
    return laravelContext()->get($uri, $headers);
}

/** @param array<string, string> $headers */
function getJson(
    string $uri,
    array $headers = [],
    int $options = 0,
): LaravelResponse {
    return laravelContext()->getJson($uri, $headers, $options);
}

/**
 * @param  array<array-key, mixed>  $data
 * @param  array<string, string>  $headers
 */
function postJson(
    string $uri,
    array $data = [],
    array $headers = [],
    int $options = 0,
): LaravelResponse {
    return laravelContext()->postJson($uri, $data, $headers, $options);
}

/**
 * @param  array<array-key, mixed>  $data
 * @param  array<string, string>  $headers
 */
function putJson(
    string $uri,
    array $data = [],
    array $headers = [],
    int $options = 0,
): LaravelResponse {
    return laravelContext()->putJson($uri, $data, $headers, $options);
}

/**
 * @param  array<array-key, mixed>  $data
 * @param  array<string, string>  $headers
 */
function patchJson(
    string $uri,
    array $data = [],
    array $headers = [],
    int $options = 0,
): LaravelResponse {
    return laravelContext()->patchJson($uri, $data, $headers, $options);
}

/**
 * @param  array<array-key, mixed>  $data
 * @param  array<string, string>  $headers
 */
function deleteJson(
    string $uri,
    array $data = [],
    array $headers = [],
    int $options = 0,
): LaravelResponse {
    return laravelContext()->deleteJson($uri, $data, $headers, $options);
}
