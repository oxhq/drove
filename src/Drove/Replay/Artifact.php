<?php

declare(strict_types=1);

namespace Drove\Replay;

use Drove\Version;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Writes a deliberately small, output-free replay artifact. Test output,
 * values, environment variables, and failure messages are excluded because
 * they may contain application secrets.
 *
 * @internal
 */
final class Artifact
{
    private readonly int $rootPid;

    private readonly int $startedNs;

    private readonly string $startedAt;

    private bool $written = false;

    /** @var array{sha256: string, tests: int, scopes: int, environment: ?array<string, mixed>, extensions: ?array<string, mixed>}|null */
    private ?array $plan = null;

    /**
     * @param  list<string>  $arguments
     */
    private function __construct(
        private readonly string $path,
        private readonly bool $onlyOnFailure,
        private readonly array $arguments,
        private readonly int $processes,
        private readonly int $timeoutMs,
    ) {
        $pid = getmypid();

        if (! is_int($pid)) {
            throw new RuntimeException('Drove could not resolve its replay root process.');
        }

        $this->rootPid = $pid;
        $this->startedNs = hrtime(true);
        $this->startedAt = gmdate('c');

        register_shutdown_function($this->writeFatal(...));
    }

    /**
     * @param  list<string>  $arguments
     */
    public static function create(
        string $rootPath,
        string $path,
        bool $onlyOnFailure,
        array $arguments,
        int $processes,
        int $timeoutMs,
    ): self {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Drove requires a valid replay artifact path.');
        }

        if (! self::isAbsolute($path)) {
            $path = $rootPath.DIRECTORY_SEPARATOR.$path;
        }

        $directory = realpath(dirname($path));

        if ($directory === false || ! is_dir($directory) || ! is_writable($directory)) {
            throw new InvalidArgumentException(
                'The Drove replay artifact directory must already exist and be writable.',
            );
        }

        $path = $directory.DIRECTORY_SEPARATOR.basename($path);

        if (file_exists($path)) {
            throw new InvalidArgumentException(
                'Drove will not overwrite an existing replay artifact.',
            );
        }

