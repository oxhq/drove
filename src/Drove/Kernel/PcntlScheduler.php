<?php

declare(strict_types=1);

namespace Drove\Kernel;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class PcntlScheduler implements Scheduler
{
    /** @var array<string, array{read: resource, write: resource}> */
    private array $pools;

    private readonly ChildProtocol $protocol;

    /** @var array<int, true> */
    private array $activeChildGroups = [];

    /** @var list<resource> */
    private array $ancestorSockets = [];

    /** @var array<string, int> */
    private array $heldPermits = [];

    /**
     * @param  array<string, int>  $scopeConcurrency
     */
    public function __construct(
        private readonly string $runId,
        int $concurrency,
        array $scopeConcurrency = [],
        private readonly int $defaultTimeoutMs = 1_000,
        private readonly int $termGraceMs = 50,
    ) {
        foreach ([
            'pcntl_fork',
            'pcntl_waitpid',
            'posix_kill',
            'posix_setpgid',
            'stream_socket_pair',
        ] as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException(sprintf('Drove requires %s().', $function));
            }
        }

        if ($runId === '' || $concurrency < 1 || $defaultTimeoutMs < 1 || $termGraceMs < 1) {
            throw new InvalidArgumentException('Drove received invalid scheduler configuration.');
        }

        $this->protocol = new ChildProtocol($runId);
        $this->pools['@global'] = $this->createPool($concurrency);

        foreach ($scopeConcurrency as $scopeId => $limit) {
            if ($scopeId === '' || $limit < 1) {
                throw new InvalidArgumentException('Drove scope concurrency limits must be positive.');
            }

            $this->pools['scope:'.$scopeId] = $this->createPool($limit);
        }
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
        $pending = [];
        $children = [];
        $results = [];
        $completionOrder = [];
        $interruption = new class
        {
            private ?int $signal = null;

            public function capture(int $signal): void
            {
                $this->signal ??= $signal;
            }

            /**
             * @phpstan-impure
             */
            public function current(): ?int
            {
                pcntl_signal_dispatch();

                return $this->signal;
            }
        };

        foreach ($tasks as $ordinal => $task) {
            $pending[$ordinal] = $this->normalizeTask($task, $ordinal);
        }

        $previousAsyncSignals = pcntl_async_signals(false);
        $previousHandlers = [
            SIGINT => pcntl_signal_get_handler(SIGINT),
            SIGTERM => pcntl_signal_get_handler(SIGTERM),
        ];
        $interrupt = static function (int $signal) use ($interruption): void {
            $interruption->capture($signal);
        };
        pcntl_signal(SIGINT, $interrupt);
        pcntl_signal(SIGTERM, $interrupt);
        pcntl_async_signals(true);

