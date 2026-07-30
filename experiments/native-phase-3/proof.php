<?php

declare(strict_types=1);

use Drove\Kernel\ChildProtocol;
use Drove\Kernel\DroverScheduler;
use Drove\Kernel\FailureKind;
use Drove\Kernel\NativeLibrary;
use Drove\Kernel\Scheduler;
use Drove\Native\DeclarationRegistry;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Drove\Native\Selection;
use Drove\Native\Surface\SupportedSurface;

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

final class NativePhaseThreeHeap
{
    public static int $value = 41;

    public static int $unsupportedBodyExecutions = 0;
}

$fail = static fn (string $message): never => throw new RuntimeException($message);
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (! $condition) {
        $fail($message);
    }
};
$fixture = getenv('DROVE_NATIVE_FIXTURE') ?: 'conformance';
$processes = (int) (getenv('DROVE_NATIVE_PROCESSES') ?: 1);
$requestedScheduler = getenv('DROVE_NATIVE_SCHEDULER') ?: 'auto';
$assert(in_array($fixture, ['conformance', 'stress'], true), 'Unknown native Phase 3 fixture.');
$assert($processes >= 1 && $processes <= 30, 'DROVE_NATIVE_PROCESSES must be between 1 and 30.');

if ($requestedScheduler === 'auto') {
    $droverLibrary = getenv('DROVER_LIBRARY');
    $requestedScheduler = is_string($droverLibrary) && $droverLibrary !== ''
        ? 'drover'
        : 'inline';
}

$assert(
    in_array($requestedScheduler, ['drover', 'inline'], true),
    'DROVE_NATIVE_SCHEDULER must be drover or inline.',
);
$forked = $requestedScheduler === 'drover';
$revisionFromEnvironment = getenv('DROVE_EVIDENCE_REVISION');
$evidenceRevision = is_string($revisionFromEnvironment)
    && trim($revisionFromEnvironment) !== ''
        ? trim($revisionFromEnvironment)
        : null;
$assert(
    ! $forked || $evidenceRevision !== null,
    'DROVE_EVIDENCE_REVISION is required for Drover evidence.',
);
$runtimePlatform = [
    'os_family' => PHP_OS_FAMILY,
    'os' => PHP_OS,
    'architecture' => php_uname('m'),
    'php' => PHP_VERSION,
];
$resolvedDroverLibrary = null;
$droverIdentity = null;

