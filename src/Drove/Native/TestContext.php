<?php

declare(strict_types=1);

namespace Drove\Native;

use Drove\Extension\ExtensionSet;
use Drove\Kernel\AssertionFailed;
use Drove\Kernel\ScopeContext;
use Drove\Kernel\TestOutcome;
use InvalidArgumentException;
use LogicException;
use ReflectionFunction;
use RuntimeException;
use Throwable;

final class TestContext
{
    private static ?self $active = null;

    /** @var array<string, mixed> */
    private array $fixtures = [];

    private int $assertions = 0;

    private int $cleanups = 0;

    /** @var list<string> */
    private array $notes = [];

    private bool $allowsNoAssertions = false;

    private readonly string $metricsToken;

    public function __construct(
        private readonly ExtensionSet $extensions = new ExtensionSet([]),
        string $metricsToken = '',
        private readonly ScopeContext $scope = new ScopeContext,
    ) {
        $this->metricsToken = $metricsToken === ''
            ? bin2hex(random_bytes(16))
            : $metricsToken;
    }

    public function extensionValue(string $owner, string $field): mixed
    {
        return $this->extensions->contextValue($owner, $field);
    }

    public function app(): mixed
    {
        return $this->scope->app();
    }

    public function assertWith(
        string $owner,
        string $matcher,
        mixed $actual,
        mixed ...$arguments,
    ): void {
        self::recordAssertion();
        $case = $this->scope->metadata()['case'] ?? [];
        $result = $this->extensions->match($owner, $matcher, $actual, array_values($arguments), $case);

        if (! $result->passed) {
            throw new AssertionFailed($result->failureMessage ?? 'Drove extension matcher failed.');
        }
    }

    public function defer(\Closure $cleanup): void
    {
        if (new ReflectionFunction($cleanup)->getNumberOfParameters() !== 0) {
            throw new InvalidArgumentException('Native deferred cleanup closures do not accept parameters.');
        }

        $context = $this;
        $this->scope->defer(
            static fn (): mixed => $context->executeCleanup($cleanup),
        );
    }

    /** @param array<int, mixed>|string $note */
    public function note(array|string $note): self
    {
        $notes = is_array($note) ? array_values($note) : [$note];

        if ($notes === []) {
            throw new InvalidArgumentException('Native runtime notes require non-empty strings.');
        }

        $validated = [];

        foreach ($notes as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Native runtime notes require non-empty strings.');
            }

            $validated[] = $value;
        }

        $this->notes = array_values(array_unique([...$this->notes, ...$validated]));
        $this->emitMetrics();

