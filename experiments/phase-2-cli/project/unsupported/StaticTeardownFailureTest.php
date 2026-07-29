<?php

declare(strict_types=1);

beforeAll(function (): void {
    droveCompatibilityMarker('teardown_before_all');
});

beforeEach(function (): void {
    droveCompatibilityMarker('teardown_before_each');
});

afterEach(function (): void {
    droveCompatibilityMarker('teardown_after_each');
});

afterAll(function (): void {
    droveCompatibilityMarker('teardown_after_all');
});

test('passes before class teardown fails', function (): void {
    droveCompatibilityMarker('teardown_body');

    expect(true)->toBeTrue();
});