if ($forked) {
    $resolvedDroverLibrary = NativeLibrary::resolve();
    $droverLibraryHash = hash_file('sha256', $resolvedDroverLibrary);
    $assert(is_string($droverLibraryHash), 'Could not hash the resolved Drover library.');
    $droverIdentity = [
        'scheduler_class' => DroverScheduler::class,
        'library_sha256' => $droverLibraryHash,
        'protocol_version' => ChildProtocol::VERSION,
        'protocol_max_frame_bytes' => 1_048_576,
    ];
}
$memoryLimitRaw = ini_get('memory_limit');
$memoryLimitBytes = static function (string $value) use ($fail): int {
    $value = trim($value);

    if ($value === '-1') {
        return -1;
    }

    if (preg_match('/^(\d+)([KMG])?$/Di', $value, $matches) !== 1) {
        $fail('Unsupported PHP memory_limit value '.$value.'.');
    }

    $multiplier = match (strtoupper($matches[2] ?? '')) {
        'K' => 1024,
        'M' => 1024 ** 2,
        'G' => 1024 ** 3,
        default => 1,
    };

    return (int) $matches[1] * $multiplier;
};
$memoryLimitBytes = $memoryLimitBytes($memoryLimitRaw);
$proofStartedNs = hrtime(true);
$surface = SupportedSurface::load();
$surfaceHash = $surface->hash();
$assert(
    $surfaceHash === '32782524791372b99ee8ba27b0b5b66054a8e28759be48e50b5e78055f6bf96f',
    'The native supported-surface manifest changed without updating its conformance proof.',
);
$manifest = $surface->manifest();
$assert(
    ($manifest['functions'] ?? null) === [
        'declaration' => ['describe', 'it', 'test'],
        'dataset' => ['dataset'],
        'environment' => ['environment'],
        'expectation' => ['expect'],
        'hook' => ['afterAll', 'afterEach', 'beforeAll', 'beforeEach'],
    ]
        && ($manifest['methods']['context'] ?? null) === ['app', 'assertWith', 'defer', 'extensionValue']
        && ($manifest['methods']['declaration'] ?? null) === ['group', 'skip', 'timeout', 'todo', 'with']
        && ($manifest['methods']['expectation'] ?? null) === ['toBe', 'toEqual'],
    'The native supported-surface manifest claims an unproved declaration or expectation.',
);
/**
 * @param  list<string>  $command
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
$runProcess = static function (array $command) use ($fail, $rootPath): array {
    $pipes = [];
    $process = proc_open(
        array_values($command),
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $rootPath,
    );

    if (! is_resource($process)) {
        $fail('Could not start a native Phase 3 CLI proof.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if (! is_string($stdout) || ! is_string($stderr)) {
        $fail('Could not capture native Phase 3 CLI output.');
    }

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
};
$helpRun = $runProcess([
    PHP_BINARY,
    '-d',
    'ffi.enable=true',
    $rootPath.'/bin/drove',
    '--native',
    '--help',
]);
$help = $helpRun['stdout'];
$assert(
    $helpRun['exit_code'] === 0
        && $helpRun['stderr'] === ''
        && preg_match_all('/^  (--[a-z-]+)(?:=[A-Z]+)?\s/m', $help, $helpOptions) === 6
        && $helpOptions[1] === [
            '--processes',
            '--filter',
            '--group',
            '--exclude-group',
            '--help',
            '--version',
        ],
    'Native CLI help claims an unproved option.',
);
$assert(
    $surface->scanFile(__DIR__.'/suite.php') === [],
    'The supported native fixture produced scanner diagnostics.',
);
$diagnostics = $surface->scanFile(__DIR__.'/unsupported.php');
$assert(
    count($diagnostics) === 1
        && $diagnostics[0]['code'] === 'DROVE_NATIVE_UNSUPPORTED_DEPENDENCY'
        && $diagnostics[0]['construct'] === 'depends',
    'The unsupported dependency surface did not produce its stable diagnostic.',
);
$assert(
    NativePhaseThreeHeap::$unsupportedBodyExecutions === 0,
    'Compatibility scanning executed an unsupported test body.',
);
$diagnostics[0]['path'] = 'experiments/native-phase-3/unsupported.php';
$nativeCliExecutionChecked = false;

if ($forked) {
    $cliRun = $runProcess([
        PHP_BINARY,
        '-d',
        'ffi.enable=true',
        $rootPath.'/bin/drove',
        '--native',
        '--processes=1',
        '--group=cli',
        'experiments/native-phase-3/cli-suite.php',
    ]);
    $assert(
        $cliRun['exit_code'] === 0
            && $cliRun['stderr'] === ''
            && substr_count($cliRun['stdout'], ' ✓ native CLI smoke') === 1
            && substr_count($cliRun['stdout'], 'Tests: 1 passed (1)') === 1
            && substr_count($cliRun['stdout'], 'Assertions: 1') === 1
            && ! str_contains($cliRun['stdout'], 'native CLI filtered case')
            && ! str_contains($cliRun['stdout'], 'The native CLI group filter did not apply.'),
        'The native CLI did not execute exactly the selected passing case.',
    );
    $unsupportedCliRun = $runProcess([
        PHP_BINARY,
        '-d',
        'ffi.enable=true',
        $rootPath.'/bin/drove',
        '--native',
        'experiments/native-phase-3/unsupported.php',
    ]);
    $assert(
        $unsupportedCliRun['exit_code'] === 2
            && $unsupportedCliRun['stdout'] === ''
            && substr_count(
                $unsupportedCliRun['stderr'],
                'DROVE_NATIVE_UNSUPPORTED_DEPENDENCY experiments/native-phase-3/unsupported.php:',
            ) === 1
            && ! str_contains($unsupportedCliRun['stderr'], 'Unsupported source reached execution.'),
        'The native CLI did not reject the unsupported fixture before execution.',
    );
    $nativeCliExecutionChecked = true;
}
$supportedAliasSource = <<<'PHP'
<?php

use Drove\Native\{function expect as check, function test as verify};

check(1)->toBe(1);
verify('supported alias', static function (): void {})->group('aliases');
PHP;
$assert(
    $surface->scan($supportedAliasSource, 'supported-alias.php') === [],
    'Supported grouped native function aliases produced scanner diagnostics.',
);
$unsupportedAliasSource = <<<'PHP'
<?php

use function Drove\Native\expect as droveCheck;
use function Drove\Native\test as droveCase;
use function Pest\{expect as pestCheck, uses as pestConfigure};

droveCase('dependency alias', static function (): void {})->depends('other');
pestCheck(1)->toMatchSnapshot();
pestConfigure();
$dynamic = droveCheck(...);
PHP;
$aliasDiagnostics = $surface->scan($unsupportedAliasSource, 'unsupported-alias.php');
$aliasProjection = array_map(
    static fn (array $diagnostic): array => [
        'code' => $diagnostic['code'],
        'construct' => $diagnostic['construct'],
    ],
    $aliasDiagnostics,
);
$assert(
    $aliasProjection === [
        [
            'code' => 'DROVE_NATIVE_UNSUPPORTED_DEPENDENCY',
            'construct' => 'depends',
        ],
        [
            'code' => 'DROVE_NATIVE_UNSUPPORTED_FRONTEND',
            'construct' => 'Pest\\expect',
        ],
        [
            'code' => 'DROVE_NATIVE_UNSUPPORTED_FRONTEND',
            'construct' => 'Pest\\uses',
        ],
        [
            'code' => 'DROVE_NATIVE_UNSUPPORTED_FUNCTION_ALIAS',
            'construct' => 'dynamic-function-alias',
        ],
    ],
    'Native and Pest function aliases bypassed scanner classification.',
);
array_push($diagnostics, ...$aliasDiagnostics);
$qualifiedSource = <<<'PHP'
<?php

\Drove\Native\test('native qualified', static function (): void {})->depends('other');
\Pest\test('pest qualified', static function (): void {})->group('fast');
\Acme\test('foreign qualified', static function (): void {})->depends('other');
\Drove\Native\expect(1)->toMatchSnapshot();
\Pest\uses();
\Acme\expect(1)->toMatchSnapshot();
PHP;
$qualifiedDiagnostics = $surface->scan($qualifiedSource, 'qualified-functions.php');
$qualifiedProjection = array_map(
    static fn (array $diagnostic): array => [
        'code' => $diagnostic['code'],
        'construct' => $diagnostic['construct'],
    ],
    $qualifiedDiagnostics,
);
$assert(
    $qualifiedProjection === [
        [
            'code' => 'DROVE_NATIVE_UNSUPPORTED_DEPENDENCY',
            'construct' => 'depends',
        ],
        [
            'code' => 'DROVE_NATIVE_UNSUPPORTED_FRONTEND',
            'construct' => 'Pest\\test',
        ],
        [
            'code' => 'DROVE_NATIVE_UNSUPPORTED_SNAPSHOT',
            'construct' => 'toMatchSnapshot',
        ],
        [
            'code' => 'DROVE_NATIVE_UNSUPPORTED_FRONTEND',
            'construct' => 'Pest\\uses',
        ],
    ],
    'Qualified native, Pest, and unrelated function calls were misclassified.',
);
$foreignAliasSource = <<<'PHP'
<?php

use function Acme\test;

test('foreign import', static function (): void {})->depends('other');
PHP;
$assert(
    $surface->scan($foreignAliasSource, 'foreign-function-alias.php') === [],
    'A foreign function import was misclassified as Drove native syntax.',
);
$pestAliasSource = <<<'PHP'
<?php

use function Pest\test;

test('portable Pest chain', static function (): void {})->group('fast');
PHP;
$pestAliasDiagnostics = $surface->scan($pestAliasSource, 'pest-function-alias.php');
$assert(
    count($pestAliasDiagnostics) === 1
        && $pestAliasDiagnostics[0]['code'] === 'DROVE_NATIVE_UNSUPPORTED_FRONTEND'
        && $pestAliasDiagnostics[0]['construct'] === 'Pest\\test',
    'An imported Pest frontend call bypassed the native bridge boundary.',
);
$bareFrontendSource = <<<'PHP'
<?php

test('bare test', static function (): void {})->group('fast');
it('bare it', static function (): void {});
describe('bare describe', static function (): void {});
expect(1)->toBe(1);
dataset('bare dataset', [[1]]);
beforeAll(static function (): void {});
beforeEach(static function (): void {});
afterEach(static function (): void {});
afterAll(static function (): void {});
PHP;
$bareFrontendDiagnostics = $surface->scan($bareFrontendSource, 'bare-frontend.php');
$assert(
    count($bareFrontendDiagnostics) === 9
        && array_all(
            $bareFrontendDiagnostics,
            static fn (array $diagnostic): bool => $diagnostic['code']
                === 'DROVE_NATIVE_UNSUPPORTED_FRONTEND',
        ),
    'Bare Pest-compatible functions bypassed the native import identity gate.',
);
array_push($diagnostics, ...$qualifiedDiagnostics);
$diagnostics[] = $pestAliasDiagnostics[0];
array_push($diagnostics, ...$bareFrontendDiagnostics);
$namespaceIsolationSource = <<<'PHP'
<?php

namespace NativeBlock {
    use Drove\Native\{function expect as check, function test as verify};

    verify('native block', static function (): void {})->group('native');
    check(1)->toBe(1);
}

namespace PestBlock {
    test('bare Pest fallback', static function (): void {});
}
PHP;
$namespaceIsolationDiagnostics = $surface->scan(
    $namespaceIsolationSource,
    'namespace-isolation.php',
);
$assert(
    count($namespaceIsolationDiagnostics) === 1
        && $namespaceIsolationDiagnostics[0]['code'] === 'DROVE_NATIVE_UNSUPPORTED_FRONTEND'
        && $namespaceIsolationDiagnostics[0]['construct'] === 'Pest\\test',
    'A native import leaked into a separate braced namespace block.',
);
$separateAliasSource = <<<'PHP'
<?php

namespace NativeAliasBlock {
    use function Drove\Native\test as verify;

    verify('native alias', static function (): void {})->group('native');
}

namespace ForeignAliasBlock {
    use function Acme\test as verify;

    verify('foreign alias', static function (): void {})->depends('foreign');
}
PHP;
$assert(
    $surface->scan($separateAliasSource, 'separate-aliases.php') === [],
    'Function aliases in separate namespace blocks were treated as ambiguous.',
);
array_push($diagnostics, ...$namespaceIsolationDiagnostics);
$aliasScannerChecked = true;
$suitePath = __DIR__.'/suite.php';
$capture = static fn (): DeclarationRegistry => Declarations::capture(
    static fn () => require $suitePath,
    $rootPath,
    'Drove native phase 3 '.$fixture,
);
$duplicateDatasetRejected = false;
$duplicateRegistry = new DeclarationRegistry($rootPath, 'Drove duplicate dataset proof');
$duplicateDefinition = $duplicateRegistry->declareTest(
    'rejects duplicate dataset keys',
    static function (int $value): void {
        //
    },
    $suitePath,
    1,
);
$duplicateDefinition->with((static function (): iterable {
    yield 'same' => [1];
    yield 'same' => [2];
})());

try {
    $duplicateRegistry->plan();
} catch (LogicException $exception) {
    $duplicateDatasetRejected = str_starts_with(
        $exception->getMessage(),
        'Duplicate native dataset case key ',
    );
}

$assert(
    $duplicateDatasetRejected,
    'Duplicate native dataset case IDs were not rejected during planning.',
);
$planningStartedNs = hrtime(true);
$declarations = $capture();
$plan = $declarations->plan();
$planningMs = round((hrtime(true) - $planningStartedNs) / 1_000_000, 3);
$workingDirectory = getcwd();

if ($workingDirectory === false || ! chdir(__DIR__)) {
    $fail('Could not enter the native Phase 3 fixture directory.');
}

try {
    $secondPlan = $capture()->plan();
} finally {
    chdir($workingDirectory);
}

$assert($plan === $secondPlan, 'Native Phase 3 planning is not deterministic across working directories.');

/**
 * @param  array<string, mixed>  $plan
 * @return list<array<string, mixed>>
 */
