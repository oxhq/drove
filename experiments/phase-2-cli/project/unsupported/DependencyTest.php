<?php

declare(strict_types=1);

test('dependency source', fn (): int => 42);

test('dependency consumer', function (int $value): void {
    expect($value)->toBe(42);
})->depends('dependency source');
