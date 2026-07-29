<?php

declare(strict_types=1);

test('passes after one second', function (): void {
    usleep(1_100_000);

    expect(true)->toBeTrue();
});
