<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;
use InvalidArgumentException;
use LogicException;

final class TestDefinition
{
    /** @var iterable<mixed, mixed>|string|null */
    private iterable|string|null $dataset = null;

    /** @var list<string> */
    private array $groups = [];

    private string $disposition = 'run';

    private string $reason = '';

    private int $timeoutMs = 0;

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
}
