<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Closure;
use Drove\Kernel\AssertionFailed;
use Drove\Native\TestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

final class LaravelResponse
{
    private mixed $decodedJson = null;

    private bool $jsonDecoded = false;

    public function __construct(
        public readonly Response $baseResponse,
        private readonly Request $request,
    ) {}

    public function __get(string $name): mixed
    {
        if ($name === 'original') {
            return $this->baseResponse->original ?? null;
        }

        throw new InvalidArgumentException(sprintf(
            'Native Laravel response property [%s] does not exist.',
            $name,
        ));
    }

    public function status(): int
    {
        return $this->baseResponse->getStatusCode();
    }

    public function content(): string
    {
        $content = $this->baseResponse->getContent();

        return is_string($content) ? $content : '';
    }

    public function header(string $name): ?string
    {
        $value = $this->baseResponse->headers->get($name);

        return is_string($value) ? $value : null;
    }

    public function isRedirect(): bool
    {
        return $this->baseResponse->isRedirection() && $this->header('Location') !== null;
    }

    public function getContent(): string
    {
        return $this->content();
    }

    public function isOk(): bool
    {
        return $this->status() === Response::HTTP_OK;
    }

    public function json(?string $key = null): mixed
    {
        $decoded = $this->decodedJson();

        return $key === null
            ? $decoded
            : Arr::get(is_array($decoded) ? $decoded : [], $key);
    }

    public function assertStatus(int $status): self
    {
        return $this->assertStatusCode($status);
    }

    public function assertOk(): self
    {
        return $this->assertStatusCode(Response::HTTP_OK);
    }

    public function assertCreated(): self
    {
        return $this->assertStatusCode(Response::HTTP_CREATED);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatusCode(Response::HTTP_FORBIDDEN);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatusCode(Response::HTTP_NOT_FOUND);
    }

    public function assertSuccessful(): self
    {
        TestContext::recordAssertion();

        if ($this->status() < 200 || $this->status() >= 300) {
            throw new AssertionFailed(sprintf(
                'Expected a successful response status [>=200, <300], but received [%d].',
                $this->status(),
            ));
        }

        return $this;
    }

    public function assertNoContent(int $status = Response::HTTP_NO_CONTENT): self
    {
        TestContext::recordAssertion();

        if ($this->status() !== $status || $this->content() !== '') {
            throw new AssertionFailed(sprintf(
                'Expected an empty response with status [%d], but received status [%d] and %d content bytes.',
                $status,
                $this->status(),
                strlen($this->content()),
            ));
        }

        return $this;
    }

    /** @param string|list<string> $value */
    public function assertSee(string|array $value, bool $escape = true): self
    {
        TestContext::recordAssertion();

        foreach (Arr::wrap($value) as $needle) {
            $needle = $escape ? e($needle) : $needle;

            if (! str_contains($this->content(), $needle)) {
                throw new AssertionFailed(sprintf(
                    'Response to %s %s does not contain [%s].',
                    $this->request->getMethod(),
                    $this->request->getRequestUri(),
                    $needle,
                ));
            }
        }

        return $this;
    }

    /** @param string|list<string> $value */
    public function assertDontSee(string|array $value, bool $escape = true): self
    {
        foreach (Arr::wrap($value) as $needle) {
            TestContext::recordAssertion();
            $needle = $escape ? e($needle) : $needle;

            if (str_contains($this->content(), $needle)) {
                throw new AssertionFailed(sprintf(
                    'Response to %s %s unexpectedly contains [%s].',
                    $this->request->getMethod(),
                    $this->request->getRequestUri(),
                    $needle,
                ));
            }
        }

        return $this;
    }

    /** @param string|list<string> $value */
    public function assertSeeText(string|array $value, bool $escape = true): self
    {
        TestContext::recordAssertion();
        $content = self::normalizeHtmlText($this->content());

        foreach (Arr::wrap($value) as $needle) {
            $needle = self::normalizeHtmlText($escape ? e($needle) : $needle);

            if ($needle !== '' && ! str_contains($content, $needle)) {
                throw new AssertionFailed(sprintf(
                    'Response text does not contain [%s].',
                    $needle,
                ));
            }
        }

        return $this;
    }

