<?php

declare(strict_types=1);

return [
    'name' => 'Drove native package proof',
    'env' => env('APP_ENV', 'production'),
    'debug' => false,
    'url' => 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [],
    'maintenance' => ['driver' => 'file', 'store' => 'database'],
];
