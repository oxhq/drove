<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use ReflectionFunction;
use Throwable;

final class DeclarationRegistry
{
    /**
     * @var array<string, array{
     *     id: string,
     *     type: 'file'|'describe',
     *     name?: string,
     *     path?: string,
     *     hooks: array{
     *         before_all: list<string>,
     *         before_each: list<string>,
     *         after_each: list<string>,
     *         after_all: list<string>
     *     },
     *     tests: list<array<string, mixed>>,
     *     children: list<string>
     * }>
     */
    private array $nodes = [];

    /** @var array<string, string> */
    private array $files = [];

    /** @var list<string> */
    private array $fileOrder = [];

    /** @var list<string> */
    private array $scopeStack = [];

    /** @var array<string, Closure> */
    private array $tests = [];

    /** @var array<string, Closure> */
    private array $hooks = [];

    private bool $poisoned = false;

    private readonly string $rootPath;

    public function __construct(
        string $rootPath,
        private readonly string $suiteName = 'Drove Native',
    ) {
        if ($suiteName === '') {
            throw new InvalidArgumentException('The native suite name cannot be empty.');
        }

        if ($rootPath === '' || ! is_dir($rootPath)) {
            throw new InvalidArgumentException('The native suite root must be an existing directory.');
        }

        $this->rootPath = $this->normalizePath($rootPath);
    }

    public function declareTest(
        string $description,
        Closure $body,
        string $sourcePath,
        int $sourceLine,
    ): void {
        $this->assertHealthy();
        $this->assertDescription($description, 'test');
        $this->assertNoParameters($body, 'test');
        $sourcePath = $this->canonicalPath($sourcePath);
        $this->assertSourceLine($sourceLine);
        $scopeId = $this->activeScope($sourcePath);
        $id = 'test:'.$scopeId.'::'.rawurlencode($description);

        if (isset($this->tests[$id])) {
            throw new LogicException(sprintf('Duplicate native test ID %s.', $id));
        }

        $this->tests[$id] = $body;
        $this->nodes[$scopeId]['tests'][] = [
            'id' => $id,
            'name' => $description,
            'source' => [
                'path' => $sourcePath,
                'line' => $sourceLine,
            ],
            'dataset' => null,
            'groups' => [],
            'timeout_ms' => 0,
        ];
    }

    public function declareDescribe(
        string $description,
        Closure $declarations,
        string $sourcePath,
        int $sourceLine,
    ): void {
        $this->assertHealthy();
        $this->assertDescription($description, 'describe');
        $this->assertNoParameters($declarations, 'describe');
        $this->assertSourceLine($sourceLine);
        $parentId = $this->activeScope($this->canonicalPath($sourcePath));
        $id = $parentId.'::describe:'.rawurlencode($description);

        if (isset($this->nodes[$id])) {
            throw new LogicException(sprintf('Duplicate native describe scope ID %s.', $id));
        }

        $this->nodes[$id] = $this->node($id, 'describe', $description);
        $this->nodes[$parentId]['children'][] = $id;
        $this->scopeStack[] = $id;

        try {
            $declarations();
        } catch (Throwable $throwable) {
            $this->poisoned = true;

            throw $throwable;
        } finally {
            $closedScope = array_pop($this->scopeStack);

            if ($closedScope !== $id) {
                throw new LogicException('Native declaration scope stack became unbalanced.');
            }
        }
    }

    public function declareHook(
        string $phase,
        Closure $hook,
        string $sourcePath,
        int $sourceLine,
    ): void {
        $this->assertHealthy();

        if (! in_array($phase, ['before_all', 'before_each', 'after_each', 'after_all'], true)) {
            throw new InvalidArgumentException(sprintf('Unknown native hook phase %s.', $phase));
        }

        $this->assertNoParameters($hook, str_replace('_', ' ', $phase).' hook');
        $this->assertSourceLine($sourceLine);
        $scopeId = $this->activeScope($this->canonicalPath($sourcePath));
        $ordinal = count($this->nodes[$scopeId]['hooks'][$phase]);
        $id = sprintf('hook:%s::%s:%d', $scopeId, $phase, $ordinal);

        if ($phase === 'before_all' || $phase === 'after_all') {
            $scopeHook = $hook;
            $hook = static fn (): mixed => $scopeHook();
        }

        $this->hooks[$id] = $hook;
        $this->nodes[$scopeId]['hooks'][$phase][] = $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        $this->assertHealthy();
        $children = [];

        foreach ($this->fileOrder as $fileId) {
            $node = $this->compileNode($fileId);

            if ($node !== null) {
                $children[] = $node;
            }
        }

        return [
            'schema' => 1,
            'root' => [
                'id' => 'suite:root',
                'type' => 'suite',
                'name' => $this->suiteName,
                'metadata' => [],
                'state_policy' => 'inherit',
                'concurrency' => null,
                'timeout_ms' => 0,
                'hooks' => $this->emptyHooks(),
                'tests' => [],
                'children' => $children,
            ],
        ];
    }

