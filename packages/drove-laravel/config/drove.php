<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Drove executable
    |--------------------------------------------------------------------------
    |
    | The Artisan command always launches Drove in a fresh PHP subprocess.
    | A relative path is resolved from the Laravel application's base path.
    |
    */
    'binary' => env('DROVE_BINARY', 'vendor/bin/drove'),

    /*
    |--------------------------------------------------------------------------
    | Database state adapter
    |--------------------------------------------------------------------------
    |
    | Supported drivers are deliberately narrow:
    | - transaction: one MySQL connection in a disposable test database
    | - sqlite-copy: one file-backed SQLite connection in DELETE journal mode
    |
    */
    'state' => [
        'driver' => env('DROVE_LARAVEL_STATE'),
        'connection' => env('DROVE_LARAVEL_DB_CONNECTION'),
        'workspace' => env('DROVE_LARAVEL_SQLITE_WORKSPACE'),
    ],
];
