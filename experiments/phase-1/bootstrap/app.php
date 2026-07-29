<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withExceptions()
    ->create();

$app->booted(static function (): void {
    $GLOBALS['drove_laravel_boot_pids'][] = getmypid();
});

return $app;
