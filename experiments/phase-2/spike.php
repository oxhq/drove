<?php

declare(strict_types=1);

use Drove\Pest\ScopeCompiler;
use Pest\Contracts\HasPrintableTestCaseName;
use Pest\Factories\TestCaseFactory;
use Pest\Factories\TestCaseMethodFactory;
use Pest\Kernel as PestKernel;
use Pest\Support\Str;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PHPUnit\Framework\TestSuite as PHPUnitTestSuite;
use PHPUnit\TextUI\Configuration\Registry as PHPUnitConfiguration;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require __DIR__.'/vendor/autoload.php';

if (! function_exists('pcntl_fork') || ! function_exists('posix_getppid')) {
    fwrite(STDERR, "Drove Phase 2 requires pcntl and posix.\n");
    exit(2);
}

set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
});

$rootPid = getmypid();
$GLOBALS['drove_root_pid'] = $rootPid;
$GLOBALS['drove_scope_state'] = (object) ['mutations' => ['root']];
$GLOBALS['drove_before_all_proof'] = (object) ['count' => 0, 'pid' => null];
$GLOBALS['drove_after_all_proof'] = (object) ['count' => 0, 'pid' => null];

$outputBufferLevel = ob_get_level();
PestKernel::boot(
    PestTestSuite::getInstance(__DIR__, 'tests'),
    new ArrayInput([]),
    new BufferedOutput,
);

while (ob_get_level() > $outputBufferLevel) {
    ob_end_clean();
}

$fixture = realpath(__DIR__.'/tests/ScopeIrTest.php');

if ($fixture === false) {
    throw new RuntimeException('The Phase 2 Pest fixture does not exist.');
}

$compiler = ScopeCompiler::activate(__DIR__);
$rejectedClosures = [];

foreach ([
    'synthetic' => null,
    'foreign' => function (): void {},
] as $label => $closure) {
    $factory = new TestCaseFactory($fixture);
    $method = new TestCaseMethodFactory($fixture, $closure);
    $method->description = sprintf('rejects %s closure', $label);
    $factory->addMethod($method);

    try {
        ScopeCompiler::capture($factory);
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'Drove file plans require test closures declared in their test file.') {
            $rejectedClosures[] = $label;
        }
    }
}

if ($rejectedClosures !== ['synthetic', 'foreign']) {
    throw new RuntimeException('Drove accepted a synthetic or foreign test closure.');
}

$suite = PHPUnitTestSuite::empty('drove-phase-two');
$suite->addTestFile($fixture);
(new TestSuiteFilterProcessor)->process(PHPUnitConfiguration::get(), $suite);

$plan = $compiler->plan($fixture);
$planJson = json_encode($plan, JSON_THROW_ON_ERROR);
$planIsScalar = json_decode($planJson, true, flags: JSON_THROW_ON_ERROR) === $plan;
$expectedPlan = [
    'id' => 'file:tests/ScopeIrTest.php',
    'type' => 'file',
    'path' => 'tests/ScopeIrTest.php',
    'tests' => [
        [
            'id' => 'test:tests/ScopeIrTest.php::%60prepared%20siblings%60%20%E2%86%92%20it%20runs%20beta%20from%20prepared%20state',
            'name' => '`prepared siblings` → it runs beta from prepared state',
            'scope' => ['prepared siblings'],
            'source' => ['path' => 'tests/ScopeIrTest.php', 'line' => 20],
        ],
        [
            'id' => 'test:tests/ScopeIrTest.php::%60prepared%20siblings%60%20%E2%86%92%20it%20runs%20alpha%20from%20prepared%20state',
            'name' => '`prepared siblings` → it runs alpha from prepared state',
            'scope' => ['prepared siblings'],
            'source' => ['path' => 'tests/ScopeIrTest.php', 'line' => 41],
        ],
    ],
];

$classSuite = $suite->tests()[0] ?? null;

