<?php

declare(strict_types=1);

use Drove\Kernel\ChildProtocol;
use Drove\Kernel\DroverScheduler;
use Drove\Kernel\NativeLibrary;

function nativePhaseFiveFail(string $message): never
{
    throw new RuntimeException($message);
}

/** @phpstan-assert true $condition */
function nativePhaseFiveAssert(bool $condition, string $message): void
{
    if (! $condition) {
        nativePhaseFiveFail($message);
    }
}

/**
 * @return array{
 *     evidence_revision: string,
 *     runtime_platform: array{os_family: string, os: string, architecture: string, php: string},
 *     drover_identity: array{
 *         scheduler_class: class-string<DroverScheduler>,
 *         target: string,
 *         library_sha256: string,
 *         protocol_version: int,
 *         protocol_max_frame_bytes: int
 *     }
 * }
 */
function nativePhaseFiveIdentity(): array
{
    $revision = getenv('DROVE_EVIDENCE_REVISION');

    nativePhaseFiveAssert(
        is_string($revision)
            && preg_match('/\A[a-f0-9]{40}\z/D', $revision) === 1,
        'DROVE_EVIDENCE_REVISION must be the exact 40-character Git SHA.',
    );

    $library = NativeLibrary::resolve();
    $hash = hash_file('sha256', $library);

    nativePhaseFiveAssert(
        is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1,
        'Could not hash the resolved Drover library.',
    );

    return [
        'evidence_revision' => $revision,
        'runtime_platform' => [
            'os_family' => PHP_OS_FAMILY,
            'os' => PHP_OS,
            'architecture' => php_uname('m'),
            'php' => PHP_VERSION,
        ],
        'drover_identity' => [
            'scheduler_class' => DroverScheduler::class,
            'target' => NativeLibrary::target(),
            'library_sha256' => $hash,
            'protocol_version' => ChildProtocol::VERSION,
            'protocol_max_frame_bytes' => 1_048_576,
        ],
    ];
}

function nativePhaseFiveAssertPhase(string $phase): void
{
    nativePhaseFiveAssert(
        in_array(
            $phase,
            ['bootstrap', 'planning', 'environment_prepare', 'execution', 'rendering'],
            true,
        ),
        'Native Phase 5 received an invalid measurement phase.',
    );
}

function nativePhaseFiveReadPhase(string $path): string
{
    $stream = @fopen($path, 'rb');
    nativePhaseFiveAssert(
        is_resource($stream),
        'Could not open the native Phase 5 measurement phase.',
    );

    try {
        nativePhaseFiveAssert(
            flock($stream, LOCK_SH),
            'Could not lock the native Phase 5 measurement phase for reading.',
        );

        try {
            $contents = stream_get_contents($stream);
            nativePhaseFiveAssert(
                is_string($contents),
                'Could not read the native Phase 5 measurement phase.',
            );
        } finally {
            flock($stream, LOCK_UN);
        }
    } finally {
        fclose($stream);
    }

    $phase = trim($contents);
    nativePhaseFiveAssertPhase($phase);

    return $phase;
}

function nativePhaseFivePhase(string $phase): void
{
    $path = getenv('DROVE_PHASE5_PHASE_FILE');

    if (! is_string($path) || $path === '') {
        return;
    }

    nativePhaseFiveAssertPhase($phase);
    $stream = @fopen($path, 'c+b');
    nativePhaseFiveAssert(
        is_resource($stream),
        'Could not open the native Phase 5 measurement phase.',
    );

    try {
        nativePhaseFiveAssert(
            flock($stream, LOCK_EX),
            'Could not lock the native Phase 5 measurement phase for writing.',
        );

        try {
            nativePhaseFiveAssert(
                rewind($stream)
                    && ftruncate($stream, 0)
                    && fwrite($stream, $phase) === strlen($phase)
                    && fflush($stream),
                'Could not publish the native Phase 5 measurement phase.',
            );
        } finally {
            flock($stream, LOCK_UN);
        }
    } finally {
        fclose($stream);
    }
}

/**
 * @return array{read_bytes: int, write_bytes: int, cancelled_write_bytes: int}|null
 */
function nativePhaseFiveIoSnapshot(?int $pid = null): ?array
{
    if (PHP_OS_FAMILY !== 'Linux') {
        return null;
    }

    $pid ??= getmypid();
    $contents = @file_get_contents('/proc/'.$pid.'/io');

    if (! is_string($contents)) {
        return null;
    }

    $values = [];

    foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
        if (preg_match('/\A(read_bytes|write_bytes|cancelled_write_bytes):\s+(\d+)\z/D', $line, $match) === 1) {
            $values[$match[1]] = (int) $match[2];
        }
    }

    if (! isset(
        $values['read_bytes'],
        $values['write_bytes'],
        $values['cancelled_write_bytes'],
    )) {
        return null;
    }

    return [
        'read_bytes' => $values['read_bytes'],
        'write_bytes' => $values['write_bytes'],
        'cancelled_write_bytes' => $values['cancelled_write_bytes'],
    ];
}

/**
 * @param  array{read_bytes: int, write_bytes: int, cancelled_write_bytes: int}|null  $before
 * @param  array{read_bytes: int, write_bytes: int, cancelled_write_bytes: int}|null  $after
 * @return array{read_bytes: int, write_bytes: int, cancelled_write_bytes: int}|null
 */
function nativePhaseFiveIoDelta(?array $before, ?array $after): ?array
{
    if ($before === null || $after === null) {
        return null;
    }

    return [
        'read_bytes' => max(0, $after['read_bytes'] - $before['read_bytes']),
        'write_bytes' => max(0, $after['write_bytes'] - $before['write_bytes']),
        'cancelled_write_bytes' => max(
            0,
            $after['cancelled_write_bytes'] - $before['cancelled_write_bytes'],
        ),
    ];
}