$planTests = static function (array $plan) use ($fail): array {
    $root = $plan['root'] ?? null;

    if (! is_array($root)) {
        $fail('Native Phase 3 plan has no suite root.');
    }

    $pending = [$root];
    $tests = [];

    while ($pending !== []) {
        $scope = array_pop($pending);

        if (! is_array($scope)) {
            $fail('Native Phase 3 plan contains an invalid scope.');
        }

        $scopeTests = $scope['tests'] ?? null;
        $children = $scope['children'] ?? null;

        if (! is_array($scopeTests) || ! array_is_list($scopeTests)
            || ! is_array($children) || ! array_is_list($children)) {
            $fail('Native Phase 3 plan contains invalid descendants.');
        }

        foreach ($scopeTests as $test) {
            if (! is_array($test)) {
                $fail('Native Phase 3 plan contains an invalid test.');
            }

            $tests[] = $test;
        }

        array_push($pending, ...array_reverse($children));
    }

    return $tests;
};
$plannedTests = $planTests($plan);
$datasetCases = [];
$expectedTestCount = $fixture === 'stress' ? 10_000 : 49;
$expectedRunnableCount = $fixture === 'stress' ? 10_000 : 47;
$expectedAssertionCount = $fixture === 'stress' ? 10_000 : 46;
$expectedCleanupCount = $fixture === 'stress' ? 10_000 : 35;
$assert(count($plannedTests) === $expectedTestCount, 'Native Phase 3 planned an unexpected test count.');
$caseIds = array_column($plannedTests, 'id');
$assert(
    count($caseIds) === count(array_unique($caseIds)),
    'Native Phase 3 planned duplicate case IDs.',
);
$stressShardCount = 0;

