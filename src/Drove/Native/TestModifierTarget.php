<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;

abstract class TestModifierTarget
{
    /** @param Closure(TestDefinition): void $modifier */
    abstract protected function apply(string $name, Closure $modifier): static;

    public function group(string ...$groups): static
    {
        return $this->apply('group', static function (TestDefinition $definition) use ($groups): void {
            $definition->group(...$groups);
        });
    }

    public function skip(bool|string $condition = true, string $reason = ''): static
    {
        return $this->apply('skip', static function (TestDefinition $definition) use ($condition, $reason): void {
            $definition->skip($condition, $reason);
        });
    }

    public function todo(string $reason = ''): static
    {
        return $this->apply('todo', static function (TestDefinition $definition) use ($reason): void {
            $definition->todo($reason);
        });
    }

    public function timeout(int $milliseconds): static
    {
        return $this->apply('timeout', static function (TestDefinition $definition) use ($milliseconds): void {
            $definition->timeout($milliseconds);
        });
    }

    public function throws(string|int $exception, ?string $message = null, ?int $code = null): static
    {
        return $this->apply('throws', static function (TestDefinition $definition) use ($exception, $message, $code): void {
            $definition->throws($exception, $message, $code);
        });
    }

    /** @param (callable(): bool)|bool $condition */
    public function throwsIf(
        callable|bool $condition,
        string|int $exception,
        ?string $message = null,
        ?int $code = null,
    ): static {
        return $this->apply('throwsIf', static function (TestDefinition $definition) use ($condition, $exception, $message, $code): void {
            $definition->throwsIf($condition, $exception, $message, $code);
        });
    }

    /** @param (callable(): bool)|bool $condition */
    public function throwsUnless(
        callable|bool $condition,
        string|int $exception,
        ?string $message = null,
        ?int $code = null,
    ): static {
        return $this->apply('throwsUnless', static function (TestDefinition $definition) use ($condition, $exception, $message, $code): void {
            $definition->throwsUnless($condition, $exception, $message, $code);
        });
    }

    public function fails(?string $message = null): static
    {
        return $this->apply('fails', static function (TestDefinition $definition) use ($message): void {
            $definition->fails($message);
        });
    }

    /** @param array<int, string>|string $assignee */
    public function assignee(array|string $assignee): static
    {
        return $this->apply('assignee', static function (TestDefinition $definition) use ($assignee): void {
            $definition->assignee($assignee);
        });
    }

    /** @param array<int, string|int>|string|int $number */
    public function issue(array|string|int $number): static
    {
        return $this->apply('issue', static function (TestDefinition $definition) use ($number): void {
            $definition->issue($number);
        });
    }

    /** @param array<int, string|int>|string|int $number */
    public function ticket(array|string|int $number): static
    {
        return $this->apply('ticket', static function (TestDefinition $definition) use ($number): void {
            $definition->ticket($number);
        });
    }

    /** @param array<int, string|int>|string|int $number */
    public function pr(array|string|int $number): static
    {
        return $this->apply('pr', static function (TestDefinition $definition) use ($number): void {
            $definition->pr($number);
        });
    }

    /** @param array<int, string>|string $note */
    public function note(array|string $note): static
    {
        return $this->apply('note', static function (TestDefinition $definition) use ($note): void {
            $definition->note($note);
        });
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
    ): static {
        return $this->apply('wip', static function (TestDefinition $definition) use ($note, $assignee, $issue, $pr): void {
            $definition->wip($note, $assignee, $issue, $pr);
        });
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
    ): static {
        return $this->apply('done', static function (TestDefinition $definition) use ($note, $assignee, $issue, $pr): void {
            $definition->done($note, $assignee, $issue, $pr);
        });
    }

    /** @param array<class-string|string>|class-string ...$classes */
    public function references(string|array ...$classes): static
    {
        return $this->apply('references', static function (TestDefinition $definition) use ($classes): void {
            $definition->references(...$classes);
        });
    }

    /** @param array<class-string|string>|class-string ...$classes */
    public function see(string|array ...$classes): static
    {
        return $this->apply('see', static function (TestDefinition $definition) use ($classes): void {
            $definition->see(...$classes);
        });
    }

    public function throwsNoExceptions(): static
    {
        return $this->apply('throwsNoExceptions', static function (TestDefinition $definition): void {
            $definition->throwsNoExceptions();
        });
    }

    /** @param array<int, string>|string ...$targets */
    public function covers(array|string ...$targets): static
    {
        return $this->apply('covers', static function (TestDefinition $definition) use ($targets): void {
            $definition->covers(...$targets);
        });
    }

    public function coversFunction(string ...$functions): static
    {
        return $this->apply('coversFunction', static function (TestDefinition $definition) use ($functions): void {
            $definition->coversFunction(...$functions);
        });
    }

    public function coversTrait(string ...$traits): static
    {
        return $this->apply('coversTrait', static function (TestDefinition $definition) use ($traits): void {
            $definition->coversTrait(...$traits);
        });
    }
}
