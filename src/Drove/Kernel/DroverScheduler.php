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
        void drover_map_cancel(void *map);
        size_t drover_map_last_result_len(void *map);
        int32_t drover_map_copy_last_result(void *map, char *destination, size_t capacity);
        uint32_t drover_map_max_active(void *map);
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

    /**
     * @param  array<string, mixed>  $scopeConcurrency
     */
    public function __construct(
        private readonly string $runId,
        int $concurrency,
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
    }

    public function __destruct()
    {
        if ($this->freed) {
            return;
        }

        try {
            $this->terminateActiveMaps();
            $this->releaseHeldPermits();
            $this->ffi->drover_engine_free($this->engine);
        } catch (Throwable) {
            //
        }

        $this->freed = true;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    /**
     * @param  list<array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms?: int, permit?: bool}>  $tasks
     * @return array{results: list<array<string, mixed>>, completion_order: list<string>}
     */
    public function map(array $tasks, Closure $execute): array
    {
        if ($tasks === []) {
            return ['results' => [], 'completion_order' => []];
        }

        $this->assertWaitableChildren();
        $normalized = [];

        foreach ($tasks as $ordinal => $task) {
            $normalized[$ordinal] = $this->normalizeTask($task, $ordinal);
        }

        $map = $this->ffi->drover_map_new($this->engine, count($normalized));

        if (\FFI::isNull($map)) {
            throw new RuntimeException('Unable to initialize a native Drover task map.');
        }

        $mapId = $this->nextMapId++;
        $this->activeMaps[$mapId] = $map;
        $results = [];
        $completionOrder = [];
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
            foreach ($normalized as $ordinal => $task) {
                $submitted = $this->ffi->drover_map_submit(
                    $map,
                    $task['id'],
                    $task['kind'],
                    $task['scope_id'],
                    $this->encodeScopes($task['scopes']),
                    $task['timeout_ms'],
                    $task['permit'] ? 1 : 0,
                );

                if ($submitted !== $ordinal) {
                    throw new RuntimeException(sprintf('Drover rejected process task %s.', $task['id']));
                }
            }

            $action = $this->ffi->new('DroverAction');

            while (true) {
                if ($interruptedSignal !== null && ! $interruptionSent) {
                    if ($this->ffi->drover_map_interrupt($map, $interruptedSignal) !== 0) {
                        throw new RuntimeException('Drover could not interrupt its active task map.');
                    }

                    $interruptionSent = true;
                }

                $role = $this->ffi->drover_map_step($map, \FFI::addr($action));

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
                    $result = $this->copyResult($map);
                    $results[$result['ordinal']] = $result;

                    if (($result['telemetry']['pid'] ?? null) !== null) {
                        $completionOrder[] = $result['id'];
                    }

                    continue;
                }

                if ($role === self::ROLE_DONE) {
                    break;
                }

                if ($role === self::ROLE_ERROR) {
                    $message = \FFI::string(\FFI::addr($action->message[0]));

                    throw new RuntimeException('Native Drover failed: '.$message);
                }

                throw new RuntimeException('Drover returned an unknown scheduler role.');
            }

            ksort($results);

            return [
                'results' => array_values($results),
                'completion_order' => $completionOrder,
            ];
        } finally {
            pcntl_async_signals(false);

            foreach ($previousHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            pcntl_async_signals($previousAsyncSignals);
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
            throw new RuntimeException('Drover could not acquire lifecycle permits.');
        }

        try {
            return $work();
        } finally {
            if ($this->ffi->drover_engine_release($this->engine, $encoded) !== 0) {
                throw new RuntimeException('Drover could not release lifecycle permits.');
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

            $this->terminateActiveMaps();
            $this->releaseHeldPermits();
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
        $this->releaseHeldPermits();
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
        $this->terminateActiveMaps();
        $this->releaseHeldPermits();

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

    private function terminateActiveMaps(): void
    {
        foreach ($this->activeMaps as $map) {
            $this->ffi->drover_map_cancel($map);
        }

        $this->activeMaps = [];
    }

    private function releaseHeldPermits(): void
    {
        $this->ffi->drover_engine_release_all($this->engine);
    }

    private function assertWaitableChildren(): void
    {
        if (pcntl_signal_get_handler(SIGCHLD) !== SIG_DFL) {
            throw new RuntimeException('Drover requires SIGCHLD to use its default waitable-child disposition.');
        }
    }
}