        return new self(
            $path,
            $onlyOnFailure,
            self::redactArguments($arguments),
            $processes,
            $timeoutMs,
        );
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function recordPlan(array $plan): void
    {
        $encoded = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->plan = [
            'sha256' => hash('sha256', $encoded),
            'tests' => $this->countNodes($plan, 'tests'),
            'scopes' => $this->countScopes($plan),
            'environment' => $this->environmentProjection($plan),
            'extensions' => $this->extensionProjection($plan),
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     */
    public function writeRun(array $run): void
    {
        if ($this->onlyOnFailure && ($run['exit_code'] ?? 1) === 0) {
            return;
        }

        $counts = [];
        $failures = [];
        $memoryPeaks = [memory_get_peak_usage(true)];

        foreach (is_array($run['scopes'] ?? null) ? $run['scopes'] : [] as $scope) {
            if (! is_array($scope)) {
                continue;
            }

            $telemetry = is_array($scope['telemetry'] ?? null)
                ? $scope['telemetry']
                : [];
            $signal = $telemetry['interrupted_signal']
                ?? $telemetry['signal']
                ?? null;
            $memoryPeakBytes = $telemetry['memory_peak_bytes'] ?? null;

            if (is_int($memoryPeakBytes) && $memoryPeakBytes >= 0) {
                $memoryPeaks[] = $memoryPeakBytes;
            }

            foreach (is_array($scope['failures'] ?? null) ? $scope['failures'] : [] as $failure) {
                if (! is_array($failure)) {
                    continue;
                }

                $failures[] = [
                    'test_id' => null,
                    'scope_id' => is_string($scope['id'] ?? null) ? $scope['id'] : null,
                    'kind' => is_string($failure['kind'] ?? null) ? $failure['kind'] : 'unknown',
                    'phase' => is_string($failure['phase'] ?? null) ? $failure['phase'] : 'unknown',
                    'signal' => is_int($signal) ? $signal : null,
                ];
            }
        }

        foreach (is_array($run['tests'] ?? null) ? $run['tests'] : [] as $test) {
            if (! is_array($test)) {
                continue;
            }

            $status = is_string($test['status'] ?? null) ? $test['status'] : 'invalid';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $failure = $test['failure'] ?? null;
            $telemetry = is_array($test['telemetry'] ?? null)
                ? $test['telemetry']
                : [];
            $memoryPeakBytes = $telemetry['memory_peak_bytes'] ?? null;

            if (is_int($memoryPeakBytes) && $memoryPeakBytes >= 0) {
                $memoryPeaks[] = $memoryPeakBytes;
            }

            if (is_array($failure)) {
                $signal = $telemetry['interrupted_signal']
                    ?? $telemetry['signal']
                    ?? null;
                $failures[] = [
                    'test_id' => is_string($test['id'] ?? null) ? $test['id'] : null,
                    'scope_id' => is_string($test['scope_id'] ?? null) ? $test['scope_id'] : null,
                    'kind' => is_string($failure['kind'] ?? null) ? $failure['kind'] : 'unknown',
                    'phase' => is_string($failure['phase'] ?? null) ? $failure['phase'] : 'unknown',
                    'signal' => is_int($signal) ? $signal : null,
                ];
            }
        }

        ksort($counts);

        $this->write([
            ...$this->base('run'),
            'plan' => $this->plan,
            'memory_peak_bytes' => max($memoryPeaks),
            'memory_peak_sample_count' => count($memoryPeaks),
            'result' => [
                'exit_code' => is_int($run['exit_code'] ?? null) ? $run['exit_code'] : 1,
                'status' => is_string($run['status'] ?? null) ? $run['status'] : 'unknown',
                'counts' => $counts,
                'failures' => $failures,
                'completion_order' => is_array($run['completion_order'] ?? null)
                    ? $run['completion_order']
                    : [],
                'observed_concurrency' => is_array($run['observed_concurrency'] ?? null)
                    ? $run['observed_concurrency']
                    : [],
            ],
        ]);
    }

    public function writeCrash(Throwable $throwable): void
    {
        $this->write([
            ...$this->base('crash'),
            'plan' => $this->plan,
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'memory_peak_sample_count' => 1,
            'crash' => [
                'class' => $throwable::class,
                'message_sha256' => hash('sha256', $throwable->getMessage()),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ],
        ]);
    }

    public function path(): string
    {
        return $this->path;
    }

    private function writeFatal(): void
    {
        if ($this->written || getmypid() !== $this->rootPid) {
            return;
        }

        $error = error_get_last();

        if (! is_array($error)
            || ! in_array(
                $error['type'],
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
                true,
            )) {
            return;
        }

        $this->write([
            ...$this->base('fatal'),
            'plan' => $this->plan,
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'memory_peak_sample_count' => 1,
            'fatal' => [
                'type' => $error['type'],
                'message_sha256' => hash('sha256', $error['message']),
                'file' => $error['file'],
                'line' => $error['line'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(array $payload): void
    {
        if ($this->written || getmypid() !== $this->rootPid) {
            return;
        }

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
        $temporary = $this->path.'.tmp.'.$this->rootPid.'.'.bin2hex(random_bytes(6));
        $stream = @fopen($temporary, 'xb');

        if ($stream === false) {
            throw new RuntimeException('Drove could not create its replay artifact.');
        }

        try {
            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('Drove could not secure its replay artifact.');
            }

            $offset = 0;

            while ($offset < strlen($json)) {
                $written = fwrite($stream, substr($json, $offset));

                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException('Drove could not write its replay artifact.');
                }

                $offset += $written;
            }

            if (! fflush($stream)) {
                throw new RuntimeException('Drove could not flush its replay artifact.');
            }
        } catch (Throwable $throwable) {
            @unlink($temporary);

            throw $throwable;
        } finally {
            fclose($stream);
        }

        if (! @link($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException(
                'Drove could not publish its replay artifact without overwriting a file.',
            );
        }

        @unlink($temporary);
        $this->written = true;
    }

    /**
     * @return array<string, mixed>
     */
    private function base(string $kind): array
    {
        return [
            'schema' => 1,
            'kind' => $kind,
            'drove_version' => Version::current(),
            'started_at' => $this->startedAt,
            'duration_ms' => round((hrtime(true) - $this->startedNs) / 1_000_000, 3),
            'platform' => [
                'os_family' => PHP_OS_FAMILY,
                'os' => PHP_OS,
                'architecture' => php_uname('m'),
                'php' => PHP_VERSION,
            ],
            'command' => [
                'arguments' => $this->arguments,
                'processes' => $this->processes,
                'timeout_ms' => $this->timeoutMs,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function countNodes(array $node, string $key): int
    {
        $count = is_array($node[$key] ?? null) ? count($node[$key]) : 0;

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $count += $this->countNodes($child, $key);
            }
        }

        if (is_array($node['root'] ?? null)) {
            $count += $this->countNodes($node['root'], $key);
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function countScopes(array $node): int
    {
        $count = isset($node['type']) ? 1 : 0;

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $count += $this->countScopes($child);
            }
        }

        if (is_array($node['root'] ?? null)) {
            $count += $this->countScopes($node['root']);
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>|null
     */
    private function environmentProjection(array $plan): ?array
    {
        $environment = $plan['environment'] ?? null;

        if (! is_array($environment)
            || ($environment['schema'] ?? null) !== 1
            || ! is_string($environment['coordination'] ?? null)
            || ! is_array($environment['resources'] ?? null)) {
            return null;
        }

        $resources = [];

        foreach ($environment['resources'] as $key => $resource) {
            if (! is_string($key)
                || ! is_array($resource)
                || ! is_string($resource['kind'] ?? null)
                || (($resource['provider'] ?? null) !== null
                    && ! is_string($resource['provider']))
                || ! is_array($resource['capabilities'] ?? null)
                || ! array_is_list($resource['capabilities'])
                || array_any(
                    $resource['capabilities'],
                    static fn (mixed $capability): bool => ! is_string($capability),
                )
                || ! is_array($resource['limitations'] ?? null)
                || ! array_is_list($resource['limitations'])
                || array_any(
                    $resource['limitations'],
                    static fn (mixed $limitation): bool => ! is_string($limitation),
                )) {
                return null;
            }

            $provider = $resource['provider'] ?? null;

            if (is_string($provider)
                && (strlen($provider) > 64
                    || preg_match('/^[a-z][a-z0-9]*(?:[-_.][a-z0-9]+)*$/i', $provider) !== 1)) {
                $provider = '[REDACTED]';
            }

            $resources[$key] = [
                'kind' => $resource['kind'],
                'provider' => $provider,
                'capabilities' => $resource['capabilities'],
            ];
        }

        return [
            'schema' => 1,
            'coordination' => $environment['coordination'],
            'resources' => $resources,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{schema: 1, extensions: list<array{id: string, api_version: int}>}|null
     */
    private function extensionProjection(array $plan): ?array
    {
        $metadata = $plan['root']['metadata']['extensions'] ?? null;

        if (! is_array($metadata)
            || ($metadata['schema'] ?? null) !== 1
            || ! is_array($metadata['extensions'] ?? null)
            || ! array_is_list($metadata['extensions'])) {
            return null;
        }

        $extensions = [];

        foreach ($metadata['extensions'] as $extension) {
            if (! is_array($extension)
                || ! is_string($extension['id'] ?? null)
                || preg_match(
                    '~^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$~D',
                    $extension['id'],
                ) !== 1
                || ! is_int($extension['api_version'] ?? null)
                || $extension['api_version'] < 1) {
                return null;
            }

            if (isset($extensions[$extension['id']])) {
                return null;
            }

            $extensions[$extension['id']] = [
                'id' => $extension['id'],
                'api_version' => $extension['api_version'],
            ];
        }

        ksort($extensions, SORT_STRING);

        return ['schema' => 1, 'extensions' => array_values($extensions)];
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private static function redactArguments(array $arguments): array
    {
        $redacted = [];
        $redactNext = false;

        foreach ($arguments as $argument) {
            if ($redactNext) {
                $redacted[] = '[REDACTED]';
                $redactNext = false;

                continue;
            }

            if (preg_match('/^--[^=]*(?:token|password|secret|key)$/i', $argument) === 1) {
                $redacted[] = $argument;
                $redactNext = true;

                continue;
            }

            if (preg_match('/^(--[^=]*(?:token|password|secret|key))=.*/i', $argument, $match) === 1) {
                $redacted[] = $match[1].'=[REDACTED]';

                continue;
            }

            $redacted[] = $argument;
        }

        return $redacted;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
