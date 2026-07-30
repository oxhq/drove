<?php

declare(strict_types=1);

use Drove\Kernel\FailureKind;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\PcntlScheduler;
use Drove\Kernel\Scheduler;
use Drove\Native\DeclarationRegistry;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Drove\Native\TestContext;
use Pest\TestSuite;
use PHPUnit\Framework\TestCase;

$rootPath = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($rootPath): void {
    if (! str_starts_with($class, 'Drove\\')) {
        return;
    }

    $file = $rootPath.'/src/Drove/'
        .str_replace('\\', '/', substr($class, strlen('Drove\\')))
        .'.php';

    if (is_file($file)) {
        require $file;
    }
});

require $rootPath.'/src/Drove/Native/functions.php';

final class NativePhaseOneHeap
{
    public static int $value = 41;
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$suitePath = __DIR__.'/suite.php';
$capture = static fn (): DeclarationRegistry => Declarations::capture(
    static fn () => require $suitePath,
    $rootPath,
    'Drove native phase 1',
);
$first = $capture();
$second = $capture();
$plan = $first->plan();
$workingDirectory = getcwd();

if ($workingDirectory === false || ! chdir(__DIR__)) {
    throw new RuntimeException('Could not enter the native fixture directory.');
}

try {
    $fromSubdirectory = $capture();
} finally {
    chdir($workingDirectory);
}

$assert($plan === $fromSubdirectory->plan(), 'Native IDs changed with the invocation directory.');
$captureFailure = new RuntimeException('native capture sentinel');
$captureFailed = false;

try {
    Declarations::capture(
        static function () use ($captureFailure): never {
            throw $captureFailure;
        },
        $rootPath,
    );
} catch (Throwable $throwable) {
    $captureFailed = true;
    $assert($throwable === $captureFailure, 'Native capture replaced the declaration failure.');
}

$assert($captureFailed, 'Native capture swallowed a declaration failure.');
$assert(! Declarations::isCapturing(), 'Native declarations leaked outside their capture boundary.');

$parameterRejected = false;

try {
    Declarations::capture(
        static function (): void {
            \Drove\Native\test(
                'invalid parameter',
                static function (TestContext $context): void {},
            );
        },
        $rootPath,
    );
} catch (InvalidArgumentException) {
    $parameterRejected = true;
}

$assert($parameterRejected, 'Native Phase 1 accepted a parameterized test closure.');
$poisoned = Declarations::capture(
    static function (): void {
        try {
            \Drove\Native\describe('broken declaration', static function (): void {
                \Drove\Native\test('partial test', static function (): void {});

                throw new RuntimeException('broken describe');
            });
        } catch (RuntimeException) {
            //
        }
    },
    $rootPath,
);
$poisonRejected = false;

try {
    $poisoned->plan();
} catch (LogicException) {
    $poisonRejected = true;
}

$assert($poisonRejected, 'A caught describe failure left a runnable partial plan.');
$assert($plan === $second->plan(), 'Native declaration IDs or Scope IR are not deterministic.');
$assert(($plan['schema'] ?? null) === 1, 'Native declarations did not emit Scope IR schema 1.');
$assert(($plan['root']['type'] ?? null) === 'suite', 'Native declarations did not emit a suite root.');
$assert(count($plan['root']['children'] ?? []) === 1, 'The native file scope was not compiled.');
$fileScope = $plan['root']['children'][0];
$assert(count($fileScope['tests'] ?? []) === 5, 'The root native tests were not compiled.');
$assert(count($fileScope['children'] ?? []) === 1, 'The nested native scope was not compiled.');
$nestedScope = $fileScope['children'][0];
$assert(count($nestedScope['tests'] ?? []) === 2, 'The nested native tests were not compiled.');

$tests = [
    ...$fileScope['tests'],
    ...$nestedScope['tests'],
];
$ids = array_column($tests, 'id');

$assert(count($ids) === count(array_unique($ids)), 'Native test IDs are not unique.');

foreach ($tests as $test) {
    $assert(
        is_string($test['source']['path'] ?? null)
            && is_int($test['source']['line'] ?? null)
            && $test['source']['line'] > 0
            && preg_match('~^(?:[A-Za-z]:/|/)~', $test['source']['path']) !== 1,
        'A native test lost its source location.',
    );
}

json_encode($plan, JSON_THROW_ON_ERROR);

$nativeFiles = [];
$nativeIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $rootPath.'/src/Drove/Native',
    FilesystemIterator::SKIP_DOTS,
));

foreach ($nativeIterator as $nativeFile) {
    if ($nativeFile->isFile() && strtolower((string) $nativeFile->getExtension()) === 'php') {
        $nativeFiles[] = $nativeFile->getPathname();
    }
}

