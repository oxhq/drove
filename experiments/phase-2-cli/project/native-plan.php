<?php

declare(strict_types=1);

use Drove\Pest\ScopeCompiler;
use Drove\Pest\TestCaseRuntime;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\TextUI\Configuration\BootstrapLoader;
use PHPUnit\TextUI\Configuration\Builder;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\Configuration\TestSuiteBuilder;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

require __DIR__.'/vendor/autoload.php';

$arguments = ['drove-native-plan', 'unsupported/OrdinaryPhpUnitTest.php'];
$outputLevel = ob_get_level();

PestKernel::boot(
    PestTestSuite::getInstance(__DIR__, 'tests'),
    new ArgvInput($arguments),
    new BufferedOutput,
);

while (ob_get_level() > $outputLevel) {
    ob_end_clean();
}

$compiler = ScopeCompiler::activate(__DIR__, ownsScopeHooks: true);
$configuration = (new Builder)->build($arguments);
(new PhpHandler)->handle($configuration->php());
(new BootstrapLoader)->handle($configuration);
$suite = (new TestSuiteBuilder)->build($configuration);
(new TestSuiteFilterProcessor)->process($configuration, $suite);
$runtime = TestCaseRuntime::fromSuite($compiler, $suite);
$descriptors = array_values($runtime->descriptors());
$ids = array_column($descriptors, 'id');
$datasets = array_column($descriptors, 'dataset');
$expectedIds = [
    'test:unsupported/OrdinaryPhpUnitTest.php::test_native_dataset::dataset:name:named%20row',
    'test:unsupported/OrdinaryPhpUnitTest.php::test_native_dataset::dataset:index:0',
    'test:unsupported/OrdinaryPhpUnitTest.php::test_native_incomplete',
];
$validDescriptors = $ids === $expectedIds
    && ($datasets[0] ?? null) === ['key' => 'named row', 'label' => ' with data set "named row"']
    && ($datasets[1] ?? null) === ['key' => 0, 'label' => ' with data set #0']
    && array_key_exists(2, $datasets)
    && $datasets[2] === null;

foreach ($descriptors as $descriptor) {
    $validDescriptors = $validDescriptors
        && ($descriptor['source']['path'] ?? null) === 'unsupported/OrdinaryPhpUnitTest.php'
        && is_int($descriptor['source']['line'] ?? null)
        && $descriptor['source']['line'] > 0
        && ($descriptor['groups'] ?? null) === ['native'];
}

$passed = $validDescriptors;

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'ids' => $ids,
    'descriptors' => $descriptors,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
