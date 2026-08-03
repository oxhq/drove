<?php

declare(strict_types=1);

use Drove\Kernel\FailureKind;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\PcntlScheduler;
use Drove\Kernel\Scheduler;
use Drove\Kernel\ScopeContext;
use Drove\Kernel\TestOutcome;
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
    )->plan();
} catch (InvalidArgumentException) {
    $parameterRejected = true;
}

$assert(
    $parameterRejected,
    'Native planning accepted a parameterized test closure without a dataset.',
);
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
$assert(count($fileScope['tests'] ?? []) === 11, 'The root native tests were not compiled.');
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

    $names = array_map(
        static fn (array $token): string => $token[1],
        array_filter(
            token_get_all($source),
            static fn (mixed $token): bool => is_array($token) && in_array(
                $token[0],
                [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_STRING],
                true,
            ),
        ),
    );

    foreach (['Pest', 'PHPUnit', 'Testbench', '__destruct'] as $forbidden) {
        $assert(
            ! array_any(
                $names,
                static fn (string $name): bool => preg_match(
                    sprintf('~(?:^|\\\\)%s(?:\\\\|$)~i', preg_quote($forbidden, '~')),
                    $name,
                ) === 1,
            ),
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
$hookAssertionCount = getenv('DROVE_NATIVE_SCHEDULER') === 'pcntl' ? 6 : 4;
$assert(
    ($byName['accepts loose equality']['assertions'] ?? null) === 26 + $hookAssertionCount,
    'The native expectation catalog assertion count diverged.',
);

$assert(
    ($byName['accepts expected exception']['status'] ?? null) === 'passed'
        && ($byName['accepts expected exception']['assertions'] ?? null) === 3 + $hookAssertionCount,
    sprintf(
        'Native class/message/code expected-exception metrics diverged: expected %d, got %s.',
        3 + $hookAssertionCount,
        var_export($byName['accepts expected exception']['assertions'] ?? null, true),
    ),
);

foreach ([
    'accepts expected exception dataset [first]',
    'accepts expected exception dataset [second]',
] as $name) {
    $assert(
        ($byName[$name]['status'] ?? null) === 'passed'
            && ($byName[$name]['assertions'] ?? null) === 1 + $hookAssertionCount,
        sprintf('Native expected-exception semantics diverged for %s.', $name),
    );
}

foreach ([
    'rejects expected exception class mismatch' => 'Expected exception LogicException, got RuntimeException.',
    'rejects expected exception message mismatch' => "Expected exception message to contain 'different message', got 'native expected exception'.",
    'rejects expected exception code mismatch' => 'Expected exception code 2, got 1.',
] as $name => $message) {
    $test = $byName[$name] ?? null;
    $assert(
        is_array($test)
            && ($test['status'] ?? null) === 'failed'
            && ($test['failure']['kind'] ?? null) === FailureKind::AssertionFailure->value
            && ($test['failure']['message'] ?? null) === $message,
        sprintf('Native expected-exception mismatch semantics diverged for %s.', $name),
    );
}

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

$statCacheBoundary = [
    'checked' => false,
    'inherited_cache_cleared' => false,
    'post_cleanup_cache_cleared' => false,
];

if (
    DIRECTORY_SEPARATOR === '/'
    && function_exists('pcntl_fork')
    && function_exists('pcntl_waitpid')
    && function_exists('stream_socket_pair')
) {
    $statCacheFile = tempnam(sys_get_temp_dir(), 'drove-native-stat-cache-');

    if (! is_string($statCacheFile)) {
        throw new RuntimeException('Could not create the native stat-cache fixture.');
    }

    $statCacheSockets = stream_socket_pair(
        STREAM_PF_UNIX,
        STREAM_SOCK_STREAM,
        STREAM_IPPROTO_IP,
    );

    if ($statCacheSockets === false) {
        throw new RuntimeException('Could not create the native stat-cache control socket.');
    }

    [$statCacheParentSocket, $statCacheChildSocket] = $statCacheSockets;
    $statCacheSiblingPid = pcntl_fork();

    if ($statCacheSiblingPid === -1) {
        fclose($statCacheParentSocket);
        fclose($statCacheChildSocket);

        throw new RuntimeException('Could not fork the native stat-cache sibling process.');
    }

    if ($statCacheSiblingPid === 0) {
        fclose($statCacheParentSocket);

        while (($command = fgets($statCacheChildSocket)) !== false) {
            $command = trim($command);

            if ($command === 'quit') {
                break;
            }

            $modifiedAt = filter_var($command, FILTER_VALIDATE_INT);
            $result = is_int($modifiedAt) && touch($statCacheFile, $modifiedAt)
                ? "ok\n"
                : "error\n";
            fwrite($statCacheChildSocket, $result);
        }

        fclose($statCacheChildSocket);
        exit(0);
    }

    fclose($statCacheChildSocket);
    $touchFromSibling = static function (int $modifiedAt) use ($assert, $statCacheParentSocket): void {
        $assert(
            fwrite($statCacheParentSocket, $modifiedAt."\n") !== false
                && fgets($statCacheParentSocket) === "ok\n",
            'The native stat-cache sibling process failed.',
        );
    };
    $initialMtime = time() - 300;
    $bodyMtime = $initialMtime + 30;
    $afterMtime = $bodyMtime + 30;
    $statCacheStartMtime = null;
    $statCachePostTestMtime = null;
    $statCacheAfterAll = null;
    $statCacheRun = null;
    $directStatCacheStartMtime = null;
    $directStatCacheStaleMtime = null;
    $directStatCachePostTestMtime = null;
    $directStatCacheResult = null;

    try {
        $statCacheRegistry = Declarations::capture(
            static function () use (
                $afterMtime,
                $bodyMtime,
                $statCacheFile,
                &$statCacheAfterAll,
                &$statCacheStartMtime,
                $touchFromSibling,
            ): void {
                \Drove\Native\afterAll(static function () use (
                    $afterMtime,
                    $statCacheFile,
                    &$statCacheAfterAll,
                ): void {
                    $statCacheAfterAll = filemtime($statCacheFile);
                    \Drove\Native\expect($statCacheAfterAll)->toBe($afterMtime);
                });
                \Drove\Native\test('clears inherited stat cache at test start', static function () use (
                    $bodyMtime,
                    $statCacheFile,
                    &$statCacheStartMtime,
                ): void {
                    $statCacheStartMtime = filemtime($statCacheFile);
                    \Drove\Native\expect($statCacheStartMtime)->toBe($bodyMtime);
                });
                \Drove\Native\test('clears completed lifecycle stat cache', static function () use (
                    $afterMtime,
                    $bodyMtime,
                    $statCacheFile,
                    $touchFromSibling,
                ): void {
                    \Drove\Native\expect(true)->toBeTrue();
                    $touchFromSibling($bodyMtime);
                    clearstatcache(true, $statCacheFile);
                    $observedBodyMtime = filemtime($statCacheFile);
                    $touchFromSibling($afterMtime);
                    $observedStaleMtime = filemtime($statCacheFile);

                    if ([$observedBodyMtime, $observedStaleMtime] !== [$bodyMtime, $bodyMtime]) {
                        throw new RuntimeException('The completed lifecycle did not retain its primed stat entry.');
                    }
                });
            },
            $rootPath,
            'Drove native stat-cache lifecycle proof',
        );
        $statCacheScheduler = new class($assert, $bodyMtime, $initialMtime, $statCacheFile, $touchFromSibling, static function (int $modifiedAt) use (&$statCachePostTestMtime): void {
            $statCachePostTestMtime = $modifiedAt;
        },
        ) implements Scheduler
        {
            public function __construct(
                private readonly Closure $assert,
                private readonly int $bodyMtime,
                private readonly int $initialMtime,
                private readonly string $statCacheFile,
                private readonly Closure $touchFromSibling,
                private readonly Closure $recordPostTestMtime,
            ) {}

            public function runId(): string
            {
                return 'native-stat-cache-inline';
            }

            public function map(array $tasks, Closure $execute): array
            {
                $results = [];
                $completionOrder = [];

                foreach ($tasks as $ordinal => $task) {
                    $startedNs = hrtime(true);

                    if (
                        $task['kind'] === 'test'
                        && str_contains($task['id'], 'clears%20inherited%20stat%20cache')
                    ) {
                        ($this->touchFromSibling)($this->initialMtime);
                        clearstatcache(true, $this->statCacheFile);
                        ($this->assert)(
                            filemtime($this->statCacheFile) === $this->initialMtime,
                            'Could not prime the inherited native stat-cache entry.',
                        );
                        ($this->touchFromSibling)($this->bodyMtime);
                        ($this->assert)(
                            filemtime($this->statCacheFile) === $this->initialMtime,
                            'The inherited native stat-cache entry was not stale before test execution.',
                        );
                    } elseif ($task['kind'] === 'test') {
                        ($this->touchFromSibling)($this->bodyMtime);
                        clearstatcache(true, $this->statCacheFile);
                    }

                    $value = $execute($task);

                    if (
                        $task['kind'] === 'test'
                        && str_contains($task['id'], 'clears%20completed%20lifecycle')
                    ) {
                        ($this->recordPostTestMtime)(filemtime($this->statCacheFile));
                    }

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
        $statCacheRun = new Runner($statCacheScheduler)->run($statCacheRegistry);
        $directOutcome = TestOutcome::passed();
        $directBody = static function () use (
            &$directStatCacheStartMtime,
            $directOutcome,
            $statCacheFile,
        ): TestOutcome {
            $directStatCacheStartMtime = filemtime($statCacheFile);

            return $directOutcome;
        };
        $directCleanup = static function () use (
            $afterMtime,
            $bodyMtime,
            &$directStatCacheStaleMtime,
            $statCacheFile,
            $touchFromSibling,
        ): void {
            clearstatcache(true, $statCacheFile);
            $cachedMtime = filemtime($statCacheFile);
            $touchFromSibling($afterMtime);
            $directStatCacheStaleMtime = filemtime($statCacheFile);

            if ([$cachedMtime, $directStatCacheStaleMtime] !== [$bodyMtime, $bodyMtime]) {
                throw new RuntimeException('The direct lifecycle cleanup did not retain its primed stat entry.');
            }
        };
        $directExecutor = new LifecycleExecutor(
            $statCacheScheduler,
            static fn (string $id): Closure => $id === 'hook:stat-cache-cleanup'
                ? $directCleanup
                : throw new RuntimeException('Unexpected hook '.$id),
            static fn (string $id, ScopeContext $context = new ScopeContext): Closure => $directBody,
        );
        $directRunTest = new ReflectionMethod(LifecycleExecutor::class, 'runTest');
        $touchFromSibling($initialMtime);
        clearstatcache(true, $statCacheFile);
        $assert(
            filemtime($statCacheFile) === $initialMtime,
            'Could not prime the direct inherited native stat-cache entry.',
        );
        $touchFromSibling($bodyMtime);
        $assert(
            filemtime($statCacheFile) === $initialMtime,
            'The direct inherited native stat-cache entry was not stale before test execution.',
        );
        $directStatCacheResult = $directRunTest->invoke(
            $directExecutor,
            [
                'id' => 'test:stat-cache-direct',
                'name' => 'direct stat-cache boundary',
                'source' => null,
                'dataset' => null,
                'groups' => [],
            ],
            [[
                'id' => 'scope:stat-cache-direct',
                'before_each' => [],
                'after_each' => ['hook:stat-cache-cleanup'],
            ]],
            new ScopeContext,
        );
        $directStatCachePostTestMtime = filemtime($statCacheFile);
    } finally {
        fwrite($statCacheParentSocket, "quit\n");
        fclose($statCacheParentSocket);
        $statCacheSiblingStatus = 0;
        $waitedStatCacheSiblingPid = pcntl_waitpid($statCacheSiblingPid, $statCacheSiblingStatus);
        $assert(
            $waitedStatCacheSiblingPid === $statCacheSiblingPid
                && pcntl_wifexited($statCacheSiblingStatus)
                && pcntl_wexitstatus($statCacheSiblingStatus) === 0,
            'The native stat-cache sibling process did not exit cleanly.',
        );
        clearstatcache(true, $statCacheFile);

        if (is_file($statCacheFile)) {
            unlink($statCacheFile);
        }
    }

    $assert(
        ($statCacheRun['status'] ?? null) === 'passed'
            && ($statCacheRun['exit_code'] ?? null) === 0
            && $statCacheStartMtime === $bodyMtime
            && $statCachePostTestMtime === $afterMtime
            && $statCacheAfterAll === $afterMtime
            && ($directStatCacheResult['status'] ?? null) === 'passed'
            && $directStatCacheStartMtime === $bodyMtime
            && $directStatCacheStaleMtime === $bodyMtime
            && $directStatCachePostTestMtime === $afterMtime,
        sprintf(
            'Native lifecycle stat-cache parity failed: %s.',
            json_encode(
                [
                    'run' => $statCacheRun,
                    'start_mtime' => $statCacheStartMtime,
                    'post_test_mtime' => $statCachePostTestMtime,
                    'after_all_mtime' => $statCacheAfterAll,
                    'direct_result' => $directStatCacheResult,
                    'direct_start_mtime' => $directStatCacheStartMtime,
                    'direct_stale_mtime' => $directStatCacheStaleMtime,
                    'direct_post_test_mtime' => $directStatCachePostTestMtime,
                    'expected_after_all_mtime' => $afterMtime,
                ],
                JSON_THROW_ON_ERROR,
            ),
        ),
    );
    $statCacheBoundary = [
        'checked' => true,
        'inherited_cache_cleared' => true,
        'post_cleanup_cache_cleared' => true,
    ];
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
        'stat_cache_boundary' => $statCacheBoundary,
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