if ($fixture === 'stress') {
    $fileScopes = $plan['root']['children'] ?? null;
    $assert(
        is_array($fileScopes)
            && array_is_list($fileScopes)
            && count($fileScopes) === 1
            && ($fileScopes[0]['tests'] ?? null) === [],
        'Native Phase 3 stress plan did not contain exactly one empty file scope.',
    );
    $stressShards = $fileScopes[0]['children'] ?? null;
    $assert(
        is_array($stressShards)
            && array_is_list($stressShards)
            && count($stressShards) === 30,
        'Native Phase 3 did not plan exactly 30 deterministic stress shards.',
    );
    $stressShardSizes = array_map(
        static fn (array $scope): int => count($scope['tests'] ?? []),
        $stressShards,
    );
    sort($stressShardSizes, SORT_NUMERIC);
    $assert(
        $stressShardSizes === [
            ...array_fill(0, 20, 333),
            ...array_fill(0, 10, 334),
        ] && array_all(
            $stressShards,
            static fn (array $scope): bool => ($scope['children'] ?? null) === [],
        ),
        'Native Phase 3 stress shard sizes diverged.',
    );
    $stressShardCount = count($stressShards);
}

foreach ($plannedTests as $plannedTest) {
    $source = $plannedTest['source'] ?? null;
    $assert(
        is_array($source)
            && ($source['path'] ?? null) === 'experiments/native-phase-3/suite.php'
            && is_int($source['line'] ?? null)
            && $source['line'] > 0,
        'A native Phase 3 case lost its portable source location.',
    );
}

$dualFailure = null;

