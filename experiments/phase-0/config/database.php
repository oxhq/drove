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
];
