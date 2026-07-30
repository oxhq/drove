<?php

declare(strict_types=1);

namespace Drove\Pest;

use Closure;
use InvalidArgumentException;
use OutOfBoundsException;
use Pest\Contracts\HasPrintableTestCaseName;
use Pest\Factories\TestCaseFactory;
use Pest\Factories\TestCaseMethodFactory;
use Pest\Support\Description;
use Pest\Support\HigherOrderMessage;
use Pest\Support\Reflection as PestReflection;
use Pest\Support\Str;
use ReflectionClass;
use ReflectionFunction;
use RuntimeException;

/**
 * @internal
 *
 * @phpstan-type TestDescriptor array{
 *     id: string,
 *     frontend_id: string,
 *     name: string,
 *     scope: list<string>,
 *     source: array{path: string, line: int},
 *     groups?: list<string>,
 *     disposition?: 'run'|'todo',
 *     dataset?: array{key: int|string, label: string}|null
 * }
 * @phpstan-type FilePlan array{id: string, type: string, path: string, tests: list<TestDescriptor>}
 * @phpstan-type CaseBinding array{file: string, base_id: string, test: TestDescriptor}
 */
final class ScopeCompiler
{
    private static ?self $active = null;

    /** @var array<string, FilePlan> */
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

    /** @var array<string, array<string, array{test: TestDescriptor, disposition: 'run'|'todo'}>> */
    private array $caseTemplates = [];

    /** @var array<string, true> */
    private array $boundCases = [];

    /** @var array<string, true> */
    private array $boundClassLifecycles = [];

    /** @var array<string, true> */
    private array $nativeFiles = [];

    /** @var list<string>|null */
    private ?array $boundFileOrder = null;

    private function __construct(
        private readonly string $rootPath,
        private readonly bool $ownsScopeHooks,
    ) {
        //
    }

    public static function activate(string $rootPath, bool $ownsScopeHooks = false): self
    {
        $rootPath = realpath($rootPath);

        if ($rootPath === false) {
            throw new InvalidArgumentException('The Drove root path does not exist.');
        }

        return self::$active = new self(str_replace('\\', '/', $rootPath), $ownsScopeHooks);
    }

    public static function ownsScopeHooks(): bool
    {
        return self::$active instanceof self && self::$active->ownsScopeHooks;
    }

    public static function isActive(): bool
    {
        return self::$active instanceof self;
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
    ): bool {
        if (! self::$active instanceof self) {
            return false;
        }

        self::$active->registerHook($phase, $filename, $hook, $describing);

        return true;
    }

    /**
     * @return FilePlan
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

    public function hasPlan(string $filename): bool
    {
        return isset($this->plans[$this->canonicalPath($filename)]);
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return $this->boundFileOrder ?? array_keys($this->plans);
    }

    /**
     * @param  list<string>  $groups
     * @return CaseBinding
     */
    public function nativeCaseDescriptor(
        string $filename,
        string $sourceFile,
        int $line,
        string $class,
        string $method,
        int|string $dataset,
        string $datasetLabel,
        array $groups,
    ): array {
        $filename = $this->canonicalPath($filename);
        $sourceFile = $this->canonicalPath($sourceFile);

        if (isset($this->plans[$filename]) && ! isset($this->nativeFiles[$filename])) {
            throw new RuntimeException(sprintf(
                'Drove cannot mix Pest and native PHPUnit cases in %s.',
                $filename,
            ));
        }

        if (! isset($this->nativeFiles[$filename])) {
            $path = $this->relativePath($filename);
            $this->nativeFiles[$filename] = true;
            $this->plans[$filename] = [
                'id' => 'file:'.$path,
                'type' => 'file',
                'path' => $path,
                'tests' => [],
            ];
            $this->scopePlans[$filename] = [
                'id' => 'file:'.$path,
                'type' => 'file',
                'path' => $path,
                'hooks' => [
                    'before_all' => [],
                    'before_each' => [],
                    'after_each' => [],
                    'after_all' => [],
                ],
                'tests' => [],
                'children' => [],
            ];
        }

        if (! isset($this->caseTemplates[$filename][$method])) {
            $path = $this->relativePath($filename);
            $test = [
                'id' => 'test:'.$path.'::'.rawurlencode($method),
                'frontend_id' => $class.'::'.$method,
                'name' => $method,
                'scope' => [],
                'source' => [
                    'path' => $this->relativePath($sourceFile),
                    'line' => $line,
                ],
            ];
            $this->caseTemplates[$filename][$method] = [
                'test' => $test,
                'disposition' => 'run',
            ];
            $this->plans[$filename]['tests'][] = $test;
            $this->scopePlans[$filename]['tests'][] = $test + [
                'before_each' => [],
                'after_each' => [],
            ];
        }

        return $this->caseDescriptor(
            $filename,
            $method,
            $dataset,
            $datasetLabel,
            $groups,
        );
    }