if (! $classSuite instanceof PHPUnitTestSuite || count($classSuite->tests()) !== 2) {
    throw new RuntimeException('Pest did not generate exactly two Phase 2 cases.');
}

$factory = PestTestSuite::getInstance()->tests->get($fixture);

if ($factory === null || count($factory->methods) !== 2) {
    throw new RuntimeException('Pest did not retain exactly two Phase 2 methods.');
}

$casesByMethod = [];

foreach (array_reverse($classSuite->tests()) as $case) {
    if (! $case instanceof PHPUnitTestCase || ! $case instanceof HasPrintableTestCaseName) {
        throw new RuntimeException('Pest generated an unexpected Phase 2 case.');
    }

    $casesByMethod[$case->name()] = $case;
}

$resolvers = [];

foreach ($plan['tests'] as $testNode) {
    $methodName = Str::evaluable($testNode['name']);
    $method = $factory->getMethod($methodName);
    $case = $casesByMethod[$methodName] ?? null;
    $closure = $compiler->closure($testNode['id']);

    if (! $case instanceof PHPUnitTestCase || $method->closure !== $closure) {
        throw new RuntimeException(sprintf('Drove could not resolve %s.', $testNode['id']));
    }

    $resolvers[$testNode['id']] = [
        'case' => $case,
        'closure' => $closure,
        'method' => $methodName,
    ];
}

$planIds = array_column($plan['tests'], 'id');

if (array_keys($resolvers) !== $planIds) {
    throw new RuntimeException('Drove resolver order differs from the Scope IR.');
}

$generatedClasses = array_values(array_unique(array_map(
    static fn (array $resolver): string => $resolver['case']::class,
    $resolvers,
)));

if (count($generatedClasses) !== 1) {
    throw new RuntimeException('The Phase 2 tests did not share one generated Pest class.');
}

$generatedClass = $generatedClasses[0];
$loaderFile = (new ReflectionClass(PHPUnit\Runner\TestSuiteLoader::class))->getFileName();
$localCompilerHash = hash_file('sha256', '/pest/src/Drove/Pest/ScopeCompiler.php');
$installedCompilerHash = hash_file('sha256', __DIR__.'/vendor/pestphp/pest/src/Drove/Pest/ScopeCompiler.php');
$usesLocalDrove = is_string($localCompilerHash)
    && hash_equals($localCompilerHash, (string) $installedCompilerHash)
    && is_string($loaderFile)
    && str_ends_with(str_replace('\\', '/', $loaderFile), '/vendor/pestphp/pest/overrides/Runner/TestSuiteLoader.php');

if (! $usesLocalDrove) {
    throw new RuntimeException('Phase 2 is not using this Drove fork.');
}

$generatedClass::setUpBeforeClass();

