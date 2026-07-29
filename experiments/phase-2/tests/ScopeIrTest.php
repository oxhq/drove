<?php

declare(strict_types=1);
use Tests\TestCase;

beforeAll(function (): void {
    $GLOBALS['drove_phase_two_hooks']['before_all']++;
});

beforeEach(function (): void {
    if ($this->bindingMarker() !== 'custom-test-case') {
        throw new RuntimeException('Drove beforeEach lost TestCase binding.');
    }

    $GLOBALS['drove_phase_two_hooks']['before_each']++;
});

afterEach(function (): void {
    if ($this->bindingMarker() !== 'custom-test-case') {
        throw new RuntimeException('Drove afterEach lost TestCase binding.');
    }

    $GLOBALS['drove_phase_two_hooks']['after_each']++;
});

afterAll(function (): void {
    $GLOBALS['drove_phase_two_hooks']['after_all']++;
});

test('runs a generated dataset case', function (string $label, int $number): void {
    $GLOBALS['drove_phase_two_bodies'][$label] = [
        'class' => $this::class,
        'binding' => $this->bindingMarker(),
        'number' => $number,
    ];

    echo $label.':'.$number;

    expect($this)->toBeInstanceOf(TestCase::class)
        ->and($this->bindingMarker())->toBe('custom-test-case')
        ->and($number)->toBe($label === 'alpha' ? 1 : 2);
})->with([
    'alpha' => ['alpha', 1],
    'beta' => ['beta', 2],
])->group('datasets', 'fast');

test('preserves a skipped case', function (): never {
    throw new RuntimeException('A skipped Pest body ran.');
})->skip('intentional skip')->group('state');

todo('preserves a todo case')->group('state');

test('rethrows the original assertion failure', function (): void {
    expect('actual')->toBe('expected');
});

test('is removed by PHPUnit filtering', function (): never {
    throw new RuntimeException('A filtered Pest case was cataloged.');
})->group('filtered-out');