    /**
     * @param  list<string>  $groups
     * @return CaseBinding
     */
    public function caseDescriptor(
        string $filename,
        string $method,
        int|string $dataset,
        string $datasetLabel,
        array $groups,
    ): array {
        $filename = $this->canonicalPath($filename);
        $template = $this->caseTemplates[$filename][$method] ?? throw new OutOfBoundsException(sprintf(
            'No Drove test template was captured for %s::%s.',
            $filename,
            $method,
        ));
        $test = $template['test'];
        $baseId = $test['id'];

        $hasDataset = $dataset !== '';
        $datasetKey = $hasDataset
            ? (is_int($dataset) ? 'index:' : 'name:').rawurlencode((string) $dataset)
            : null;
        $test['id'] = $baseId.($datasetKey === null ? '' : '::dataset:'.$datasetKey);
        $test['frontend_id'] .= $hasDataset ? '#'.$dataset : '';
        $test['name'] .= $hasDataset ? $datasetLabel : '';
        $test['groups'] = array_values(array_unique($groups));
        $test['disposition'] = $template['disposition'];
        $test['dataset'] = $hasDataset
            ? ['key' => $dataset, 'label' => $datasetLabel]
            : null;

        return ['file' => $filename, 'base_id' => $baseId, 'test' => $test];
    }

    /**
     * @param  array<string, list<CaseBinding>>  $casesByFile
     */
    public function bindCases(array $casesByFile): void
    {
        $this->boundFileOrder = [];

        foreach ($casesByFile as $filename => $cases) {
            $filename = $this->canonicalPath($filename);
            $this->boundFileOrder[] = $filename;

            if (isset($this->boundCases[$filename])) {
                throw new RuntimeException(sprintf('Drove cases were already bound for %s.', $filename));
            }

            $expanded = [];
            $tests = [];
            $ids = [];

            foreach ($cases as $case) {
                $id = $case['test']['id'];

                if (isset($ids[$id])) {
                    throw new RuntimeException(sprintf('Drove received a duplicate case ID for %s.', $filename));
                }

                $ids[$id] = true;
                $expanded[$case['base_id']][] = $case['test'];
                $tests[] = $case['test'];
            }

            $this->plans[$filename]['tests'] = $tests;
            $this->scopePlans[$filename] = $this->expandCases(
                $this->scopePlans[$filename],
                $expanded,
            );
            $this->boundCases[$filename] = true;
        }
    }

    public function bindClassLifecycle(
        string $filename,
        ?Closure $beforeClass,
        ?Closure $afterClass,
    ): void {
        if (! $beforeClass instanceof Closure && ! $afterClass instanceof Closure) {
            return;
        }

        $filename = $this->canonicalPath($filename);

        if (isset($this->boundClassLifecycles[$filename])) {
            throw new RuntimeException(sprintf(
                'Drove class lifecycle was already bound for %s.',
                $filename,
            ));
        }

        $plan = $this->scopePlans[$filename] ?? throw new OutOfBoundsException(sprintf(
            'No Drove scope plan was captured for %s.',
            $filename,
        ));
        $scopeId = $this->plans[$filename]['id'] ?? throw new OutOfBoundsException(sprintf(
            'No Drove file plan was captured for %s.',
            $filename,
        ));

        if ($beforeClass instanceof Closure) {
            $hookId = 'hook:'.$scopeId.'::set_up_before_class';
            array_unshift($plan['hooks']['before_all'], $hookId);
            $this->hookClosures[$hookId] = $beforeClass;
        }

        if ($afterClass instanceof Closure) {
            $hookId = 'hook:'.$scopeId.'::tear_down_after_class';
            array_unshift($plan['hooks']['after_all'], $hookId);
            $this->hookClosures[$hookId] = $afterClass;
        }

        $this->scopePlans[$filename] = $plan;
        $this->boundClassLifecycles[$filename] = true;
    }

