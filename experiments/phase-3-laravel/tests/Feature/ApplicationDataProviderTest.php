<?php

declare(strict_types=1);

dataset('booted Laravel application', static function (): array {
    return [[[
        'environment' => app()->environment(),
        'application_id' => spl_object_id(app()),
    ]]];
});

it('makes the prepared application available to data providers', function (
    array $provider,
): void {
    expect($provider)
        ->toBe([
            'environment' => 'testing',
            'application_id' => spl_object_id(app()),
        ]);
})->with('booted Laravel application');
