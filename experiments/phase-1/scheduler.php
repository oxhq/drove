<?php

declare(strict_types=1);

use Drove\Kernel\FailureKind;
use Drove\Kernel\PcntlScheduler;

require __DIR__.'/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$task = static fn (string $id, string $kind = 'test', int $timeout = 1_000, bool $permit = true): array => [
    'id' => $id,
    'kind' => $kind,
    'scope_id' => 'root',
    'scopes' => ['root'],
    'timeout_ms' => $timeout,
    'permit' => $permit,
];

$rejectsInvalidConfiguration = static function (Closure $create): bool {
    try {
        $create();
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
};
$assert(
    $rejectsInvalidConfiguration(static fn (): PcntlScheduler => new PcntlScheduler('scheduler-global-bound', 257)),
    'The PHP scheduler accepted global concurrency above 256.',
);
$assert(
    $rejectsInvalidConfiguration(
        static fn (): PcntlScheduler => new PcntlScheduler('scheduler-scope-bound', 1, ['root' => 257]),
    ),
    'The PHP scheduler accepted scope concurrency above 256.',
);

$parentPid = getmypid();
$bounded = new PcntlScheduler('scheduler-bounded', 1);
$boundedRun = $bounded->map(
    array_map(static fn (int $index): array => $task('bounded-'.$index), range(1, 24)),
    static function () use ($parentPid): int {
        $children = trim((string) file_get_contents(sprintf(
            '/proc/%d/task/%d/children',
            $parentPid,
            $parentPid,
        )));
        usleep(10_000);

        return $children === '' ? 0 : count(preg_split('/\s+/', $children));
    },
);
$assert(
    max(array_column($boundedRun['results'], 'value')) === 1,
    'The PHP scheduler forked tasks before acquiring permits.',
);

$large = new PcntlScheduler('scheduler-large', 1);
$largeRun = $large->map(
    [$task('large-value')],
    static fn (): array => ['blob' => str_repeat('v', 1_200_000)],
);
$assert(
    $largeRun['results'][0]['value']['blob'] === str_repeat('v', 1_200_000),
    'The child protocol truncated a structured value larger than one frame.',
);

$oom = new PcntlScheduler('scheduler-oom', 1);
$oomRun = $oom->map(
    [$task('out-of-memory')],
    static function (): never {
        if (ini_set('memory_limit', '32M') === false) {
            throw new RuntimeException('Unable to lower the child memory limit.');
        }

        str_repeat('x', 64 * 1024 * 1024);

        exit(2);
    },
);
$assert(
    $oomRun['results'][0]['failure']['kind'] === FailureKind::OutOfMemory->value,
    'An out-of-memory fatal error was not classified.',
);

$shutdownSentinel = sys_get_temp_dir().'/drove-pcntl-shutdown-'.bin2hex(random_bytes(8));
$cleanup = new PcntlScheduler('scheduler-cleanup', 1, termGraceMs: 25);
$started = hrtime(true);
$cleanupRun = $cleanup->map(
    [$task('suppressed-shutdown', timeout: 200)],
    static function () use ($shutdownSentinel): string {
        register_shutdown_function(static fn () => file_put_contents($shutdownSentinel, 'ran'));

        return 'terminal-sent';
    },
);
$cleanupMs = (hrtime(true) - $started) / 1_000_000;
$assert(
    $cleanupRun['results'][0]['status'] === 'passed'
        && $cleanupMs < 700
        && ! file_exists($shutdownSentinel),
    'The PHP scheduler executed inherited shutdown work past the task boundary.',
);

$escaped = new PcntlScheduler('scheduler-escaped', 1, termGraceMs: 25);
$started = hrtime(true);
$escapedRun = $escaped->map(
    [$task('escaped-descendant', timeout: 200)],
    static function (): string {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to create the escaped-descendant fixture.');
        }

        if ($pid === 0) {
            posix_setpgid(0, 0);
            usleep(1_500_000);
            exit(0);
        }

        usleep(1_500_000);

        return 'parent-finished';
    },
);
$escapedMs = (hrtime(true) - $started) / 1_000_000;
$assert(
    $escapedRun['results'][0]['failure']['kind'] === FailureKind::BlockedDescendant->value
        && $escapedMs < 700,
    'A timed-out escaped descendant was not classified as blocked.',
);

$nested = new PcntlScheduler('scheduler-nested', 1, termGraceMs: 25);
$execute = null;
$execute = static function (array $current) use (&$execute, $nested, $task): mixed {
    if ($current['id'] === 'outer-scope') {
        return $nested->map(
            [$task('inner-test', timeout: 5_000)],
            $execute,
        );
    }

    usleep(5_000_000);

    return null;
};
$started = hrtime(true);
$nestedRun = $nested->map(
    [$task('outer-scope', kind: 'scope', timeout: 200, permit: false)],
    $execute,
);
$sentinel = $nested->map(
    [$task('sentinel')],
    static fn (): string => 'sentinel-passed',
);
$nestedMs = (hrtime(true) - $started) / 1_000_000;
$assert(
    $nestedRun['results'][0]['failure']['kind'] === FailureKind::Timeout->value
        && $sentinel['results'][0]['value'] === 'sentinel-passed'
        && $nestedMs < 700,
    'A nested scope timeout leaked its child or concurrency permit.',
);

echo json_encode([
    'status' => 'passed',
    'bounded_children' => 1,
    'large_value_bytes' => 1_200_000,
    'oom' => FailureKind::OutOfMemory->value,
    'cleanup_ms' => round($cleanupMs, 3),
    'escaped_ms' => round($escapedMs, 3),
    'nested_ms' => round($nestedMs, 3),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
