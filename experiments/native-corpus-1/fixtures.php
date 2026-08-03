<?php

declare(strict_types=1);

namespace DroveNativePestCorpus;

use Drove\Extension\Contracts\Entrypoint;
use Drove\Extension\Contracts\Matcher;
use Drove\Extension\MatchInput;
use Drove\Extension\MatchResult;
use Drove\Extension\Registry;
use Drove\Native\TestContext;

final class PestCorpusEntrypoint implements Entrypoint
{
    public function register(Registry $registry): void
    {
        $registry->registerMatcher('macro-true', self::matcher(
            static fn (MatchInput $input): bool => $input->actual === true,
        ));
        $registry->registerMatcher('macro-not-true', self::matcher(
            static fn (MatchInput $input): bool => $input->actual !== true,
        ));
        $registry->registerMatcher('macro-same', self::matcher(
            static fn (MatchInput $input): bool => $input->actual === ($input->arguments[0] ?? null),
        ));
        $registry->registerMatcher('macro-not-same', self::matcher(
            static fn (MatchInput $input): bool => $input->actual !== ($input->arguments[0] ?? null),
        ));
        $registry->registerMatcher('is-true', self::matcher(
            static fn (MatchInput $input): bool => $input->actual === true,
            1,
        ));
        $registry->registerMatcher('is-not-true', self::matcher(
            static fn (MatchInput $input): bool => $input->actual !== true,
            1,
        ));
        $registry->registerMatcher('pipeline-to-be', self::pipeline(false));
        $registry->registerMatcher('pipeline-not-to-be', self::pipeline(true));
    }

    /** @param \Closure(MatchInput): bool $matches */
    private static function matcher(\Closure $matches, ?int $messageIndex = null): Matcher
    {
        return new readonly class($matches, $messageIndex) implements Matcher
        {
            /** @param \Closure(MatchInput): bool $matches */
            public function __construct(
                private \Closure $matches,
                private ?int $messageIndex,
            ) {}

            public function match(MatchInput $input): MatchResult
            {
                if (($this->matches)($input)) {
                    return MatchResult::pass();
                }

                $message = $this->messageIndex === null
                    ? null
                    : ($input->arguments[$this->messageIndex] ?? null);

                return MatchResult::fail(is_string($message) && $message !== ''
                    ? $message
                    : 'The native Pest corpus matcher failed.');
            }
        };
    }

    private static function pipeline(bool $negated): Matcher
    {
        return new readonly class($negated) implements Matcher
        {
            public function __construct(private bool $negated) {}

            public function match(MatchInput $input): MatchResult
            {
                $expected = $input->arguments[0] ?? null;
                $state = $input->arguments[1] ?? null;
                $ignoreCase = $input->arguments[2] ?? false;

                if (! is_object($state)
                    || ! isset($state->runCount, $state->appliedCount)
                    || ! is_array($state->runCount)
                    || ! is_array($state->appliedCount)) {
                    return MatchResult::fail('The pipeline matcher requires explicit state.');
                }

                $state->runCount['char']++;

                if ($input->actual instanceof PipelineChar) {
                    $state->appliedCount['char']++;
                    $matches = $expected instanceof PipelineChar
                        && $input->actual->value === $expected->value;

                    if ($expected instanceof PipelineChar) {
                        TestContext::recordAssertion();
                    }

                    return $this->result($matches);
                }

                if ($input->actual instanceof PipelineNumber) {
                    $state->runCount['number']++;
                    $state->appliedCount['number']++;
                    $matches = $expected instanceof PipelineNumber
                        && $input->actual->value === $expected->value;

                    if ($expected instanceof PipelineNumber) {
                        TestContext::recordAssertion();
                    }

                    return $this->result($matches);
                }

                if ($input->actual === '*' && is_numeric($expected)) {
                    $state->runCount['wildcard']++;
                    $state->appliedCount['wildcard']++;

                    return $this->result(true);
                }

                $state->runCount['symbol']++;

                if ($input->actual instanceof PipelineSymbol) {
                    $state->appliedCount['symbol']++;
                    $matches = $expected instanceof PipelineSymbol
                        && $input->actual->value === $expected->value;

                    if ($expected instanceof PipelineSymbol) {
                        TestContext::recordAssertion();
                    }

                    return $this->result($matches);
                }

                $matches = is_string($input->actual) && $ignoreCase === true
                    ? is_string($expected) && strcasecmp($input->actual, $expected) === 0
                    : $input->actual === $expected;

                return $this->result($matches);
            }

            private function result(bool $matches): MatchResult
            {
                $matches = $this->negated ? ! $matches : $matches;

                return $matches
                    ? MatchResult::pass()
                    : MatchResult::fail('The explicit pipeline replacement did not match.');
            }
        };
    }
}

final class TrueConstraint {}

final class PipelineNumber
{
    public function __construct(public int $value) {}
}

final class PipelineChar
{
    public function __construct(public string $value) {}
}

final class PipelineSymbol
{
    public function __construct(public string $value) {}
}

final class PipelineState
{
    /** @var array<string, int> */
    public array $runCount = [];

    /** @var array<string, int> */
    public array $appliedCount = [];

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->appliedCount = $this->runCount = [
            'char' => 0,
            'number' => 0,
            'wildcard' => 0,
            'symbol' => 0,
        ];
    }
}

trait Retrievable {}

final class UsesRetrievable
{
    use Retrievable;
}

final class DoesNotUseRetrievable {}

final class DocumentedTarget
{
    /** A documented value. */
    public string $value = '';

    /** A documented method. */
    public function documented(): void
    {
        // Intentionally empty.
    }
}

final class UndocumentedTarget
{
    public string $value = '';

    public function undocumented(): void {}
}
