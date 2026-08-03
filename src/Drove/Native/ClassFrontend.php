<?php

declare(strict_types=1);

namespace Drove\Native;

use Drove\Native\Attributes\DataProvider;
use Drove\Native\Attributes\Group;
use Drove\Native\Attributes\Test;
use Drove\Native\Attributes\TestClass;
use Drove\Native\Attributes\Throws;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class ClassFrontend
{
    /**
     * @param  list<class-string>  $classes
     */
    public function declare(array $classes): int
    {
        $declared = 0;
        $classByFile = [];

        foreach (array_values(array_unique($classes)) as $class) {
            if (! class_exists($class)) {
                throw new InvalidArgumentException(sprintf(
                    'Native class test %s is not loaded.',
                    $class,
                ));
            }

            $reflection = new ReflectionClass($class);
            $methods = $this->testMethods($reflection);

            if ($methods === []) {
                continue;
            }

            $path = $reflection->getFileName();

            if (! is_string($path)) {
                throw new RuntimeException(sprintf(
                    'Native class test %s has no source file.',
                    $reflection->getName(),
                ));
            }

            $path = $this->normalizePath($path);

            if (isset($classByFile[$path])) {
                throw new InvalidArgumentException(sprintf(
                    'Native class files may declare only one test class; %s contains %s and %s.',
                    $path,
                    $classByFile[$path],
                    $reflection->getName(),
                ));
            }

            $classByFile[$path] = $reflection->getName();

            $this->declareClass($reflection, $methods);
            $declared++;
        }

        return $declared;
    }

    /**
     * @param  list<string>  $files
     */
    public function declareFiles(array $files): int
    {
        $sources = [];

        foreach ($files as $file) {
            $source = realpath($file);

            if (! is_string($source) || ! is_file($source)) {
                throw new InvalidArgumentException(sprintf(
                    'Native class source %s does not exist.',
                    $file,
                ));
            }

            $sources[$this->normalizePath($source)] = true;
        }

        $classes = [];

        foreach (get_declared_classes() as $class) {
            $reflection = new ReflectionClass($class);
            $source = $reflection->getFileName();

            if (is_string($source)
                && isset($sources[$this->normalizePath($source)])
                && $reflection->getAttributes(TestClass::class) !== []) {
                $classes[] = $class;
            }
        }

        sort($classes, SORT_STRING);

        return $this->declare($classes);
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @param  list<ReflectionMethod>  $methods
     */
    private function declareClass(ReflectionClass $class, array $methods): void
    {
        if ($class->isAbstract()) {
            throw new InvalidArgumentException(sprintf(
                'Native class test %s cannot be abstract.',
                $class->getName(),
            ));
        }

        $constructor = $class->getConstructor();

        if ($constructor instanceof ReflectionMethod
            && $constructor->getNumberOfRequiredParameters() !== 0) {
            throw new InvalidArgumentException(sprintf(
                'Native class test %s requires a zero-argument constructor.',
                $class->getName(),
            ));
        }

        $path = $class->getFileName();

        if (! is_string($path)) {
            throw new RuntimeException(sprintf(
                'Native class test %s has no source file.',
                $class->getName(),
            ));
        }

        $beforeClass = $this->lifecycle($class, 'setUpBeforeClass', true);
        $afterClass = $this->lifecycle($class, 'tearDownAfterClass', true);
        $beforeEach = $this->lifecycle($class, 'setUp', false);
        $afterEach = $this->lifecycle($class, 'tearDown', false);
        $classGroups = $this->groups($class);
        $registry = Declarations::current();

        if ($beforeClass instanceof ReflectionMethod) {
            $registry->declareHook(
                'before_all',
                static fn (): mixed => $beforeClass->invoke(null),
                $path,
                $this->sourceLine($beforeClass),
            );
        }

        if ($afterClass instanceof ReflectionMethod) {
            $registry->declareHook(
                'after_all',
                static fn (): mixed => $afterClass->invoke(null),
                $path,
                $this->sourceLine($afterClass),
            );
        }

        foreach ($methods as $method) {
            $this->declareMethod(
                $registry,
                $class,
                $method,
                $beforeEach,
                $afterEach,
                $classGroups,
                $path,
            );
        }
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @param  list<string>  $classGroups
     */
    private function declareMethod(
        DeclarationRegistry $registry,
        ReflectionClass $class,
        ReflectionMethod $method,
        ?ReflectionMethod $beforeEach,
        ?ReflectionMethod $afterEach,
        array $classGroups,
        string $path,
    ): void {
        $throwsAttributes = $method->getAttributes(Throws::class);

        if (count($throwsAttributes) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Native class test %s::%s() may declare only one expected exception.',
                $class->getName(),
                $method->getName(),
            ));
        }

        $throws = $throwsAttributes === []
            ? null
            : $throwsAttributes[0]->newInstance();
        $definition = $registry->declareTest(
            $method->getName(),
            static function (...$arguments) use (
                $afterEach,
                $beforeEach,
                $class,
                $method,
            ): mixed {
                $instance = $class->newInstance();
                $initialized = false;

                try {
                    $beforeEach?->invoke($instance);
                    $initialized = true;

                    return $method->invokeArgs($instance, $arguments);
                } finally {
                    if ($initialized) {
                        $afterEach?->invoke($instance);
                    }
                }
            },
            $path,
            $this->sourceLine($method),
            $path,
        );
        $groups = array_values(array_unique([
            ...$classGroups,
            ...$this->groups($method),
        ]));

        if ($groups !== []) {
            $definition->group(...$groups);
        }

        if ($throws instanceof Throws) {
            $exception = $throws->class ?? $throws->message ?? $throws->code;

            if ($exception === null) {
                throw new RuntimeException('A native expected exception lost its constraints.');
            }

            $definition->throws($exception, $throws->message, $throws->code);
        }

        $providers = $method->getAttributes(DataProvider::class);

        if (count($providers) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Native class test %s::%s() may use only one data provider.',
                $class->getName(),
                $method->getName(),
            ));
        }

        if ($providers === []) {
            if ($method->getNumberOfRequiredParameters() !== 0) {
                throw new InvalidArgumentException(sprintf(
                    'Native class test %s::%s() requires a data provider.',
                    $class->getName(),
                    $method->getName(),
                ));
            }

            return;
        }

        $providerName = $providers[0]->newInstance()->method;

        if (! $class->hasMethod($providerName)) {
            throw new InvalidArgumentException(sprintf(
                'Native class data provider %s::%s() does not exist.',
                $class->getName(),
                $providerName,
            ));
        }

        $provider = $class->getMethod($providerName);

        if (! $provider->isPublic()
            || ! $provider->isStatic()
            || $provider->getNumberOfParameters() !== 0) {
            throw new InvalidArgumentException(sprintf(
                'Native class data provider %s::%s() must be public, static, and parameterless.',
                $class->getName(),
                $providerName,
            ));
        }

        $dataset = 'class:'.$class->getName().'::'.$method->getName();
        $registry->declareDataset(
            $dataset,
            static function () use ($class, $provider): iterable {
                $rows = $provider->invoke(null);

                if (! is_iterable($rows)) {
                    throw new InvalidArgumentException(sprintf(
                        'Native class data provider %s::%s() must return an iterable.',
                        $class->getName(),
                        $provider->getName(),
                    ));
                }

                return $rows;
            },
        );
        $definition->with($dataset);
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return list<ReflectionMethod>
     */
    private function testMethods(ReflectionClass $class): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            $marked = $method->getAttributes(Test::class) !== [];

            if (! $marked && ! str_starts_with($method->getName(), 'test')) {
                continue;
            }

            if (! $method->isPublic() || $method->isStatic() || $method->isAbstract()) {
                throw new InvalidArgumentException(sprintf(
                    'Native class test method %s::%s() must be public, concrete, and non-static.',
                    $class->getName(),
                    $method->getName(),
                ));
            }

            $methods[] = $method;
        }

        usort($methods, static fn (ReflectionMethod $left, ReflectionMethod $right): int => [
            $left->getFileName(),
            $left->getStartLine(),
            $left->getName(),
        ] <=> [
            $right->getFileName(),
            $right->getStartLine(),
            $right->getName(),
        ]);

        return $methods;
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function lifecycle(
        ReflectionClass $class,
        string $method,
        bool $static,
    ): ?ReflectionMethod {
        if (! $class->hasMethod($method)) {
            return null;
        }

        $lifecycle = $class->getMethod($method);

        if ($lifecycle->isPrivate()
            || $lifecycle->isStatic() !== $static
            || $lifecycle->getNumberOfParameters() !== 0) {
            throw new InvalidArgumentException(sprintf(
                'Native class lifecycle %s::%s() has an invalid signature.',
                $class->getName(),
                $method,
            ));
        }

        return $lifecycle;
    }

    /**
     * @param  ReflectionClass<object>|ReflectionMethod  $reflection
     * @return list<string>
     */
    private function groups(ReflectionClass|ReflectionMethod $reflection): array
    {
        return array_map(
            static fn (ReflectionAttribute $attribute): string => $attribute->newInstance()->name,
            $reflection->getAttributes(Group::class),
        );
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return preg_match('/^[A-Z]:/', $path) === 1
            ? strtolower($path[0]).substr($path, 1)
            : $path;
    }

    /** @param ReflectionClass<object>|ReflectionMethod $reflection */
    private function sourceLine(ReflectionClass|ReflectionMethod $reflection): int
    {
        $line = $reflection->getStartLine();

        if (! is_int($line)) {
            throw new RuntimeException('A native class declaration has no source line.');
        }

        return $line;
    }
}