    /** @param list<string> $values */
    public function assertSeeInOrder(array $values, bool $escape = true): self
    {
        TestContext::recordAssertion();
        $content = html_entity_decode($this->content(), ENT_QUOTES, 'UTF-8');
        $position = 0;

        foreach ($values as $value) {
            $value = $escape ? e($value) : $value;

            if ($value === '') {
                continue;
            }

            $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
            $found = mb_strpos($content, $value, $position);

            if ($found === false || $found < $position) {
                throw new AssertionFailed(sprintf(
                    'Response does not contain [%s] in the specified order.',
                    $value,
                ));
            }

            $position = $found + mb_strlen($value);
        }

        return $this;
    }

    public function assertViewIs(string $name): self
    {
        TestContext::recordAssertion();
        $view = $this->view();

        if ($view->name() !== $name) {
            throw new AssertionFailed(sprintf(
                'Expected response view [%s], but received [%s].',
                $name,
                $view->name(),
            ));
        }

        return $this;
    }

    public function assertViewHas(string $key, mixed $value = null): self
    {
        TestContext::recordAssertion();
        $data = $this->view()->gatherData();
        $actual = Arr::get($data, $key);
        $matches = $value === null
            ? Arr::has($data, $key)
            : ($value instanceof Closure ? $value($actual) === true : $actual == $value);

        if (! $matches) {
            throw new AssertionFailed(sprintf(
                'Response view data [%s] does not match the expected value.',
                $key,
            ));
        }

        return $this;
    }

    /** @param array<array-key, mixed> $expected */
    public function assertJson(array $expected, bool $strict = false): self
    {
        TestContext::recordAssertion();
        $actual = $this->decodedJson();

        if (! is_array($actual) || ! self::containsSubset($actual, $expected, $strict)) {
            throw new AssertionFailed(sprintf(
                'Response JSON does not contain the expected subset: %s',
                self::export($expected),
            ));
        }

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        TestContext::recordAssertion();
        $actual = $this->json($path);

        if ($actual !== $expected) {
            throw new AssertionFailed(sprintf(
                'Response JSON path [%s] expected %s, received %s.',
                $path,
                self::export($expected),
                self::export($actual),
            ));
        }

        return $this;
    }

    /** @param string|list<string> $errors */
    public function assertJsonValidationErrors(
        string|array $errors,
        string $responseKey = 'errors',
    ): self {
        TestContext::recordAssertion();
        $errors = is_string($errors) ? [$errors] : $errors;

        if ($errors === []) {
            throw new AssertionFailed('No validation errors were provided.');
        }

        $actual = $this->json($responseKey);

        if (! is_array($actual)) {
            throw new AssertionFailed('Response does not contain JSON validation errors.');
        }

        foreach ($errors as $error) {
            TestContext::recordAssertion();

            if (! array_key_exists($error, $actual)) {
                throw new AssertionFailed(sprintf(
                    'Response JSON validation errors do not contain [%s].',
                    $error,
                ));
            }
        }

        return $this;
    }

    private function assertStatusCode(int $status): self
    {
        TestContext::recordAssertion();

        if ($this->status() !== $status) {
            throw new AssertionFailed(sprintf(
                'Expected response status [%d], but received [%d].',
                $status,
                $this->status(),
            ));
        }

        return $this;
    }

    private function decodedJson(): mixed
    {
        if ($this->jsonDecoded) {
            return $this->decodedJson;
        }

        try {
            $this->decodedJson = json_decode(
                $this->content(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new AssertionFailed(
                'Response does not contain valid JSON: '.$exception->getMessage(),
            );
        }

        $this->jsonDecoded = true;

        return $this->decodedJson;
    }

    private function view(): View
    {
        $view = $this->baseResponse->original ?? null;

        if (! $view instanceof View) {
            throw new AssertionFailed('The response is not a view.');
        }

        return $view;
    }

    private static function normalizeHtmlText(string $value): string
    {
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = trim($value);
        $normalized = preg_replace('/\s+/u', ' ', $value);

        return is_string($normalized) ? $normalized : $value;
    }

    /**
     * @param  array<array-key, mixed>  $actual
     * @param  array<array-key, mixed>  $expected
     */
    private static function containsSubset(
        array $actual,
        array $expected,
        bool $strict,
    ): bool {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }

            if (is_array($value) && is_array($actual[$key])) {
                if (! self::containsSubset($actual[$key], $value, $strict)) {
                    return false;
                }

                continue;
            }

            if ($strict ? $actual[$key] !== $value : $actual[$key] != $value) {
                return false;
            }
        }

        return true;
    }

    private static function export(mixed $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return is_string($encoded) ? $encoded : get_debug_type($value);
    }
}
