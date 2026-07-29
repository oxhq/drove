<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

function droveCompatibilityMarker(string $event): void
{
    $path = getenv('DROVE_COMPATIBILITY_MARKER');

    if (! is_string($path) || $path === ''
        || file_put_contents($path, $event.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the Drove compatibility marker.');
    }
}