        return $this;
    }

    public function expectNotToPerformAssertions(): void
    {
        $this->allowsNoAssertions = true;
        $this->emitMetrics();
    }

    public static function fail(string $message = ''): never
    {
        self::recordAssertion();

        throw new AssertionFailed($message !== '' ? $message : 'Failed explicitly.');
    }

    public static function recordAssertion(): void
    {
        if (self::$active instanceof self) {
            self::$active->assertions++;
            self::$active->emitMetrics();
        }
    }

    public static function hasActiveCase(): bool
    {
        return self::$active instanceof self;
    }

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    public static function wrapHook(\Closure $hook, string $phase): \Closure
    {
        return self::wrapHooks([$hook], $phase);
    }

    /** @param non-empty-list<\Closure> $hooks */
    public static function wrapHooks(array $hooks, string $phase): \Closure
    {
        return new self(metricsToken: 'hook-prototype')->hookClosure($hooks, $phase);
    }

    /** @param non-empty-list<\Closure> $hooks */
    private function hookClosure(array $hooks, string $phase): \Closure
    {
        return fn (): mixed => $this->executeHooks($hooks, $phase);
    }

    public function caseClosure(CaseDefinition $case): \Closure
    {
        return fn (): TestOutcome => $this->executeCase($case);
    }

    /**
     * @return array{
     *     output: string,
     *     assertions: ?int,
     *     cleanups: ?int,
     *     notes: list<string>,
     *     allows_no_assertions: bool
     * }
     */
    public static function extractMetrics(string $output, string $token): array
    {
        $pattern = '/\x1eDROVE_NATIVE_ASSERTIONS:'
            .preg_quote($token, '/')
            .':([0-9]+):([0-9]+)\x1f/';
        $matched = preg_match_all($pattern, $output, $matches);

        if ($matched === false) {
            throw new RuntimeException('Drove could not decode native assertion metrics.');
        }

        $clean = preg_replace($pattern, '', $output);

        if (! is_string($clean)) {
            throw new RuntimeException('Drove could not remove native assertion metrics.');
        }

        $assertions = $matches[1] ?? [];
        $cleanups = $matches[2] ?? [];
        $lastAssertion = $assertions === [] ? null : $assertions[array_key_last($assertions)];
        $lastCleanup = $cleanups === [] ? null : $cleanups[array_key_last($cleanups)];
        $statePattern = '/\x1eDROVE_NATIVE_STATE:'
            .preg_quote($token, '/')
            .':([A-Za-z0-9+\/=]+)\x1f/';
        $stateMatched = preg_match_all($statePattern, $clean, $stateMatches);

        if ($stateMatched === false) {
            throw new RuntimeException('Drove could not decode native runtime state.');
        }

        $clean = preg_replace($statePattern, '', $clean);

        if (! is_string($clean)) {
            throw new RuntimeException('Drove could not remove native runtime state.');
        }

        $encodedStates = $stateMatches[1] ?? [];
        $encodedState = $encodedStates === [] ? null : $encodedStates[array_key_last($encodedStates)];
        $state = ['notes' => [], 'allows_no_assertions' => false];

        if (is_string($encodedState)) {
            $decoded = base64_decode($encodedState, true);

            if (! is_string($decoded)) {
                throw new RuntimeException('Drove native runtime state is not valid base64.');
            }

            $candidate = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);

            if (! is_array($candidate)
                || ! is_array($candidate['notes'] ?? null)
                || ! array_is_list($candidate['notes'])
                || array_any($candidate['notes'], static fn (mixed $note): bool => ! is_string($note))
                || ! is_bool($candidate['allows_no_assertions'] ?? null)) {
                throw new RuntimeException('Drove native runtime state has an invalid shape.');
            }

            $state = $candidate;
        }

        return [
            'output' => $clean,
            'assertions' => is_string($lastAssertion) ? (int) $lastAssertion : null,
            'cleanups' => is_string($lastCleanup) ? (int) $lastCleanup : null,
            'notes' => $state['notes'],
            'allows_no_assertions' => $state['allows_no_assertions'],
        ];
    }

    public function &__get(string $name): mixed
    {
        if (! array_key_exists($name, $this->fixtures)) {
            throw new LogicException(sprintf(
                'Undefined test context fixture [%s].',
                $name,
            ));
        }

        return $this->fixtures[$name];
    }

    public function __set(string $name, mixed $value): void
    {
        $this->fixtures[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->fixtures[$name]);
    }

    private function executeCase(CaseDefinition $case): TestOutcome
    {
        try {
            return $this->withActive(function () use ($case): TestOutcome {
                if ($case->disposition === 'skipped') {
                    return TestOutcome::skipped($case->reason);
                }

                if ($case->disposition === 'todo') {
                    return TestOutcome::todo($case->reason);
                }

                $returned = $this->invokeCase($case);

                return $returned instanceof TestOutcome
                    ? $returned
                    : TestOutcome::passed();
            });
        } finally {
            $this->emitMetrics();
        }
    }

    /** @param non-empty-list<\Closure> $hooks */
    private function executeHooks(array $hooks, string $phase): mixed
    {
        try {
            return $this->withActive(function () use ($hooks): mixed {
                $result = null;

                foreach ($hooks as $hook) {
                    $result = $this->invoke($hook, []);
                }

                return $result;
            });
        } finally {
            if ($phase === 'before_each' || $phase === 'after_each') {
                $this->emitMetrics();
            }
        }
    }

    private function invokeCase(CaseDefinition $case): mixed
    {
        if ($case->expectedException === null) {
            return $this->invoke($case->body, $case->arguments);
        }

        $expected = $case->expectedException;

        foreach ($expected as $criterion) {
            if ($criterion !== null) {
                self::recordAssertion();
            }
        }

        try {
            $this->invoke($case->body, $case->arguments);
        } catch (Throwable $throwable) {
            $class = $expected['class'];
            $message = $expected['message'];
            $code = $expected['code'];

            if ($class !== null && ! $throwable instanceof $class) {
                throw new AssertionFailed(sprintf(
                    'Expected exception %s, got %s.',
                    $class,
                    $throwable::class,
                ), $throwable->getCode(), previous: $throwable);
            }

            if ($message !== null && ! str_contains($throwable->getMessage(), $message)) {
                throw new AssertionFailed(sprintf(
                    'Expected exception message to contain %s, got %s.',
                    var_export($message, true),
                    var_export($throwable->getMessage(), true),
                ), $throwable->getCode(), previous: $throwable);
            }

            if ($code !== null && $throwable->getCode() !== $code) {
                throw new AssertionFailed(sprintf(
                    'Expected exception code %d, got %d.',
                    $code,
                    $throwable->getCode(),
                ), $throwable->getCode(), previous: $throwable);
            }

            return null;
        }

        throw new AssertionFailed('Expected exception was not thrown.');
    }

    private function withActive(\Closure $work): mixed
    {
        $previous = self::$active;
        self::$active = $this;

        try {
            return $work();
        } finally {
            self::$active = $previous;
        }
    }

    /** @param list<mixed> $arguments */
    private function invoke(\Closure $closure, array $arguments): mixed
    {
        $reflection = new ReflectionFunction($closure);

        if ($reflection->isStatic()) {
            return $reflection->invokeArgs($arguments);
        }

        $bound = $closure->bindTo($this, $reflection->getClosureScopeClass()?->getName() ?? self::class);

        if (! $bound instanceof \Closure) {
            throw new LogicException('Native test closure could not be bound to its test context.');
        }

        return new ReflectionFunction($bound)->invokeArgs($arguments);
    }

    private function executeCleanup(\Closure $cleanup): mixed
    {
        try {
            return $this->withActive(fn (): mixed => $this->invoke($cleanup, []));
        } finally {
            $this->cleanups++;
            $this->emitMetrics();
        }
    }

    private function emitMetrics(): void
    {
        echo "\x1eDROVE_NATIVE_ASSERTIONS:"
            .$this->metricsToken
            .':'
            .$this->assertions
            .':'
            .$this->cleanups
            ."\x1f";
        if ($this->notes !== [] || $this->allowsNoAssertions) {
            echo "\x1eDROVE_NATIVE_STATE:"
                .$this->metricsToken
                .':'
                .base64_encode(json_encode([
                    'notes' => $this->notes,
                    'allows_no_assertions' => $this->allowsNoAssertions,
                ], JSON_THROW_ON_ERROR))
                ."\x1f";
        }
    }
}
