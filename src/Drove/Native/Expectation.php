<?php

declare(strict_types=1);

namespace Drove\Native;

use BadMethodCallException;
use Closure;
use Countable;
use DateTimeInterface;
use Drove\Kernel\AssertionFailed;
use InvalidArgumentException;
use LogicException;
use OutOfRangeException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

final readonly class Expectation
{
    public function __construct(
        public mixed $value,
        private bool $negated = false,
    ) {
        //
    }

    public function and(mixed $value): self
    {
        return $value instanceof self ? $value : new self($value);
    }

    public function any(): AnyExpectationValue
    {
        return AnyExpectationValue::Value;
    }

    public function not(): self
    {
        return new self($this->value, ! $this->negated);
    }

    public function property(string $name): self
    {
        TestContext::recordAssertion();

        if (is_array($this->value) && array_key_exists($name, $this->value)) {
            return new self($this->value[$name]);
        }

        if (is_object($this->value) && property_exists($this->value, $name)) {
            return new self($this->value->{$name});
        }

        throw new LogicException(sprintf('Undefined native expectation value property [%s].', $name));
    }

    public function each(callable $callback): self
    {
        if (! is_iterable($this->value)) {
            throw new BadMethodCallException('Expectation value is not iterable.');
        }

        foreach ($this->value as $key => $value) {
            $expectation = new self($value, $this->negated);
            $callback($expectation, $key);
        }

        return new self($this->value);
    }

    public function json(): self
    {
        if (! is_string($this->value)) {
            throw new InvalidArgumentException('Native JSON expectations require a string value.');
        }

        TestContext::recordAssertion();

        try {
            $decoded = json_decode($this->value, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            TestContext::recordAssertion();
            throw new AssertionFailed($exception->getMessage(), $exception->getCode(), previous: $exception);
        }

        return new self($decoded, $this->negated);
    }

    public function sequence(mixed ...$expectations): self
    {
        if (! is_iterable($this->value)) {
            throw new BadMethodCallException('Expectation value is not iterable.');
        }

        if ($expectations === []) {
            throw new InvalidArgumentException('No sequence expectations defined.');
        }

        $items = is_array($this->value)
            ? $this->value
            : iterator_to_array($this->value, true);

        $ordinal = 0;

        foreach ($items as $key => $value) {
            $expected = $expectations[$ordinal % count($expectations)];
            $valueExpectation = new self($value);

            if (is_callable($expected)) {
                $expected(
                    $valueExpectation,
                    new self($key),
                );
            } else {
                $valueExpectation->toEqual($expected);
            }

            $ordinal++;
        }

        if ($ordinal < count($expectations)) {
            throw new OutOfRangeException('Sequence expectations are more than the iterable items.');
        }

        return new self($this->value);
    }

    public function toBe(mixed $expected, string $message = ''): self
    {
        $strings = is_string($this->value) && is_string($expected);

        return $this->assertCondition(
            $this->value === $expected,
            $strings
                ? 'Failed asserting that two strings are identical.'
                : sprintf(
                    'Failed asserting that %s is identical to %s.',
                    $this->export($this->value),
                    $this->export($expected),
                ),
            $strings
                ? 'Failed asserting that two strings are not identical.'
                : sprintf(
                    'Failed asserting that %s is not identical to %s.',
                    $this->export($this->value),
                    $this->export($expected),
                ),
            $message,
        );
    }

    public function toEqual(mixed $expected, string $message = ''): self
    {
        return $this->assertCondition(
            $this->value == $expected,
            sprintf(
                'Failed asserting that %s equals %s.',
                $this->export($this->value),
                $this->export($expected),
            ),
            sprintf(
                'Failed asserting that %s does not equal %s.',
                $this->export($this->value),
                $this->export($expected),
            ),
            $message,
        );
    }

    public function toBeEmpty(string $message = ''): self
    {
        return $this->assertCondition(
            empty($this->value),
            sprintf('Failed asserting that %s is empty.', $this->export($this->value)),
            sprintf('Failed asserting that %s is not empty.', $this->export($this->value)),
            $message,
        );
    }

    public function toBeNull(string $message = ''): self
    {
        return $this->assertCondition(
            $this->value === null,
            sprintf('Failed asserting that %s is null.', $this->export($this->value)),
            'Failed asserting that null is not null.',
            $message,
        );
    }

    public function toBeTrue(string $message = ''): self
    {
        return $this->assertCondition(
            $this->value === true,
            sprintf('Failed asserting that %s is true.', $this->export($this->value)),
            'Failed asserting that true is not true.',
            $message,
        );
    }

    public function toBeTruthy(string $message = ''): self
    {
        return $this->assertCondition(
            (bool) $this->value,
            sprintf('Failed asserting that %s is truthy.', $this->export($this->value)),
            sprintf('Failed asserting that %s is not truthy.', $this->export($this->value)),
            $message,
        );
    }

    public function toBeFalse(string $message = ''): self
    {
        return $this->assertCondition(
            $this->value === false,
            sprintf('Failed asserting that %s is false.', $this->export($this->value)),
            'Failed asserting that false is not false.',
            $message,
        );
    }

    public function toBeFalsy(string $message = ''): self
    {
        return $this->assertCondition(
            ! (bool) $this->value,
            sprintf('Failed asserting that %s is falsy.', $this->export($this->value)),
            sprintf('Failed asserting that %s is not falsy.', $this->export($this->value)),
            $message,
        );
    }

    public function toBeGreaterThan(int|float|string|DateTimeInterface $expected, string $message = ''): self
    {
        return $this->comparison($this->value > $expected, 'greater than', $expected, $message);
    }

    public function toBeGreaterThanOrEqual(int|float|string|DateTimeInterface $expected, string $message = ''): self
    {
        TestContext::recordAssertion();

        return $this->comparison($this->value >= $expected, 'greater than or equal to', $expected, $message);
    }

    public function toBeLessThan(int|float|string|DateTimeInterface $expected, string $message = ''): self
    {
        return $this->comparison($this->value < $expected, 'less than', $expected, $message);
    }

    public function toBeLessThanOrEqual(int|float|string|DateTimeInterface $expected, string $message = ''): self
    {
        TestContext::recordAssertion();

        return $this->comparison($this->value <= $expected, 'less than or equal to', $expected, $message);
    }

    public function toBeBetween(
        int|float|DateTimeInterface $lowest,
        int|float|DateTimeInterface $highest,
        string $message = '',
    ): self {
        return $this->assertChecks([
            [
                $this->value >= $lowest,
                sprintf('Failed asserting that %s is greater than or equal to %s.', $this->export($this->value), $this->export($lowest)),
            ],
            [
                $this->value <= $highest,
                sprintf('Failed asserting that %s is less than or equal to %s.', $this->export($this->value), $this->export($highest)),
            ],
        ], sprintf(
            'Failed asserting that %s is not between %s and %s.',
            $this->export($this->value),
            $this->export($lowest),
            $this->export($highest),
        ), $message, assertionsPerCheck: 2);
    }

    /** @param class-string $class */
    public function toBeInstanceOf(string $class, string $message = ''): self
    {
        return $this->assertCondition(
            $this->value instanceof $class,
            sprintf('Failed asserting that %s is an instance of %s.', $this->export($this->value), $class),
            sprintf('Failed asserting that %s is not an instance of %s.', $this->export($this->value), $class),
            $message,
        );
    }

    public function toContain(mixed ...$needles): self
    {
        $checks = [];

        foreach ($needles as $needle) {
            $checks[] = [
                $this->contains($needle, true),
                sprintf('Failed asserting that %s contains %s.', $this->export($this->value), $this->export($needle)),
            ];
        }

        return $this->assertChecks(
            $checks,
            sprintf('Failed asserting that %s does not contain all expected values.', $this->export($this->value)),
        );
    }

    public function toContainEqual(mixed ...$needles): self
    {
        $checks = [];

        foreach ($needles as $needle) {
            $checks[] = [
                $this->contains($needle, false),
                sprintf('Failed asserting that %s contains a value equal to %s.', $this->export($this->value), $this->export($needle)),
            ];
        }

        return $this->assertChecks(
            $checks,
            sprintf('Failed asserting that %s does not contain all expected equal values.', $this->export($this->value)),
        );
    }

    public function toStartWith(string $expected, string $message = ''): self
    {
        $value = $this->stringValue();

        return $this->assertCondition(
            str_starts_with($value, $expected),
            sprintf('Failed asserting that %s starts with %s.', $this->export($value), $this->export($expected)),
            sprintf('Failed asserting that %s does not start with %s.', $this->export($value), $this->export($expected)),
            $message,
        );
    }

    public function toEndWith(string $expected, string $message = ''): self
    {
        $value = $this->stringValue();

        return $this->assertCondition(
            str_ends_with($value, $expected),
            sprintf('Failed asserting that %s ends with %s.', $this->export($value), $this->export($expected)),
            sprintf('Failed asserting that %s does not end with %s.', $this->export($value), $this->export($expected)),
            $message,
        );
    }

    public function toHaveLength(int $expected, string $message = ''): self
    {
        $actual = is_string($this->value)
            ? $this->stringLength($this->value)
            : $this->size($this->value, allowObject: true);

        return $this->assertCondition(
            $actual === $expected,
            sprintf('Failed asserting that actual length %d matches expected length %d.', $actual, $expected),
            sprintf('Failed asserting that actual length %d does not match expected length %d.', $actual, $expected),
            $message,
        );
    }

    public function toHaveCount(int $expected, string $message = ''): self
    {
        $actual = $this->size($this->value);

        return $this->assertCondition(
            $actual === $expected,
            sprintf('Failed asserting that actual size %d matches expected size %d.', $actual, $expected),
            sprintf('Failed asserting that actual size %d does not match expected size %d.', $actual, $expected),
            $message,
        );
    }

    /** @param Countable|iterable<mixed> $expected */
    public function toHaveSameSize(Countable|iterable $expected, string $message = ''): self
    {
        $expectedSize = $this->size($expected);
        $actualSize = $this->size($this->value);

        return $this->assertCondition(
            $actualSize === $expectedSize,
            sprintf('Failed asserting that actual size %d matches expected size %d.', $actualSize, $expectedSize),
            sprintf('Failed asserting that actual size %d does not match expected size %d.', $actualSize, $expectedSize),
            $message,
        );
    }

    public function toHaveProperty(
        string $name,
        mixed $expected = MissingExpectationValue::Value,
        string $message = '',
    ): self {
        $object = is_object($this->value);
        $found = $object && property_exists($this->value, $name);
        $actual = $found ? $this->value->{$name} : null;
        $checks = [
            [$object, sprintf('Failed asserting that %s is an object.', $this->export($this->value))],
            [$found, sprintf('Failed asserting that object has property %s.', $this->export($name))],
        ];

        if (! $expected instanceof MissingExpectationValue && ! $expected instanceof AnyExpectationValue) {
            $checks[] = [
                $actual == $expected,
                sprintf('Failed asserting that property %s equals %s.', $this->export($name), $this->export($expected)),
            ];
        }

        return $this->assertChecks(
            $checks,
            sprintf('Failed asserting that object does not have matching property %s.', $this->export($name)),
            $message,
        );
    }

    /** @param iterable<string, mixed>|iterable<int, string> $names */
    public function toHaveProperties(iterable $names, string $message = ''): self
    {
        $checks = [];

        foreach ($names as $name => $expected) {
            $hasExpected = ! is_int($name);
            $property = $hasExpected ? (string) $name : $expected;

            if (! is_string($property)) {
                throw new InvalidArgumentException('Native property lists require string property names.');
            }

            $object = is_object($this->value);
            $found = $object && property_exists($this->value, $property);
            $actual = $found ? $this->value->{$property} : null;
            $checks[] = [$object, sprintf('Failed asserting that %s is an object.', $this->export($this->value))];
            $checks[] = [$found, sprintf('Failed asserting that object has property %s.', $this->export($property))];

            if ($hasExpected) {
                $checks[] = [
                    $actual == $expected,
                    sprintf('Failed asserting that property %s equals %s.', $this->export($property), $this->export($expected)),
                ];
            }
        }

        return $this->assertChecks(
            $checks,
            sprintf('Failed asserting that %s does not have all expected properties.', $this->export($this->value)),
            $message,
        );
    }

    public function toEqualCanonicalizing(mixed $expected, string $message = ''): self
    {
        return $this->assertCondition(
            $this->canonical($this->value) == $this->canonical($expected),
            sprintf('Failed asserting that %s equals %s after canonicalizing.', $this->export($this->value), $this->export($expected)),
            sprintf('Failed asserting that %s does not equal %s after canonicalizing.', $this->export($this->value), $this->export($expected)),
            $message,
        );
    }

    public function toEqualWithDelta(mixed $expected, float $delta, string $message = ''): self
    {
        if (! is_int($this->value) && ! is_float($this->value)
            || ! is_int($expected) && ! is_float($expected)) {
            throw new InvalidArgumentException('Native delta expectations require numeric values.');
        }

        return $this->assertCondition(
            abs($this->value - $expected) <= $delta,
            sprintf('Failed asserting that %s equals %s within delta %s.', $this->export($this->value), $this->export($expected), $this->export($delta)),
            sprintf('Failed asserting that %s does not equal %s within delta %s.', $this->export($this->value), $this->export($expected), $this->export($delta)),
            $message,
        );
    }

    /** @param iterable<int|string, mixed> $values */
    public function toBeIn(iterable $values, string $message = ''): self
    {
        $found = false;

        foreach ($values as $value) {
            if ($this->value === $value) {
                $found = true;
                break;
            }
        }

        return $this->assertCondition(
            $found,
            sprintf('Failed asserting that %s is in the expected values.', $this->export($this->value)),
            sprintf('Failed asserting that %s is not in the expected values.', $this->export($this->value)),
            $message,
        );
    }

    public function toBeInfinite(string $message = ''): self
    {
        return $this->typeCondition(is_float($this->value) && is_infinite($this->value), 'infinite', $message);
    }

    public function toBeArray(string $message = ''): self
    {
        return $this->typeCondition(is_array($this->value), 'an array', $message);
    }

    public function toBeList(string $message = ''): self
    {
        return $this->typeCondition(is_array($this->value) && array_is_list($this->value), 'a list', $message);
    }

    public function toBeBool(string $message = ''): self
    {
        return $this->typeCondition(is_bool($this->value), 'a bool', $message);
    }

    public function toBeCallable(string $message = ''): self
    {
        return $this->typeCondition(is_callable($this->value), 'callable', $message);
    }

    public function toBeFloat(string $message = ''): self
    {
        return $this->typeCondition(is_float($this->value), 'a float', $message);
    }

    public function toBeInt(string $message = ''): self
    {
        return $this->typeCondition(is_int($this->value), 'an int', $message);
    }

    public function toBeIterable(string $message = ''): self
    {
        return $this->typeCondition(is_iterable($this->value), 'iterable', $message);
    }

    public function toBeNumeric(string $message = ''): self
    {
        return $this->typeCondition(is_numeric($this->value), 'numeric', $message);
    }

    public function toBeDigits(string $message = ''): self
    {
        return $this->pattern('/^[0-9]+$/D', 'digits', $message);
    }

    public function toBeObject(string $message = ''): self
    {
        return $this->typeCondition(is_object($this->value), 'an object', $message);
    }

    public function toBeResource(string $message = ''): self
    {
        return $this->typeCondition(is_resource($this->value), 'a resource', $message);
    }

    public function toBeScalar(string $message = ''): self
    {
        return $this->typeCondition(is_scalar($this->value), 'scalar', $message);
    }

    public function toBeString(string $message = ''): self
    {
        return $this->typeCondition(is_string($this->value), 'a string', $message);
    }

    public function toBeJson(string $message = ''): self
    {
        $string = is_string($this->value);

        return $this->assertChecks([
            [$string, sprintf('Failed asserting that %s is a string.', $this->export($this->value))],
            [$string && json_validate($this->value), sprintf('Failed asserting that %s is valid JSON.', $this->export($this->value))],
        ], sprintf('Failed asserting that %s is not valid JSON.', $this->export($this->value)), $message);
    }

    public function toBeNan(string $message = ''): self
    {
        return $this->typeCondition(is_float($this->value) && is_nan($this->value), 'NAN', $message);
    }

    public function toHaveKey(
        string|int $key,
        mixed $expected = MissingExpectationValue::Value,
        string $message = '',
    ): self {
        [$found, $actual] = $this->lookupKey($key);
        $checks = [
            [$found, sprintf('Failed asserting that %s has key %s.', $this->export($this->value), $this->export($key))],
        ];

        if (! $expected instanceof MissingExpectationValue) {
            $checks[] = [
                $found && $actual == $expected,
                sprintf('Failed asserting that key %s equals %s.', $this->export($key), $this->export($expected)),
            ];
        }

        return $this->assertChecks(
            $checks,
            sprintf('Failed asserting that %s does not have matching key %s.', $this->export($this->value), $this->export($key)),
            $message,
        );
    }

    /** @param array<array-key, mixed> $keys */
    public function toHaveKeys(array $keys, string $message = ''): self
    {
        foreach ($this->flattenKeys($keys) as $key) {
            new self($this->value, $this->negated)->toHaveKey($key, message: $message);
        }

        return new self($this->value);
    }

    public function toHaveSnakeCaseKeys(string $message = ''): self
    {
        return $this->caseKeys('/^[\p{Ll}_]+$/uD', 'snake_case', $message);
    }

    public function toHaveKebabCaseKeys(string $message = ''): self
    {
        return $this->caseKeys('/^[\p{Ll}-]+$/uD', 'kebab-case', $message);
    }

    public function toHaveCamelCaseKeys(string $message = ''): self
    {
        return $this->caseKeys('/^\p{Ll}[\p{Ll}\p{Lu}]+$/uD', 'camelCase', $message);
    }

    public function toHaveStudlyCaseKeys(string $message = ''): self
    {
        return $this->caseKeys('/^\p{Lu}+\p{Ll}[\p{Ll}\p{Lu}]+$/uD', 'StudlyCase', $message);
    }

    public function toBeDirectory(string $message = ''): self
    {
        $path = $this->stringValue();

        return $this->fileCondition(is_dir($path), 'a directory', $path, $message);
    }

    public function toBeReadableDirectory(string $message = ''): self
    {
        $path = $this->stringValue();

        return $this->fileAccessCondition(is_dir($path), is_readable($path), 'a readable directory', $path, $message);
    }

    public function toBeWritableDirectory(string $message = ''): self
    {
        $path = $this->stringValue();

        return $this->fileAccessCondition(is_dir($path), is_writable($path), 'a writable directory', $path, $message);
    }

    public function toBeFile(string $message = ''): self
    {
        $path = $this->stringValue();

        return $this->fileCondition(is_file($path), 'a file', $path, $message);
    }

    public function toBeReadableFile(string $message = ''): self
    {
        $path = $this->stringValue();

        return $this->fileAccessCondition(is_file($path), is_readable($path), 'a readable file', $path, $message);
    }

    public function toBeWritableFile(string $message = ''): self
    {
        $path = $this->stringValue();

        return $this->fileAccessCondition(is_file($path), is_writable($path), 'a writable file', $path, $message);
    }

    /** @param iterable<int|string, mixed> $expected */
    public function toMatchArray(iterable $expected, string $message = ''): self
    {
        $actual = is_object($this->value) && method_exists($this->value, 'toArray')
            ? $this->value->toArray()
            : (array) $this->value;
        $checks = [];

        foreach ($expected as $key => $value) {
            $found = array_key_exists($key, $actual);
            $checks[] = [$found, sprintf('Failed asserting that array has key %s.', $this->export($key))];
            $checks[] = [
                $found && $actual[$key] == $value,
                sprintf('Failed asserting that key %s equals %s.', $this->export($key), $this->export($value)),
            ];
        }

        return $this->assertChecks(
            $checks,
            sprintf('Failed asserting that %s does not match the expected array subset.', $this->export($this->value)),
            $message,
        );
    }

    /** @param object|iterable<string, mixed> $expected */
    public function toMatchObject(object|iterable $expected, string $message = ''): self
    {
        if (! is_object($this->value) && ! is_string($this->value)) {
            throw new InvalidArgumentException('Native object subset expectations require an object or class string.');
        }

        $properties = is_iterable($expected)
            ? $this->iterableArray($expected, preserveKeys: true)
            : (array) $expected;
        $checks = [];

        foreach ($properties as $property => $value) {
            $found = property_exists($this->value, (string) $property);
            $actual = $found ? $this->value->{$property} : null;
            $checks[] = [$found, sprintf('Failed asserting that object has property %s.', $this->export($property))];
            $checks[] = [
                $found && $actual == $value,
                sprintf('Failed asserting that property %s equals %s.', $this->export($property), $this->export($value)),
            ];
        }

        return $this->assertChecks(
            $checks,
            sprintf('Failed asserting that %s does not match the expected object subset.', $this->export($this->value)),
            $message,
        );
    }

    public function toMatch(string $expression, string $message = ''): self
    {
        $value = $this->stringValue();
        $matches = @preg_match($expression, $value);

        if ($matches === false) {
            throw new InvalidArgumentException('Native match expectations require a valid regular expression.');
        }

        return $this->assertCondition(
            $matches === 1,
            sprintf('Failed asserting that %s matches %s.', $this->export($value), $this->export($expression)),
            sprintf('Failed asserting that %s does not match %s.', $this->export($value), $this->export($expression)),
            $message,
        );
    }

    public function toHaveLineCountLessThan(int $lines, string $message = ''): self
    {
        if ($lines < 1) {
            throw new InvalidArgumentException('Native line-count expectations require a positive limit.');
        }

        $path = $this->classFile();
        $contents = file($path);

        return $this->assertCondition(
            is_array($contents) && count($contents) < $lines,
            sprintf('Failed asserting that %s has fewer than %d lines.', $this->export($this->value), $lines),
            sprintf('Failed asserting that %s does not have fewer than %d lines.', $this->export($this->value), $lines),
            $message,
        );
    }

    public function toHaveMethodsDocumented(string $message = ''): self
    {
        $reflection = $this->classReflection();
        $documented = array_all(
            array_filter(
                $reflection->getMethods(),
                static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $reflection->getName(),
            ),
            static fn (ReflectionMethod $method): bool => $method->getDocComment() !== false,
        );

        return $this->assertCondition(
            $documented,
            sprintf('Failed asserting that %s has documented methods.', $reflection->getName()),
            sprintf('Failed asserting that %s has undocumented methods.', $reflection->getName()),
            $message,
        );
    }

    public function toHavePropertiesDocumented(string $message = ''): self
    {
        $reflection = $this->classReflection();
        $documented = array_all(
            array_filter(
                $reflection->getProperties(),
                static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $reflection->getName()
                    && ! $property->isPromoted(),
            ),
            static fn (ReflectionProperty $property): bool => $property->getDocComment() !== false,
        );

        return $this->assertCondition(
            $documented,
            sprintf('Failed asserting that %s has documented properties.', $reflection->getName()),
            sprintf('Failed asserting that %s has undocumented properties.', $reflection->getName()),
            $message,
        );
    }

    /** @param array<int, string>|string $methods */
    public function toHavePublicMethodsBesides(array|string $methods, string $message = ''): self
    {
        return $this->methodsBesides(ReflectionMethod::IS_PUBLIC, $methods, 'public', $message);
    }

    public function toHavePublicMethods(string $message = ''): self
    {
        return $this->toHavePublicMethodsBesides([], $message);
    }

    /** @param array<int, string>|string $methods */
    public function toHaveProtectedMethodsBesides(array|string $methods, string $message = ''): self
    {
        return $this->methodsBesides(ReflectionMethod::IS_PROTECTED, $methods, 'protected', $message);
    }

    public function toHaveProtectedMethods(string $message = ''): self
    {
        return $this->toHaveProtectedMethodsBesides([], $message);
    }

    /** @param array<int, string>|string $methods */
    public function toHavePrivateMethodsBesides(array|string $methods, string $message = ''): self
    {
        return $this->methodsBesides(ReflectionMethod::IS_PRIVATE, $methods, 'private', $message);
    }

    public function toHavePrivateMethods(string $message = ''): self
    {
        return $this->toHavePrivateMethodsBesides([], $message);
    }

    public function toUseStrictTypes(string $message = ''): self
    {
        $source = file_get_contents($this->classFile());
        $strict = is_string($source) && preg_match(
            '/^<\?php\s*(?:\/\*[\s\S]*?\*\/|\/\/[^\r\n]*(?:\r?\n|$)|\s)*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/m',
            $source,
        ) === 1;

        return $this->assertCondition(
            $strict,
            sprintf('Failed asserting that %s uses strict types.', $this->export($this->value)),
            sprintf('Failed asserting that %s does not use strict types.', $this->export($this->value)),
            $message,
        );
    }

    public function toUseTrait(string $trait, string $message = ''): self
    {
        $uses = $this->usedTraits($this->classReflection());

        return $this->assertCondition(
            trait_exists($trait) && isset($uses[$trait]),
            sprintf('Failed asserting that %s uses trait %s.', $this->export($this->value), $trait),
            sprintf('Failed asserting that %s does not use trait %s.', $this->export($this->value), $trait),
            $message,
        );
    }

    /** @param class-string $class */
    public function toContainOnlyInstancesOf(string $class, string $message = ''): self
    {
        if (! is_iterable($this->value)) {
            throw new InvalidArgumentException('Native instance collection expectations require an iterable value.');
        }

        $matches = true;

        foreach ($this->value as $value) {
            if (! is_object($value) || ! $value instanceof $class) {
                $matches = false;
                break;
            }
        }

        return $this->assertCondition(
            $matches,
            sprintf('Failed asserting that iterable contains only instances of %s.', $class),
            sprintf('Failed asserting that iterable does not contain only instances of %s.', $class),
            $message,
        );
    }

    public function toThrow(
        callable|string|Throwable $exception,
        ?string $exceptionMessage = null,
        string $message = '',
    ): self {
        if (! is_callable($this->value)) {
            throw new InvalidArgumentException('Native throw expectations require a callable value.');
        }

        $callback = null;
        $expectedClass = null;
        $expectedMessage = $exceptionMessage;
        $messageOnly = false;
        $exactMessage = false;

        if ($exception instanceof Closure) {
            $callback = $exception;
            $parameters = new ReflectionFunction($exception)->getParameters();

            if (count($parameters) !== 1) {
                throw new InvalidArgumentException('The given closure must have a single parameter type-hinted as the class string.');
            }

            $type = $parameters[0]->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new InvalidArgumentException('The given closure\'s parameter must be type-hinted as the class string.');
            }

            $expectedClass = $type->getName();
        } elseif ($exception instanceof Throwable) {
            $expectedClass = $exception::class;
            $expectedMessage ??= $exception->getMessage();
            $exactMessage = true;
        } elseif (is_string($exception) && is_a($exception, Throwable::class, true)) {
            $expectedClass = $exception;
        } elseif (is_string($exception)) {
            $expectedMessage = $exception;
            $messageOnly = true;
        }

        $caught = null;

        try {
            call_user_func($this->value);
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        if (! $caught instanceof Throwable) {
            return $this->assertCondition(
                false,
                $messageOnly
                    ? sprintf('Exception with message "%s" not thrown.', $expectedMessage)
                    : sprintf('Exception "%s" not thrown.', $expectedClass),
                'Failed asserting that callable throws no matching exception.',
                $message,
            );
        }

        if ($messageOnly
            && $caught instanceof \Error
            && $caught->getMessage() === sprintf('Class "%s" not found', $exception)) {
            TestContext::recordAssertion();
            throw $caught;
        }

        $checks = [];

        if ($exception instanceof Throwable) {
            $checks[] = [
                $caught instanceof ($exception::class),
                sprintf(
                    'Failed asserting that an instance of class %s is an instance of class %s.',
                    $caught::class,
                    $expectedClass,
                ),
            ];
        }

        if ($expectedMessage !== null) {
            $checks[] = [
                $exactMessage
                    ? $caught->getMessage() === $expectedMessage
                    : str_contains($caught->getMessage(), $expectedMessage),
                $this->stringContainsFailure($caught->getMessage(), $expectedMessage),
            ];
        }

        if ($expectedClass !== null && ! $exception instanceof Throwable) {
            $checks[] = [
                $caught instanceof $expectedClass,
                sprintf(
                    'Failed asserting that an instance of class %s is an instance of class %s.',
                    $caught::class,
                    $expectedClass,
                ),
            ];
        }

        $result = $this->assertChecks(
            $checks,
            sprintf('Failed asserting that callable does not throw %s.', $expectedClass ?? $this->export($expectedMessage)),
            $message,
        );

        if ($callback instanceof Closure && ! $this->negated) {
            $callback($caught);
        }

        return $result;
    }

    public function toBeUppercase(string $message = ''): self
    {
        return $this->pattern('/^\p{Lu}+$/uD', 'uppercase', $message);
    }

    public function toBeLowercase(string $message = ''): self
    {
        return $this->pattern('/^\p{Ll}+$/uD', 'lowercase', $message);
    }

    public function toBeAlphaNumeric(string $message = ''): self
    {
        return $this->pattern('/^[A-Za-z0-9]+$/D', 'alphanumeric', $message);
    }

    public function toBeAlpha(string $message = ''): self
    {
        return $this->pattern('/^[A-Za-z]+$/D', 'alpha', $message);
    }

    public function toBeSnakeCase(string $message = ''): self
    {
        return $this->pattern('/^[\p{Ll}_]+$/uD', 'snake_case', $message);
    }

    public function toBeKebabCase(string $message = ''): self
    {
        return $this->pattern('/^[\p{Ll}-]+$/uD', 'kebab-case', $message);
    }

    public function toBeCamelCase(string $message = ''): self
    {
        return $this->pattern('/^\p{Ll}[\p{Ll}\p{Lu}]+$/uD', 'camelCase', $message);
    }

    public function toBeStudlyCase(string $message = ''): self
    {
        return $this->pattern('/^\p{Lu}+\p{Ll}[\p{Ll}\p{Lu}]+$/uD', 'StudlyCase', $message);
    }

    public function toBeUuid(string $message = ''): self
    {
        return $this->stringPattern(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
            'a UUID',
            $message,
        );
    }

    public function toBeUlid(string $message = ''): self
    {
        return $this->stringPattern('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', 'a ULID', $message);
    }

    public function toBeEmail(string $message = ''): self
    {
        $value = (string) $this->value;

        return $this->assertCondition(
            filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            sprintf('Failed asserting that %s is an email address.', $value),
            sprintf('Failed asserting that %s is not an email address.', $value),
            $message,
        );
    }

    public function toBeUrl(string $message = ''): self
    {
        $value = (string) $this->value;

        return $this->assertCondition(
            filter_var($value, FILTER_VALIDATE_URL) !== false,
            sprintf('Failed asserting that %s is a url.', $value),
            sprintf('Failed asserting that %s is not a url.', $value),
            $message,
        );
    }

    public function toBeSlug(string $message = ''): self
    {
        $value = (string) $this->value;
        $valid = preg_match('/[\pL\pN]/u', $value) === 1;

        return $this->assertCondition(
            $valid,
            sprintf('Failed asserting that %s can be converted to a slug.', $value),
            sprintf('Failed asserting that %s cannot be converted to a slug.', $value),
            $message,
        );
    }

    public function toBeIpAddress(string $message = ''): self
    {
        $value = $this->stringValue();

        return $this->stringFilter($value, filter_var($value, FILTER_VALIDATE_IP) !== false, 'an IP address', $message);
    }

    public function toBeMacAddress(string $message = ''): self
    {
        $value = $this->stringValue();

        return $this->stringFilter($value, filter_var($value, FILTER_VALIDATE_MAC) !== false, 'a MAC address', $message);
    }

    public function toBeHostname(string $message = ''): self
    {
        $value = $this->stringValue();

        return $this->stringFilter(
            $value,
            filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false,
            'a hostname',
            $message,
        );
    }

    public function toBeDomain(string $message = ''): self
    {
        $value = $this->stringValue();

        return $this->stringFilter(
            $value,
            str_contains($value, '.')
                && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false,
            'a domain',
            $message,
        );
    }

    public function toBeBase64(string $message = ''): self
    {
        $value = $this->stringValue();
        $decoded = base64_decode($value, true);

        return $this->stringFilter(
            $value,
            $decoded !== false && base64_encode($decoded) === $value,
            'base64-encoded',
            $message,
        );
    }

    public function toBeHexadecimal(string $message = ''): self
    {
        return $this->stringPattern('/^[0-9a-f]+$/iD', 'hexadecimal', $message);
    }

    /** @param list<array{bool, string}> $checks */
    private function assertChecks(
        array $checks,
        string $negatedFailure,
        string $message = '',
        int $assertionsPerCheck = 1,
    ): self {
        foreach ($checks as [$passes, $failure]) {
            for ($assertion = 0; $assertion < $assertionsPerCheck; $assertion++) {
                TestContext::recordAssertion();
            }

            if ($passes) {
                continue;
            }

            if ($this->negated) {
                return new self($this->value);
            }

            throw new AssertionFailed($message !== '' ? $message : $failure);
        }

        if ($this->negated) {
            throw new AssertionFailed($message !== '' ? $message : $negatedFailure);
        }

        return new self($this->value);
    }

    private function assertCondition(
        bool $passes,
        string $failure,
        string $negatedFailure,
        string $message = '',
    ): self {
        return $this->assertChecks([[$passes, $failure]], $negatedFailure, $message);
    }

    private function comparison(bool $passes, string $comparison, mixed $expected, string $message): self
    {
        return $this->assertCondition(
            $passes,
            sprintf('Failed asserting that %s is %s %s.', $this->export($this->value), $comparison, $this->export($expected)),
            sprintf('Failed asserting that %s is not %s %s.', $this->export($this->value), $comparison, $this->export($expected)),
            $message,
        );
    }

    private function typeCondition(bool $passes, string $type, string $message): self
    {
        return $this->assertCondition(
            $passes,
            sprintf('Failed asserting that %s is %s.', $this->export($this->value), $type),
            sprintf('Failed asserting that %s is not %s.', $this->export($this->value), $type),
            $message,
        );
    }

    private function fileCondition(bool $passes, string $type, string $path, string $message): self
    {
        return $this->assertCondition(
            $passes,
            sprintf('Failed asserting that %s is %s.', $this->export($path), $type),
            sprintf('Failed asserting that %s is not %s.', $this->export($path), $type),
            $message,
        );
    }

    private function fileAccessCondition(
        bool $exists,
        bool $accessible,
        string $type,
        string $path,
        string $message,
    ): self {
        return $this->assertChecks([
            [$exists, sprintf('Failed asserting that %s exists.', $this->export($path))],
            [$accessible, sprintf('Failed asserting that %s is %s.', $this->export($path), $type)],
        ], sprintf('Failed asserting that %s is not %s.', $this->export($path), $type), $message);
    }

    private function pattern(string $pattern, string $description, string $message): self
    {
        $value = (string) $this->value;

        return $this->assertCondition(
            preg_match($pattern, $value) === 1,
            sprintf('Failed asserting that %s is %s.', $this->export($value), $description),
            sprintf('Failed asserting that %s is not %s.', $this->export($value), $description),
            $message,
        );
    }

    private function stringPattern(string $pattern, string $description, string $message): self
    {
        $value = $this->stringValue();

        return $this->assertCondition(
            preg_match($pattern, $value) === 1,
            sprintf('Failed asserting that %s is %s.', $this->export($value), $description),
            sprintf('Failed asserting that %s is not %s.', $this->export($value), $description),
            $message,
        );
    }

    /** @return ReflectionClass<object> */
    private function classReflection(): ReflectionClass
    {
        if (! is_string($this->value)
            || (! class_exists($this->value) && ! trait_exists($this->value) && ! interface_exists($this->value))) {
            throw new InvalidArgumentException('Native architecture expectations require a class, trait, or interface string.');
        }

        return new ReflectionClass($this->value);
    }

    private function classFile(): string
    {
        $path = $this->classReflection()->getFileName();

        if (! is_string($path) || ! is_file($path)) {
            throw new InvalidArgumentException('Native architecture expectations require a user-defined source file.');
        }

        return $path;
    }

    /** @param array<int, string>|string $allowed */
    private function methodsBesides(int $filter, array|string $allowed, string $visibility, string $message): self
    {
        $reflection = $this->classReflection();
        $allowed = is_array($allowed) ? $allowed : [$allowed];
        $extra = array_filter(
            $reflection->getMethods($filter),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $reflection->getName()
                && ! in_array($method->getName(), $allowed, true),
        );

        return $this->assertCondition(
            $extra !== [],
            sprintf('Failed asserting that %s has %s methods besides the allowed list.', $reflection->getName(), $visibility),
            sprintf('Failed asserting that %s has no %s methods besides the allowed list.', $reflection->getName(), $visibility),
            $message,
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return array<class-string, true>
     */
    private function usedTraits(ReflectionClass $reflection): array
    {
        $traits = [];

        do {
            foreach ($reflection->getTraits() as $trait) {
                $traits[$trait->getName()] = true;
                $traits += $this->usedTraits($trait);
            }

            $reflection = $reflection->getParentClass();
        } while ($reflection instanceof ReflectionClass);

        return $traits;
    }

    private function stringFilter(string $value, bool $passes, string $description, string $message): self
    {
        return $this->assertCondition(
            $passes,
            sprintf('Failed asserting that %s is %s.', $this->export($value), $description),
            sprintf('Failed asserting that %s is not %s.', $this->export($value), $description),
            $message,
        );
    }

    private function stringValue(): string
    {
        if (! is_string($this->value)) {
            InvalidExpectationValue::expected('string');
        }

        return $this->value;
    }

    private function contains(mixed $needle, bool $strict): bool
    {
        if (is_string($this->value)) {
            return str_contains($this->value, (string) $needle);
        }

        if (! is_iterable($this->value)) {
            throw new InvalidArgumentException('Native contain expectations require a string or iterable value.');
        }

        foreach ($this->value as $value) {
            if ($strict ? $value === $needle : $value == $needle) {
                return true;
            }
        }

        return false;
    }

    private function size(mixed $value, bool $allowObject = false): int
    {
        if (is_countable($value)) {
            return count($value);
        }

        if (is_iterable($value)) {
            $count = 0;

            foreach ($value as $_) {
                $count++;
            }

            return $count;
        }

        if ($allowObject && is_object($value)) {
            $array = method_exists($value, 'toArray') ? $value->toArray() : (array) $value;

            return count($array);
        }

        InvalidExpectationValue::expected('countable|iterable');
    }

    private function stringLength(string $value): int
    {
        $length = preg_match_all('/./us', $value);

        return $length === false ? strlen($value) : $length;
    }

    private function stringContainsFailure(string $actual, string $expected): string
    {
        return sprintf(
            'Failed asserting that \'%s\' [ASCII](length: %d) contains "%s" [ASCII](length: %d).',
            str_replace(['\\', '\''], ['\\\\', '\\\''], $actual),
            strlen($actual),
            str_replace(['\\', '"'], ['\\\\', '\\"'], $expected),
            strlen($expected),
        );
    }

    private function caseKeys(string $pattern, string $description, string $message): self
    {
        if (! is_iterable($this->value)) {
            InvalidExpectationValue::expected('iterable');
        }

        return $this->assertChecks(
            $this->caseKeyChecks($this->value, $pattern, $description),
            sprintf('Failed asserting that all iterable keys are not %s.', $description),
            $message,
        );
    }

    /**
     * @param  iterable<array-key, mixed>  $values
     * @return list<array{bool, string}>
     */
    private function caseKeyChecks(iterable $values, string $pattern, string $description): array
    {
        $checks = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $checks[] = [
                    preg_match($pattern, $key) === 1,
                    sprintf('Failed asserting that key %s is %s.', $this->export($key), $description),
                ];
            }

            if (is_array($value)) {
                array_push($checks, ...$this->caseKeyChecks($value, $pattern, $description));
            }
        }

        return $checks;
    }

    /** @return array{bool, mixed} */
    private function lookupKey(string|int $key): array
    {
        $value = is_object($this->value) && method_exists($this->value, 'toArray')
            ? $this->value->toArray()
            : (array) $this->value;

        if (array_key_exists($key, $value)) {
            return [true, $value[$key]];
        }

        if (! is_string($key) || ! str_contains($key, '.')) {
            return [false, null];
        }

        $current = $value;

        foreach (explode('.', $key) as $segment) {
            if (is_object($current) && method_exists($current, 'toArray')) {
                $current = $current->toArray();
            }

            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * @param  array<array-key, mixed>  $keys
     * @return list<int|string>
     */
    private function flattenKeys(array $keys, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($keys as $index => $key) {
            if (is_array($key)) {
                array_push($flattened, ...$this->flattenKeys($key, $prefix.$index.'.'));

                continue;
            }

            if (! is_int($key) && ! is_string($key)) {
                throw new InvalidArgumentException('Native key lists require integer or string keys.');
            }

            $flattened[] = $prefix.(is_int($index) ? $key : $index);
        }

        return $flattened;
    }

    /**
     * @param  iterable<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function iterableArray(iterable $value, bool $preserveKeys): array
    {
        return is_array($value) ? $value : iterator_to_array($value, $preserveKeys);
    }

    private function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->canonical(...), $value);
        usort($value, static fn (mixed $left, mixed $right): int => serialize($left) <=> serialize($right));

        return $value;
    }

    private function export(mixed $value): string
    {
        if (is_object($value)) {
            return $value::class;
        }

        if (is_resource($value)) {
            return get_debug_type($value);
        }

        return var_export($value, true);
    }
}

enum MissingExpectationValue
{
    case Value;
}

enum AnyExpectationValue
{
    case Value;
}