if ($fixture === 'conformance') {
    $datasetCases = array_values(array_filter(
        $plannedTests,
        static fn (array $test): bool => is_array($test['dataset'] ?? null),
    ));
    $assert(count($datasetCases) === 10, 'Native Phase 3 did not expand ten dataset cases.');
    $datasetLabels = array_column(array_column($datasetCases, 'dataset'), 'label');
    sort($datasetLabels, SORT_STRING);
    $assert(
        $datasetLabels === ['#0', 'large', 'long', 'medium', 'negative', 'one', 'short', 'small', 'three', 'two'],
        'Native Phase 3 dataset labels diverged.',
    );
    $datasetSuffixes = array_map(static function (array $test) use ($fail): string {
        $id = $test['id'] ?? null;

        if (! is_string($id)) {
            $fail('A native dataset case has no ID.');
        }

        $offset = strrpos($id, '::dataset:');

        if ($offset === false) {
            $fail('A native dataset case ID has no dataset suffix.');
        }

        return substr($id, $offset);
    }, $datasetCases);
    sort($datasetSuffixes, SORT_STRING);
    $expectedSuffixes = [
        '::dataset:index:0',
        '::dataset:name:large',
        '::dataset:name:long',
        '::dataset:name:medium',
        '::dataset:name:negative',
        '::dataset:name:one',
        '::dataset:name:short',
        '::dataset:name:small',
        '::dataset:name:three',
        '::dataset:name:two',
    ];
    sort($expectedSuffixes, SORT_STRING);
    $assert($datasetSuffixes === $expectedSuffixes, 'Native Phase 3 dataset case IDs diverged.');
}

$scopeHookSentinel = null;

if ($fixture === 'conformance') {
    $createdScopeHookSentinel = tempnam(sys_get_temp_dir(), 'drove-native-hooks-');

    if (! is_string($createdScopeHookSentinel)) {
        $fail('Could not create the native scope-hook sentinel.');
    }

    $scopeHookSentinel = $createdScopeHookSentinel;
    $assert(
        putenv('DROVE_NATIVE_HOOK_SENTINEL='.$scopeHookSentinel),
        'Could not configure the native scope-hook sentinel.',
    );
    register_shutdown_function(static function () use ($scopeHookSentinel): void {
        putenv('DROVE_NATIVE_HOOK_SENTINEL');

        if (is_file($scopeHookSentinel)) {
            unlink($scopeHookSentinel);
        }
    });
}

$scheduler = $forked
    ? new DroverScheduler(
        'native-phase-3-'.$fixture.'-c'.$processes,
        $processes,
        library: $resolvedDroverLibrary,
    )
    : new class implements Scheduler
    {
        public function runId(): string
        {
            return 'native-phase-3-inline';
        }

        public function map(array $tasks, Closure $execute): array
        {
            $results = [];

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
                    'memory_peak_bytes' => memory_get_peak_usage(true),
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
            }

            return [
                'results' => $results,
                'completion_order' => array_column($tasks, 'id'),
            ];
        }

        public function withPermit(array $scopes, Closure $work): mixed
        {
            return $work();
        }
    };
$parentPid = getmypid();
$executionStartedNs = hrtime(true);
$run = new Runner($scheduler)->run($declarations);
$executionMs = round((hrtime(true) - $executionStartedNs) / 1_000_000, 3);
$scopeHookBodyChecked = true;

if (is_string($scopeHookSentinel)) {
    $scopeHookContents = file_get_contents($scopeHookSentinel);
    putenv('DROVE_NATIVE_HOOK_SENTINEL');
    $scopeHookRemoved = unlink($scopeHookSentinel);
    $scopeHookBodyChecked = is_string($scopeHookContents)
        && preg_split('/\R/', trim($scopeHookContents)) === [
            'root.beforeAll',
            'nested.beforeAll',
            'nested.afterAll',
            'root.afterAll',
        ]
        && $scopeHookRemoved
        && ! file_exists($scopeHookSentinel);
    $assert($scopeHookBodyChecked, 'Native Phase 3 scope-hook bodies or cleanup diverged.');
}

$tests = $run['tests'] ?? null;

if (! is_array($tests) || ! array_is_list($tests)) {
    $fail('Native Phase 3 run returned invalid test results.');
}

$assert(count($tests) === $expectedTestCount, 'Native Phase 3 lost terminal test results.');
$resultIds = array_column($tests, 'id');
$assert(
    count($resultIds) === count(array_unique($resultIds))
        && array_diff($caseIds, $resultIds) === []
        && array_diff($resultIds, $caseIds) === [],
    'Native Phase 3 lost, duplicated, or invented case IDs.',
);
$statusCounts = [];
$assertionCount = 0;
$reportedCleanupCount = 0;
$unreportedMetrics = [];
$pidAssignments = [];
$missingExecutorNames = [];
$executorMemory = [];

foreach ($tests as $test) {
    $status = $test['status'] ?? null;
    $assertions = $test['assertions'] ?? null;
    $cleanups = $test['cleanups'] ?? null;
    $telemetry = $test['telemetry'] ?? null;

    if (! is_string($status)) {
        $fail('A native Phase 3 result lost its status.');
    }

    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

    if (! is_int($assertions) || $assertions < 0
        || ! is_int($cleanups) || $cleanups < 0) {
        $unreportedMetrics[] = [
            'id' => $test['id'] ?? null,
            'status' => $status,
            'assertions' => $assertions,
            'cleanups' => $cleanups,
        ];
    } else {
        $assertionCount += $assertions;
        $reportedCleanupCount += $cleanups;
    }

    if (is_array($telemetry) && is_int($telemetry['pid'] ?? null)) {
        $pidAssignments[] = $telemetry['pid'];
    } else {
        $missingExecutorNames[] = $test['name'] ?? '<unknown>';
    }

    if (is_array($telemetry) && is_int($telemetry['memory_peak_bytes'] ?? null)) {
        $executorMemory[] = $telemetry['memory_peak_bytes'];
    }
}

