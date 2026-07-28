<?php

declare(strict_types=1);

namespace Drove\Pest;

use Closure;
use InvalidArgumentException;
use OutOfBoundsException;
use Pest\Contracts\HasPrintableTestCaseName;
use Pest\Factories\TestCaseFactory;
use Pest\Support\Description;
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

    /** @var array<string, list<array{id: string, name: string, parent: string, description: Description}>> */
    private array $scopes = [];

    /** @var array<string, list<array{id: string, phase: string, scope: string}>> */
    private array $hooks = [];

    /** @var array<string, Closure> */
    private array $hookClosures = [];

    /** @var array<string, array<string, mixed>> */
    private array $scopePlans = [];

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
     * @param  list<Description>  $describing
     */
    public static function captureScope(
        string $filename,
        Description $description,
        array $describing,
    ): void {
        self::$active?->registerScope($filename, $description, $describing);
    }

    /**
     * @param  list<Description>  $describing
     */
    public static function captureHook(
        string $phase,
        string $filename,
        Closure $hook,
        array $describing,
    ): void {
        self::$active?->registerHook($phase, $filename, $hook, $describing);
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

    /**
     * @return array<string, mixed>
     */
    public function scopePlan(string $filename): array
    {
        $filename = $this->canonicalPath($filename);

        return $this->scopePlans[$filename] ?? throw new OutOfBoundsException(sprintf(
            'No Drove hierarchical scope plan was captured for %s.',
            $filename,
        ));
    }

    public function hook(string $hookId): Closure
    {
        return $this->hookClosures[$hookId] ?? throw new OutOfBoundsException(sprintf(
            'No Pest hook was captured for %s.',
            $hookId,
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
        $placements = [];

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
            $test = [
                'id' => $id,
                'name' => $method->description,
                'scope' => array_map(strval(...), $method->describing),
                'source' => [
                    'path' => $this->relativePath((string) $source->getFileName()),
                    'line' => $source->getStartLine(),
                ],
            ];
            $tests[] = $test;
            $scopeIds = ['file:'.$path];

            foreach ($method->describing as $description) {
                $scopeIds[] = $this->scopeId($filename, $description);
            }

            $placements[] = [
                'test' => ['id' => $test['id'], 'name' => $test['name']],
                'scopes' => $scopeIds,
            ];
        }

        $this->plans[$filename] = [
            'id' => 'file:'.$path,
            'type' => 'file',
            'path' => $path,
            'tests' => $tests,
        ];
        $this->scopePlans[$filename] = $this->scopeNode(
            $filename,
            'file:'.$path,
            'file',
            $path,
            $placements,
        );
    }

    /**
     * @param  list<Description>  $describing
     */
    private function registerScope(
        string $filename,
        Description $description,
        array $describing,
    ): void {
        $filename = $this->canonicalPath($filename);
        $path = $this->relativePath($filename);
        $fileId = 'file:'.$path;
        $parent = $describing === []
            ? $fileId
            : $this->scopeId($filename, $describing[array_key_last($describing)]);
        $siblings = array_filter(
            $this->scopes[$filename] ?? [],
            static fn (array $scope): bool => $scope['parent'] === $parent,
        );
        $ordinal = count($siblings);
        $parentOrdinal = $parent === $fileId
            ? ''
            : substr($parent, strlen('scope:'.$path.'::')).'.';

        $this->scopes[$filename][] = [
            'id' => 'scope:'.$path.'::'.$parentOrdinal.$ordinal,
            'name' => (string) $description,
            'parent' => $parent,
            'description' => $description,
        ];
    }

    /**
     * @param  list<Description>  $describing
     */
    private function registerHook(
        string $phase,
        string $filename,
        Closure $hook,
        array $describing,
    ): void {
        if (! in_array($phase, ['before_all', 'before_each', 'after_each', 'after_all'], true)) {
            throw new InvalidArgumentException(sprintf('Unknown Drove hook phase %s.', $phase));
        }

        $filename = $this->canonicalPath($filename);
        $scope = $describing === []
            ? 'file:'.$this->relativePath($filename)
            : $this->scopeId($filename, $describing[array_key_last($describing)]);
        $ordinal = count(array_filter(
            $this->hooks[$filename] ?? [],
            static fn (array $registered): bool => $registered['scope'] === $scope
                && $registered['phase'] === $phase,
        ));
        $id = sprintf('hook:%s::%s:%d', $scope, $phase, $ordinal);

        $this->hooks[$filename][] = ['id' => $id, 'phase' => $phase, 'scope' => $scope];
        $this->hookClosures[$id] = $hook;
    }

    private function scopeId(string $filename, Description $description): string
    {
        foreach ($this->scopes[$filename] ?? [] as $scope) {
            if ($scope['description'] === $description) {
                return $scope['id'];
            }
        }

        throw new RuntimeException('Drove encountered an unregistered describe scope.');
    }

    /**
     * @param  list<array{test: array{id: string, name: string}, scopes: list<string>}>  $placements
     * @return array<string, mixed>
     */
    private function scopeNode(
        string $filename,
        string $id,
        string $type,
        string $name,
        array $placements,
    ): array {
        $hooks = [];

        foreach (['before_all', 'before_each', 'after_each', 'after_all'] as $phase) {
            $hooks[$phase] = $this->hookIds($filename, $id, $phase);
        }

        $tests = [];

        foreach ($placements as $placement) {
            if ($placement['scopes'][array_key_last($placement['scopes'])] !== $id) {
                continue;
            }

            $beforeEach = [];

            foreach ($placement['scopes'] as $scope) {
                array_push($beforeEach, ...$this->hookIds($filename, $scope, 'before_each'));
            }

            $afterEach = [];

            foreach (array_reverse($placement['scopes']) as $scope) {
                array_push($afterEach, ...$this->hookIds($filename, $scope, 'after_each'));
            }

            $tests[] = $placement['test'] + [
                'before_each' => $beforeEach,
                'after_each' => $afterEach,
            ];
        }

        $children = [];

        // ponytail: linear scans are enough until real-suite hook IR makes indexing measurable.
        foreach ($this->scopes[$filename] ?? [] as $scope) {
            if ($scope['parent'] === $id) {
                $children[] = $this->scopeNode(
                    $filename,
                    $scope['id'],
                    'describe',
                    $scope['name'],
                    $placements,
                );
            }
        }

        $node = [
            'id' => $id,
            'type' => $type,
        ];
        $node[$type === 'file' ? 'path' : 'name'] = $name;
        $node['hooks'] = $hooks;
        $node['tests'] = $tests;
        $node['children'] = $children;

        return $node;
    }

    /**
     * @return list<string>
     */
    private function hookIds(string $filename, string $scope, string $phase): array
    {
        return array_values(array_map(
            static fn (array $hook): string => $hook['id'],
            array_filter(
                $this->hooks[$filename] ?? [],
                static fn (array $hook): bool => $hook['scope'] === $scope
                    && $hook['phase'] === $phase,
            ),
        ));
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
