<?php

declare(strict_types=1);

test('cleans an interrupted process tree', function (): never {
    $marker = getenv('DROVE_INTERRUPTION_MARKER');

    if (! is_string($marker) || $marker === '') {
        throw new RuntimeException('Missing interruption marker.');
    }

    $descendant = pcntl_fork();

    if ($descendant === -1) {
        throw new RuntimeException('Could not fork the interruption fixture.');
    }

    if ($descendant === 0) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_IGN);
        usleep(800_000);
        file_put_contents($marker, 'escaped');

        while (true) {
            usleep(10_000);
        }
    }

    file_put_contents($marker.'.ready', (string) getmypid());

    while (true) {
        usleep(10_000);
    }
});
