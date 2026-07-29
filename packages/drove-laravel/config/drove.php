<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Runtime mode
    |--------------------------------------------------------------------------
    |
    | "auto" treats a root without bootstrap/app.php as a package suite.
    | A Testbench package that ships that bootstrap file must explicitly set
    | DROVE_LARAVEL_RUNTIME=testbench before discovery.
    |
    */
    'runtime' => env('DROVE_LARAVEL_RUNTIME', 'auto'),

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
    | - sqlite-memory: one inherited SQLite :memory: connection
    | - sqlite-copy: one file-backed SQLite connection in DELETE journal mode
    |
    | RefreshDatabase in sqlite-memory mode requires an explicitly prepared
    | parent schema. Drove never marks migrations complete unless this contract
    | is opted into.
    |
    */
    'state' => [
        'driver' => env('DROVE_LARAVEL_STATE'),
        'connection' => env('DROVE_LARAVEL_DB_CONNECTION'),
        'prepared_schema' => env(
            'DROVE_LARAVEL_SQLITE_PREPARED_SCHEMA',
            false,
        ),
        'workspace' => env('DROVE_LARAVEL_SQLITE_WORKSPACE'),
    ],
];