        // ponytail: the PHP backend polls a shared pipe; native Drover owns the
        // production queue and fairness policy.
        try {
            while ($pending !== [] || $children !== []) {
                foreach (array_keys($pending) as $ordinal) {
                    if ($interruption->current() !== null) {
                        break;
                    }

                    $task = $pending[$ordinal];
                    $permitNames = $task['permit'] ? $this->poolNames($task['scopes']) : [];

                    if ($permitNames !== [] && ! $this->tryAcquire($permitNames)) {
                        continue;
                    }

                    if ($interruption->current() !== null) {
                        $this->release($permitNames);

                        break;
                    }

                    $this->rememberPermits($permitNames);
                    unset($pending[$ordinal]);
                    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

                    if ($sockets === false) {
                        if ($permitNames !== []) {
                            $this->forgetPermits($permitNames);
                            $this->release($permitNames);
                        }

                        $results[$ordinal] = $this->parentFailure(
                            $task,
                            FailureKind::ForkFailure,
                            'Unable to create a Drove child channel.',
                        );

                        continue;
                    }

                    [$parentSocket, $childSocket] = $sockets;

                    if (($signal = $interruption->current()) !== null) {
                        fclose($parentSocket);
                        fclose($childSocket);
                        $this->forgetPermits($permitNames);
                        $this->release($permitNames);
                        $results[$ordinal] = $this->parentFailure(
                            $task,
                            FailureKind::UserInterruption,
                            sprintf('The Drove run was interrupted by signal %d.', $signal),
                        );

                        break;
                    }

                    $pid = pcntl_fork();

                    if ($pid === -1) {
                        fclose($parentSocket);
                        fclose($childSocket);

                        if ($permitNames !== []) {
                            $this->forgetPermits($permitNames);
                            $this->release($permitNames);
                        }

                        $results[$ordinal] = $this->parentFailure(
                            $task,
                            FailureKind::ForkFailure,
                            'Unable to fork a Drove task.',
                        );

                        continue;
                    }

                    if ($pid === 0) {
                        fclose($parentSocket);

                        foreach ($children as $sibling) {
                            fclose($sibling['socket']);
                        }

                        foreach ($this->ancestorSockets as $ancestorSocket) {
                            if (is_resource($ancestorSocket)) {
                                fclose($ancestorSocket);
                            }
                        }

                        $this->ancestorSockets = [];
                        $this->runChild($task, $childSocket, $execute);
                    }

                    fclose($childSocket);
                    stream_set_blocking($parentSocket, false);
                    @posix_setpgid($pid, $pid);
                    $this->activeChildGroups[$pid] = true;

                    $children[$pid] = [
                        'pid' => $pid,
                        'task' => $task,
                        'socket' => $parentSocket,
                        'buffer' => '',
                        'frames' => [],
                        'stdout' => '',
                        'stderr' => '',
                        'value_buffer' => '',
                        'protocol_error' => null,
                        'started_ns' => null,
                        'deadline_ns' => null,
                        'finished_ns' => null,
                        'terminal_received_ns' => null,
                        'finished' => null,
                        'reaped' => false,
                        'wait_status' => null,
                        'eof' => false,
                        'timed_out' => false,
                        'term_ns' => null,
                        'interrupted_signal' => null,
                        'interruption_term_ns' => null,
                        'kill_sent' => false,
                        'kill_ns' => null,
                        'cleanup_term_ns' => null,
                        'cleanup_failed' => false,
                        'permit_names' => $permitNames,
                        'released' => false,
                    ];
                }

                $now = hrtime(true);

                if (($signal = $interruption->current()) !== null) {
                    foreach ($pending as $ordinal => $task) {
                        $results[$ordinal] = $this->parentFailure(
                            $task,
                            FailureKind::UserInterruption,
                            sprintf('The Drove run was interrupted by signal %d.', $signal),
                        );
                    }

                    $pending = [];

                    foreach ($children as $pid => &$child) {
                        if ($child['interrupted_signal'] === null) {
                            $child['interrupted_signal'] = $signal;
                            $child['interruption_term_ns'] = $now;
                            @posix_kill(-$pid, SIGTERM);
                        }
                    }

                    unset($child);
                }

                foreach (array_keys($children) as $pid) {
                    $child = &$children[$pid];
                    $this->protocol->drain($child);

                    if (! $child['reaped']) {
                        $waitStatus = 0;
                        $waited = pcntl_waitpid($pid, $waitStatus, WNOHANG);

                        if ($waited === $pid) {
                            $child['reaped'] = true;
                            $child['wait_status'] = $waitStatus;
                            $child['finished_ns'] ??= hrtime(true);
                            $completionOrder[] = $child['task']['id'];
                        } elseif ($waited === -1 && pcntl_get_last_error() !== PCNTL_EINTR) {
                            $child['reaped'] = true;
                            $child['wait_status'] = null;
                            $child['finished_ns'] ??= hrtime(true);
                            $child['protocol_error'] ??= 'waitpid() lost the Drove child.';
                            $completionOrder[] = $child['task']['id'];
                        }
                    }

                    if ($child['started_ns'] !== null
                        && ! $child['reaped']
                        && $child['task']['timeout_ms'] > 0
                        && $child['interrupted_signal'] === null
                        && ! $child['timed_out']
                        && $now >= max(
                            $child['deadline_ns'],
                            $child['terminal_received_ns'] === null
                                ? 0
                                : $child['terminal_received_ns'] + $this->termGraceMs * 1_000_000,
                        )) {
                        @posix_kill(-$pid, SIGTERM);
                        $child['timed_out'] = true;
                        $child['term_ns'] = $now;
                    }

                    if ($child['timed_out']
                        && ! $child['kill_sent']
                        && $now - $child['term_ns'] >= $this->termGraceMs * 1_000_000) {
                        @posix_kill(-$pid, SIGKILL);
                        $child['kill_sent'] = true;
                        $child['kill_ns'] = $now;
                    }

                    if ($child['interrupted_signal'] !== null
                        && ! $child['kill_sent']
                        && $now - $child['interruption_term_ns'] >= $this->termGraceMs * 1_000_000) {
                        @posix_kill(-$pid, SIGKILL);
                        $child['kill_sent'] = true;
                        $child['kill_ns'] = $now;
                    }

                    if ($child['reaped']
                        && ! $child['eof']
                        && ! $child['timed_out']
                        && $child['interrupted_signal'] === null) {
                        if ($child['cleanup_term_ns'] === null) {
                            @posix_kill(-$pid, SIGTERM);
                            $child['cleanup_term_ns'] = $now;
                        } elseif (! $child['kill_sent']
                            && $now - $child['cleanup_term_ns'] >= $this->termGraceMs * 1_000_000) {
                            @posix_kill(-$pid, SIGKILL);
                            $child['kill_sent'] = true;
                            $child['kill_ns'] = $now;
                        }
                    }

                    $this->protocol->drain($child);

                    if ($child['reaped']
                        && ! $child['eof']
                        && $child['kill_sent']
                        && $child['kill_ns'] !== null
                        && $now - $child['kill_ns'] >= $this->termGraceMs * 1_000_000) {
                        $child['cleanup_failed'] = true;
                        $child['eof'] = true;
                    }

                    if ($child['reaped'] && $child['eof']) {
                        if ($child['permit_names'] !== [] && ! $child['released']) {
                            $this->forgetPermits($child['permit_names']);
                            $this->release($child['permit_names']);
                            $child['released'] = true;
                        }

                        $results[$child['task']['ordinal']] = $this->finish($child);
                        fclose($child['socket']);
                        unset($this->activeChildGroups[$pid]);
                        unset($children[$pid]);
                    }

                    unset($child);
                }

                if ($pending !== [] || $children !== []) {
                    usleep(1_000);
                }
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
        }
    }

