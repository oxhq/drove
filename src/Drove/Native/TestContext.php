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

final class TestContext
{
    private static ?self $active = null;

    /** @var array<string, mixed> */
    private array $fixtures = [];

    private int $assertions = 0;

    private int $cleanups = 0;

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
        $result = $this->extensions->match($owner, $matcher, $actual, array_values($arguments));

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

    public static function wrapHook(\Closure $hook, string $phase): \Closure
    {
        return new self(metricsToken: 'hook-prototype')->hookClosure($hook, $phase);
    }

    private function hookClosure(\Closure $hook, string $phase): \Closure
    {
        return function () use ($hook, $phase): mixed {
            return $this->executeHook($hook, $phase);
        };
    }

    public function caseClosure(CaseDefinition $case): \Closure
    {
        return function () use ($case): TestOutcome {
            return $this->executeCase($case);
        };
    }

    /**
     * @return array{output: string, assertions: ?int, cleanups: ?int}
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

        return [
            'output' => $clean,
            'assertions' => is_string($lastAssertion) ? (int) $lastAssertion : null,
            'cleanups' => is_string($lastCleanup) ? (int) $lastCleanup : null,
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

                $returned = $this->invoke($case->body, $case->arguments);

                return $returned instanceof TestOutcome
                    ? $returned
                    : TestOutcome::passed();
            });
        } finally {
            $this->emitMetrics();
        }
    }

    private function executeHook(\Closure $hook, string $phase): mixed
    {
        try {
            return $this->withActive(fn (): mixed => $this->invoke($hook, []));
        } finally {
            if ($phase === 'before_each' || $phase === 'after_each') {
                $this->emitMetrics();
            }
        }
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
    }
}
