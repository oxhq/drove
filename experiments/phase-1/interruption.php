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

$task = static fn (string $id): array => [
    'id' => $id,
    'kind' => 'test',
    'scope_id' => 'root',
    'scopes' => ['root'],
    'timeout_ms' => 5_000,
    'permit' => true,
];

$runInterruption = static function (int $signal) use ($assert, $task): float {
    $parentPid = getmypid();
    $marker = sprintf('/tmp/drove-interruption-%d-%d', $parentPid, $signal);
    $escapedMarker = $marker.'-escaped';
    @unlink($marker);
    @unlink($escapedMarker);
    $sender = pcntl_fork();

    if ($sender === -1) {
        throw new RuntimeException('Unable to fork the interruption sender.');
    }

    if ($sender === 0) {
        $deadline = hrtime(true) + 2_000_000_000;

        while (! file_exists($marker) && hrtime(true) < $deadline) {
            usleep(1_000);
        }

        exit(file_exists($marker) && posix_kill($parentPid, $signal) ? 0 : 1);
    }

    $scheduler = new PcntlScheduler('interruption-'.$signal, 1, termGraceMs: 25);
    $started = hrtime(true);

    try {
        $run = $scheduler->map(
            array_map(
                static fn (int $index): array => $task('interrupt-'.$signal.'-'.$index),
                range(0, 3),
            ),
            static function () use ($escapedMarker, $marker): never {
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM, SIG_IGN);
                $descendant = pcntl_fork();

                if ($descendant === -1) {
                    throw new RuntimeException('Unable to fork the interruption descendant.');
                }

                if ($descendant === 0) {
                    usleep(200_000);
                    file_put_contents($escapedMarker, 'escaped');

                    while (true) {
                        usleep(10_000);
                    }
                }

                file_put_contents($marker, (string) getmypid());

                while (true) {
                    usleep(10_000);
                }
            },
        );
    } finally {
        $senderStatus = 0;
        pcntl_waitpid($sender, $senderStatus);
        @unlink($marker);
    }

    $elapsedMs = (hrtime(true) - $started) / 1_000_000;
    $assert(
        count($run['results']) === 4
            && array_all(
                $run['results'],
                static fn (array $result): bool => ($result['failure']['kind'] ?? null)
                    === FailureKind::UserInterruption->value,
            ),
        'Active and pending tasks were not classified as user interruptions.',
    );
    $assert(
        is_int($run['results'][0]['telemetry']['pid'])
            && array_all(
                array_slice($run['results'], 1),
                static fn (array $result): bool => $result['telemetry']['pid'] === null,
            ),
        'The scheduler spawned work after receiving an interruption.',
    );
    $assert(
        pcntl_wifexited($senderStatus)
            && pcntl_wexitstatus($senderStatus) === 0
            && $elapsedMs < 700,
        'Interrupted process groups did not stop within the TERM/KILL bound.',
    );
    usleep(250_000);
    $assert(! file_exists($escapedMarker), 'An interrupted descendant escaped its process group.');
    @unlink($escapedMarker);

    $sentinel = $scheduler->map(
        [$task('sentinel-'.$signal)],
        static fn (): string => 'passed',
    );
    $assert(
        $sentinel['results'][0]['value'] === 'passed',
        'An interrupted run leaked its concurrency permit.',
    );

    return $elapsedMs;
};

$previousAsyncSignals = pcntl_async_signals(false);
$previousHandlers = [
    SIGINT => pcntl_signal_get_handler(SIGINT),
    SIGTERM => pcntl_signal_get_handler(SIGTERM),
];
$restored = [];
$handler = static function (int $signal) use (&$restored): void {
    $restored[] = $signal;
};
pcntl_signal(SIGINT, $handler);
pcntl_signal(SIGTERM, $handler);
pcntl_async_signals(true);

try {
    $durations = [
        'sigint_ms' => $runInterruption(SIGINT),
        'sigterm_ms' => $runInterruption(SIGTERM),
    ];
    posix_kill(getmypid(), SIGINT);
    posix_kill(getmypid(), SIGTERM);
    $assert($restored === [SIGINT, SIGTERM], 'The scheduler did not restore prior signal handlers.');
} finally {
    pcntl_async_signals(false);

    foreach ($previousHandlers as $signal => $previousHandler) {
        pcntl_signal($signal, $previousHandler);
    }

    pcntl_async_signals($previousAsyncSignals);
}

echo json_encode(
    ['status' => 'passed'] + $durations,
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
).PHP_EOL;
