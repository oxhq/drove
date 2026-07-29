<?php

declare(strict_types=1);

namespace Drove\Kernel;

use JsonException;
use RuntimeException;

/**
 * @internal
 */
final readonly class ChildProtocol
{
    public const int VERSION = 1;

    private const int CHUNK_SIZE = 524_288;

    private const int FRAME_LIMIT = 1_048_576;

    private const int STREAM_LIMIT = 67_108_864;

    public function __construct(private string $runId)
    {
        //
    }

    /**
     * @param  resource  $socket
     * @param  array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
     */
    public function writeStarted(mixed $socket, array $task, int $startedNs): void
    {
        $this->writeFrame($socket, $this->frame($task, 0, 'task.started', [
            'started_ns' => $startedNs,
        ]));
    }

    /**
     * @param  resource  $socket
     * @param  array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
     * @param  array<string, mixed>|null  $failure
     *
     * @throws JsonException
     */
    public function writeFinished(
        mixed $socket,
        array $task,
        string $status,
        mixed $value,
        ?array $failure,
        int $finishedNs,
        ?int $memoryPeakBytes,
        string $stdout,
        string $stderr,
    ): void {
        $value = json_encode($value, JSON_THROW_ON_ERROR);
        $sequence = 1;

        foreach ([
            'task.stdout' => $stdout,
            'task.stderr' => $stderr,
            'task.value' => $value,
        ] as $type => $bytes) {
            for ($offset = 0; $offset < strlen($bytes); $offset += self::CHUNK_SIZE) {
                $this->writeFrame($socket, $this->frame($task, $sequence++, $type, [
                    'encoding' => 'base64',
                    'data' => base64_encode(substr($bytes, $offset, self::CHUNK_SIZE)),
                ]));
            }
        }

        $this->writeFrame($socket, $this->frame($task, $sequence, 'task.finished', [
            'status' => $status,
            'failure' => $failure,
            'finished_ns' => $finishedNs,
            'memory_peak_bytes' => $memoryPeakBytes,
        ]));
    }

    /**
     * @param  array<string, mixed>  $child
     */
    public function drain(array &$child): void
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

                if ($header === false) {
                    throw new RuntimeException('Drove could not decode a child frame length.');
                }

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
        } catch (\Throwable $throwable) {
            $child['protocol_error'] = $throwable->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $child
     */
    public function value(array $child): mixed
    {
        return json_decode($child['value_buffer'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array{id: string, kind: 'scope'|'test', scope_id: string, scopes: list<string>, timeout_ms: int, permit: bool, ordinal: int}  $task
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function frame(array $task, int $sequence, string $type, array $payload): array
    {
        return [
            'protocol_version' => self::VERSION,
            'run_id' => $this->runId,
            'task_id' => $task['id'],
            'task_kind' => $task['kind'],
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
    private function writeFrame(mixed $socket, array $frame): void
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
    private function acceptFrame(array &$child, mixed $frame): void
    {
        $task = $child['task'];
        $sequence = count($child['frames']);

        if (! is_array($frame)
            || ($frame['protocol_version'] ?? null) !== self::VERSION
            || ($frame['run_id'] ?? null) !== $this->runId
            || ($frame['task_id'] ?? null) !== $task['id']
            || ($frame['task_kind'] ?? null) !== $task['kind']
            || ($frame['scope_id'] ?? null) !== $task['scope_id']
            || ($frame['ordinal'] ?? null) !== $task['ordinal']
            || ($frame['sequence'] ?? null) !== $sequence
            || ! is_array($frame['payload'] ?? null)
            || $child['finished'] !== null) {
            throw new RuntimeException('Drove received an inconsistent child frame.');
        }

        $type = $frame['type'] ?? null;

        if ($sequence === 0) {
            if ($type !== 'task.started' || ! is_int($frame['payload']['started_ns'] ?? null)) {
                throw new RuntimeException('Drove received an invalid child start.');
            }

            $child['started_ns'] = $frame['payload']['started_ns'];
            $child['deadline_ns'] = $child['started_ns'] + $task['timeout_ms'] * 1_000_000;
        } elseif (in_array($type, ['task.stdout', 'task.stderr', 'task.value'], true)) {
            $this->acceptChunk($child, $type, $frame['payload']);
            $frame['payload'] = ['bytes' => strlen($frame['payload']['data'])];
        } elseif ($type === 'task.finished') {
            $this->acceptTerminal($child, $frame);
        } else {
            throw new RuntimeException('Drove received an invalid child event sequence.');
        }

        $child['frames'][] = $frame;
    }

    /**
     * @param  array<string, mixed>  $child
     * @param  array<string, mixed>  $payload
     */
    private function acceptChunk(array &$child, string $type, array $payload): void
    {
        if (($payload['encoding'] ?? null) !== 'base64' || ! is_string($payload['data'] ?? null)) {
            throw new RuntimeException('Drove received an invalid child data chunk.');
        }

        $bytes = base64_decode($payload['data'], true);

        if ($bytes === false) {
            throw new RuntimeException('Drove received invalid base64 child data.');
        }

        $target = match ($type) {
            'task.stdout' => 'stdout',
            'task.stderr' => 'stderr',
            'task.value' => 'value_buffer',
        };

        if (strlen($child[$target]) + strlen($bytes) > self::STREAM_LIMIT) {
            throw new RuntimeException('A Drove child stream exceeded 64 MiB.');
        }

        $child[$target] .= $bytes;
    }

    /**
     * @param  array<string, mixed>  $child
     * @param  array<string, mixed>  $frame
     */
    private function acceptTerminal(array &$child, array $frame): void
    {
        $payload = $frame['payload'];
        $status = $payload['status'] ?? null;
        $failure = $payload['failure'] ?? null;
        $finishedNs = $payload['finished_ns'] ?? null;
        $memoryPeakBytes = $payload['memory_peak_bytes'] ?? null;

        if (! in_array($status, ['passed', 'failed'], true)
            || ! is_int($finishedNs)
            || ($memoryPeakBytes !== null && ! is_int($memoryPeakBytes))
            || ($status === 'passed' && $failure !== null)
            || ($status === 'failed' && ! $this->validFailure($failure))) {
            throw new RuntimeException('Drove received an invalid terminal child event.');
        }

        $child['finished'] = $frame;
        $child['finished_ns'] = $finishedNs;
        $child['terminal_received_ns'] = hrtime(true);
    }

    private function validFailure(mixed $failure): bool
    {
        return is_array($failure)
            && is_string($failure['kind'] ?? null)
            && FailureKind::tryFrom($failure['kind']) instanceof FailureKind
            && is_string($failure['message'] ?? null)
            && is_string($failure['phase'] ?? null)
            && array_key_exists('hook_id', $failure);
    }
}
