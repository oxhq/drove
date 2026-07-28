<?php

declare(strict_types=1);

use Pest\Exceptions\BeforeAllWithinDescribe;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\Framework\TestSuite as PHPUnitTestSuite;
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

$fixture = realpath(__DIR__.'/inactive-fixtures/NestedBeforeAllTest.php');

if ($fixture === false) {
    throw new RuntimeException('The inactive-mode fixture does not exist.');
}

$thrown = false;

try {
    PHPUnitTestSuite::empty('drove-phase-one-inactive')->addTestFile($fixture);
} catch (BeforeAllWithinDescribe) {
    $thrown = true;
}

if (! $thrown) {
    throw new RuntimeException('Nested beforeAll was accepted while Drove was inactive.');
}

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'nested_before_all' => 'rejected',
], JSON_THROW_ON_ERROR).PHP_EOL);
