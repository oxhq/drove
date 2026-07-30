<?php

declare(strict_types=1);

namespace Drove\Native;

use Drove\Extension\ExtensionSet;
use Drove\Kernel\AssertionFailed;
use LogicException;

final class TestContext
{
    /** @var array<string, mixed> */
    private array $fixtures = [];

    public function __construct(
        private readonly ExtensionSet $extensions = new ExtensionSet([]),
    ) {
        //
    }

    public function extensionValue(string $owner, string $field): mixed
    {
        return $this->extensions->contextValue($owner, $field);
    }

    public function assertWith(
        string $owner,
        string $matcher,
        mixed $actual,
        mixed ...$arguments,
    ): void {
        $result = $this->extensions->match($owner, $matcher, $actual, array_values($arguments));

        if (! $result->passed) {
            throw new AssertionFailed($result->failureMessage ?? 'Drove extension matcher failed.');
        }
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
}
