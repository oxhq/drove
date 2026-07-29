<?php

declare(strict_types=1);

use Drove\Kernel\StateAdapterException;
use Drove\Laravel\LaravelRuntime;

require __DIR__.'/vendor/autoload.php';

$expected = $argv[1] ?? null;
$expectedMessage = match ($expected) {
    'process' => 'Drove Laravel requires process APP_ENV=testing before boot.',
    'application' => 'Drove Laravel requires the booted application environment to be testing.',
    'connection' => 'Drove requires its selected database connection (secondary) to be Laravel\'s default connection (sqlite).',
    default => null,
};

if (! is_string($expectedMessage)) {
    fwrite(STDERR, 'runtime-guard-worker requires process, application, or connection.'.PHP_EOL);

    exit(2);
}

$failure = null;

try {
    LaravelRuntime::boot(__DIR__);
} catch (StateAdapterException $exception) {
    $failure = $exception->getMessage();
}

$passed = $failure === $expectedMessage;

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'expected' => $expected,
    'failure' => $failure,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
