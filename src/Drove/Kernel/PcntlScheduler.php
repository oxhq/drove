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
final class PcntlScheduler
{
    private const FRAME_LIMIT = 1_048_576;

    /** @var array<string, array{read: resource, write: resource}> */
    private array $pools = [];

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
     * @param  list<array{id: string, scope_id: string, scopes: list<string>, timeout_ms?: int, permit?: bool}>  $tasks
     * @return array{results: list<array<string, mixed>>, completion_order: list<string>}
     */
    public function map(array $tasks, Closure $execute): array
    {
        $children = [];
        $results = [];
        $completionOrder = [];

        foreach ($tasks as $ordinal => $task) {
            $task = $this->normalizeTask($task, $ordinal);
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($sockets === false) {
                $results[$ordinal] = $this->parentFailure(
                    $task,
                    FailureKind::ForkFailure,
                    'Unable to create a Drove child channel.',
                );

                continue;
            }

            [$parentSocket, $childSocket] = $sockets;
            $pid = pcntl_fork();

            if ($pid === -1) {
                fclose($parentSocket);
                fclose($childSocket);
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

                $this->runChild($task, $childSocket, $execute);
            }

            fclose($childSocket);
            stream_set_blocking($parentSocket, false);
            @posix_setpgid($pid, $pid);

            $children[$pid] = [
                'pid' => $pid,
                'task' => $task,
                'socket' => $parentSocket,
                'buffer' => '',
                'frames' => [],
                'protocol_error' => null,
                'started_ns' => null,
                'deadline_ns' => null,
                'finished_ns' => null,
                'finished' => null,
                'reaped' => false,
                'wait_status' => null,
                'eof' => false,
                'timed_out' => false,
                'term_ns' => null,
                'kill_sent' => false,
                'cleanup_term_ns' => null,
                'released' => false,
            ];
        }

        // ponytail: a 1 ms nonblocking poll is enough for the Phase 1 C=8 ceiling;
        // replace it with pidfd/epoll in the native backend.
        while ($children !== []) {
            $now = hrtime(true);

            foreach (array_keys($children) as $pid) {
                $child = &$children[$pid];
                $this->drain($child);

                if (! $child['reaped']) {
                    $waitStatus = 0;
                    $waited = pcntl_waitpid($pid, $waitStatus, WNOHANG);

                    if ($waited === $pid) {
                        $child['reaped'] = true;
                        $child['wait_status'] = $waitStatus;
                        $completionOrder[] = $child['task']['id'];
                    } elseif ($waited === -1 && pcntl_get_last_error() !== PCNTL_EINTR) {
                        $child['reaped'] = true;
                        $child['wait_status'] = null;
                        $child['protocol_error'] ??= 'waitpid() lost the Drove child.';
                        $completionOrder[] = $child['task']['id'];
                    }
                }

                if ($child['started_ns'] !== null
                    && $child['finished'] === null
                    && ! $child['reaped']
                    && $child['task']['timeout_ms'] > 0
                    && ! $child['timed_out']
                    && $now >= $child['deadline_ns']) {
                    @posix_kill(-$pid, SIGTERM);
                    $child['timed_out'] = true;
                    $child['term_ns'] = $now;
                }

                if ($child['timed_out']
                    && ! $child['kill_sent']
                    && $now - $child['term_ns'] >= $this->termGraceMs * 1_000_000) {
                    @posix_kill(-$pid, SIGKILL);
                    $child['kill_sent'] = true;
                }

                if ($child['reaped'] && ! $child['eof'] && ! $child['timed_out']) {
                    if ($child['cleanup_term_ns'] === null) {
                        @posix_kill(-$pid, SIGTERM);
                        $child['cleanup_term_ns'] = $now;
                    } elseif (! $child['kill_sent']
                        && $now - $child['cleanup_term_ns'] >= $this->termGraceMs * 1_000_000) {
                        @posix_kill(-$pid, SIGKILL);
                        $child['kill_sent'] = true;
                    }
                }

                $this->drain($child);

                if ($child['reaped'] && $child['eof']) {
                    if ($child['started_ns'] !== null && $child['task']['permit'] && ! $child['released']) {
                        $this->release($this->poolNames($child['task']['scopes']));
                        $child['released'] = true;
                    }

                    $results[$child['task']['ordinal']] = $this->finish($child);
                    fclose($child['socket']);
                    unset($children[$pid]);
                }

                unset($child);
            }

            if ($children !== []) {
                usleep(1_000);
            }
        }

        ksort($results);

        return [
            'results' => array_values($results),
            'completion_order' => $completionOrder,
        ];
    }

