<?php

declare(strict_types=1);

beforeAll(function (): void {
    droveCompatibilityMarker('static_before_all');
});

beforeEach(function (): void {
    droveCompatibilityMarker('static_before_each');
});

afterEach(function (): void {
    droveCompatibilityMarker('static_after_each');
});

afterAll(function (): void {
    droveCompatibilityMarker('static_after_all');
});

test('runs custom static lifecycle', function (): void {
    droveCompatibilityMarker('static_body');

    expect(true)->toBeTrue();
});
