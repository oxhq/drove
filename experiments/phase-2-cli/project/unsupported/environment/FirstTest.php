<?php

beforeAll(function (): void {
    $path = getenv('DROVE_ENV_BEFORE_ALL_MARKER');

    if (is_string($path) && $path !== '') {
        file_put_contents($path, 'first'.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
});

test('first unsafe environment case', fn () => expect(true)->toBeTrue());
