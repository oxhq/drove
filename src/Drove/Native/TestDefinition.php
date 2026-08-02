<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;
use Drove\Kernel\AssertionFailed;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class TestDefinition
{
    /** @var iterable<mixed, mixed>|string|null */
    private iterable|string|null $dataset = null;

    /** @var list<string> */
    private array $groups = [];

    private string $disposition = 'run';

    private string $reason = '';

    private int $timeoutMs = 0;

    /** @var array{class: string|null, message: string|null, code: int|null}|null */
    private ?array $expectedException = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private bool $frozen = false;

    /** @var list<array{key: int|string, arguments: list<mixed>}>|null */
    private ?array $materialized = null;

    public function __construct(
        private readonly Closure $body,
    ) {
        //
    }

    /** @param iterable<mixed, mixed>|string $dataset */
    public function with(iterable|string $dataset): self
    {
        $this->assertMutable();

        if ($this->dataset !== null) {
            throw new LogicException('A native test may declare only one dataset.');
        }

        if (is_string($dataset) && trim($dataset) === '') {
            throw new InvalidArgumentException('A named native dataset requires a name.');
        }

        $this->dataset = $dataset;

        return $this;
    }

    public function group(string ...$groups): self
    {
        $this->assertMutable();

        if ($groups === []
            || array_any($groups, static fn (string $group): bool => trim($group) === '')) {
            throw new InvalidArgumentException('Native groups must be non-empty strings.');
        }

        $this->groups = array_values(array_unique([...$this->groups, ...$groups]));

        return $this;
    }

    public function skip(bool|string $condition = true, string $reason = ''): self
    {
        $this->assertMutable();

        if ($condition === false) {
            return $this;
        }

        $this->disposition = 'skipped';
        $this->reason = is_string($condition) ? $condition : $reason;
        $this->reason = trim($this->reason) === '' ? 'Skipped.' : $this->reason;

        return $this;
    }

    public function todo(string $reason = ''): self
    {
        $this->assertMutable();
        $this->disposition = 'todo';
        $this->reason = trim($reason) === '' ? 'Todo.' : $reason;

        return $this;
    }

    public function timeout(int $milliseconds): self
    {
        $this->assertMutable();

        if ($milliseconds < 1) {
            throw new InvalidArgumentException('A native test timeout must be at least one millisecond.');
        }

        $this->timeoutMs = $milliseconds;

        return $this;
    }

    public function throws(string|int $exception, ?string $message = null, ?int $code = null): self
    {
        $this->assertMutable();

        $class = null;

        if (is_int($exception)) {
            $code = $exception;
        } elseif (is_a($exception, Throwable::class, true)) {
            $class = $exception;
        } else {
            $message = $exception;
        }

        $this->expectedException = [
            'class' => $class,
            'message' => $message,
            'code' => $code,
        ];

        return $this;
    }

    /** @param (callable(): bool)|bool $condition */
    public function throwsIf(
        callable|bool $condition,
        string|int $exception,
        ?string $message = null,
        ?int $code = null,
    ): self {
        $this->assertMutable();

        if ((is_callable($condition) ? $condition() : $condition) === true) {
            return $this->throws($exception, $message, $code);
        }

        return $this;
    }

    /** @param (callable(): bool)|bool $condition */
    public function throwsUnless(
        callable|bool $condition,
        string|int $exception,
        ?string $message = null,
        ?int $code = null,
    ): self {
        $this->assertMutable();

        if ((is_callable($condition) ? $condition() : $condition) === false) {
            return $this->throws($exception, $message, $code);
        }

        return $this;
    }

    public function fails(?string $message = null): self
    {
        return $this->throws(AssertionFailed::class, $message);
    }

    /** @param array<int, string>|string $assignee */
    public function assignee(array|string $assignee): self
    {
        $this->metadata['assignees'] = $this->strings(
            'assignee',
            $assignee,
            $this->metadata['assignees'] ?? [],
        );

        return $this;
    }

    /** @param array<int, string|int>|string|int $number */
    public function issue(array|string|int $number): self
    {
        $this->metadata['issues'] = $this->numbers(
            'issue',
            $number,
            $this->metadata['issues'] ?? [],
        );

        return $this;
    }

    /** @param array<int, string|int>|string|int $number */
    public function ticket(array|string|int $number): self
    {
        return $this->issue($number);
    }

    /** @param array<int, string|int>|string|int $number */
    public function pr(array|string|int $number): self
    {
        $this->metadata['prs'] = $this->numbers(
            'pull request',
            $number,
            $this->metadata['prs'] ?? [],
        );

        return $this;
    }

    /** @param array<int, string>|string $note */
    public function note(array|string $note): self
    {
        $this->metadata['notes'] = $this->strings(
            'note',
            $note,
            $this->metadata['notes'] ?? [],
        );

        return $this;
    }

    /**
     * @param  array<int, string>|string|null  $note
     * @param  array<int, string>|string|null  $assignee
     * @param  array<int, string|int>|string|int|null  $issue
     * @param  array<int, string|int>|string|int|null  $pr
     */
    public function wip(
        array|string|null $note = null,
        array|string|null $assignee = null,
        array|string|int|null $issue = null,
        array|string|int|null $pr = null,
    ): self {
        return $this->workflowMetadata($note, $assignee, $issue, $pr);
    }

    /**
     * @param  array<int, string>|string|null  $note
     * @param  array<int, string>|string|null  $assignee
     * @param  array<int, string|int>|string|int|null  $issue
     * @param  array<int, string|int>|string|int|null  $pr
     */
    public function done(
        array|string|null $note = null,
        array|string|null $assignee = null,
        array|string|int|null $issue = null,
        array|string|int|null $pr = null,
    ): self {
        return $this->workflowMetadata($note, $assignee, $issue, $pr);
    }

    /** @param array<class-string|string>|class-string ...$classes */
    public function references(string|array ...$classes): self
    {
        $this->assertMutable();

        if ($classes === []) {
            throw new InvalidArgumentException('Native references require at least one target.');
        }

        $this->metadata['references'] = array_values(array_unique(
            [...($this->metadata['references'] ?? []), ...$classes],
            SORT_REGULAR,
        ));

        return $this;
    }

    /** @param array<class-string|string>|class-string ...$classes */
    public function see(string|array ...$classes): self
    {
        return $this->references(...$classes);
    }

    public function throwsNoExceptions(): self
    {
        $this->assertMutable();
        $this->metadata['allows_no_assertions'] = true;

        return $this;
    }

    /** @param array<int, string>|string ...$targets */
    public function covers(array|string ...$targets): self
    {
        $this->assertMutable();
        $this->metadata['coverage'] = [
            ...($this->metadata['coverage'] ?? []),
            ...Coverage::targets(...$targets),
        ];

        return $this;
    }

    public function coversFunction(string ...$functions): self
    {
        return $this->coverageOf('function', array_values($functions));
    }

    public function coversTrait(string ...$traits): self
    {
        return $this->coverageOf('trait', array_values($traits));
    }

    public function body(): Closure
    {
        return $this->body;
    }

    /** @return list<string> */
    public function groups(): array
    {
        return $this->groups;
    }

    public function disposition(): string
    {
        return $this->disposition;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function timeoutMs(): int
    {
        return $this->timeoutMs;
    }

    /** @return array{class: string|null, message: string|null, code: int|null}|null */
    public function expectedException(): ?array
    {
        return $this->expectedException;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function datasetName(): ?string
    {
        return is_string($this->dataset) ? $this->dataset : null;
    }

    /**
     * @return list<array{key: int|string, arguments: list<mixed>}>|null
     */
    public function inlineRows(): ?array
    {
        if ($this->dataset === null || is_string($this->dataset)) {
            return null;
        }

        if ($this->materialized !== null) {
            return $this->materialized;
        }

        $this->materialized = self::materialize($this->dataset);

        return $this->materialized;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * @param  iterable<mixed, mixed>  $rows
     * @return list<array{key: int|string, arguments: list<mixed>}>
     */
    public static function materialize(iterable $rows): array
    {
        $materialized = [];
        $caseKeys = [];

        foreach ($rows as $key => $row) {
            if (! is_int($key) && ! is_string($key)) {
                throw new InvalidArgumentException('Native dataset keys must be integers or strings.');
            }

            $caseKey = is_int($key)
                ? 'index:'.$key
                : 'name:'.rawurlencode($key);

            if (isset($caseKeys[$caseKey])) {
                throw new LogicException(sprintf('Duplicate native dataset case key %s.', $caseKey));
            }

            $caseKeys[$caseKey] = true;
            $materialized[] = [
                'key' => $key,
                'arguments' => is_array($row) ? array_values($row) : [$row],
            ];
        }

        if ($materialized === []) {
            throw new InvalidArgumentException('Native datasets cannot be empty.');
        }

        return $materialized;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Native test declarations cannot change after planning.');
        }
    }

    /**
     * @param  array<int, string>|string|null  $note
     * @param  array<int, string>|string|null  $assignee
     * @param  array<int, string|int>|string|int|null  $issue
     * @param  array<int, string|int>|string|int|null  $pr
     */
    private function workflowMetadata(
        array|string|null $note,
        array|string|null $assignee,
        array|string|int|null $issue,
        array|string|int|null $pr,
    ): self {
        $this->assertMutable();

        if ($issue !== null) {
            $this->issue($issue);
        }

        if ($pr !== null) {
            $this->pr($pr);
        }

        if ($assignee !== null) {
            $this->assignee($assignee);
        }

        if ($note !== null) {
            $this->note($note);
        }

        return $this;
    }

    /**
     * @param  array<int, mixed>|string  $values
     * @param  list<string>  $existing
     * @return list<string>
     */
    private function strings(string $name, array|string $values, array $existing): array
    {
        $this->assertMutable();
        $values = is_array($values) ? array_values($values) : [$values];

        if ($values === [] || array_any($values, static fn (mixed $value): bool => ! is_string($value) || trim($value) === '')) {
            throw new InvalidArgumentException(sprintf('Native %s metadata requires non-empty strings.', $name));
        }

        return array_values(array_unique([...$existing, ...$values]));
    }

    /**
     * @param  array<int, string|int>|string|int  $values
     * @param  list<int>  $existing
     * @return list<int>
     */
    private function numbers(string $name, array|string|int $values, array $existing): array
    {
        $this->assertMutable();
        $values = is_array($values) ? array_values($values) : [$values];
        $normalized = [];

        foreach ($values as $value) {
            $value = ltrim((string) $value, '#');

            if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
                throw new InvalidArgumentException(sprintf('Native %s metadata requires positive integer identifiers.', $name));
            }

            $normalized[] = (int) $value;
        }

        return array_values(array_unique([...$existing, ...$normalized]));
    }

    /** @param list<string> $targets */
    private function coverageOf(string $kind, array $targets): self
    {
        $this->assertMutable();

        if ($targets === []) {
            throw new InvalidArgumentException('Coverage requires at least one target.');
        }

        foreach ($targets as $target) {
            $this->metadata['coverage'][] = Coverage::target($target, $kind);
        }

        return $this;
    }
}