    public function resolveTest(string $testId): Closure
    {
        $this->assertHealthy();

        return $this->tests[$testId] ?? throw new OutOfBoundsException(sprintf(
            'No native test closure was captured for %s.',
            $testId,
        ));
    }

    public function resolveHook(string $hookId): Closure
    {
        $this->assertHealthy();

        return $this->hooks[$hookId] ?? throw new OutOfBoundsException(sprintf(
            'No native hook closure was captured for %s.',
            $hookId,
        ));
    }

    private function activeScope(string $sourcePath): string
    {
        if ($this->scopeStack !== []) {
            return $this->scopeStack[array_key_last($this->scopeStack)];
        }

        if (isset($this->files[$sourcePath])) {
            return $this->files[$sourcePath];
        }

        $id = 'file:'.$sourcePath;
        $this->files[$sourcePath] = $id;
        $this->fileOrder[] = $id;
        $this->nodes[$id] = $this->node($id, 'file', $sourcePath);

        return $id;
    }

    /**
     * @return array{
     *     id: string,
     *     type: 'file'|'describe',
     *     name?: string,
     *     path?: string,
     *     hooks: array{
     *         before_all: list<string>,
     *         before_each: list<string>,
     *         after_each: list<string>,
     *         after_all: list<string>
     *     },
     *     tests: list<array<string, mixed>>,
     *     children: list<string>
     * }
     */
    private function node(string $id, string $type, string $name): array
    {
        $node = [
            'id' => $id,
            'type' => $type,
            'hooks' => $this->emptyHooks(),
            'tests' => [],
            'children' => [],
        ];
        $node[$type === 'file' ? 'path' : 'name'] = $name;

        /** @var array{
         *     id: string,
         *     type: 'file'|'describe',
         *     name?: string,
         *     path?: string,
         *     hooks: array{
         *         before_all: list<string>,
         *         before_each: list<string>,
         *         after_each: list<string>,
         *         after_all: list<string>
         *     },
         *     tests: list<array<string, mixed>>,
         *     children: list<string>
         * } $node
         */
        return $node;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function compileNode(string $id): ?array
    {
        $source = $this->nodes[$id];
        $children = [];

        foreach ($source['children'] as $childId) {
            $child = $this->compileNode($childId);

            if ($child !== null) {
                $children[] = $child;
            }
        }

        if ($source['tests'] === [] && $children === []) {
            return null;
        }

        $node = [
            'id' => $source['id'],
            'type' => $source['type'],
            'metadata' => [],
            'state_policy' => 'inherit',
            'concurrency' => null,
            'timeout_ms' => 0,
            'hooks' => $source['hooks'],
            'tests' => $source['tests'],
            'children' => $children,
        ];
        if ($source['type'] === 'file') {
            $node['path'] = $source['path'] ?? throw new LogicException(
                sprintf('Native file scope %s has no source path.', $id),
            );
        } else {
            $node['name'] = $source['name'] ?? throw new LogicException(
                sprintf('Native describe scope %s has no name.', $id),
            );
        }

        return $node;
    }

    /**
     * @return array{
     *     before_all: list<string>,
     *     before_each: list<string>,
     *     after_each: list<string>,
     *     after_all: list<string>
     * }
     */
    private function emptyHooks(): array
    {
        return [
            'before_all' => [],
            'before_each' => [],
            'after_each' => [],
            'after_all' => [],
        ];
    }

    private function canonicalPath(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('A native declaration source path is required.');
        }

        $path = $this->normalizePath($path);
        $root = rtrim($this->rootPath, '/');

        if (! str_starts_with($path, $root.'/')) {
            throw new InvalidArgumentException(sprintf(
                'Native declaration source %s is outside suite root %s.',
                $path,
                $this->rootPath,
            ));
        }

        return substr($path, strlen($root) + 1);
    }

    private function normalizePath(string $path): string
    {
        $canonical = realpath($path);

        if ($canonical === false) {
            throw new InvalidArgumentException(sprintf('Native path %s does not exist.', $path));
        }

        $path = str_replace('\\', '/', $canonical);

        if (preg_match('/^[A-Z]:/', $path) === 1) {
            return strtolower($path[0]).substr($path, 1);
        }

        return $path;
    }

    private function assertDescription(string $description, string $kind): void
    {
        if ($description === '') {
            throw new InvalidArgumentException(sprintf('A native %s description is required.', $kind));
        }
    }

    private function assertSourceLine(int $sourceLine): void
    {
        if ($sourceLine < 1) {
            throw new InvalidArgumentException('A native declaration source line must be positive.');
        }
    }

    private function assertNoParameters(Closure $closure, string $kind): void
    {
        if (new ReflectionFunction($closure)->getNumberOfParameters() !== 0) {
            throw new InvalidArgumentException(sprintf(
                'Native %s closures do not accept parameters in Phase 1.',
                $kind,
            ));
        }
    }

    private function assertHealthy(): void
    {
        if ($this->poisoned) {
            throw new LogicException(
                'Native declarations cannot continue after a describe declaration failed.',
            );
        }
    }
}
