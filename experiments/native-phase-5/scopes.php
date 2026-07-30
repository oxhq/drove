<?php

declare(strict_types=1);

use Drove\Kernel\DroverScheduler;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\ScopeContext;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/support.php';

final class NativePhaseFiveScopeState
{
    public static int $value = 0;
}

final class NativePhaseFiveBranchProbe
{
    /** @var array<string, string> */
    private array $owned = [];

    public function __construct(
        private readonly string $workspace,
    ) {
        nativePhaseFiveAssert(
            mkdir($this->workspace.'/branches', 0700, true),
            'Could not create the native Phase 5 branch probe workspace.',
        );
        $this->writeState([
            'current' => 0,
            'peak' => 0,
            'enters' => 0,
            'leaves' => 0,
            'enters_by_kind' => ['scope' => 0, 'test' => 0],
        ]);
    }

    public function beforeDispatch(): void
    {
        //
    }

    /** @param array<string, mixed> $task */
    public function enterDescendant(ScopeContext $scope, array $task): void
    {
        $kind = $task['kind'] ?? null;
        $id = $task['id'] ?? null;
        nativePhaseFiveAssert(
            in_array($kind, ['scope', 'test'], true)
                && is_string($id)
                && $id !== '',
            'Native Phase 5 branch probe received an invalid task.',
        );
        $key = $kind.':'.$id;
        $path = $this->workspace.'/branches/'
            .getmypid().'-'.hash('sha256', $key).'.branch';
        $this->mutate(static function (array $state) use ($kind, $path): array {
            nativePhaseFiveAssert(
                file_put_contents($path, 'prepared', LOCK_EX) !== false,
                'Could not create a native Phase 5 prepared branch.',
            );
            $state['current']++;
            $state['peak'] = max($state['peak'], $state['current']);
            $state['enters']++;
            $state['enters_by_kind'][$kind]++;

            return $state;
        });
        $this->owned[$key] = $path;
    }

    /** @param array<string, mixed> $task */
    public function leaveDescendant(ScopeContext $scope, array $task): void
    {
        $kind = $task['kind'] ?? null;
        $id = $task['id'] ?? null;
        $key = $kind.':'.$id;
        $path = $this->owned[$key] ?? null;
        nativePhaseFiveAssert(
            is_string($path),
            'Native Phase 5 branch probe lost an owned branch.',
        );
        $this->mutate(static function (array $state) use ($path): array {
            nativePhaseFiveAssert(
                is_file($path) && unlink($path),
                'Could not remove a native Phase 5 prepared branch.',
            );
            $state['current']--;
            $state['leaves']++;
            nativePhaseFiveAssert(
                $state['current'] >= 0,
                'Native Phase 5 branch probe underflowed.',
            );

            return $state;
        });
        unset($this->owned[$key]);
    }

    public function afterDispatch(): void
    {
        //
    }

    /**
     * @return array{
     *     current: int,
     *     peak: int,
     *     enters: int,
     *     leaves: int,
     *     enters_by_kind: array{scope: int, test: int}
     * }
     */
    public function snapshot(): array
    {
        $stream = fopen($this->workspace.'/branch.lock', 'c+');
        nativePhaseFiveAssert(is_resource($stream), 'Could not open the branch probe lock.');

        try {
            nativePhaseFiveAssert(flock($stream, LOCK_EX), 'Could not lock the branch probe.');
            $state = $this->readState();
            flock($stream, LOCK_UN);
        } finally {
            fclose($stream);
        }

        return $state;
    }

