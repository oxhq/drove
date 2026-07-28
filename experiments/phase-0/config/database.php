<?php

declare(strict_types=1);

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => database_path('drove.sqlite'),
            'foreign_key_constraints' => true,
        ],
    ],
    'redis' => [
        'client' => 'phpredis',
        'default' => [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 0,
        ],
    ],
];
