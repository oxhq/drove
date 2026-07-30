<?php

declare(strict_types=1);

namespace Drove\Kernel;

use Closure;
use FFI\CData;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class DroverScheduler implements Scheduler
{
    private const int ROLE_ERROR = -1;

    private const int ROLE_PROGRESS = 0;

    private const int ROLE_CHILD = 1;

    private const int ROLE_RESULT = 2;

    private const int ROLE_DONE = 3;

    private const string CDEF = <<<'C'
        typedef struct {
            int32_t role;
            uint32_t ordinal;
            int32_t pid;
            int32_t child_fd;
            int32_t status;
            int32_t timed_out;
            int32_t exit_code;
            int32_t term_signal;
            uint32_t protocol_version;
            uint32_t active_count;
            uint32_t max_active;
            uint32_t frame_count;
            char task_id[512];
            char failure_kind[64];
            char message[256];
        } DroverAction;

        typedef struct {
            uint32_t schema;
            uint64_t forks;
            uint64_t scope_workers;
            uint64_t executor_workers;
            uint64_t process_anchors;
            uint32_t peak_live_pids;
            uint32_t peak_outstanding_tasks;
            uint32_t outstanding_task_limit;
        } DroverTopology;

        uint32_t drover_protocol_version(void);
        size_t drover_protocol_max_frame_bytes(void);
        void *drover_engine_new(
            const char *run_id,
            uint32_t concurrency,
            const char *scope_limits_json,
            uint64_t term_grace_ms
        );
        int32_t drover_engine_acquire(void *engine, const char *scopes_json);
        int32_t drover_engine_release(void *engine, const char *scopes_json);
        int32_t drover_engine_release_all(void *engine);
        size_t drover_engine_error_len(void *engine);
        int32_t drover_engine_copy_error(void *engine, char *destination, size_t capacity);
        void drover_engine_free(void *engine);
        void *drover_map_new(void *engine, uint32_t queue_capacity);
        int32_t drover_map_submit(
            void *map,
            const char *task_id,
            const char *task_kind,
            const char *scope_id,
            const char *scopes_json,
            uint64_t timeout_ms,
            int32_t permit
        );
        int32_t drover_map_step(void *map, DroverAction *action);
        int32_t drover_map_interrupt(void *map, int32_t signal);
        int32_t drover_map_cancel(void *map);
        size_t drover_map_cancel_error_len(void *map);
        int32_t drover_map_copy_cancel_error(void *map, char *destination, size_t capacity);
        size_t drover_map_last_result_len(void *map);
        int32_t drover_map_copy_last_result(void *map, char *destination, size_t capacity);
        uint32_t drover_map_max_active(void *map);
        int32_t drover_map_topology(void *map, DroverTopology *topology);
        void drover_map_free(void *map);
        void drover_child_exit(int32_t status);
    C;

    private readonly mixed $ffi;

    private readonly ChildProtocol $protocol;

    /** @var CData */
    private readonly mixed $engine;

    /** @var array<int, CData> */
    private array $activeMaps = [];

    /** @var list<resource> */
    private array $ancestorSockets = [];

    private int $nextMapId = 0;

    private bool $freed = false;

    private bool $cancellationRequested = false;

    /**
     * @var array{
     *     schema: int,
     *     forks: int,
     *     scope_workers: int,
     *     executor_workers: int,
     *     process_anchors: int,
     *     peak_live_pids: int,
     *     peak_outstanding_tasks: int,
     *     outstanding_task_limit: int
     * }
     */
    private array $topologyTelemetry;

    /**
     * @param  array<string, mixed>  $scopeConcurrency
     */
    public function __construct(
        private readonly string $runId,
        private readonly int $concurrency,
        array $scopeConcurrency = [],
        private readonly int $defaultTimeoutMs = 1_000,
        int $termGraceMs = 50,
        ?string $library = null,
    ) {
        foreach ([
            'pcntl_async_signals',
            'pcntl_signal',
            'pcntl_signal_get_handler',
        ] as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException(sprintf('Drover requires %s().', $function));
            }
        }

        if (! class_exists(\FFI::class)) {
            throw new RuntimeException('Drover requires the PHP FFI extension.');
        }

        if ($runId === ''
            || $concurrency < 1
            || $concurrency > 256
            || $defaultTimeoutMs < 1
            || $termGraceMs < 1) {
            throw new InvalidArgumentException('Drove received invalid scheduler configuration.');
        }

        foreach ($scopeConcurrency as $scopeId => $limit) {
            if ($scopeId === '' || ! is_int($limit) || $limit < 1 || $limit > 256) {
                throw new InvalidArgumentException('Drove scope concurrency limits must be between 1 and 256.');
            }
        }

        $library = NativeLibrary::resolve($library);

        try {
            $this->ffi = $this->loadFfi($library);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Unable to load the native Drover library: '.$throwable->getMessage(), 0, $throwable);
        }

        if ($this->ffi->drover_protocol_version() !== ChildProtocol::VERSION
            || $this->ffi->drover_protocol_max_frame_bytes() !== 1_048_576) {
            throw new RuntimeException('Drover and ChildProtocol v1 are incompatible.');
        }

        $limits = json_encode((object) $scopeConcurrency, JSON_THROW_ON_ERROR);
        $this->engine = $this->ffi->drover_engine_new(
            $runId,
            $concurrency,
            $limits,
            $termGraceMs,
        );

        if (\FFI::isNull($this->engine)) {
            throw new RuntimeException('Unable to initialize the native Drover permit engine.');
        }

        $this->protocol = new ChildProtocol($runId);
        $this->topologyTelemetry = $this->emptyTopologyTelemetry();
    }

    public function __destruct()
    {
        if ($this->freed) {
            return;
        }

        try {
            $this->cancelActiveMapsBestEffort('scheduler destruction');
            $this->releaseHeldPermitsBestEffort('scheduler destruction');
            $this->ffi->drover_engine_free($this->engine);
        } catch (Throwable $throwable) {
            $this->reportBestEffortCleanupFailure('scheduler destruction', $throwable);
        }

        $this->freed = true;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    /**
     * Requests cancellation at the next safe PHP/native boundary.
     */
    public function requestCancellation(): void
    {
        if ($this->activeMaps !== []) {
            $this->cancellationRequested = true;
        }
    }

    /**
     * Returns process-local counters accumulated across maps executed by this scheduler.
     *
     * @return array{
     *     schema: int,
     *     forks: int,
     *     scope_workers: int,
     *     executor_workers: int,
     *     process_anchors: int,
     *     peak_live_pids: int,
     *     peak_outstanding_tasks: int,
     *     outstanding_task_limit: int
     * }
     */
    public function topologyTelemetry(): array
    {
        return $this->topologyTelemetry;
    }

    /**
     * @param  list<array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms?: int, permit?: bool}>  $tasks
     * @return array{
     *     results: list<array<string, mixed>>,
     *     completion_order: list<string>,
     *     telemetry: array{
     *         topology: array{
     *             schema: int,
     *             forks: int,
     *             scope_workers: int,
     *             executor_workers: int,
     *             process_anchors: int,
     *             peak_live_pids: int,
     *             peak_outstanding_tasks: int,
     *             outstanding_task_limit: int
     *         }
     *     }
     * }
     */
    public function map(array $tasks, Closure $execute): array
    {
        if ($tasks === []) {
            return [
                'results' => [],
                'completion_order' => [],
                'telemetry' => ['topology' => $this->emptyTopologyTelemetry()],
            ];
        }

        $this->assertWaitableChildren();
        $normalized = [];
        $taskIds = [];

        foreach ($tasks as $ordinal => $task) {
            $normalizedTask = $this->normalizeTask($task, $ordinal);

            if (isset($taskIds[$normalizedTask['id']])) {
                throw new InvalidArgumentException(sprintf(
                    'Drove received duplicate process task ID %s.',
                    $normalizedTask['id'],
                ));
            }

            $normalized[$ordinal] = $normalizedTask;
            $taskIds[$normalizedTask['id']] = true;
        }

        unset($taskIds);
        $outstandingLimit = 2 * $this->concurrency;
        $map = $this->ffi->drover_map_new($this->engine, $outstandingLimit);

        if (\FFI::isNull($map)) {
            throw new RuntimeException('Unable to initialize a native Drover task map.');
        }

        $mapId = $this->nextMapId++;
        $this->cancellationRequested = false;
        $this->activeMaps[$mapId] = $map;
        $results = [];
        $completionOrder = [];
        $nextOrdinal = 0;
        $outstanding = 0;
        $peakOutstanding = 0;
        $interruptedSignal = null;
        $interruptionSent = false;
        $previousAsyncSignals = pcntl_async_signals(false);
        $previousHandlers = [
            SIGINT => pcntl_signal_get_handler(SIGINT),
            SIGTERM => pcntl_signal_get_handler(SIGTERM),
        ];
        $interrupt = function (int $signal) use (&$interruptedSignal): void {
            $interruptedSignal ??= $signal;

            if ($this->ancestorSockets !== []) {
                $this->exitInterruptedChild($signal);
            }
        };
        pcntl_signal(SIGINT, $interrupt);
        pcntl_signal(SIGTERM, $interrupt);
        pcntl_async_signals(true);

        try {
            $action = $this->ffi->new('DroverAction');

            while (true) {
                if ($interruptedSignal !== null && ! $interruptionSent) {
                    $counter = count($normalized);
                    for ($ordinal = $nextOrdinal; $ordinal < $counter; $ordinal++) {
                        $task = $normalized[$ordinal];
                        $results[$task['ordinal']] = $this->interruptedPendingResult(
                            $task,
                            $interruptedSignal,
                        );
                    }

                    $nextOrdinal = count($normalized);

                    if ($this->ffi->drover_map_interrupt($map, $interruptedSignal) !== 0) {
                        throw new RuntimeException('Drover could not interrupt its active task map.');
                    }

                    $interruptionSent = true;
                }

                while (! $interruptionSent
                    && $nextOrdinal < count($normalized)
                    && $outstanding < $outstandingLimit) {
                    $task = $normalized[$nextOrdinal];
                    $submitted = $this->ffi->drover_map_submit(
                        $map,
                        $task['id'],
                        $task['kind'],
                        $task['scope_id'],
                        $this->encodeScopes($task['scopes']),
                        $task['timeout_ms'],
                        $task['permit'] ? 1 : 0,
                    );

                    if ($submitted !== $nextOrdinal) {
                        throw new RuntimeException(sprintf('Drover rejected process task %s.', $task['id']));
                    }

                    $nextOrdinal++;
                    $outstanding++;
                    $peakOutstanding = max($peakOutstanding, $outstanding);
                }

                $role = $this->ffi->drover_map_step($map, \FFI::addr($action));
                pcntl_signal_dispatch();

                if ($this->consumeCancellationRequest()) {
                    throw new RuntimeException('Drove explicit cancellation requested.');
                }

                if ($role === self::ROLE_PROGRESS) {
                    continue;
                }

                if ($role === self::ROLE_CHILD) {
                    $ordinal = $action->ordinal;
                    $task = $normalized[$ordinal] ?? throw new RuntimeException('Drover returned an unknown task.');
                    $taskId = \FFI::string(\FFI::addr($action->task_id[0]));

                    if ($taskId !== $task['id']) {
                        throw new RuntimeException('Drover returned the wrong task to a PHP child.');
                    }

                    $this->runChild($task, $action->child_fd, $execute);
                }

                if ($role === self::ROLE_RESULT) {
                    if ($outstanding < 1) {
                        throw new RuntimeException('Drover returned a result without an outstanding task.');
                    }

                    $result = $this->copyResult($map);
                    $results[$result['ordinal']] = $result;
                    $outstanding--;

                    if (($result['telemetry']['pid'] ?? null) !== null) {
                        $completionOrder[] = $result['id'];
                    }

                    continue;
                }

                if ($role === self::ROLE_DONE) {
                    if ($nextOrdinal !== count($normalized) || $outstanding !== 0) {
                        throw new RuntimeException('Drover ended before every submitted task reached a terminal result.');
                    }

                    break;
                }

                if ($role === self::ROLE_ERROR) {
                    $message = \FFI::string(\FFI::addr($action->message[0]));

                    throw new RuntimeException('Native Drover failed: '.$message);
                }

                throw new RuntimeException('Drover returned an unknown scheduler role.');
            }

            if (count($results) !== count($normalized)) {
                throw new RuntimeException('Drover did not return exactly one result per process task.');
            }

            $topology = $this->copyTopology($map);

            if ($topology['peak_outstanding_tasks'] !== $peakOutstanding
                || $topology['peak_outstanding_tasks'] > $outstandingLimit
                || $topology['outstanding_task_limit'] !== $outstandingLimit) {
                throw new RuntimeException('Drover reported inconsistent outstanding-task topology.');
            }

            $this->mergeTopologyTelemetry($topology);
            ksort($results);

            return [
                'results' => array_values($results),
                'completion_order' => $completionOrder,
                'telemetry' => ['topology' => $topology],
            ];
        } catch (Throwable $throwable) {
            try {
                $this->cancelActiveMaps();
            } catch (Throwable $cancellation) {
                throw new RuntimeException(
                    $cancellation->getMessage().' Original map failure: '.$throwable->getMessage(),
                    0,
                    $throwable,
                );
            }

            throw $throwable;
        } finally {
            pcntl_async_signals(false);

            foreach ($previousHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            pcntl_async_signals($previousAsyncSignals);
            $this->cancellationRequested = false;
            unset($this->activeMaps[$mapId]);
            $this->ffi->drover_map_free($map);
        }
    }

    /**
     * @param  list<string>  $scopes
     */
    public function withPermit(array $scopes, Closure $work): mixed
    {
        $encoded = $this->encodeScopes($scopes);

        if ($this->ffi->drover_engine_acquire($this->engine, $encoded) !== 0) {
            throw new RuntimeException(
                'Drover could not acquire lifecycle permits: '.$this->copyEngineError(),
            );
        }

        try {
            return $work();
        } finally {
            if ($this->ffi->drover_engine_release($this->engine, $encoded) !== 0) {
                throw new RuntimeException(
                    'Drover could not release lifecycle permits: '.$this->copyEngineError(),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}
     */
    private function normalizeTask(array $task, int $ordinal): array
    {
        $id = $task['id'] ?? null;
        $kind = $task['kind'] ?? null;
        $scopeId = $task['scope_id'] ?? null;
        $scopes = $task['scopes'] ?? null;
        $timeoutMs = $task['timeout_ms'] ?? $this->defaultTimeoutMs;
        $permit = $task['permit'] ?? true;

        if (! is_string($id) || $id === '' || strlen($id) >= 512
            || ! in_array($kind, ['scope', 'test'], true)
            || ! is_string($scopeId) || $scopeId === ''
            || ! is_array($scopes) || ! array_is_list($scopes)
            || array_any($scopes, static fn (mixed $scope): bool => ! is_string($scope) || $scope === '')
            || count(array_unique($scopes)) !== count($scopes)
            || ! is_int($timeoutMs) || $timeoutMs < 0
            || ! is_bool($permit)) {
            throw new InvalidArgumentException('Drove received an invalid process task.');
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'scope_id' => $scopeId,
            'scopes' => $scopes,
            'timeout_ms' => $timeoutMs,
            'permit' => $permit,
            'ordinal' => $ordinal,
        ];
    }

    /**
     * @param  array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
     */
    private function runChild(array $task, int $childFd, Closure $execute): never
    {
        $this->activeMaps = [];

        foreach ($this->ancestorSockets as $ancestorSocket) {
            if (is_resource($ancestorSocket)) {
                fclose($ancestorSocket);
            }
        }

        $this->ancestorSockets = [];
        $socket = @fopen('php://fd/'.$childFd, 'w');

        if ($socket === false) {
            $this->childExit(126);
        }

        $this->ancestorSockets[] = $socket;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);

        if ($task['kind'] === 'scope') {
            pcntl_signal(SIGTERM, function (): never {
                $this->exitInterruptedChild(SIGTERM);
            });
        }

        $startedNs = hrtime(true);
        $this->protocol->writeStarted($socket, $task, $startedNs);
        $report = new class
        {
            public bool $finished = false;
        };
        $reporterPid = getmypid();
        $emergencyReserve = str_repeat(' ', 262_144);
        register_shutdown_function(function () use (
            &$emergencyReserve,
            $report,
            $reporterPid,
            $socket,
            $task,
        ): void {
            $emergencyReserve = null;

            if (getmypid() !== $reporterPid || $report->finished) {
                return;
            }

            $this->cancelActiveMapsBestEffort('fatal child shutdown');
            $this->releaseHeldPermitsBestEffort('fatal child shutdown');
            $error = error_get_last();

            if (! is_array($error) || ! in_array($error['type'], [
                E_ERROR,
                E_PARSE,
                E_CORE_ERROR,
                E_COMPILE_ERROR,
                E_USER_ERROR,
            ], true)) {
                return;
            }

            try {
                $stdout = ob_get_contents();
                $this->protocol->writeFinished(
                    $socket,
                    $task,
                    'failed',
                    null,
                    [
                        'kind' => FailureKind::forFatal($error['message'])->value,
                        'message' => $error['message'],
                        'class' => null,
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'phase' => 'executor',
                        'hook_id' => null,
                    ],
                    hrtime(true),
                    memory_get_peak_usage(true),
                    is_string($stdout) ? $stdout : '',
                    '',
                );
            } catch (Throwable) {
                //
            }
        });

        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        $outputLevel = ob_get_level();
        ob_start();
        $status = 'passed';
        $value = null;
        $failure = null;

        try {
            $value = $execute($task);
        } catch (Throwable $throwable) {
            $status = 'failed';
            $failure = [
                'kind' => FailureKind::for($throwable, 'executor')->value,
                'message' => $throwable->getMessage(),
                'class' => $throwable::class,
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'phase' => 'executor',
                'hook_id' => null,
            ];
        }

        $stdout = '';

        while (ob_get_level() > $outputLevel) {
            $stdout = ob_get_clean().$stdout;
        }

        try {
            $this->protocol->writeFinished(
                $socket,
                $task,
                $status,
                $value,
                $failure,
                hrtime(true),
                memory_get_peak_usage(true),
                $stdout,
                '',
            );
        } catch (JsonException $exception) {
            $status = 'failed';
            $this->protocol->writeFinished(
                $socket,
                $task,
                $status,
                null,
                [
                    'kind' => FailureKind::ChildProtocolFailure->value,
                    'message' => $exception->getMessage(),
                    'class' => $exception::class,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'phase' => 'serialization',
                    'hook_id' => null,
                ],
                hrtime(true),
                memory_get_peak_usage(true),
                $stdout,
                '',
            );
        }

        $report->finished = true;
        $this->releaseHeldPermitsBestEffort('completed child shutdown');
        fclose($socket);

        $this->childExit($status === 'passed' ? 0 : 1);
    }

    private function childExit(int $status): never
    {
        $this->ffi->drover_child_exit($status);

        throw new RuntimeException('Native Drover child exit returned unexpectedly.');
    }

    private function exitInterruptedChild(int $signal): never
    {
        $this->cancelActiveMapsBestEffort('interrupted child shutdown');
        $this->releaseHeldPermitsBestEffort('interrupted child shutdown');

        $this->childExit(128 + $signal);
    }

    /**
     * @param  CData  $map
     * @return array<string, mixed>
     */
    private function copyResult(mixed $map): array
    {
        $length = $this->ffi->drover_map_last_result_len($map);

        if ($length < 2 || $length > 268_435_456) {
            throw new RuntimeException('Drover returned an invalid result size.');
        }

        $buffer = $this->ffi->new(sprintf('char[%d]', $length + 1));

        if ($this->ffi->drover_map_copy_last_result($map, $buffer, $length + 1) !== 0) {
            throw new RuntimeException('Drover could not copy a completed result.');
        }

        $result = json_decode(\FFI::string($buffer, $length), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($result)) {
            throw new RuntimeException('Drover returned an invalid result.');
        }

        return $result;
    }

    /**
     * @param  CData  $map
     * @return array{
     *     schema: int,
     *     forks: int,
     *     scope_workers: int,
     *     executor_workers: int,
     *     process_anchors: int,
     *     peak_live_pids: int,
     *     peak_outstanding_tasks: int,
     *     outstanding_task_limit: int
     * }
     */
    private function copyTopology(mixed $map): array
    {
        $topology = $this->ffi->new('DroverTopology');

        if ($this->ffi->drover_map_topology($map, \FFI::addr($topology)) !== 0) {
            throw new RuntimeException('Drover could not copy its process topology.');
        }

        return [
            'schema' => (int) $topology->schema,
            'forks' => (int) $topology->forks,
            'scope_workers' => (int) $topology->scope_workers,
            'executor_workers' => (int) $topology->executor_workers,
            'process_anchors' => (int) $topology->process_anchors,
            'peak_live_pids' => (int) $topology->peak_live_pids,
            'peak_outstanding_tasks' => (int) $topology->peak_outstanding_tasks,
            'outstanding_task_limit' => (int) $topology->outstanding_task_limit,
        ];
    }

    /**
     * @return array{
     *     schema: int,
     *     forks: int,
     *     scope_workers: int,
     *     executor_workers: int,
     *     process_anchors: int,
     *     peak_live_pids: int,
     *     peak_outstanding_tasks: int,
     *     outstanding_task_limit: int
     * }
     */
    private function emptyTopologyTelemetry(): array
    {
        return [
            'schema' => 1,
            'forks' => 0,
            'scope_workers' => 0,
            'executor_workers' => 0,
            'process_anchors' => 0,
            'peak_live_pids' => 0,
            'peak_outstanding_tasks' => 0,
            'outstanding_task_limit' => 2 * $this->concurrency,
        ];
    }

    /**
     * @param  array{
     *     schema: int,
     *     forks: int,
     *     scope_workers: int,
     *     executor_workers: int,
     *     process_anchors: int,
     *     peak_live_pids: int,
     *     peak_outstanding_tasks: int,
     *     outstanding_task_limit: int
     * }  $topology
     */
    private function mergeTopologyTelemetry(array $topology): void
    {
        foreach (['forks', 'scope_workers', 'executor_workers', 'process_anchors'] as $counter) {
            $this->topologyTelemetry[$counter] += $topology[$counter];
        }

        foreach (['peak_live_pids', 'peak_outstanding_tasks'] as $peak) {
            $this->topologyTelemetry[$peak] = max(
                $this->topologyTelemetry[$peak],
                $topology[$peak],
            );
        }
    }

    /**
     * @param  array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
     * @return array<string, mixed>
     */
    private function interruptedPendingResult(array $task, int $signal): array
    {
        return [
            'id' => $task['id'],
            'kind' => $task['kind'],
            'scope_id' => $task['scope_id'],
            'ordinal' => $task['ordinal'],
            'status' => 'failed',
            'failure' => [
                'kind' => FailureKind::UserInterruption->value,
                'message' => sprintf('The Drove run was interrupted by signal %d.', $signal),
                'class' => null,
                'file' => null,
                'line' => null,
                'phase' => 'scheduler',
                'hook_id' => null,
            ],
            'value' => null,
            'stdout' => '',
            'stderr' => '',
            'memory_peak_bytes' => null,
            'events' => [],
            'telemetry' => [
                'pid' => null,
                'pgid' => null,
                'started_ns' => null,
                'finished_ns' => null,
                'duration_ms' => null,
                'exit_code' => null,
                'signal' => null,
                'interrupted_signal' => $signal,
                'forks' => 0,
                'scope_workers' => 0,
                'executor_workers' => 0,
                'process_anchors' => 0,
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $scopes
     */
    private function encodeScopes(array $scopes): string
    {
        if (! array_is_list($scopes)
            || array_any($scopes, static fn (mixed $scope): bool => ! is_string($scope) || $scope === '')
            || count(array_unique($scopes)) !== count($scopes)) {
            throw new InvalidArgumentException('Drove received invalid scope ancestry.');
        }

        return json_encode($scopes, JSON_THROW_ON_ERROR);
    }

    private function loadFfi(string $library): mixed
    {
        return \FFI::cdef(self::CDEF, $library);
    }

    private function cancelActiveMaps(): void
    {
        $maps = $this->activeMaps;
        $this->activeMaps = [];
        $failures = [];

        foreach ($maps as $mapId => $map) {
            if ($this->ffi->drover_map_cancel($map) !== 0) {
                $failures[] = sprintf(
                    'map %d: %s',
                    $mapId,
                    $this->copyCancellationError($map),
                );
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(
                'Native Drover cancellation failed: '.implode(' | ', $failures),
            );
        }
    }

    /**
     * @param  CData  $map
     */
    private function copyCancellationError(mixed $map): string
    {
        $length = $this->ffi->drover_map_cancel_error_len($map);

        if ($length < 1 || $length > 1_048_576) {
            return 'native cancellation returned no valid diagnostic';
        }

        $buffer = $this->ffi->new(sprintf('char[%d]', $length + 1));

        if ($this->ffi->drover_map_copy_cancel_error($map, $buffer, $length + 1) !== 0) {
            return 'native cancellation diagnostic could not be copied';
        }

        return \FFI::string($buffer, $length);
    }

    private function copyEngineError(): string
    {
        $length = $this->ffi->drover_engine_error_len($this->engine);

        if ($length < 1 || $length > 1_048_576) {
            return 'native engine returned no valid diagnostic';
        }

        $buffer = $this->ffi->new(sprintf('char[%d]', $length + 1));

        if ($this->ffi->drover_engine_copy_error($this->engine, $buffer, $length + 1) !== 0) {
            return 'native engine diagnostic could not be copied';
        }

        return \FFI::string($buffer, $length);
    }

    private function cancelActiveMapsBestEffort(string $phase): void
    {
        try {
            $this->cancelActiveMaps();
        } catch (Throwable $throwable) {
            $this->reportBestEffortCleanupFailure($phase, $throwable);
        }
    }

    /**
     * @phpstan-impure
     */
    private function consumeCancellationRequest(): bool
    {
        $requested = $this->cancellationRequested;
        $this->cancellationRequested = false;

        return $requested;
    }

    private function reportBestEffortCleanupFailure(string $phase, Throwable $throwable): void
    {
        $message = sprintf(
            'Drove best-effort cleanup failure during %s: %s',
            $phase,
            $throwable->getMessage(),
        );

        try {
            if (defined('STDERR')
                && is_resource(STDERR)
                && fwrite(STDERR, $message.PHP_EOL) !== false) {
                return;
            }
        } catch (Throwable) {
            //
        }

        try {
            error_log($message);
        } catch (Throwable) {
            //
        }
    }

    private function releaseHeldPermits(): void
    {
        if ($this->ffi->drover_engine_release_all($this->engine) !== 0) {
            throw new RuntimeException(
                'Native Drover engine cleanup failed: '.$this->copyEngineError(),
            );
        }
    }

    private function releaseHeldPermitsBestEffort(string $phase): void
    {
        try {
            $this->releaseHeldPermits();
        } catch (Throwable $throwable) {
            $this->reportBestEffortCleanupFailure($phase, $throwable);
        }
    }

    private function assertWaitableChildren(): void
    {
        if (pcntl_signal_get_handler(SIGCHLD) !== SIG_DFL) {
            throw new RuntimeException('Drover requires SIGCHLD to use its default waitable-child disposition.');
        }
    }
}