/**
 * @param  list<array{started_ns: int, finished_ns: int}>  $intervals
 */
function nativePhaseFivePeakConcurrency(array $intervals): int
{
    $events = [];

    foreach ($intervals as $interval) {
        $events[] = [$interval['started_ns'], 1];
        $events[] = [$interval['finished_ns'], -1];
    }

    usort(
        $events,
        static fn (array $left, array $right): int => $left[0] <=> $right[0]
            ?: $left[1] <=> $right[1],
    );
    $active = 0;
    $peak = 0;

    foreach ($events as [, $delta]) {
        $active += $delta;
        $peak = max($peak, $active);
    }

    return $peak;
}

/**
 * @param  list<array{started_ns: int, finished_ns: int}>  $intervals
 */
function nativePhaseFiveIdleRatio(array $intervals, int $processes): float
{
    if ($intervals === []) {
        return 0.0;
    }

    $first = min(array_column($intervals, 'started_ns'));
    $last = max(array_column($intervals, 'finished_ns'));
    $capacity = max(1, ($last - $first) * $processes);
    $busy = array_sum(array_map(
        static fn (array $interval): int => max(
            0,
            $interval['finished_ns'] - $interval['started_ns'],
        ),
        $intervals,
    ));

    return round(max(0.0, 1.0 - ($busy / $capacity)), 6);
}

/**
 * @param  list<int|float>  $values
 */
function nativePhaseFiveMedian(array $values): float
{
    nativePhaseFiveAssert($values !== [], 'Cannot calculate the median of an empty sample.');
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);

    if (count($values) % 2 === 1) {
        return (float) $values[$middle];
    }

    return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
}

/**
 * @return array<string, mixed>
 */
function nativePhaseFiveReadJson(string $path): array
{
    $contents = file_get_contents($path);

    nativePhaseFiveAssert(
        is_string($contents) && trim($contents) !== '',
        'Native Phase 5 artifact is missing or empty: '.$path,
    );
    $decoded = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
    nativePhaseFiveAssert(
        is_array($decoded) && ! array_is_list($decoded),
        'Native Phase 5 artifact must be a JSON object: '.$path,
    );

    return $decoded;
}

/**
 * @param  array<string, mixed>  $payload
 */
function nativePhaseFiveWriteJson(string $path, array $payload): void
{
    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;

    nativePhaseFiveAssert(
        file_put_contents($path, $json, LOCK_EX) !== false,
        'Could not write native Phase 5 artifact: '.$path,
    );
}

/**
 * @return list<int>
 */
function nativePhaseFiveChildPids(int $pid = 0): array
{
    if (PHP_OS_FAMILY !== 'Linux') {
        return [];
    }

    $pid = $pid > 0 ? $pid : getmypid();
    $contents = @file_get_contents('/proc/'.$pid.'/task/'.$pid.'/children');

    if (! is_string($contents) || trim($contents) === '') {
        return [];
    }

    return array_values(array_filter(
        array_map(intval(...), preg_split('/\s+/', trim($contents)) ?: []),
        static fn (int $child): bool => $child > 0,
    ));
}

function nativePhaseFiveProcessAlive(int $pid): bool
{
    if ($pid < 1 || ! @posix_kill($pid, 0)) {
        return false;
    }

    if (PHP_OS_FAMILY !== 'Linux') {
        return true;
    }

    $stat = @file_get_contents('/proc/'.$pid.'/stat');

    if (! is_string($stat)
        || preg_match('/\A\d+\s+\(.+\)\s+([A-Z])\s/D', $stat, $match) !== 1) {
        return false;
    }

    return $match[1] !== 'Z';
}

/**
 * @param  list<int>  $pids
 * @return list<int>
 */
function nativePhaseFiveWaitForExit(array $pids, int $timeoutMs = 2_000): array
{
    $deadline = hrtime(true) + $timeoutMs * 1_000_000;

    do {
        $alive = array_values(array_filter($pids, nativePhaseFiveProcessAlive(...)));

        if ($alive === []) {
            return [];
        }

        usleep(5_000);
    } while (hrtime(true) < $deadline);

    return array_values(array_filter($pids, nativePhaseFiveProcessAlive(...)));
}

function nativePhaseFiveRemove(string $path): void
{
    if (! file_exists($path)) {
        return;
    }

    if (is_link($path)) {
        nativePhaseFiveFail('Refusing to follow a native Phase 5 proof symlink.');
    }

    if (! is_dir($path)) {
        nativePhaseFiveAssert(
            unlink($path),
            'Could not remove native Phase 5 proof artifact: '.$path,
        );

        return;
    }

    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.') {
            continue;
        }
        if ($name === '..') {
            continue;
        }
        nativePhaseFiveRemove($path.'/'.$name);
    }

    nativePhaseFiveAssert(
        rmdir($path),
        'Could not remove native Phase 5 proof directory: '.$path,
    );
}

/**
 * @param  array<string, mixed>  $topology
 */
function nativePhaseFiveAssertTopology(array $topology): void
{
    foreach ([
        'schema',
        'forks',
        'scope_workers',
        'executor_workers',
        'process_anchors',
        'peak_live_pids',
    ] as $field) {
        nativePhaseFiveAssert(
            is_int($topology[$field] ?? null) && $topology[$field] >= 0,
            'Native Phase 5 topology field is missing or invalid: '.$field,
        );
    }

    nativePhaseFiveAssert(
        $topology['schema'] === 1,
        'Native Phase 5 topology schema must be version 1.',
    );
}
