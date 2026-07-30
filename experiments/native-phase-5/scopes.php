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
        'timeout_ms' => 0,
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
    $executor = new LifecycleExecutor(
        $scheduler,
        static function (string $id) use ($hooks): Closure {
            $hook = $hooks[$id] ?? null;
            nativePhaseFiveAssert(
                $hook instanceof Closure,
                'Native Phase 5 could not resolve lifecycle hook '.$id.'.',
            );

            return $hook;
        },
        static function (
            string $id,
            ScopeContext $scope = new ScopeContext,
        ) use ($fixture): Closure {
            $scopeId = $scope->metadata()['id'] ?? 'phase5-suite';
            $expected = str_starts_with((string) $scopeId, 'stateful-a')
                ? 11
                : (str_starts_with((string) $scopeId, 'stateful-b') ? 22 : 0);

            return static function () use ($expected, $fixture, $id): string {
                if ($fixture === 'stateful') {
                    nativePhaseFiveAssert(
                        NativePhaseFiveScopeState::$value === $expected,
                        'A stateful test did not inherit its scope snapshot.',
                    );
                }

                usleep($fixture === 'inactive' ? 250_000 : 20_000);

                return hash('sha256', $fixture.':'.$id.':'.$expected);
            };
        },
        beforeDispatch: $branchProbe->beforeDispatch(...),
        enterDescendant: $branchProbe->enterDescendant(...),
        leaveDescendant: $branchProbe->leaveDescendant(...),
        afterDispatch: $branchProbe->afterDispatch(...),
    );
    nativePhaseFivePhase('execution');
    $executionStartedNs = hrtime(true);
    $run = $executor->run($plan, $context);
    $executionMs = round((hrtime(true) - $executionStartedNs) / 1_000_000, 3);
    nativePhaseFiveAssert(
        ($run['status'] ?? null) === 'passed'
            && ($run['exit_code'] ?? null) === 0
            && is_array($run['tests'] ?? null)
            && count($run['tests']) === $testCount
            && array_all(
                $run['tests'],
                static fn (array $test): bool => ($test['status'] ?? null) === 'passed',
            ),
        'Native Phase 5 scope lifecycle did not return every passing terminal result.',
    );
    $expectedScopeIds = [
        'phase5-suite',
        ...array_map(
            static fn (array $child): string => (string) $child['id'],
            $children,
        ),
    ];
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
    $branch = $branchProbe->snapshot();
    $branchFiles = glob($workspace.'/branches/*.branch');
    nativePhaseFiveAssert(
        $branch['current'] === 0
            && $branch['enters'] === $branch['leaves']
            && $branch['enters_by_kind']['test'] === $testCount
            && $branch['peak'] <= 2 * $processes
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
    $intervals = [];

    foreach ($transportTelemetry as $telemetry) {
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

    $topology = [
        'schema' => 1,
        'forks' => array_sum(array_column($transportTelemetry, 'forks')),
        'scope_workers' => array_sum(array_column($transportTelemetry, 'scope_workers')),
        'executor_workers' => array_sum(array_column($transportTelemetry, 'executor_workers')),
        'process_anchors' => array_sum(array_column($transportTelemetry, 'process_anchors')),
        'peak_live_pids' => nativePhaseFivePeakConcurrency($intervals),
        'peak_outstanding_tasks' => $scheduler->topologyTelemetry()['peak_outstanding_tasks'],
        'outstanding_task_limit' => $scheduler->topologyTelemetry()['outstanding_task_limit'],
    ];
    nativePhaseFiveAssertTopology($topology);
    $observedTestBodyLanes = $run['observed_concurrency']['global'] ?? null;
    $expectedScopeWorkers = $fixture === 'stateful' ? 2 : 0;
    nativePhaseFiveAssert(
        is_int($observedTestBodyLanes)
            && $observedTestBodyLanes >= 1
            && $observedTestBodyLanes <= $processes
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
    $remainingChildren = nativePhaseFiveWaitForExit(nativePhaseFiveChildPids(), 2_000);
    nativePhaseFiveAssert(
        $remainingChildren === [],
        'Native Phase 5 scope execution left child processes alive.',
    );
    nativePhaseFivePhase('rendering');
    $renderingStartedNs = hrtime(true);
    $semanticProjection = array_map(
        static fn (array $test): array => [
            'id' => $test['id'] ?? null,
            'scope_id' => $test['scope_id'] ?? null,
            'status' => $test['status'] ?? null,
            'value' => $test['value'] ?? null,
        ],
        $run['tests'],
    );
    usort(
        $semanticProjection,
        static fn (array $left, array $right): int => $left['id'] <=> $right['id'],
    );
    $semanticHash = hash(
        'sha256',
        json_encode($semanticProjection, JSON_THROW_ON_ERROR),
    );
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
        'plan' => [
            'scope_count' => 1 + count($children),
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
                'topology_peak_live_pids' => 'harness-intervals',
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
                'observed_total_processes' => $topology['peak_live_pids'],
            ],
            'prepared_branches' => [
                'peak' => $branch['peak'],
                'current_after_run' => $branch['current'],
                'enters' => $branch['enters'],
                'leaves' => $branch['leaves'],
                'enters_by_kind' => $branch['enters_by_kind'],
                'limit' => 2 * $processes,
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
