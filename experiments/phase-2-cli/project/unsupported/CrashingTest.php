<?php

declare(strict_types=1);

test('reports a killed child', function (): never {
    posix_kill(getmypid(), SIGKILL);

    exit(99);
});
