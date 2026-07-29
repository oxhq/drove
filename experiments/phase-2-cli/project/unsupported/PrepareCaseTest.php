<?php

declare(strict_types=1);

test('receives preparation before runBare', function (): void {
    expect($this->preparedBeforeSetUp)->toBeTrue()
        ->and($this->preparedTestId)->toContain('receives%20preparation%20before%20runBare')
        ->and($this->preparedPid)->toBe(getmypid());
});
