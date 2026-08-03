<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

$application = Application::configure(basePath: dirname(__DIR__))
    ->create();
$storage = getenv('DROVE_NATIVE_PACKAGE_STORAGE');

if (is_string($storage) && $storage !== '') {
    $application->useStoragePath($storage);
}

return $application;
