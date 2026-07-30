<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;
use Drove\Extension\ExtensionSet;
use Drove\Extension\PlanView;
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
     *     tests: list<string>,
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

    /** @var array<string, TestDefinition> */
    private array $definitions = [];

    /** @var array<string, array{name: string, source: array{path: string, line: int}}> */
    private array $testMetadata = [];

    /** @var array<string, CaseDefinition> */
    private array $cases = [];

    /** @var array<string, Closure> */
    private array $hooks = [];

    /** @var array<string, string> */
    private array $hookPhases = [];

    /** @var array<string, iterable<mixed, mixed>|Closure> */
    private array $datasets = [];

    /** @var array<string, list<array{key: int|string, arguments: list<mixed>}>> */
    private array $materializedDatasets = [];

    private bool $poisoned = false;

    private bool $frozen = false;

    private readonly string $rootPath;

    private readonly ExtensionSet $extensions;

    public function __construct(
        string $rootPath,
        private readonly string $suiteName = 'Drove Native',
        ?ExtensionSet $extensions = null,
    ) {
        if ($suiteName === '') {
            throw new InvalidArgumentException('The native suite name cannot be empty.');
        }

        if ($rootPath === '' || ! is_dir($rootPath)) {
            throw new InvalidArgumentException('The native suite root must be an existing directory.');
        }

        $this->rootPath = $this->normalizePath($rootPath);
        $this->extensions = $extensions ?? ExtensionSet::empty();
    }

    public function declareTest(
        string $description,
        Closure $body,
        string $sourcePath,
        int $sourceLine,
    ): TestDefinition {
        $this->assertHealthy();
        $this->assertMutable();
        $this->assertDescription($description, 'test');
        $sourcePath = $this->canonicalPath($sourcePath);
        $this->assertSourceLine($sourceLine);
        $scopeId = $this->activeScope($sourcePath);
        $id = 'test:'.$scopeId.'::'.rawurlencode($description);

        if (isset($this->definitions[$id])) {
            throw new LogicException(sprintf('Duplicate native test ID %s.', $id));
        }

        $definition = new TestDefinition($body);
        $this->definitions[$id] = $definition;
        $this->nodes[$scopeId]['tests'][] = $id;
        $this->testMetadata[$id] = [
            'name' => $description,
            'source' => ['path' => $sourcePath, 'line' => $sourceLine],
        ];

        return $definition;
    }

    /** @param iterable<mixed, mixed>|Closure $rows */
    public function declareDataset(string $name, iterable|Closure $rows): void
    {
        $this->assertHealthy();
        $this->assertMutable();

        if (trim($name) === '') {
            throw new InvalidArgumentException('A named native dataset requires a name.');
        }

        if (array_key_exists($name, $this->datasets)) {
            throw new LogicException(sprintf('Duplicate native dataset %s.', $name));
        }

        if ($rows instanceof Closure && new ReflectionFunction($rows)->getNumberOfParameters() !== 0) {
            throw new InvalidArgumentException('Native dataset providers do not accept parameters.');
        }

        $this->datasets[$name] = $rows;
    }

    public function declareDescribe(
        string $description,
        Closure $declarations,
        string $sourcePath,
        int $sourceLine,
    ): void {
        $this->assertHealthy();
        $this->assertMutable();
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
        $this->assertMutable();

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
        $this->hookPhases[$id] = $phase;
        $this->nodes[$scopeId]['hooks'][$phase][] = $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(?Selection $selection = null): array
    {
        $this->assertHealthy();
        $this->freeze();
        $this->cases = [];
        $children = [];

        foreach ($this->fileOrder as $fileId) {
            $node = $this->compileNode($fileId, $selection);

            if ($node !== null) {
                $children[] = $node;
            }
        }

        $root = [
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
        ];

        if (! $this->extensions->isEmpty()) {
            [$testCount, $scopeCount] = $this->planCounts($root);
            $root['metadata']['extensions'] = $this->extensions->planMetadata(
                new PlanView($this->suiteName, $testCount, $scopeCount),
            );
        }

        return ['schema' => 1, 'root' => $root];
    }

    public function extensions(): ExtensionSet
    {
        return $this->extensions;
    }

    public function resolveTest(string $testId): Closure
    {
        $this->assertHealthy();

        return $this->resolveCase($testId)->body;
    }

    public function resolveCase(string $testId): CaseDefinition
    {
        $this->assertHealthy();

        return $this->cases[$testId] ?? throw new OutOfBoundsException(sprintf(
            'No native test closure was captured for %s.',
            $testId,
        ));
    }

    public function resolveHook(string $hookId): Closure
    {
        $this->assertHealthy();

        $hook = $this->hooks[$hookId] ?? throw new OutOfBoundsException(sprintf(
            'No native hook closure was captured for %s.',
            $hookId,
        ));

        $phase = $this->hookPhases[$hookId] ?? null;

        return in_array($phase, ['before_each', 'after_each'], true)
            ? TestContext::wrapHook($hook, $phase)
            : $hook;
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
     *     tests: list<string>,
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
         *     tests: list<string>,
         *     children: list<string>
         * } $node
         */
        return $node;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function compileNode(string $id, ?Selection $selection): ?array
    {
        $source = $this->nodes[$id];
        $children = [];
        $tests = [];

        foreach ($source['children'] as $childId) {
            $child = $this->compileNode($childId, $selection);

            if ($child !== null) {
                $children[] = $child;
            }
        }

        foreach ($source['tests'] as $testId) {
            foreach ($this->compileDefinition($testId) as $test) {
                if ($selection === null || $selection->includes($test['name'], $test['groups'])) {
                    $tests[] = $test;
                }
            }
        }

        if ($tests === [] && $children === []) {
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
            'tests' => $tests,
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
     * @return list<array{
     *     id: string,
     *     name: string,
     *     source: array{path: string, line: int},
     *     dataset: array{key: int|string, label: string}|null,
     *     groups: list<string>,
     *     timeout_ms: int,
     *     disposition: string,
     *     disposition_reason: string
     * }>
     */
    private function compileDefinition(string $baseId): array
    {
        $definition = $this->definitions[$baseId];
        $metadata = $this->testMetadata[$baseId];
        $rows = $definition->inlineRows();
        $datasetName = $definition->datasetName();

        if ($datasetName !== null) {
            $rows = $this->namedDataset($datasetName);
        }

        if ($rows === null) {
            $this->assertBodyArguments($definition->body(), 0, $baseId);
            $this->cases[$baseId] = new CaseDefinition(
                $definition->body(),
                [],
                $definition->disposition(),
                $definition->reason(),
            );

            return [[
                'id' => $baseId,
                'name' => $metadata['name'],
                'source' => $metadata['source'],
                'dataset' => null,
                'groups' => $definition->groups(),
                'timeout_ms' => $definition->timeoutMs(),
                'disposition' => $definition->disposition(),
                'disposition_reason' => $definition->reason(),
            ]];
        }

        $tests = [];

        foreach ($rows as $row) {
            $key = $row['key'];
            $arguments = $row['arguments'];
            $caseKey = is_int($key)
                ? 'index:'.$key
                : 'name:'.rawurlencode($key);
            $caseId = $baseId.'::dataset:'.$caseKey;
            $label = is_int($key) ? '#'.$key : $key;
            $this->assertBodyArguments($definition->body(), count($arguments), $caseId);

            if (isset($this->cases[$caseId])) {
                throw new LogicException(sprintf('Duplicate native dataset case ID %s.', $caseId));
            }

            $this->cases[$caseId] = new CaseDefinition(
                $definition->body(),
                $arguments,
                $definition->disposition(),
                $definition->reason(),
            );
            $tests[] = [
                'id' => $caseId,
                'name' => $metadata['name'].' ['.$label.']',
                'source' => $metadata['source'],
                'dataset' => ['key' => $key, 'label' => $label],
                'groups' => $definition->groups(),
                'timeout_ms' => $definition->timeoutMs(),
                'disposition' => $definition->disposition(),
                'disposition_reason' => $definition->reason(),
            ];
        }

        return $tests;
    }

    /**
     * @return list<array{key: int|string, arguments: list<mixed>}>
     */
    private function namedDataset(string $name): array
    {
        if (isset($this->materializedDatasets[$name])) {
            return $this->materializedDatasets[$name];
        }

        $rows = $this->datasets[$name] ?? throw new OutOfBoundsException(sprintf(
            'Native dataset %s is not defined.',
            $name,
        ));

        if ($rows instanceof Closure) {
            $rows = $rows();
        }

        if (! is_iterable($rows)) {
            throw new InvalidArgumentException(sprintf(
                'Native dataset %s must return an iterable.',
                $name,
            ));
        }

        return $this->materializedDatasets[$name] = TestDefinition::materialize($rows);
    }

    private function assertBodyArguments(Closure $body, int $count, string $testId): void
    {
        $reflection = new ReflectionFunction($body);
        $accepts = $count >= $reflection->getNumberOfRequiredParameters()
            && ($reflection->isVariadic() || $count <= $reflection->getNumberOfParameters());

        if (! $accepts) {
            throw new InvalidArgumentException(sprintf(
                'Native test %s receives %d dataset arguments, but its closure accepts %d to %s.',
                $testId,
                $count,
                $reflection->getNumberOfRequiredParameters(),
                $reflection->isVariadic() ? 'many' : (string) $reflection->getNumberOfParameters(),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $root
     * @return array{int, int}
     */
    private function planCounts(array $root): array
    {
        $testCount = 0;
        $scopeCount = 0;
        $pending = [$root];

        while ($pending !== []) {
            $scope = array_pop($pending);
            $scopeCount++;
            $tests = $scope['tests'] ?? [];
            $children = $scope['children'] ?? [];
            $testCount += is_array($tests) ? count($tests) : 0;

            if (is_array($children)) {
                array_push($pending, ...$children);
            }
        }

        return [$testCount, $scopeCount];
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
                'Native %s closures do not accept parameters.',
                $kind,
            ));
        }
    }

    private function freeze(): void
    {
        if ($this->frozen) {
            return;
        }

        foreach ($this->definitions as $definition) {
            $definition->freeze();
        }

        $this->frozen = true;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Native declarations cannot change after planning.');
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
