<?php

declare(strict_types=1);

use function Drove\Native\expect;
use function Drove\Native\test;

test('native CLI smoke', static function (): void {
    expect(42)->toBe(42);
})->group('cli');

test('native CLI filtered case', static function (): never {
    throw new RuntimeException('The native CLI group filter did not apply.');
})->group('filtered');