ksort($statusCounts, SORT_STRING);
$expectedStatusCounts = $fixture === 'stress'
    ? ['passed' => 10_000]
    : ($forked
        ? ['failed' => 2, 'passed' => 45, 'skipped' => 1, 'todo' => 1]
        : ['failed' => 1, 'passed' => 46, 'skipped' => 1, 'todo' => 1]);
$isFailedTerminal = static fn (mixed $terminal): bool => is_array($terminal)
    && in_array($terminal['status'] ?? null, ['failed', 'blocked'], true);
$terminalProjection = static fn (array $terminal): array => [
    'id' => $terminal['id'] ?? null,
    'scope_id' => $terminal['scope_id'] ?? null,
    'name' => $terminal['name'] ?? null,
    'type' => $terminal['type'] ?? null,
    'status' => $terminal['status'] ?? null,
    'failure' => $terminal['failure'] ?? null,
    'teardown_failures' => $terminal['teardown_failures'] ?? [],
    'telemetry' => $terminal['telemetry'] ?? null,
];
$failedRoot = $isFailedTerminal($run['root'] ?? null)
    ? $terminalProjection($run['root'])
    : null;
$failedScopes = array_map(
    $terminalProjection,
    array_values(array_filter($run['scopes'] ?? [], $isFailedTerminal)),
);
$failedTests = array_map(
    $terminalProjection,
    array_values(array_filter($tests, $isFailedTerminal)),
);
$assert(
    $statusCounts === $expectedStatusCounts,
    'Native Phase 3 statuses diverged: '.json_encode([
        'counts' => $statusCounts,
        'failed_test_ids' => array_column($failedTests, 'id'),
        'terminals' => [
            'root' => $failedRoot,
            'scopes' => $failedScopes,
            'tests' => $failedTests,
        ],
    ], JSON_THROW_ON_ERROR),
);
$assert(
    array_all(
        $unreportedMetrics,
        static fn (array $metrics): bool => in_array($metrics['status'], ['failed', 'blocked'], true),
    ),
    'A passing native Phase 3 result lost metrics: '.json_encode(
        $unreportedMetrics,
        JSON_THROW_ON_ERROR,
    ),
);
$assert($assertionCount === $expectedAssertionCount, 'Native Phase 3 assertion counts diverged.');

if ($fixture === 'conformance' && $forked) {
    $timeout = array_find(
        $tests,
        static fn (array $test): bool => ($test['name'] ?? null) === 'enforces a per-test timeout',
    );
    $assert(
        is_array($timeout)
            && ($timeout['status'] ?? null) === 'failed'
            && ($timeout['failure']['kind'] ?? null) === FailureKind::Timeout->value,
        'Native Phase 3 timeout semantics diverged.',
    );
}

if ($fixture === 'conformance') {
    $dualFailure = array_find(
        $tests,
        static fn (array $test): bool => ($test['name'] ?? null) === 'preserves body and cleanup failures',
    );
    $assert(
        is_array($dualFailure)
            && ($dualFailure['failure']['kind'] ?? null) === FailureKind::AssertionFailure->value
            && count($dualFailure['teardown_failures'] ?? []) === 1
            && ($dualFailure['teardown_failures'][0]['kind'] ?? null) === FailureKind::TeardownFailure->value
            && ($dualFailure['teardown_failures'][0]['phase'] ?? null) === 'defer',
        'Native Phase 3 did not preserve its primary body failure and deferred cleanup failure.',
    );
    $dualPhases = array_values(array_filter(array_map(
        static fn (array $event): ?string => ($event['type'] ?? null) === 'hook.finished'
            && is_string($event['phase'] ?? null)
                ? $event['phase']
                : null,
        $dualFailure['events'] ?? [],
    )));
    $afterEachIndex = array_search('after_each', $dualPhases, true);
    $deferIndex = array_search('defer', $dualPhases, true);
    $assert(
        is_int($afterEachIndex) && is_int($deferIndex) && $afterEachIndex < $deferIndex,
        'Native Phase 3 deferred cleanup ran before afterEach.',
    );
}

$expectedRunStatus = $fixture === 'conformance' ? 'failed' : 'passed';
$expectedExitCode = $expectedRunStatus === 'failed' ? 1 : 0;
$assert(
    ($run['status'] ?? null) === $expectedRunStatus
        && ($run['exit_code'] ?? null) === $expectedExitCode,
    'Native Phase 3 aggregate exit semantics diverged.',
);
$observedLanes = $run['observed_concurrency']['global'] ?? null;
$assert(
    $observedLanes === ($forked ? min($processes, $expectedRunnableCount) : 1),
    'Native Phase 3 observed concurrency diverged.',
);
$executorPids = array_values(array_unique($pidAssignments));
$assert(
    count($pidAssignments) === $expectedRunnableCount,
    sprintf(
        'Native Phase 3 assigned %d executors for %d runnable tests; missing [%s].',
        count($pidAssignments),
        $expectedRunnableCount,
        implode(', ', $missingExecutorNames),
    ),
);