    /**
     * @param  Closure(array{
     *     current: int,
     *     peak: int,
     *     enters: int,
     *     leaves: int,
     *     enters_by_kind: array{scope: int, test: int}
     * }): array{
     *     current: int,
     *     peak: int,
     *     enters: int,
     *     leaves: int,
     *     enters_by_kind: array{scope: int, test: int}
     * }  $callback
     */
    private function mutate(Closure $callback): void
    {
        $stream = fopen($this->workspace.'/branch.lock', 'c+');
        nativePhaseFiveAssert(is_resource($stream), 'Could not open the branch probe lock.');

        try {
            nativePhaseFiveAssert(flock($stream, LOCK_EX), 'Could not lock the branch probe.');
            $this->writeState($callback($this->readState()));
            flock($stream, LOCK_UN);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array{
     *     current: int,
     *     peak: int,
     *     enters: int,
     *     leaves: int,
     *     enters_by_kind: array{scope: int, test: int}
     * }
     */
    private function readState(): array
    {
        $contents = file_get_contents($this->workspace.'/branch.json');
        nativePhaseFiveAssert(is_string($contents), 'Could not read the branch probe state.');
        $state = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        nativePhaseFiveAssert(
            is_array($state)
                && is_int($state['current'] ?? null)
                && is_int($state['peak'] ?? null)
                && is_int($state['enters'] ?? null)
                && is_int($state['leaves'] ?? null)
                && is_array($state['enters_by_kind'] ?? null)
                && is_int($state['enters_by_kind']['scope'] ?? null)
                && is_int($state['enters_by_kind']['test'] ?? null),
            'Native Phase 5 branch probe state is invalid.',
        );

        return $state;
    }

    /**
     * @param array{
     *     current: int,
     *     peak: int,
     *     enters: int,
     *     leaves: int,
     *     enters_by_kind: array{scope: int, test: int}
     * } $state
     */
    private function writeState(array $state): void
    {
        nativePhaseFiveAssert(
            file_put_contents(
                $this->workspace.'/branch.json',
                json_encode($state, JSON_THROW_ON_ERROR),
            ) !== false,
            'Could not write the branch probe state.',
        );
    }
}

/**
 * @param  list<array<string, mixed>>  $tests
 * @param  list<array<string, mixed>>  $children
 * @param  array<string, list<string>>  $hooks
 * @return array<string, mixed>
 */
function nativePhaseFiveScopeNode(
    string $id,
    array $tests = [],
    array $children = [],
    array $hooks = [],
): array {
    return [
        'id' => $id,
        'type' => $id === 'phase5-suite' ? 'suite' : 'describe',
        'name' => $id,
        'metadata' => [],
        'state_policy' => 'inherit',
        'concurrency' => null,
        'timeout_ms' => $hooks === [] ? 0 : 5_000,
        'hooks' => [
            'before_all' => $hooks['before_all'] ?? [],
            'before_each' => $hooks['before_each'] ?? [],
            'after_each' => $hooks['after_each'] ?? [],
            'after_all' => $hooks['after_all'] ?? [],
        ],
        'tests' => $tests,
        'children' => $children,
    ];
}

/**
 * @param  array<string, mixed>  $scope
 * @return list<string>
 */
function nativePhaseFiveScopeIds(array $scope): array
{
    $ids = [(string) $scope['id']];

    foreach ($scope['children'] as $child) {
        nativePhaseFiveAssert(
            is_array($child),
            'Native Phase 5 received an invalid child scope.',
        );
        array_push($ids, ...nativePhaseFiveScopeIds($child));
    }

    return $ids;
}

/**
 * @return array<string, mixed>
 */
function nativePhaseFiveTestNode(string $id): array
{
    return [
        'id' => $id,
        'name' => $id,
        'source' => ['path' => __FILE__, 'line' => __LINE__],
        'dataset' => null,
        'groups' => ['phase5'],
        'timeout_ms' => 5_000,
    ];
}

/**
 * @param  array<array-key, mixed>  $tests
 */
function nativePhaseFiveScopeSemanticHash(array $tests): string
{
    $projection = [];

    foreach ($tests as $test) {
        nativePhaseFiveAssert(
            is_array($test),
            'Native Phase 5 semantic projection received an invalid test.',
        );
        $projection[] = [
            'id' => $test['id'] ?? null,
            'scope_id' => $test['scope_id'] ?? null,
            'status' => $test['status'] ?? null,
            'value' => $test['value'] ?? null,
        ];
    }

    usort(
        $projection,
        static fn (array $left, array $right): int => $left['id'] <=> $right['id'],
    );

    return hash(
        'sha256',
        json_encode($projection, JSON_THROW_ON_ERROR),
    );
}

try {
    $proofStartedNs = hrtime(true);
    $identity = nativePhaseFiveIdentity();
    $fixture = getenv('DROVE_PHASE5_SCOPE_FIXTURE') ?: '';
    $inactiveScopes = (int) (getenv('DROVE_PHASE5_INACTIVE_SCOPES') ?: 0);
    $repetition = (int) (getenv('DROVE_PHASE5_REPETITION') ?: 0);
    $processes = (int) (getenv('DROVE_PHASE5_PROCESSES') ?: 30);
    $workspace = getenv('DROVE_PHASE5_WORKSPACE');
    $workspace = is_string($workspace) && $workspace !== ''
        ? $workspace
        : sys_get_temp_dir().'/drove-native-phase-5-scopes-'.bin2hex(random_bytes(8));
    nativePhaseFiveAssert(
        in_array($fixture, ['inactive', 'inert', 'stateful'], true),
        'Unknown native Phase 5 scope fixture.',
    );
    nativePhaseFiveAssert(
        $processes >= 1 && $processes <= 30,
        'DROVE_PHASE5_PROCESSES must be between 1 and 30.',
    );
    nativePhaseFiveAssert(
        $fixture !== 'inactive' || in_array($inactiveScopes, [10, 1_000], true),
        'Inactive scope proof requires exactly 10 or 1,000 siblings.',
    );
    nativePhaseFiveAssert(
        $fixture !== 'inactive' || in_array($repetition, [1, 2, 3], true),
        'Inactive scope proof requires repetition 1, 2, or 3.',
    );
    nativePhaseFiveAssert(
        ! file_exists($workspace) && mkdir($workspace, 0700, true),
        'Could not create the native Phase 5 scope workspace.',
    );
    $branchProbe = new NativePhaseFiveBranchProbe($workspace);
    $testCount = 60;
    $expectedPopulation = 1 + min($testCount, 2 * $processes);
    $tests = [];
    $children = [];
    $hooks = [];

    nativePhaseFivePhase('planning');
    $planMemoryBeforeBytes = memory_get_usage();
    $planAllocatorBeforeBytes = memory_get_usage(true);
    $planningStartedNs = hrtime(true);

    if ($fixture === 'inactive') {
        for ($index = 0; $index < $testCount; $index++) {
            $tests[] = nativePhaseFiveTestNode(sprintf('root-test-%03d', $index));
        }

        for ($index = 0; $index < $inactiveScopes; $index++) {
            $children[] = nativePhaseFiveScopeNode(sprintf('inactive-%04d', $index));
        }
    } elseif ($fixture === 'inert') {
        for ($scope = 0; $scope < 30; $scope++) {
            $scopeTests = [];

            for ($case = 0; $case < 2; $case++) {
                $scopeTests[] = nativePhaseFiveTestNode(
                    sprintf('inert-%02d-test-%d', $scope, $case),
                );
            }

            $children[] = nativePhaseFiveScopeNode(
                sprintf('inert-%02d', $scope),
                $scopeTests,
            );
        }
    } else {
        foreach (['a' => 11, 'b' => 22] as $name => $value) {
            $scopeTests = [];

            for ($case = 0; $case < 30; $case++) {
                $scopeTests[] = nativePhaseFiveTestNode(
                    sprintf('stateful-%s-test-%02d', $name, $case),
                );
            }

            $before = 'hook:stateful-'.$name.':before-all';
            $after = 'hook:stateful-'.$name.':after-all';
            $hooks[$before] = static function () use ($value): void {
                nativePhaseFiveAssert(
                    NativePhaseFiveScopeState::$value === 0,
                    'A stateful sibling observed another scope beforeAll mutation.',
                );
                NativePhaseFiveScopeState::$value = $value;
            };
            $hooks[$after] = static function () use ($value): void {
                nativePhaseFiveAssert(
                    NativePhaseFiveScopeState::$value === $value,
                    'A stateful scope lost its beforeAll snapshot.',
                );
            };
            if ($name === 'a') {
                $innerBefore = 'hook:stateful-a-inner:before-all';
                $innerAfter = 'hook:stateful-a-inner:after-all';
                $hooks[$innerBefore] = static function (): void {
                    nativePhaseFiveAssert(
                        NativePhaseFiveScopeState::$value === 11,
                        'A nested stateful scope did not inherit its parent snapshot.',
                    );
                };
                $hooks[$innerAfter] = static function (): void {
                    nativePhaseFiveAssert(
                        NativePhaseFiveScopeState::$value === 11,
                        'A nested stateful scope lost its inherited snapshot.',
                    );
                };
                $children[] = nativePhaseFiveScopeNode(
                    'stateful-a',
                    children: [
                        nativePhaseFiveScopeNode(
                            'stateful-a-inner',
                            $scopeTests,
                            hooks: [
                                'before_all' => [$innerBefore],
                                'after_all' => [$innerAfter],
                            ],
                        ),
                    ],
                    hooks: [
                        'before_all' => [$before],
                        'after_all' => [$after],
                    ],
                );
            } else {
                $children[] = nativePhaseFiveScopeNode(
                    'stateful-'.$name,
                    $scopeTests,
                    hooks: [
                        'before_all' => [$before],
                        'after_all' => [$after],
                    ],
                );
            }
        }
    }

    $plan = [
        'schema' => 1,
        'root' => nativePhaseFiveScopeNode(
            'phase5-suite',
            $tests,
            $children,
        ),
    ];
    $planMetadataBytes = strlen(json_encode($plan, JSON_THROW_ON_ERROR));
    $planMemoryAfterBytes = memory_get_usage();
    $planAllocatorAfterBytes = memory_get_usage(true);
    $planMemoryDeltaBytes = max(
        0,
        $planMemoryAfterBytes - $planMemoryBeforeBytes,
    );
    $planAllocatorDeltaBytes = max(
        0,
        $planAllocatorAfterBytes - $planAllocatorBeforeBytes,
    );
    $planningMs = round((hrtime(true) - $planningStartedNs) / 1_000_000, 3);
    nativePhaseFivePhase('environment_prepare');
    $environmentStartedNs = hrtime(true);
    $application = new stdClass;
    $context = new ScopeContext($application);
    $environmentPrepareMs = round(
        (hrtime(true) - $environmentStartedNs) / 1_000_000,
        3,
    );
    $scheduler = new DroverScheduler(
        'native-phase-5-scopes-'.$fixture.'-'.bin2hex(random_bytes(6)),
        $processes,
        defaultTimeoutMs: 5_000,
        termGraceMs: 50,
    );
    $resolveHook = static function (string $id) use ($hooks): Closure {
        $hook = $hooks[$id] ?? null;
        nativePhaseFiveAssert(
            $hook instanceof Closure,
            'Native Phase 5 could not resolve lifecycle hook '.$id.'.',
        );

        return $hook;
    };
    $resolveTest = static function (
        string $id,
        ScopeContext $scope = new ScopeContext,
    ) use ($expectedPopulation, $fixture): Closure {
        $scopeId = $scope->metadata()['id'] ?? 'phase5-suite';
        $expected = str_starts_with((string) $scopeId, 'stateful-a')
            ? 11
            : (str_starts_with((string) $scopeId, 'stateful-b') ? 22 : 0);

        return static function () use ($expected, $expectedPopulation, $fixture, $id): string {
            if ($fixture === 'stateful') {
                nativePhaseFiveAssert(
                    NativePhaseFiveScopeState::$value === $expected,
                    'A stateful test did not inherit its scope snapshot.',
                );
            }

            if ($fixture === 'inactive') {
                nativePhaseFiveWaitForPopulationAck($expectedPopulation);
            }

            usleep($fixture === 'inactive' ? 250_000 : 20_000);

            return hash('sha256', $fixture.':'.$id.':'.$expected);
        };
    };
    $executor = new LifecycleExecutor(
        $scheduler,
        $resolveHook,
        $resolveTest,
        beforeDispatch: $branchProbe->beforeDispatch(...),
        enterDescendant: $branchProbe->enterDescendant(...),
        leaveDescendant: $branchProbe->leaveDescendant(...),
        afterDispatch: $branchProbe->afterDispatch(...),
    );
    nativePhaseFivePhase('execution');
    $executionStartedNs = hrtime(true);
    $run = $executor->run($plan, $context);
    $executionMs = round((hrtime(true) - $executionStartedNs) / 1_000_000, 3);
    $failedTests = array_values(array_filter(
        $run['tests'] ?? [],
        static fn (mixed $test): bool => is_array($test)
            && ($test['status'] ?? null) !== 'passed',
    ));
    nativePhaseFiveAssert(
        ($run['status'] ?? null) === 'passed'
            && ($run['exit_code'] ?? null) === 0
            && is_array($run['tests'] ?? null)
            && count($run['tests']) === $testCount
            && array_all(
                $run['tests'],
                static fn (array $test): bool => ($test['status'] ?? null) === 'passed',
            ),
        'Native Phase 5 scope lifecycle did not return every passing terminal result: '
            .json_encode(
                $failedTests[0] ?? null,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
    );
    $expectedScopeIds = nativePhaseFiveScopeIds($plan['root']);
    $reportedScopeIds = [];

    foreach ($run['scopes'] as $scope) {
        $scopeId = $scope['id'] ?? null;
        nativePhaseFiveAssert(
            is_string($scopeId) && $scopeId !== '',
            'Native Phase 5 received a scope result without identity.',
        );

        if ($fixture === 'inactive' && $scopeId !== 'phase5-suite') {
            nativePhaseFiveAssert(
                ! isset($scope['telemetry']),
                'An empty inert scope incorrectly received worker telemetry.',
            );
        }

        $reportedScopeIds[] = $scopeId;
    }

    $startedScopeIds = [];
    $finishedScopeIds = [];

    foreach ($run['events'] as $event) {
        $scopeId = $event['scope_id'] ?? null;

        if (! is_string($scopeId)) {
            continue;
        }

        if (($event['type'] ?? null) === 'scope.started') {
            $startedScopeIds[] = $scopeId;
        }

        if (($event['type'] ?? null) === 'scope.finished') {
            $finishedScopeIds[] = $scopeId;
        }
    }

    foreach ([
        &$expectedScopeIds,
        &$reportedScopeIds,
        &$startedScopeIds,
        &$finishedScopeIds,
    ] as &$scopeIds) {
        sort($scopeIds, SORT_STRING);
    }

    unset($scopeIds);
    nativePhaseFiveAssert(
        $reportedScopeIds === $expectedScopeIds
            && $startedScopeIds === $expectedScopeIds
            && $finishedScopeIds === $expectedScopeIds,
        'Native Phase 5 lost virtual scope results or lifecycle events.',
    );
    $expectedScopeWorkers = $fixture === 'stateful' ? 3 : 0;
    $combinedBranchLimit = 2 * $processes + $expectedScopeWorkers;
    $branch = $branchProbe->snapshot();
    $branchFiles = glob($workspace.'/branches/*.branch');
    nativePhaseFiveAssert(
        $branch['current'] === 0
            && $branch['enters'] === $branch['leaves']
            && $branch['enters_by_kind']['test'] === $testCount
            && $branch['peak'] <= $combinedBranchLimit
            && is_array($branchFiles)
            && $branchFiles === [],
        'Native Phase 5 prepared branches were not bounded and cleaned.',
    );

    $scopeWorkerTelemetry = array_values(array_filter(
        array_map(
            static fn (array $scope): mixed => $scope['telemetry'] ?? null,
            $run['scopes'],
        ),
        static fn (mixed $telemetry): bool => is_array($telemetry)
            && ($telemetry['scope_workers'] ?? null) === 1,
    ));
    $testTelemetry = array_map(
        static fn (array $test): mixed => $test['telemetry'] ?? null,
        $run['tests'],
    );
    nativePhaseFiveAssert(
        array_all(
            $testTelemetry,
            static fn (mixed $telemetry): bool => is_array($telemetry),
        ),
        'Native Phase 5 scope tests lost executor telemetry.',
    );
    $transportTelemetry = [...$scopeWorkerTelemetry, ...$testTelemetry];
    $telemetryIntervals = static function (array $telemetryRows): array {
        $intervals = [];

        foreach ($telemetryRows as $telemetry) {
            nativePhaseFiveAssert(is_array($telemetry), 'Invalid Phase 5 transport telemetry.');
            $startedNs = $telemetry['started_ns'] ?? null;
            $finishedNs = $telemetry['finished_ns'] ?? null;
            nativePhaseFiveAssert(
                is_int($startedNs)
                    && is_int($finishedNs)
                    && $finishedNs >= $startedNs,
                'Native Phase 5 scope worker interval is invalid.',
            );
            $intervals[] = ['started_ns' => $startedNs, 'finished_ns' => $finishedNs];
        }

        return $intervals;
    };
    $scopeHostIntervals = $telemetryIntervals($scopeWorkerTelemetry);
    $testIntervals = $telemetryIntervals($testTelemetry);
    $intervals = [...$scopeHostIntervals, ...$testIntervals];
    $scopeHostPeak = nativePhaseFivePeakConcurrency($scopeHostIntervals);
    $executorPeak = nativePhaseFivePeakConcurrency($testIntervals);
    $activePeak = nativePhaseFivePeakConcurrency($intervals);

    $topology = [
        'schema' => 1,
        'forks' => array_sum(array_column($transportTelemetry, 'forks')),
        'scope_workers' => array_sum(array_column($transportTelemetry, 'scope_workers')),
        'executor_workers' => array_sum(array_column($transportTelemetry, 'executor_workers')),
        'process_anchors' => array_sum(array_column($transportTelemetry, 'process_anchors')),
        'peak_live_pids' => $activePeak,
        'peak_outstanding_tasks' => $scheduler->topologyTelemetry()['peak_outstanding_tasks'],
        'outstanding_task_limit' => $scheduler->topologyTelemetry()['outstanding_task_limit'],
    ];
    nativePhaseFiveAssertTopology($topology);
    $observedTestBodyLanes = $run['observed_concurrency']['global'] ?? null;
    nativePhaseFiveAssert(
        is_int($observedTestBodyLanes)
            && $observedTestBodyLanes >= 1
            && $observedTestBodyLanes <= $processes
            && ($fixture !== 'inactive' || $observedTestBodyLanes === $processes)
            && $observedTestBodyLanes === $executorPeak
            && $scopeHostPeak === $expectedScopeWorkers
            && $activePeak === $executorPeak + $scopeHostPeak
            && $topology['forks'] === $testCount + $expectedScopeWorkers
            && $topology['scope_workers'] === $expectedScopeWorkers
            && $topology['executor_workers'] === $testCount
            && $topology['process_anchors'] === 0
            && $branch['enters_by_kind']['scope'] === $expectedScopeWorkers,
        'Native Phase 5 scope topology violated inert or stateful lifecycle semantics.',
    );
    nativePhaseFiveAssert(
        $fixture !== 'stateful' || NativePhaseFiveScopeState::$value === 0,
        'Stateful scope execution mutated the prepared parent heap.',
    );
    $statefulC1Depth = null;

    if ($fixture === 'stateful') {
        $depthWorkspace = $workspace.'/stateful-c1-depth';
        $depthBranchProbe = new NativePhaseFiveBranchProbe($depthWorkspace);
        $depthScheduler = new DroverScheduler(
            'native-phase-5-stateful-c1-depth-'.bin2hex(random_bytes(6)),
            1,
            defaultTimeoutMs: 5_000,
            termGraceMs: 50,
        );
        $depthExecutor = new LifecycleExecutor(
            $depthScheduler,
            $resolveHook,
            $resolveTest,
            beforeDispatch: $depthBranchProbe->beforeDispatch(...),
            enterDescendant: $depthBranchProbe->enterDescendant(...),
            leaveDescendant: $depthBranchProbe->leaveDescendant(...),
            afterDispatch: $depthBranchProbe->afterDispatch(...),
        );
        $depthStartedNs = hrtime(true);
        $depthRun = $depthExecutor->run($plan, new ScopeContext(new stdClass));
        $depthExecutionMs = round((hrtime(true) - $depthStartedNs) / 1_000_000, 3);
        nativePhaseFiveAssert(
            ($depthRun['status'] ?? null) === 'passed'
                && ($depthRun['exit_code'] ?? null) === 0
                && is_array($depthRun['tests'] ?? null)
                && count($depthRun['tests']) === $testCount
                && array_all(
                    $depthRun['tests'],
                    static fn (array $test): bool => ($test['status'] ?? null) === 'passed',
                ),
            'Native Phase 5 stateful C1 depth proof did not pass.',
        );
        $depthScopeTelemetry = array_values(array_filter(
            array_map(
                static fn (array $scope): mixed => $scope['telemetry'] ?? null,
                $depthRun['scopes'],
            ),
            static fn (mixed $telemetry): bool => is_array($telemetry)
                && ($telemetry['scope_workers'] ?? null) === 1,
        ));
        $depthTestTelemetry = array_map(
            static fn (array $test): mixed => $test['telemetry'] ?? null,
            $depthRun['tests'],
        );
        nativePhaseFiveAssert(
            array_all(
                $depthTestTelemetry,
                static fn (mixed $telemetry): bool => is_array($telemetry),
            ),
            'Native Phase 5 stateful C1 depth proof lost executor telemetry.',
        );
        $depthScopeIntervals = $telemetryIntervals($depthScopeTelemetry);
        $depthTestIntervals = $telemetryIntervals($depthTestTelemetry);
        $depthScopeHostPeak = nativePhaseFivePeakConcurrency($depthScopeIntervals);
        $depthExecutorPeak = nativePhaseFivePeakConcurrency($depthTestIntervals);
        $depthActivePeak = nativePhaseFivePeakConcurrency([
            ...$depthScopeIntervals,
            ...$depthTestIntervals,
        ]);
        $depthTransportTelemetry = [...$depthScopeTelemetry, ...$depthTestTelemetry];
        $depthBranch = $depthBranchProbe->snapshot();
        $depthBranchFiles = glob($depthWorkspace.'/branches/*.branch');
        $depthSemanticHash = nativePhaseFiveScopeSemanticHash($depthRun['tests']);
        nativePhaseFiveAssert(
            ($depthRun['observed_concurrency']['global'] ?? null) === 1
                && count($depthScopeTelemetry) === 3
                && $depthScopeHostPeak === 3
                && $depthExecutorPeak === 1
                && $depthActivePeak === 4
                && array_sum(array_column($depthTransportTelemetry, 'forks')) === 63
                && array_sum(array_column($depthTransportTelemetry, 'scope_workers')) === 3
                && array_sum(array_column($depthTransportTelemetry, 'executor_workers')) === 60
                && $depthBranch['current'] === 0
                && $depthBranch['peak'] === 4
                && $depthBranch['enters'] === $depthBranch['leaves']
                && $depthBranch['enters_by_kind'] === ['scope' => 3, 'test' => 60]
                && is_array($depthBranchFiles)
                && $depthBranchFiles === []
                && $depthSemanticHash === nativePhaseFiveScopeSemanticHash($run['tests']),
            'Native Phase 5 stateful C1 depth topology or cleanup diverged.',
        );
        $statefulC1Depth = [
            'processes' => 1,
            'scope_ir_depth' => 2,
            'process_descendant_depth' => 3,
            'test_count' => count($depthRun['tests']),
            'terminal_result_count' => count($depthRun['tests']),
            'semantic_hash' => $depthSemanticHash,
            'execution_ms' => $depthExecutionMs,
            'forks' => array_sum(array_column($depthTransportTelemetry, 'forks')),
            'scope_workers' => array_sum(
                array_column($depthTransportTelemetry, 'scope_workers'),
            ),
            'executor_workers' => array_sum(
                array_column($depthTransportTelemetry, 'executor_workers'),
            ),
            'scope_host_peak' => $depthScopeHostPeak,
            'executor_peak' => $depthExecutorPeak,
            'active_process_peak' => $depthActivePeak,
            'prepared_branch_peak' => $depthBranch['peak'],
            'parent_heap_unchanged' => true,
            'orphan_pids' => [],
            'artifact_residue_count' => 0,
        ];
    }

    $remainingChildren = nativePhaseFiveWaitForExit(nativePhaseFiveChildPids(), 2_000);
    nativePhaseFiveAssert(
        $remainingChildren === [],
        'Native Phase 5 scope execution left child processes alive.',
    );
    nativePhaseFivePhase('rendering');
    $renderingStartedNs = hrtime(true);
    $semanticHash = nativePhaseFiveScopeSemanticHash($run['tests']);
    $resultMetadataBytes = strlen(json_encode([
        'root' => $run['root'],
        'scopes' => $run['scopes'],
        'events' => $run['events'],
    ], JSON_THROW_ON_ERROR));
    $renderingMs = round((hrtime(true) - $renderingStartedNs) / 1_000_000, 3);
    nativePhaseFiveRemove($workspace);
    $summary = [
        'schema' => 1,
        'ok' => true,
        ...$identity,
        'fixture' => $fixture,
        'inactive_scope_count' => $fixture === 'inactive' ? $inactiveScopes : 0,
        'repetition' => $fixture === 'inactive' ? $repetition : 0,
        'processes' => $processes,
        'test_count' => $testCount,
        'terminal_result_count' => count($run['tests']),
        'semantic_hash' => $semanticHash,
        'stateful_c1_depth' => $statefulC1Depth,
        'plan' => [
            'scope_count' => count($expectedScopeIds),
            'reported_scope_count' => count($reportedScopeIds),
            'scope_started_event_count' => count($startedScopeIds),
            'scope_finished_event_count' => count($finishedScopeIds),
            'metadata_bytes' => $planMetadataBytes,
            'memory_footprint_before_bytes' => $planMemoryBeforeBytes,
            'memory_footprint_after_bytes' => $planMemoryAfterBytes,
            'memory_footprint_delta_bytes' => $planMemoryDeltaBytes,
            'allocator_footprint_before_bytes' => $planAllocatorBeforeBytes,
            'allocator_footprint_after_bytes' => $planAllocatorAfterBytes,
            'allocator_footprint_delta_bytes' => $planAllocatorDeltaBytes,
            'result_metadata_bytes' => $resultMetadataBytes,
        ],
        'isolation' => [
            'parent_heap_unchanged' => NativePhaseFiveScopeState::$value === 0,
            'stateful_scope_checked' => $fixture === 'stateful',
            'inert_scope_checked' => $fixture === 'inert',
            'orphan_pids' => [],
            'artifact_residue_count' => 0,
        ],
        'telemetry' => [
            'schema' => 1,
            'measurement_sources' => [
                'topology_counts' => 'kernel-task-transport',
                'topology_peak_live_pids' => 'harness-started-finished-intervals-excludes-armed',
                'scope_host_peak' => 'scope-worker-started-finished-intervals',
                'topology_outstanding' => $fixture === 'stateful'
                    ? 'kernel-root-map-only'
                    : 'kernel',
                'prepared_branches' => 'harness-file-probe',
                'plan_memory_footprint' => 'php-used-memory',
                'timings' => 'harness',
            ],
            'timings_ms' => [
                'planning' => $planningMs,
                'environment_prepare' => $environmentPrepareMs,
                'execution' => $executionMs,
                'rendering' => $renderingMs,
                'wall' => round((hrtime(true) - $proofStartedNs) / 1_000_000, 3),
            ],
            'topology' => $topology,
            'scheduler' => [
                'requested_test_body_lanes' => $processes,
                'observed_test_body_lanes' => $observedTestBodyLanes,
                'observed_active_process_lanes' => $topology['peak_live_pids'],
                'scope_host_peak' => $scopeHostPeak,
                'executor_peak' => $executorPeak,
            ],
            'prepared_branches' => [
                'peak' => $branch['peak'],
                'current_after_run' => $branch['current'],
                'enters' => $branch['enters'],
                'leaves' => $branch['leaves'],
                'enters_by_kind' => $branch['enters_by_kind'],
                'limit' => $combinedBranchLimit,
            ],
        ],
    ];
    echo json_encode(
        $summary,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
} catch (Throwable $throwable) {
    if (isset($workspace) && is_string($workspace) && str_contains($workspace, 'drove-native-phase-5')) {
        try {
            nativePhaseFiveRemove($workspace);
        } catch (Throwable) {
            //
        }
    }

    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
