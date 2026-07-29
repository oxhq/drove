<?php

declare(strict_types=1);

beforeAll(function (): void {
    droveCompatibilityMarker('unexpected_setup_failure_before_all');
});

afterAll(function (): void {
    droveCompatibilityMarker('unexpected_setup_failure_after_all');
});

test('is blocked by class setup failure', function (): void {
    droveCompatibilityMarker('unexpected_setup_failure_body');

    expect(true)->toBeTrue();
});