if ($forked) {
    $assert(
        count($executorPids) === $expectedRunnableCount
            && ! in_array($parentPid, $executorPids, true),
        'Native Phase 3 reused an executor or ran a test in the prepared parent.',
    );
}

$cleanupProjection = [];
$scopeHookProjection = [];
$runEvents = $run['events'] ?? null;

if (! is_array($runEvents) || ! array_is_list($runEvents)) {
    $fail('Native Phase 3 returned invalid run events.');
}

foreach ($runEvents as $event) {
    if (! is_array($event)
        || ($event['type'] ?? null) !== 'hook.finished'
        || ! in_array($event['phase'] ?? null, ['before_all', 'after_all'], true)) {
        continue;
    }

    $scopeHookProjection[] = [
        'scope_id' => $event['scope_id'] ?? null,
        'hook_id' => $event['hook_id'] ?? null,
        'phase' => $event['phase'],
        'status' => $event['status'] ?? null,
    ];
}

if ($fixture === 'conformance') {
    $scopeHookPhases = array_column($scopeHookProjection, 'phase');
    $scopeHookIds = array_column($scopeHookProjection, 'scope_id');
    $assert(
        $scopeHookPhases === ['before_all', 'before_all', 'after_all', 'after_all']
            && count($scopeHookIds) === 4
            && $scopeHookIds[0] === $scopeHookIds[3]
            && $scopeHookIds[1] === $scopeHookIds[2]
            && $scopeHookIds[0] !== $scopeHookIds[1]
            && array_all(
                $scopeHookProjection,
                static fn (array $event): bool => $event['status'] === 'passed',
            ),
        'Native Phase 3 scope-hook order, multiplicity, or status diverged.',
    );
} else {
    $assert($scopeHookProjection === [], 'Native Phase 3 stress unexpectedly declared scope hooks.');
}

$hookProjection = $scopeHookProjection;

foreach ($tests as $test) {
    $events = $test['events'] ?? [];

    if (! is_array($events)) {
        $fail('A native Phase 3 result contains invalid events.');
    }

    foreach ($events as $event) {
        if (! is_array($event) || ($event['type'] ?? null) !== 'hook.finished') {
            continue;
        }

        $projection = [
            'test_id' => $test['id'],
            'hook_id' => $event['hook_id'] ?? null,
            'phase' => $event['phase'] ?? null,
            'status' => $event['status'] ?? null,
        ];
        $hookProjection[] = $projection;

        if (($event['phase'] ?? null) === 'defer') {
            $cleanupProjection[] = $projection;
        }
    }
}

$assert(
    count($cleanupProjection) === $expectedCleanupCount
        && $reportedCleanupCount === $expectedCleanupCount
        && count(array_filter(
            $cleanupProjection,
            static fn (array $event): bool => $event['status'] === 'failed',
        )) === ($fixture === 'conformance' ? 1 : 0),
    'Native Phase 3 deferred cleanup was lost or failed.',
);
$lifoCleanupChecked = true;

if ($fixture === 'conformance') {
    $lifoCase = array_find(
        $tests,
        static fn (array $test): bool => ($test['name'] ?? null) === 'uses strict identity',
    );
    $lifoDefers = is_array($lifoCase)
        ? array_values(array_filter(
            $lifoCase['events'] ?? [],
            static fn (mixed $event): bool => is_array($event)
                && ($event['type'] ?? null) === 'hook.finished'
                && ($event['phase'] ?? null) === 'defer'
                && ($event['status'] ?? null) === 'passed',
        ))
        : [];
    $lifoCleanupChecked = count($lifoDefers) === 2;
    $assert($lifoCleanupChecked, 'Native Phase 3 did not preserve LIFO cleanup evidence.');
}
$assert(
    NativePhaseThreeHeap::$value === 41,
    'Native Phase 3 mutated the prepared parent heap.',
);
$selectionProjection = [];

if ($fixture === 'conformance') {
    $selections = [
        'dataset' => [new Selection(includeGroups: ['dataset']), 10],
        'name' => [new Selection(nameContains: 'captures structured stdout'), 1],
        'exclude_wins' => [new Selection(includeGroups: ['fast'], excludeGroups: ['parallel']), 4],
    ];

    foreach ($selections as $name => [$selection, $expectedCount]) {
        $selected = new Runner($scheduler)->run($declarations, $selection);
        $selectedTests = $selected['tests'] ?? null;

        if (! is_array($selectedTests) || ! array_is_list($selectedTests)) {
            $fail('Native Phase 3 selection returned invalid test results.');
        }

        $assert(
            count($selectedTests) === $expectedCount
                && array_all(
                    $selectedTests,
                    static fn (array $test): bool => ($test['status'] ?? null) === 'passed',
                ),
            'Native Phase 3 '.$name.' selection diverged.',
        );
        $selectionProjection[$name] = array_map(
            static fn (array $test): array => [
                'id' => $test['id'] ?? null,
                'status' => $test['status'] ?? null,
                'assertions' => $test['assertions'] ?? null,
            ],
            $selectedTests,
        );
    }
}