sort($nativeFiles);

if ($nativeFiles === []) {
    throw new RuntimeException('The native frontend module was not found.');
}

foreach ($nativeFiles as $file) {
    $source = file_get_contents($file);

    if ($source === false) {
        throw new RuntimeException(sprintf('Could not inspect %s.', $file));
    }

    foreach (['Pest\\', 'PHPUnit\\', 'Testbench', '__destruct'] as $forbidden) {
        $assert(
            stripos($source, $forbidden) === false,
            sprintf('%s imports or references forbidden bridge surface %s.', $file, $forbidden),
        );
    }
}

$assertBootstrapIsolation = static function () use ($assert, $rootPath): void {
    $projectVendor = str_replace('\\', '/', $rootPath).'/vendor/';

    foreach (get_included_files() as $file) {
        $file = str_replace('\\', '/', $file);
        $assert(
            ! str_starts_with($file, $projectVendor),
            sprintf('The native proof loaded project vendor code from %s.', $file),
        );
        $assert(! str_ends_with($file, '/src/Functions.php'), 'The native proof loaded the Pest frontend.');
        $assert(! str_ends_with($file, '/src/Pest.php'), 'The native proof loaded the Pest bootstrap.');
    }

    $assert(! class_exists(TestSuite::class, false), 'The native proof loaded Pest runtime state.');
    $assert(! class_exists(TestCase::class, false), 'The native proof loaded PHPUnit runtime state.');
};
$assertBootstrapIsolation();

$processes = (int) (getenv('DROVE_NATIVE_PROCESSES') ?: 1);
$assert($processes >= 1 && $processes <= 30, 'DROVE_NATIVE_PROCESSES must be between 1 and 30.');
$requestedScheduler = getenv('DROVE_NATIVE_SCHEDULER') ?: 'auto';
$pcntl = function_exists('pcntl_fork');

$assert(
    $requestedScheduler !== 'pcntl' || $pcntl,
    'The pcntl scheduler was requested but pcntl_fork() is unavailable.',
);

$scheduler = $pcntl && $requestedScheduler !== 'inline'
    ? new PcntlScheduler('native-phase-1-c'.$processes, $processes)
    : new class implements Scheduler
    {
        public function runId(): string
        {
            return 'native-phase-1-inline';
        }

        public function map(array $tasks, Closure $execute): array
        {
            $results = [];
            $completionOrder = [];

            foreach ($tasks as $ordinal => $task) {
                $startedNs = hrtime(true);
                $value = $execute($task);
                $finishedNs = hrtime(true);
                $results[] = [
                    'id' => $task['id'],
                    'kind' => $task['kind'],
                    'scope_id' => $task['scope_id'],
                    'ordinal' => $ordinal,
                    'status' => 'passed',
                    'failure' => null,
                    'value' => $value,
                    'stdout' => '',
                    'stderr' => '',
                    'events' => [],
                    'telemetry' => [
                        'pid' => getmypid(),
                        'pgid' => getmypid(),
                        'started_ns' => $startedNs,
                        'finished_ns' => $finishedNs,
                        'duration_ms' => ($finishedNs - $startedNs) / 1_000_000,
                        'exit_code' => 0,
                        'signal' => null,
                    ],
                ];
                $completionOrder[] = $task['id'];
            }

            return ['results' => $results, 'completion_order' => $completionOrder];
        }

        public function withPermit(array $scopes, Closure $work): mixed
        {
            return $work();
        }
    };

$parentPid = getmypid();
$run = new Runner($scheduler)->run($first);
$assertBootstrapIsolation();
$assert($run['status'] === 'failed', 'The expected native failure did not fail the aggregate run.');
$assert($run['exit_code'] === 1, 'The expected native failure did not produce exit code 1.');
$byName = [];

foreach ($run['tests'] as $test) {
    $byName[$test['name']] = $test;
}

$assert(($byName['accepts loose equality']['status'] ?? null) === 'passed', 'The root native test failed.');
$assert(($byName['it passes strict identity']['status'] ?? null) === 'passed', 'The nested native test failed.');

foreach (range(1, 4) as $case) {
    $assert(
        ($byName['overlaps case '.$case]['status'] ?? null) === 'passed',
        sprintf('Native overlap case %d failed.', $case),
    );
}

$failed = $byName['reports native assertion failure'] ?? null;
$assert(is_array($failed) && $failed['status'] === 'failed', 'The native assertion failure was not reported.');
$assert(
    ($failed['failure']['kind'] ?? null) === FailureKind::AssertionFailure->value,
    'The native assertion was not classified as an assertion failure.',
);
$failedHookTrace = [];

