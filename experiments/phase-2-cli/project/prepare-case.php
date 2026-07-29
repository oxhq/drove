<?php

declare(strict_types=1);

use Drove\Kernel\DroverScheduler;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\ScopeContext;
use Drove\Pest\ScopeCompiler;
use Drove\Pest\TestCaseRuntime;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\Framework\TestCase;
use PHPUnit\TextUI\Configuration\BootstrapLoader;
use PHPUnit\TextUI\Configuration\Builder;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\Configuration\TestSuiteBuilder;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

require __DIR__.'/vendor/autoload.php';

$arguments = ['drove-prepare-case', 'unsupported/PrepareCaseTest.php'];
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

$rootPid = getmypid();
$markerPath = sys_get_temp_dir().'/drove-prepare-case-'.$rootPid;
@unlink($markerPath);
$runtime = TestCaseRuntime::fromSuite(
    $compiler,
    $suite,
    static function (TestCase $case, ScopeContext $context) use ($markerPath): void {
        if (! $case instanceof DrovePreparedCaseTestCase) {
            throw new RuntimeException('Drove prepared the wrong generated TestCase.');
        }

        $testId = $context->metadata()['test_id'] ?? null;

        if (! is_string($testId)) {
            throw new RuntimeException('Drove prepared a case without test metadata.');
        }

        $case->preparedTestId = $testId;
        $case->preparedPid = getmypid();

        if (file_put_contents(
            $markerPath,
            json_encode(['pid' => getmypid(), 'test_id' => $testId], JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        ) === false) {
            throw new RuntimeException('Drove could not write the case preparation marker.');
        }
    },
);
$resolvers = $runtime->resolvers();
$run = (new LifecycleExecutor(
    new DroverScheduler('drove-prepare-case', 1),
    $compiler->hook(...),
    static fn (string $id): array => $resolvers[$id],
))->run($compiler->suitePlan($compiler->files()));
$markerLines = file($markerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
@unlink($markerPath);
$marker = is_array($markerLines) && count($markerLines) === 1
    ? json_decode($markerLines[0], true, flags: JSON_THROW_ON_ERROR)
    : null;
$test = $run['tests'][0] ?? null;
$passed = $run['exit_code'] === 0
    && count($run['tests']) === 1
    && is_array($test)
    && $test['status'] === 'passed'
    && is_array($marker)
    && $marker['test_id'] === $test['id']
    && $marker['pid'] === $test['telemetry']['pid']
    && $marker['pid'] !== $rootPid;

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'root_pid' => $rootPid,
    'prepare' => $marker,
    'test' => is_array($test) ? [
        'id' => $test['id'],
        'status' => $test['status'],
        'pid' => $test['telemetry']['pid'],
    ] : null,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
