<?php

declare(strict_types=1);

return [
    'name' => 'Drove Laravel Proof',
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'cipher' => 'AES-256-CBC',
    'key' => env(
        'APP_KEY',
        'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    ),
    'previous_keys' => [],
    'maintenance' => [
        'driver' => 'file',
        'store' => 'database',
    ],
];