try {
    $beforeAllProof = $GLOBALS['drove_before_all_proof'];
    $scopeState = $GLOBALS['drove_scope_state'];

    if ($beforeAllProof->count !== 1
        || $beforeAllProof->pid !== $rootPid
        || $scopeState->mutations !== ['root', 'prepared']) {
        throw new RuntimeException('The file snapshot was not prepared exactly once in the root.');
    }

    $children = [];

    foreach ($planIds as $testId) {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create a Phase 2 result pipe.');
        }

        [$parentSocket, $childSocket] = $sockets;
        $childPid = pcntl_fork();

        if ($childPid === -1) {
            fclose($parentSocket);
            fclose($childSocket);
            throw new RuntimeException('Unable to fork a Phase 2 test.');
        }

        if ($childPid === 0) {
            fclose($parentSocket);
            $case = $resolvers[$testId]['case'];
            $result = ['id' => $testId, 'status' => 'passed', 'pid' => getmypid()];

            try {
                $case->run();

                if (! $case->status()->isSuccess()) {
                    throw new RuntimeException(sprintf(
                        'Generated Pest test ended with %s: %s',
                        $case->status()->asString(),
                        $case->status()->message(),
                    ));
                }

                $result += [
                    'generated_method' => $case->name(),
                    'test_status' => $case->status()->asString(),
                    'assertions' => $case->numberOfAssertionsPerformed(),
                    'pest_ran' => $case->__ran,
                    'execution' => $GLOBALS['drove_pest_execution'] ?? null,
                ];
            } catch (Throwable $throwable) {
                $result['status'] = 'failed';
                $result['error'] = $throwable::class.': '.$throwable->getMessage();
            }

            fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
            fclose($childSocket);

            exit($result['status'] === 'passed' ? 0 : 1);
        }

        fclose($childSocket);
        $children[$testId] = ['pid' => $childPid, 'socket' => $parentSocket];
    }

    $results = [];

    foreach ($planIds as $testId) {
        $child = $children[$testId];
        $waitStatus = 0;

        do {
            $waitedPid = pcntl_waitpid($child['pid'], $waitStatus);
        } while ($waitedPid === -1 && pcntl_get_last_error() === PCNTL_EINTR);

        $payload = trim((string) stream_get_contents($child['socket']));
        fclose($child['socket']);

        $result = $payload === ''
            ? ['id' => $testId, 'status' => 'failed', 'error' => 'No child result.']
            : json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $result['exit_code'] = $waitedPid === $child['pid'] && pcntl_wifexited($waitStatus)
            ? pcntl_wexitstatus($waitStatus)
            : 128;
        $results[$testId] = $result;
    }
} finally {
    if (getmypid() === $rootPid) {
        $generatedClass::tearDownAfterClass();
    }
}

$afterAllProof = $GLOBALS['drove_after_all_proof'];
$expectedExecutions = [
    $planIds[0] => ['marker' => 'beta', 'end' => ['root', 'prepared', 'beta']],
    $planIds[1] => ['marker' => 'alpha', 'end' => ['root', 'prepared', 'alpha']],
];
$childPids = [];
$passed = $planIsScalar
    && $plan === $expectedPlan
    && array_keys($results) === $planIds
    && $scopeState->mutations === ['root', 'prepared']
    && $afterAllProof->count === 1
    && $afterAllProof->pid === $rootPid;

foreach ($plan['tests'] as $testNode) {
    $testId = $testNode['id'];
    $result = $results[$testId];
    $expected = $expectedExecutions[$testId];
    $childPids[] = $result['pid'] ?? null;
    $passed = $passed
        && ($result['id'] ?? null) === $testId
        && ($result['status'] ?? null) === 'passed'
        && ($result['exit_code'] ?? 128) === 0
        && ($result['generated_method'] ?? null) === $resolvers[$testId]['method']
        && ($result['test_status'] ?? null) === 'success'
        && ($result['assertions'] ?? 0) >= 4
        && ($result['pest_ran'] ?? null) === true
        && ($result['execution']['marker'] ?? null) === $expected['marker']
        && ($result['execution']['start_state'] ?? null) === ['root', 'prepared']
        && ($result['execution']['end_state'] ?? null) === $expected['end']
        && ($result['execution']['pid'] ?? null) === ($result['pid'] ?? null)
        && ($result['execution']['ppid'] ?? null) === $rootPid
        && ($result['execution']['generated_method'] ?? null) === $resolvers[$testId]['method'];
}

$passed = $passed
    && count(array_unique($childPids)) === 2
    && ! in_array($rootPid, $childPids, true);

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'pest_version' => Pest\version(),
    'local_drove_compiler_sha256' => $localCompilerHash,
    'loader' => $loaderFile,
    'generated_class' => $generatedClass,
    'rejected_closures' => $rejectedClosures,
    'scope_ir' => $plan,
    'resolver_ids' => array_keys($resolvers),
    'before_all' => ['count' => $beforeAllProof->count, 'pid' => $beforeAllProof->pid],
    'after_all' => ['count' => $afterAllProof->count, 'pid' => $afterAllProof->pid],
    'root_scope_after_children' => $scopeState->mutations,
    'children' => array_values($results),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
