<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (getenv('DROVE_LARAVEL') !== '1') {
            return;
        }

        $name = $_SERVER['DROVE_LARAVEL_PROOF_DIRECTORY']
            ?? getenv('DROVE_LARAVEL_PROOF_DIRECTORY')
            ?: 'drove-proof';

        if (! is_string($name)
            || preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $name) !== 1) {
            throw new RuntimeException('The Laravel proof directory name is invalid.');
        }

        $directory = storage_path('framework/'.$name);

        if (! is_dir($directory)
            && ! mkdir($directory, 0777, true)
            && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the Laravel proof directory.');
        }

        if (file_put_contents(
            $directory.'/boot-pids.log',
            getmypid().PHP_EOL,
            FILE_APPEND | LOCK_EX,
        ) === false) {
            throw new RuntimeException('Unable to record the Laravel bootstrap PID.');
        }
    }
}
