<?php

declare(strict_types=1);

namespace Drove\Pest;

use Closure;
use InvalidArgumentException;
use OutOfBoundsException;
use Pest\Contracts\HasPrintableTestCaseName;
use Pest\Factories\TestCaseFactory;
use ReflectionClass;
use ReflectionFunction;
use RuntimeException;

/**
 * @internal
 */
final class ScopeCompiler
{
    private static ?self $active = null;

    /** @var array<string, array{id: string, type: string, path: string, tests: list<array{id: string, name: string, scope: list<string>, source: array{path: string, line: int}}>}> */
    private array $plans = [];

    /** @var array<string, Closure> */
    private array $closures = [];

    private function __construct(private readonly string $rootPath)
    {
        //
    }

    public static function activate(string $rootPath): self
    {
        $rootPath = realpath($rootPath);

        if ($rootPath === false) {
            throw new InvalidArgumentException('The Drove root path does not exist.');
        }

        return self::$active = new self(str_replace('\\', '/', $rootPath));
    }

    public static function capture(?TestCaseFactory $factory): void
    {
        if (self::$active instanceof self && $factory instanceof TestCaseFactory) {
            self::$active->compile($factory);
        }
    }

    /**
     * @return array{id: string, type: string, path: string, tests: list<array{id: string, name: string, scope: list<string>, source: array{path: string, line: int}}>}
     */
    public function plan(string $filename): array
    {
        $filename = $this->canonicalPath($filename);

        return $this->plans[$filename] ?? throw new OutOfBoundsException(sprintf(
            'No Drove scope plan was captured for %s.',
            $filename,
        ));
    }

    public function closure(string $testId): Closure
    {
        return $this->closures[$testId] ?? throw new OutOfBoundsException(sprintf(
            'No Pest closure was captured for %s.',
            $testId,
        ));
    }

    private function compile(TestCaseFactory $factory): void
    {
        $filename = $this->canonicalPath($factory->filename);

        if (isset($this->plans[$filename])) {
            return;
        }

        // ponytail: scan declared Pest cases until TestCaseFactory exposes generation state.
        foreach (get_declared_classes() as $class) {
            if (! is_subclass_of($class, HasPrintableTestCaseName::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->hasProperty('__filename')
                && $this->canonicalPath((string) $reflection->getStaticPropertyValue('__filename')) === $filename) {
                throw new RuntimeException('Drove Scope IR must be captured before Pest generates its TestCase.');
            }
        }

        $path = $this->relativePath($filename);
        $tests = [];

        foreach ($factory->methods as $method) {
            if ($method->description === null || ! $method->closure instanceof Closure || $method->receivesArguments()) {
                throw new RuntimeException('Drove file plans only support explicit Pest closures without arguments.');
            }

            $source = new ReflectionFunction($method->closure);

            if ($this->canonicalPath((string) $source->getFileName()) !== $filename) {
                throw new RuntimeException('Drove file plans require test closures declared in their test file.');
            }

            $id = 'test:'.$path.'::'.rawurlencode($method->description);

            if (isset($this->closures[$id])) {
                throw new RuntimeException(sprintf('Duplicate Drove test ID %s.', $id));
            }

            $this->closures[$id] = $method->closure;
            $tests[] = [
                'id' => $id,
                'name' => $method->description,
                'scope' => array_map(strval(...), $method->describing),
                'source' => [
                    'path' => $this->relativePath((string) $source->getFileName()),
                    'line' => $source->getStartLine(),
                ],
            ];
        }

        $this->plans[$filename] = [
            'id' => 'file:'.$path,
            'type' => 'file',
            'path' => $path,
            'tests' => $tests,
        ];
    }

    private function canonicalPath(string $path): string
    {
        $path = realpath($path);

        if ($path === false) {
            throw new InvalidArgumentException('A Drove scope path does not exist.');
        }

        return str_replace('\\', '/', $path);
    }

    private function relativePath(string $path): string
    {
        $path = $this->canonicalPath($path);
        $prefix = rtrim($this->rootPath, '/').'/';

        if (! str_starts_with($path, $prefix)) {
            throw new InvalidArgumentException(sprintf('%s is outside the Drove root.', $path));
        }

        return substr($path, strlen($prefix));
    }
}