    /**
     * @param  list<string>  $scopes
     */
    public function withPermit(array $scopes, Closure $work): mixed
    {
        $names = $this->poolNames($scopes);
        $this->acquire($names);

        try {
            return $work();
        } finally {
            // ponytail: PHP scope hooks rely on finally; the native broker will
            // reclaim leases after uncatchable host failure.
            $this->release($names);
        }
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{id: string, scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}
     */
    private function normalizeTask(array $task, int $ordinal): array
    {
        $id = $task['id'] ?? null;
        $scopeId = $task['scope_id'] ?? null;
        $scopes = $task['scopes'] ?? null;
        $timeoutMs = $task['timeout_ms'] ?? $this->defaultTimeoutMs;
        $permit = $task['permit'] ?? true;

        if (! is_string($id) || $id === ''
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
            'scope_id' => $scopeId,
            'scopes' => $scopes,
            'timeout_ms' => $timeoutMs,
            'permit' => $permit,
            'ordinal' => $ordinal,
        ];
    }

    /**
     * @param  array{id: string, scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
     * @param  resource  $socket
     */
    private function runChild(array $task, $socket, Closure $execute): never
    {
        if (! @posix_setpgid(0, 0)) {
            $this->writeFrame($socket, $this->frame($task, 0, 'task.started', [
                'started_ns' => hrtime(true),
            ]));
            $this->writeFrame($socket, $this->frame($task, 1, 'task.finished', [
                'status' => 'failed',
                'failure' => [
                    'kind' => FailureKind::ForkFailure->value,
                    'message' => 'The Drove child could not create its process group.',
                    'class' => null,
                    'file' => null,
                    'line' => null,
                    'phase' => 'process_group',
                    'hook_id' => null,
                ],
                'finished_ns' => hrtime(true),
                'stdout' => '',
            ]));
            fclose($socket);

            exit(1);
        }

        $names = $task['permit'] ? $this->poolNames($task['scopes']) : [];

        if ($names !== []) {
            $this->acquire($names);
        }

        $startedNs = hrtime(true);
        $this->writeFrame($socket, $this->frame($task, 0, 'task.started', [
            'started_ns' => $startedNs,
        ]));

        $reported = false;
        $reporterPid = getmypid();
        register_shutdown_function(function () use (&$reported, $reporterPid, $socket, $task): void {
            if (getmypid() !== $reporterPid || $reported) {
                return;
            }

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
                $this->writeFrame($socket, $this->frame($task, 1, 'task.finished', [
                    'status' => 'failed',
                    'failure' => [
                        'kind' => FailureKind::PhpFatalError->value,
                        'message' => $error['message'],
                        'class' => null,
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'phase' => 'executor',
                        'hook_id' => null,
                    ],
                    'finished_ns' => hrtime(true),
                    'stdout' => '',
                ]));
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
            $kind = is_a($throwable, 'PHPUnit\Framework\AssertionFailedError')
                ? FailureKind::AssertionFailure
                : FailureKind::PhpException;
            $failure = [
                'kind' => $kind->value,
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
            $stdout = (string) ob_get_clean().$stdout;
        }

        $payload = [
            'status' => $status,
            'value' => $value,
            'finished_ns' => hrtime(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'stdout' => $stdout,
        ];

        if ($failure !== null) {
            $payload['failure'] = $failure;
        }

        $this->writeFrame($socket, $this->frame($task, 1, 'task.finished', $payload));
        $reported = true;
        fclose($socket);

        exit($status === 'passed' ? 0 : 1);
    }

    /**
     * @param  array{id: string, scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function frame(array $task, int $sequence, string $type, array $payload): array
    {
        return [
            'schema' => 1,
            'run_id' => $this->runId,
            'task_id' => $task['id'],
            'scope_id' => $task['scope_id'],
            'ordinal' => $task['ordinal'],
            'sequence' => $sequence,
            'type' => $type,
            'payload' => $payload,
        ];
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $frame
     */
    private function writeFrame($socket, array $frame): void
    {
        $json = json_encode($frame, JSON_THROW_ON_ERROR);

        if (strlen($json) > self::FRAME_LIMIT) {
            throw new RuntimeException('A Drove child frame exceeded 1 MiB.');
        }

        $payload = pack('N', strlen($json)).$json;
        $written = 0;

        while ($written < strlen($payload)) {
            $bytes = @fwrite($socket, substr($payload, $written));

            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Unable to write a Drove child frame.');
            }

            $written += $bytes;
        }
    }

    /**
     * @param  array<string, mixed>  $child
     */
    private function drain(array &$child): void
    {
        while (true) {
            $chunk = @fread($child['socket'], 65_536);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $child['buffer'] .= $chunk;
        }

        $child['eof'] = feof($child['socket']);

        if ($child['protocol_error'] !== null) {
            return;
        }

        try {
            while (strlen($child['buffer']) >= 4) {
                $header = unpack('Nlength', substr($child['buffer'], 0, 4));
                $length = $header['length'];

                if ($length < 2 || $length > self::FRAME_LIMIT) {
                    throw new RuntimeException('Drove received an invalid child frame length.');
                }

                if (strlen($child['buffer']) < 4 + $length) {
                    break;
                }

                $json = substr($child['buffer'], 4, $length);
                $child['buffer'] = substr($child['buffer'], 4 + $length);
                $frame = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                $this->acceptFrame($child, $frame);
            }

            if ($child['eof'] && $child['buffer'] !== '') {
                throw new RuntimeException('Drove received a truncated child frame.');
            }
        } catch (Throwable $throwable) {
            $child['protocol_error'] = $throwable->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $child
     * @param  mixed  $frame
     */
    private function acceptFrame(array &$child, mixed $frame): void
    {
        $task = $child['task'];
        $sequence = count($child['frames']);

        if (! is_array($frame)
            || ($frame['schema'] ?? null) !== 1
            || ($frame['run_id'] ?? null) !== $this->runId
            || ($frame['task_id'] ?? null) !== $task['id']
            || ($frame['scope_id'] ?? null) !== $task['scope_id']
            || ($frame['ordinal'] ?? null) !== $task['ordinal']
            || ($frame['sequence'] ?? null) !== $sequence
            || ! is_array($frame['payload'] ?? null)) {
            throw new RuntimeException('Drove received an inconsistent child frame.');
        }

        $expectedType = $sequence === 0 ? 'task.started' : 'task.finished';

        if (($frame['type'] ?? null) !== $expectedType || $sequence > 1) {
            throw new RuntimeException('Drove received an invalid child event sequence.');
        }

        if ($sequence === 0) {
            $startedNs = $frame['payload']['started_ns'] ?? null;

            if (! is_int($startedNs)) {
                throw new RuntimeException('Drove received a child start without a monotonic timestamp.');
            }

            $child['started_ns'] = $startedNs;
            $child['deadline_ns'] = $startedNs + $task['timeout_ms'] * 1_000_000;
        } else {
            $status = $frame['payload']['status'] ?? null;
            $finishedNs = $frame['payload']['finished_ns'] ?? null;

            if (! in_array($status, ['passed', 'failed'], true) || ! is_int($finishedNs)) {
                throw new RuntimeException('Drove received an invalid terminal child event.');
            }

            $child['finished'] = $frame;
            $child['finished_ns'] = $finishedNs;
        }

        $child['frames'][] = $frame;
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

        if ($child['timed_out']) {
            return $this->failedResult(
                $task,
                FailureKind::Timeout,
                'The Drove task exceeded its timeout.',
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

            return [
                'id' => $task['id'],
                'scope_id' => $task['scope_id'],
                'ordinal' => $task['ordinal'],
                'status' => $payload['status'],
                'failure' => $payload['failure'] ?? null,
                'value' => $payload['value'] ?? null,
                'stdout' => $payload['stdout'] ?? '',
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
     * @param  array{id: string, scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
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
            'memory_peak_bytes' => null,
            'events' => $events,
            'telemetry' => $telemetry,
        ];
    }

    /**
     * @param  array{id: string, scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
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
    private function release(array $names): void
    {
        foreach (array_reverse($names) as $name) {
            if (@fwrite($this->pools[$name]['write'], '.') !== 1) {
                throw new RuntimeException('Unable to release a Drove concurrency permit.');
            }
        }
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
