<?php

declare(strict_types=1);

use Pest\Exceptions\AfterAllWithinDescribe;
use Pest\Exceptions\BeforeAllWithinDescribe;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require __DIR__.'/vendor/autoload.php';

$outputBufferLevel = ob_get_level();
PestKernel::boot(
    PestTestSuite::getInstance(__DIR__, 'inactive-fixtures'),
    new ArrayInput([]),
    new BufferedOutput,
);

while (ob_get_level() > $outputBufferLevel) {
    ob_end_clean();
}

$checks = [
    [__DIR__.'/inactive-fixtures/NestedBeforeAllTest.php', BeforeAllWithinDescribe::class],
    [__DIR__.'/inactive-fixtures/NestedAfterAllTest.php', AfterAllWithinDescribe::class],
];

foreach ($checks as [$fixture, $exception]) {
    $thrown = false;

    try {
        require $fixture;
    } catch (Throwable $throwable) {
        $thrown = $throwable instanceof $exception;
    }

    if (! $thrown) {
        throw new RuntimeException(sprintf(
            '%s was accepted while Drove was inactive.',
            basename($fixture),
        ));
    }
}

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'nested_before_all' => 'rejected',
    'nested_after_all' => 'rejected',
], JSON_THROW_ON_ERROR).PHP_EOL);
