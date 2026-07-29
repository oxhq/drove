<?php

declare(strict_types=1);

beforeAll(function (): void {
    droveCompatibilityMarker('before_all');
});

beforeEach(function (): void {
    $this->droveBeforeEach++;
    droveCompatibilityMarker('before_each');
});

afterEach(function (): void {
    expect($this->droveBeforeEach)->toBe(1);
    droveCompatibilityMarker('after_each');
});

afterAll(function (): void {
    droveCompatibilityMarker('after_all');
});

test('runs a named dataset', function (string $label, int $value): void {
    droveCompatibilityMarker('body_named');
    echo $label.':'.$value;

    expect($this)->toBeInstanceOf(DroveCompatibilityTestCase::class)
        ->and($this->bindingMarker())->toBe('custom-test-case')
        ->and($this->droveBeforeEach)->toBe(1)
        ->and($value)->toBe(10);
})->with('drove named rows');

test('runs a positional dataset', function (string $label, int $value): void {
    droveCompatibilityMarker('body_positional');
    echo $label.':'.$value;

    expect($this)->toBeInstanceOf(DroveCompatibilityTestCase::class)
        ->and($this->bindingMarker())->toBe('custom-test-case')
        ->and($this->droveBeforeEach)->toBe(1)
        ->and($value)->toBe(20);
})->with([
    ['positional', 20],
]);

test('preserves an installed skip', function (): never {
    throw new RuntimeException('The installed skipped body ran.');
})->skip('installed skip');

todo('preserves an installed todo');

describe('an installed describe', function (): void {
    beforeAll(function (): void {
        droveCompatibilityMarker('nested_before_all');
    });

    beforeEach(function (): void {
        droveCompatibilityMarker('nested_before_each');
    });

    afterEach(function (): void {
        droveCompatibilityMarker('nested_after_each');
    });

    afterAll(function (): void {
        droveCompatibilityMarker('nested_after_all');
    });

    test('runs a nested describe case', function (): void {
        droveCompatibilityMarker('body_nested');

        expect($this)->toBeInstanceOf(DroveCompatibilityTestCase::class)
            ->and($this->bindingMarker())->toBe('custom-test-case');
    });
});