foreach ($failed['events'] as $event) {
    if (($event['type'] ?? null) !== 'hook.finished') {
        continue;
    }

    $phase = $event['phase'] ?? null;
    $status = $event['status'] ?? null;

    if (! is_string($phase) || ! is_string($status)) {
        throw new RuntimeException('A failed native test hook event lost its phase or status.');
    }

    $failedHookTrace[] = $phase.':'.$status;
}

$assert(
    $failedHookTrace === [
        'before_each:passed',
        'before_each:passed',
        'after_each:passed',
        'after_each:passed',
    ],
    'Native lifecycle or teardown diverged after the assertion failure.',
);

foreach (['before_all', 'before_each', 'after_each', 'after_all'] as $phase) {
    $assert(
        array_any(
            $run['events'],
            static fn (array $event): bool => ($event['type'] ?? null) === 'hook.finished'
                && ($event['phase'] ?? null) === $phase
                && ($event['status'] ?? null) === 'passed',
        ),
        sprintf('The native %s lifecycle phase did not finish.', $phase),
    );
}

$strictHookTrace = [];

foreach ($byName['it passes strict identity']['events'] as $event) {
    if (($event['type'] ?? null) !== 'hook.finished') {
        continue;
    }

    $phase = $event['phase'] ?? null;

    if (! is_string($phase)) {
        throw new RuntimeException('A native hook event lost its phase.');
    }

    $strictHookTrace[] = $phase;
}

$assert(
    $strictHookTrace === ['before_each', 'before_each', 'after_each', 'after_each'],
    'Native nested hook order diverged from the expected lifecycle.',
);
$scopeHookCounts = [];

foreach ($run['events'] as $event) {
    $phase = $event['phase'] ?? null;

    if (($event['type'] ?? null) !== 'hook.finished') {
        continue;
    }

    if (! is_string($phase)) {
        continue;
    }

    if (! in_array($phase, ['before_all', 'after_all'], true)) {
        continue;
    }

    $scopeHookCounts[$phase] = ($scopeHookCounts[$phase] ?? 0) + 1;
}

$assert(($scopeHookCounts['before_all'] ?? 0) === 2, 'Native beforeAll hooks did not run exactly once per scope.');
$assert(($scopeHookCounts['after_all'] ?? 0) === 2, 'Native afterAll hooks did not run exactly once per scope.');

$observedConcurrency = $run['observed_concurrency']['global'] ?? null;
$expectedConcurrency = $scheduler instanceof PcntlScheduler
    ? min($processes, count($run['tests']))
    : 1;
$assert(
    $observedConcurrency === $expectedConcurrency,
    sprintf(
        'Native execution observed concurrency %s instead of %d.',
        var_export($observedConcurrency, true),
        $expectedConcurrency,
    ),
);
$executorPids = array_values(array_unique(array_map(
    static fn (array $test): mixed => $test['telemetry']['pid'] ?? null,
    $run['tests'],
)));
$assert(
    ! in_array(null, $executorPids, true),
    'A native test result lost its executor PID.',
);

if ($scheduler instanceof PcntlScheduler) {
    $assert(
        count($executorPids) === count($run['tests']),
        'Forked native execution reused an executor process across tests.',
    );
    $assert(
        ! in_array($parentPid, $executorPids, true),
        'A native test executed in the prepared parent process.',
    );
    $assert(
        NativePhaseOneHeap::$value === 41,
        'A native test mutated the prepared parent heap.',
    );
}

$projection = LifecycleExecutor::semanticProjection($run);
$summary = [
    'schema' => 1,
    'ok' => true,
    'run_status' => $run['status'],
    'exit_code' => $run['exit_code'],
    'scheduler' => $scheduler instanceof PcntlScheduler ? 'pcntl' : 'inline',
    'processes' => $processes,
    'observed_concurrency' => $observedConcurrency,
    'executor_pids' => $executorPids,
    'fork_isolation_checked' => $scheduler instanceof PcntlScheduler,
    'dependency_guard_checked' => true,
    'bootstrap_isolation_checked' => true,
    'lifecycle_checked' => [
        'nested_trace' => $strictHookTrace,
        'failed_trace' => $failedHookTrace,
        'scope_hook_counts' => $scopeHookCounts,
    ],
    'test_count' => count($run['tests']),
    'plan_hash' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)),
    'semantic_hash' => hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR)),
    'tests' => array_map(
        static fn (array $test): array => [
            'name' => $test['name'],
            'status' => $test['status'],
            'failure_kind' => $test['failure']['kind'] ?? null,
        ],
        $run['tests'],
    ),
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
