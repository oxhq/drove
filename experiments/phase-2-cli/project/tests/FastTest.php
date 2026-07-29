<?php

declare(strict_types=1);

test('alpha fast', function (): void {
    echo 'alpha-output';

    expect(true)->toBeTrue();
})->group('fast');

test('beta slow', function (): void {
    expect(true)->toBeTrue();
})->group('slow');

it('supports the it alias', function (): void {
    expect(true)->toBeTrue();
});