    /**
     * @param  list<string>  $scopes
     */
    public function withPermit(array $scopes, Closure $work): mixed
    {
        $names = $this->poolNames($scopes);
        $this->acquire($names);
        $this->rememberPermits($names);

        try {
            return $work();
        } finally {
            $this->forgetPermits($names);
            $this->release($names);
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

        if (! is_string($id) || $id === ''
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
     * @param  resource  $socket
     */
    private function runChild(array $task, mixed $socket, Closure $execute): never
    {
        $this->activeChildGroups = [];
        $this->heldPermits = [];
        $this->ancestorSockets[] = $socket;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);

        if ($task['kind'] === 'scope') {
            pcntl_signal(SIGTERM, function (): never {
                $this->terminateActiveChildren();
                $this->releaseHeldPermits();

                exit(128 + SIGTERM);
            });
        }

        if (! @posix_setpgid(0, 0)) {
            $this->protocol->writeStarted($socket, $task, hrtime(true));
            $this->protocol->writeFinished(
                $socket,
                $task,
                'failed',
                null,
                [
                    'kind' => FailureKind::ForkFailure->value,
                    'message' => 'The Drove child could not create its process group.',
                    'class' => null,
                    'file' => null,
                    'line' => null,
                    'phase' => 'process_group',
                    'hook_id' => null,
                ],
                hrtime(true),
                memory_get_peak_usage(true),
                '',
                '',
            );
            fclose($socket);

            exit(1);
        }

        $startedNs = hrtime(true);
        $this->protocol->writeStarted($socket, $task, $startedNs);

        $report = new class
        {
            public bool $finished = false;
        };
        $reporterPid = getmypid();
        $emergencyReserve = str_repeat(' ', 262_144);
        register_shutdown_function(function () use (&$emergencyReserve, $report, $reporterPid, $socket, $task): void {
            $emergencyReserve = null;

            if (getmypid() !== $reporterPid || $report->finished) {
                return;
            }

            $this->terminateActiveChildren();
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
        } catch (\JsonException $exception) {
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

        exit($status === 'passed' ? 0 : 1);
    }

    /**
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    private function finish(array $child): array
    {
        $task = $child['task'];
        $waitStatus = $child['wait_status'];
        $exitCode = is_int($waitStatus) && pcntl_wifexited($waitStatus)
            ? pcntl_wexitstatus($waitStatus)
            : null;
        $signal = is_int($waitStatus) && pcntl_wifsignaled($waitStatus)
            ? pcntl_wtermsig($waitStatus)
            : null;
        $finishedNs = $child['finished_ns'] ?? hrtime(true);
        $telemetry = [
            'pid' => $child['pid'],
            'pgid' => $child['pid'],
            'started_ns' => $child['started_ns'],
            'finished_ns' => $finishedNs,
            'duration_ms' => $child['started_ns'] === null
                ? null
                : round(($finishedNs - $child['started_ns']) / 1_000_000, 3),
            'exit_code' => $exitCode,
            'signal' => $signal,
        ];

        if ($child['interrupted_signal'] !== null) {
            return $this->failedResult(
                $task,
                FailureKind::UserInterruption,
                sprintf('The Drove run was interrupted by signal %d.', $child['interrupted_signal']),
                $telemetry,
                $child['frames'],
            );
        }

        if ($child['timed_out']) {
            return $this->failedResult(
                $task,
                FailureKind::Timeout,
                'The Drove task exceeded its timeout.',
                $telemetry,
                $child['frames'],
            );
        }

        if ($child['cleanup_failed']) {
            return $this->failedResult(
                $task,
                FailureKind::BlockedDescendant,
                'A Drove descendant kept the task channel open after forced cleanup.',
                $telemetry,
                $child['frames'],
            );
        }

        if ($signal !== null) {
            return $this->failedResult(
                $task,
                FailureKind::SignalTermination,
                sprintf('The Drove task ended from signal %d.', $signal),
                $telemetry,
                $child['frames'],
            );
        }

        if ($child['protocol_error'] !== null) {
            return $this->failedResult(
                $task,
                FailureKind::ChildProtocolFailure,
                $child['protocol_error'],
                $telemetry,
                $child['frames'],
            );
        }

        if (is_array($child['finished'])) {
            $payload = $child['finished']['payload'];
            $expectedExitCode = $payload['status'] === 'passed' ? 0 : null;

            if ($expectedExitCode === 0 && $exitCode !== 0) {
                return $this->failedResult(
                    $task,
                    FailureKind::ChildProtocolFailure,
                    'A passing Drove task exited unsuccessfully.',
                    $telemetry,
                    $child['frames'],
                );
            }

            try {
                $value = $this->protocol->value($child);
            } catch (Throwable $throwable) {
                return $this->failedResult(
                    $task,
                    FailureKind::ChildProtocolFailure,
                    $throwable->getMessage(),
                    $telemetry,
                    $child['frames'],
                );
            }

            return [
                'id' => $task['id'],
                'kind' => $task['kind'],
                'scope_id' => $task['scope_id'],
                'ordinal' => $task['ordinal'],
                'status' => $payload['status'],
                'failure' => $payload['failure'] ?? null,
                'value' => $value,
                'stdout' => $child['stdout'],
                'stderr' => $child['stderr'],
                'memory_peak_bytes' => $payload['memory_peak_bytes'] ?? null,
                'events' => $child['frames'],
                'telemetry' => $telemetry,
            ];
        }

        return $this->failedResult(
            $task,
            FailureKind::ChildProtocolFailure,
            sprintf('The Drove task exited without a terminal result (exit %s).', $exitCode ?? 'unknown'),
            $telemetry,
            $child['frames'],
        );
    }

    /**
     * @param  array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
     * @param  array<string, mixed>  $telemetry
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function failedResult(
        array $task,
        FailureKind $kind,
        string $message,
        array $telemetry,
        array $events,
    ): array {
        return [
            'id' => $task['id'],
            'kind' => $task['kind'],
            'scope_id' => $task['scope_id'],
            'ordinal' => $task['ordinal'],
            'status' => 'failed',
            'failure' => [
                'kind' => $kind->value,
                'message' => $message,
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
            'events' => $events,
            'telemetry' => $telemetry,
        ];
    }

    /**
     * @param  array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
     * @return array<string, mixed>
     */
    private function parentFailure(array $task, FailureKind $kind, string $message): array
    {
        return $this->failedResult($task, $kind, $message, [
            'pid' => null,
            'pgid' => null,
            'started_ns' => null,
            'finished_ns' => null,
            'duration_ms' => null,
            'exit_code' => null,
            'signal' => null,
        ], []);
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function poolNames(array $scopes): array
    {
        $names = [];

        foreach (array_reverse($scopes) as $scope) {
            $name = 'scope:'.$scope;

            if (isset($this->pools[$name])) {
                $names[] = $name;
            }
        }

        $names[] = '@global';

        return $names;
    }

    /**
     * @param  list<string>  $names
     */
    private function acquire(array $names): void
    {
        foreach ($names as $name) {
            do {
                $token = @fread($this->pools[$name]['read'], 1);
            } while ($token === false || $token === '');
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function tryAcquire(array $names): bool
    {
        $acquired = [];

        foreach ($names as $name) {
            $token = @fread($this->pools[$name]['read'], 1);

            if ($token !== '.') {
                $this->release($acquired);

                return false;
            }

            $acquired[] = $name;
        }

        return true;
    }

    /**
     * @param  list<string>  $names
     */
    private function release(array $names): void
    {
        foreach (array_reverse($names) as $name) {
            if (@fwrite($this->pools[$name]['write'], '.') !== 1) {
                throw new RuntimeException('Unable to release a Drove concurrency permit.');
            }
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function rememberPermits(array $names): void
    {
        foreach ($names as $name) {
            $this->heldPermits[$name] = ($this->heldPermits[$name] ?? 0) + 1;
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function forgetPermits(array $names): void
    {
        foreach ($names as $name) {
            $this->heldPermits[$name]--;

            if ($this->heldPermits[$name] === 0) {
                unset($this->heldPermits[$name]);
            }
        }
    }

    private function releaseHeldPermits(): void
    {
        foreach ($this->heldPermits as $name => $count) {
            $this->release(array_fill(0, $count, $name));
        }

        $this->heldPermits = [];
    }

    private function terminateActiveChildren(): void
    {
        foreach (array_keys($this->activeChildGroups) as $pid) {
            @posix_kill(-$pid, SIGKILL);
        }

        $this->activeChildGroups = [];
    }

    /**
     * @return array{read: resource, write: resource}
     */
    private function createPool(int $limit): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create a Drove concurrency permit pool.');
        }

        [$read, $write] = $sockets;
        stream_set_blocking($read, false);
        stream_set_read_buffer($read, 0);
        stream_set_write_buffer($write, 0);

        for ($permit = 0; $permit < $limit; $permit++) {
            if (fwrite($write, '.') !== 1) {
                throw new RuntimeException('Unable to initialize Drove concurrency permits.');
            }
        }

        return ['read' => $read, 'write' => $write];
    }
}
