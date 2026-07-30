<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require __DIR__.'/vendor/autoload.php';

$process = new Process([
    PHP_BINARY,
    'artisan',
    'drove',
    '--',
    '--pest',
    'tests/Feature/ApplicationDataProviderTest.php',
], __DIR__, [
    'DROVE_LARAVEL_RUNTIME' => 'application',
]);
$process->setTimeout(120);
$exitCode = $process->run();
$passed = $exitCode === 0
    && str_contains(
        $process->getOutput(),
        'makes the prepared application available to data providers',
    );

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'exit_code' => $exitCode,
    'stdout' => $process->getOutput(),
    'stderr' => $process->getErrorOutput(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