    /**
     * @param  array<int, mixed>  $filenames
     * @param  array{name?: string, scope_concurrency?: array<string, int>, test_timeouts?: array<string, int>, default_test_timeout_ms?: int}  $configuration
     * @return array<string, mixed>
     */
    public function suitePlan(array $filenames, array $configuration = []): array
    {
        $children = [];

        foreach ($filenames as $filename) {
            if (! is_string($filename)) {
                throw new InvalidArgumentException('Drove suite files must be paths.');
            }

            $plan = $this->plan($filename);

            if ($plan['tests'] === []) {
                continue;
            }

            $tests = [];

            foreach ($plan['tests'] as $test) {
                $tests[$test['id']] = $test;
            }

            $children[] = $this->runtimeNode(
                $this->scopePlan($filename),
                $tests,
                $configuration,
            );
        }

        return [
            'schema' => 1,
            'root' => [
                'id' => 'suite:root',
                'type' => 'suite',
                'name' => $configuration['name'] ?? 'Drove',
                'metadata' => [],
                'state_policy' => 'inherit',
                'concurrency' => $configuration['scope_concurrency']['suite:root'] ?? null,
                'hooks' => [
                    'before_all' => [],
                    'before_each' => [],
                    'after_each' => [],
                    'after_all' => [],
                ],
                'tests' => [],
                'children' => $children,
            ],
        ];
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
            if ($method->description === null || ! $method->closure instanceof Closure) {
                throw new RuntimeException('Drove file plans only support explicit Pest closures.');
            }

            $source = new ReflectionFunction($method->closure);
            $sourceFile = $this->canonicalPath((string) $source->getFileName());

            if ($sourceFile !== $filename && ! $method->todo) {
                throw new RuntimeException('Drove file plans require test closures declared in their test file.');
            }

            $id = 'test:'.$path.'::'.rawurlencode($method->description);

            if (isset($this->closures[$id])) {
                throw new RuntimeException(sprintf('Duplicate Drove test ID %s.', $id));
            }

            $line = $sourceFile === $filename
                ? $source->getStartLine()
                : $this->todoLine($method, $filename);

            if ($line === false) {
                throw new RuntimeException('Drove could not read a test source line.');
            }

            $this->closures[$id] = $method->closure;
            $test = [
                'id' => $id,
                'frontend_id' => 'pest:'.$path.'::'.Str::evaluable($method->description),
                'name' => $method->description,
                'scope' => array_values(array_map(strval(...), $method->describing)),
                'source' => [
                    'path' => $this->relativePath($filename),
                    'line' => $line,
                ],
            ];
            $this->caseTemplates[$filename][Str::evaluable($method->description)] = [
                'test' => $test,
                'disposition' => $method->todo ? 'todo' : 'run',
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
            if ($placement['scopes'] === []) {
                continue;
            }

            if ($placement['scopes'][count($placement['scopes']) - 1] !== $id) {
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

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<TestDescriptor>>  $expanded
     * @return array<string, mixed>
     */
    private function expandCases(array $node, array $expanded): array
    {
        $tests = [];

        foreach ($node['tests'] as $test) {
            foreach ($expanded[$test['id']] ?? [] as $case) {
                $tests[] = $case + [
                    'before_each' => $test['before_each'],
                    'after_each' => $test['after_each'],
                ];
            }
        }

        $children = array_map(
            fn (array $child): array => $this->expandCases($child, $expanded),
            $node['children'],
        );
        $node['tests'] = $tests;
        $node['children'] = array_values(array_filter(
            $children,
            static fn (array $child): bool => $child['tests'] !== [] || $child['children'] !== [],
        ));

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>  $tests
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function runtimeNode(array $node, array $tests, array $configuration): array
    {
        $id = $node['id'];
        $runtimeTests = [];

        foreach ($node['tests'] as $test) {
            $source = $tests[$test['id']] ?? throw new RuntimeException(sprintf(
                'Drove could not enrich test %s.',
                $test['id'],
            ));
            $runtimeTests[] = $test + [
                'source' => $source['source'],
                'timeout_ms' => $configuration['test_timeouts'][$test['id']]
                    ?? $configuration['default_test_timeout_ms']
                    ?? 0,
            ];
        }

        $node['metadata'] = [];
        $node['state_policy'] = 'inherit';
        $node['concurrency'] = $configuration['scope_concurrency'][$id] ?? null;
        $node['timeout_ms'] = 0;
        if ($this->ownsScopeHooks) {
            $node['hooks']['before_each'] = [];
            $node['hooks']['after_each'] = [];
        }
        $node['tests'] = $runtimeTests;
        $node['children'] = array_map(
            fn (array $child): array => $this->runtimeNode($child, $tests, $configuration),
            $node['children'],
        );

        return $node;
    }

    private function todoLine(TestCaseMethodFactory $method, string $filename): int
    {
        $messages = PestReflection::getPropertyValue($method->chains, 'messages');

        if (is_array($messages)) {
            foreach ($messages as $message) {
                if ($message instanceof HigherOrderMessage
                    && $message->name === 'markTestSkipped'
                    && $this->canonicalPath($message->filename) === $filename) {
                    return $message->line;
                }
            }
        }

        throw new RuntimeException('Drove could not locate a todo source line.');
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
