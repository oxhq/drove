<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Drove\Kernel\AssertionFailed;
use Drove\Native\TestContext;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\Concerns\InteractsWithContainer;
use Illuminate\Foundation\Testing\Concerns\InteractsWithExceptionHandling;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Http\Request;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use Symfony\Component\HttpFoundation\Response;

final class LaravelTestContext
{
    use InteractsWithContainer {
        withoutVite as public;
        withVite as public;
    }
    use InteractsWithExceptionHandling {
        withExceptionHandling as public;
        withoutExceptionHandling as public;
    }
    use MakesHttpRequests {
        get as private frameworkGet;
        getJson as private frameworkGetJson;
        postJson as private frameworkPostJson;
        putJson as private frameworkPutJson;
        patchJson as private frameworkPatchJson;
        deleteJson as private frameworkDeleteJson;
    }

    protected Application $app;

    public function __construct(Application $application)
    {
        $this->app = $application;
    }

    public function application(): Application
    {
        return $this->app;
    }

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): LaravelResponse
    {
        return $this->response($this->frameworkGet($uri, $headers));
    }

    /** @param array<string, string> $headers */
    public function getJson(
        string $uri,
        array $headers = [],
        int $options = 0,
    ): LaravelResponse {
        return $this->response($this->frameworkGetJson($uri, $headers, $options));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function postJson(
        string $uri,
        array $data = [],
        array $headers = [],
        int $options = 0,
    ): LaravelResponse {
        return $this->response($this->frameworkPostJson($uri, $data, $headers, $options));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function putJson(
        string $uri,
        array $data = [],
        array $headers = [],
        int $options = 0,
    ): LaravelResponse {
        return $this->response($this->frameworkPutJson($uri, $data, $headers, $options));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function patchJson(
        string $uri,
        array $data = [],
        array $headers = [],
        int $options = 0,
    ): LaravelResponse {
        return $this->response($this->frameworkPatchJson($uri, $data, $headers, $options));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function deleteJson(
        string $uri,
        array $data = [],
        array $headers = [],
        int $options = 0,
    ): LaravelResponse {
        return $this->response($this->frameworkDeleteJson($uri, $data, $headers, $options));
    }

    /**
     * @param  Model|class-string<Model>|string  $table
     * @param  array<string, mixed>  $data
     */
    public function assertDatabaseHas(
        Model|string $table,
        array $data = [],
        ?string $connection = null,
    ): self {
        TestContext::recordAssertion();
        [$tableName, $connectionName, $data] = $this->databaseTarget($table, $data);
        $connection ??= $connectionName;

        if (! $this->app->make(DatabaseManager::class)->connection($connection)->table($tableName)->where($data)->exists()) {
            throw new AssertionFailed(sprintf(
                'Database table [%s] does not contain a row matching %s.',
                $tableName,
                self::export($data),
            ));
        }

        return $this;
    }

    /**
     * @param  Model|class-string<Model>|string  $table
     * @param  array<string, mixed>  $data
     */
    public function assertDatabaseMissing(
        Model|string $table,
        array $data = [],
        ?string $connection = null,
    ): self {
        TestContext::recordAssertion();
        [$tableName, $connectionName, $data] = $this->databaseTarget($table, $data);
        $connection ??= $connectionName;

        if ($this->app->make(DatabaseManager::class)->connection($connection)->table($tableName)->where($data)->exists()) {
            throw new AssertionFailed(sprintf(
                'Database table [%s] contains an unexpected row matching %s.',
                $tableName,
                self::export($data),
            ));
        }

        return $this;
    }

    /** @param Model|class-string<Model>|string $table */
    public function assertDatabaseCount(
        Model|string $table,
        int $expected,
        ?string $connection = null,
    ): self {
        TestContext::recordAssertion();
        [$tableName, $connectionName] = $this->databaseTarget($table, []);
        $connection ??= $connectionName;
        $actual = $this->app->make(DatabaseManager::class)->connection($connection)->table($tableName)->count();

        if ($actual !== $expected) {
            throw new AssertionFailed(sprintf(
                'Database table [%s] expected %d rows, but contains %d.',
                $tableName,
                $expected,
                $actual,
            ));
        }

        return $this;
    }

    public function assertAuthenticatedAs(
        Authenticatable $user,
        ?string $guard = null,
    ): self {
        TestContext::recordAssertion();
        $authenticated = $this->app->make(AuthManager::class)->guard($guard)->user();

        if (! $authenticated instanceof Authenticatable) {
            throw new AssertionFailed('The current user is not authenticated.');
        }

        TestContext::recordAssertion();

        if (! $user instanceof $authenticated) {
            throw new AssertionFailed('The currently authenticated user is not who was expected.');
        }

        TestContext::recordAssertion();

        if ($authenticated->getAuthIdentifier() !== $user->getAuthIdentifier()) {
            throw new AssertionFailed('The currently authenticated user is not who was expected.');
        }

        return $this;
    }

    /**
     * @param  class-string  $controller
     * @param  class-string<FormRequest>  $formRequest
     */
    public function assertActionUsesFormRequest(
        string $controller,
        string $method,
        string $formRequest,
    ): self {
        TestContext::recordAssertion();

        if (! is_subclass_of($formRequest, FormRequest::class)) {
            throw new AssertionFailed($formRequest.' is not a type of Form Request.');
        }

        try {
            $action = (new ReflectionClass($controller))->getMethod($method);
        } catch (ReflectionException) {
            TestContext::fail('Controller action could not be found: '.$controller.'@'.$method);
        }

        TestContext::recordAssertion();

        if (! $action->isPublic()) {
            throw new AssertionFailed(sprintf(
                'Action "%s" is not public; controller actions must be public.',
                $method,
            ));
        }

        $usesFormRequest = array_any(
            $action->getParameters(),
            static function (ReflectionParameter $parameter) use ($formRequest): bool {
                $type = $parameter->getType();

                return $type instanceof ReflectionNamedType
                    && $type->getName() === $formRequest;
            },
        );

        TestContext::recordAssertion();

        if (! $usesFormRequest) {
            throw new AssertionFailed(sprintf(
                'Action "%s" does not use the "%s" Form Request.',
                $method,
                $formRequest,
            ));
        }

        return $this;
    }

    protected function createTestResponse(
        mixed $response,
        mixed $request,
    ): LaravelResponse {
        if ($response instanceof LaravelResponse) {
            return $response;
        }

        if (! $response instanceof Response || ! $request instanceof Request) {
            throw new InvalidArgumentException(
                'Native Laravel HTTP execution returned an invalid response or request.',
            );
        }

        return new LaravelResponse($response, $request);
    }

    protected function followRedirects(mixed $response): LaravelResponse|Response
    {
        if (! $response instanceof LaravelResponse && ! $response instanceof Response) {
            throw new InvalidArgumentException(
                'Native Laravel redirect execution requires an HTTP response.',
            );
        }

        $this->followRedirects = false;

        while ($response instanceof LaravelResponse
            ? $response->isRedirect()
            : $response->isRedirection()) {
            $location = $response instanceof LaravelResponse
                ? $response->header('Location')
                : $response->headers->get('Location');

            if (! is_string($location)) {
                throw new InvalidArgumentException(
                    'Native Laravel received a redirect without a Location header.',
                );
            }

            $response = $this->get($location);
        }

        return $response;
    }

    private function response(mixed $response): LaravelResponse
    {
        if (! $response instanceof LaravelResponse) {
            throw new InvalidArgumentException(
                'Native Laravel HTTP execution did not return a LaravelResponse.',
            );
        }

        return $response;
    }

    /**
     * @param  Model|class-string<Model>|string  $table
     * @param  array<string, mixed>  $data
     * @return array{string, ?string, array<string, mixed>}
     */
    private function databaseTarget(Model|string $table, array $data): array
    {
        $model = $table instanceof Model
            ? $table
            : (is_subclass_of($table, Model::class) ? new $table : null);

        if ($model instanceof Model) {
            if ($table instanceof Model) {
                $data = [$model->getKeyName() => $model->getKey(), ...$data];
            }

            return [$model->getTable(), $model->getConnectionName(), $data];
        }

        if ($table === '') {
            throw new InvalidArgumentException('A native Laravel database assertion requires a table.');
        }

        return [$table, null, $data];
    }

    /** @param array<string, mixed> $value */
    private static function export(array $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return is_string($encoded) ? $encoded : 'an unencodable row';
    }
}