$outputProjection = array_map(
    static fn (array $test): array => [
        'id' => $test['id'] ?? null,
        'stdout' => $test['stdout'] ?? null,
        'stderr' => $test['stderr'] ?? null,
    ],
    $tests,
);

if ($fixture === 'conformance') {
    $outputCase = array_find(
        $tests,
        static fn (array $test): bool => ($test['name'] ?? null) === 'captures structured stdout',
    );
    $assert(
        is_array($outputCase)
            && ($outputCase['stdout'] ?? null) === "native-phase-3:stdout\n"
            && ($outputCase['stderr'] ?? null) === '',
        'Native Phase 3 structured output capture diverged.',
    );
}

$semanticProjection = array_map(
    static fn (array $test): array => [
        'id' => $test['id'] ?? null,
        'name' => $test['name'] ?? null,
        'dataset' => $test['dataset'] ?? null,
        'groups' => $test['groups'] ?? null,
        'status' => $test['status'] ?? null,
        'assertions' => $test['assertions'] ?? null,
        'failure_kind' => $test['failure']['kind'] ?? null,
        'value' => $test['value'] ?? null,
    ],
    $tests,
);
$proofWallMs = round((hrtime(true) - $proofStartedNs) / 1_000_000, 3);
$summary = [
    'schema' => 1,
    'ok' => true,
    'evidence_revision' => $evidenceRevision,
    'runtime_platform' => $runtimePlatform,
    'drover_identity' => $droverIdentity,
    'fixture' => $fixture,
    'scheduler' => $forked ? 'drover' : 'inline',
    'processes' => $processes,
    'lanes_requested' => $processes,
    'lanes_observed' => $observedLanes,
    'test_count' => count($tests),
    'runnable_count' => $expectedRunnableCount,
    'terminal_result_count' => count($tests),
    'stress_shard_count' => $stressShardCount,
    'status_counts' => $statusCounts,
    'assertion_count' => $assertionCount,
    'cleanup_count' => count($cleanupProjection),
    'reported_cleanup_count' => $reportedCleanupCount,
    'unreported_metric_result_count' => count($unreportedMetrics),
    'executor_pid_assignments' => count($pidAssignments),
    'unique_executor_pid_count' => count($executorPids),
    'executor_pids' => $executorPids,
    'one_test_per_executor_checked' => $forked
        && count($executorPids) === $expectedRunnableCount,
    'no_batch_checked' => $forked
        && count($executorPids) === $expectedRunnableCount,
    'fork_isolation_checked' => $forked,
    'planning_ms' => $planningMs,
    'execution_ms' => $executionMs,
    'wall_ms' => $run['duration_ms'] ?? null,
    'proof_wall_ms' => $proofWallMs,
    'memory_limit_raw' => $memoryLimitRaw,
    'memory_limit_bytes' => $memoryLimitBytes,
    'parent_peak_memory_bytes' => memory_get_peak_usage(true),
    'executor_memory_samples' => count($executorMemory),
    'executor_peak_memory_bytes' => $executorMemory === [] ? null : max($executorMemory),
    'executor_reported_peak_memory_bytes_sum' => $executorMemory === [] ? null : array_sum($executorMemory),
    'surface_hash' => $surfaceHash,
    'help_hash' => hash('sha256', $help),
    'diagnostic_hash' => hash('sha256', json_encode($diagnostics, JSON_THROW_ON_ERROR)),
    'plan_hash' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)),
    'case_id_hash' => hash('sha256', json_encode($caseIds, JSON_THROW_ON_ERROR)),
    'semantic_hash' => hash('sha256', json_encode($semanticProjection, JSON_THROW_ON_ERROR)),
    'hook_hash' => hash('sha256', json_encode($hookProjection, JSON_THROW_ON_ERROR)),
    'output_hash' => hash('sha256', json_encode($outputProjection, JSON_THROW_ON_ERROR)),
    'cleanup_hash' => hash('sha256', json_encode($cleanupProjection, JSON_THROW_ON_ERROR)),
    'selection_hash' => hash('sha256', json_encode($selectionProjection, JSON_THROW_ON_ERROR)),
    'source_scanner_checked' => true,
    'unsupported_pre_execution_checked' => NativePhaseThreeHeap::$unsupportedBodyExecutions === 0,
    'native_cli_execution_checked' => $nativeCliExecutionChecked,
    'function_alias_scanner_checked' => $aliasScannerChecked,
    'duplicate_dataset_rejected_checked' => $duplicateDatasetRejected,
    'dataset_identity_checked' => $fixture === 'stress' || count($datasetCases) === 10,
    'selection_precedence_checked' => $fixture === 'stress' || count($selectionProjection) === 3,
    'scope_hook_body_checked' => $scopeHookBodyChecked,
    'lifo_cleanup_checked' => $lifoCleanupChecked,
    'dual_failure_preserved_checked' => $fixture === 'stress' || is_array($dualFailure),
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
