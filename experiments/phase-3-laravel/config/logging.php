<?php

declare(strict_types=1);

return [
    'default' => 'stderr',
    'deprecations' => [
        'channel' => 'null',
        'trace' => false,
    ],
    'channels' => [
        'stderr' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
            'level' => 'debug',
        ],
        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],
    ],
];
