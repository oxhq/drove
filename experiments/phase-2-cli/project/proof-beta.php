<?php

declare(strict_types=1);

use Drove\Replay\Artifact;
use DroveBetaProof\Observer;

require __DIR__.'/vendor/autoload.php';

$expect = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$phase = static fn (string $name): int => fwrite(STDERR, 'proof-beta: '.$name.PHP_EOL);
$execute = static function (array $command, ?string $workingDirectory = null): array {
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $workingDirectory ?? __DIR__);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start a beta proof command.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit' => proc_close($process),
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
};
$run = static fn (string ...$arguments): array => $execute([
    PHP_BINARY,
    __DIR__.'/vendor/bin/drove',
    '--pest',
    ...$arguments,
]);
$readJson = static function (string $path): array {
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Could not read '.$path.'.');
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('Expected a JSON object in '.$path.'.');
    }

    return $decoded;
};
$workspace = sys_get_temp_dir().'/drove-beta-proof-'.bin2hex(random_bytes(8));

if (! mkdir($workspace, 0700)) {
    throw new RuntimeException('Could not create the beta proof workspace.');
}

$pluginCache = __DIR__.'/vendor/pest-plugins.json';
$originalPluginCache = file_get_contents($pluginCache);
$plugins = $originalPluginCache === false
    ? []
    : json_decode($originalPluginCache, true, flags: JSON_THROW_ON_ERROR);

if (! is_array($plugins)) {
    throw new RuntimeException('The installed Pest plugin cache is invalid.');
}

$plugins[] = Observer::class;
file_put_contents(
    $pluginCache,
    json_encode(array_values(array_unique($plugins)), JSON_THROW_ON_ERROR),
    LOCK_EX,
);

try {
    $phase('diagnostics and plugin observers');
    $version = $run('--version');
    $expect(
        $version['exit'] === 0
            && preg_match('/^Drove 0\.4\./', $version['stdout']) === 1,
        'The installed Drove version diagnostic drifted: '.$version['stderr'],
    );

    $compatibility = $run('--compatibility');
    $registry = json_decode($compatibility['stdout'], true, flags: JSON_THROW_ON_ERROR);
    $expect(
        $compatibility['exit'] === 0
            && is_array($registry)
            && ($registry['schema'] ?? null) === 1
            && ($registry['platforms']['windows']['status'] ?? null) === 'unsupported'
            && ($registry['capabilities']['pest-phpunit-bridge-coverage'] ?? null) === 'beta'
            && ($registry['capabilities']['native-coverage'] ?? null) === 'unsupported'
            && ($registry['capabilities']['drove-plugin-observers'] ?? null) === 'alpha'
            && ($registry['environment_contract']['schema'] ?? null) === 1
            && in_array(
                'scope-isolated',
                $registry['environment_contract']['resource_capabilities'] ?? [],
                true,
            ),
        'The installed compatibility registry drifted: '.$compatibility['stderr'],
    );

    $customConsumer = $workspace.'/custom-consumer';
    $fixtureRoot = is_file(__DIR__.'/composer.lock') ? __DIR__ : dirname(__DIR__);
    mkdir($customConsumer.'/tests', 0700, true);
    copy($fixtureRoot.'/composer.lock', $customConsumer.'/composer.lock');
    $customComposer = $readJson($fixtureRoot.'/composer.json');
    unset($customComposer['config']['allow-plugins']);
    file_put_contents(
        $customConsumer.'/composer.json',
        json_encode(
            $customComposer,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
        LOCK_EX,
    );
    file_put_contents(
        $customConsumer.'/tests/SmokeTest.php',
        <<<'PHP'
<?php

test('custom vendor passes', fn () => expect(true)->toBeTrue());
PHP,
        LOCK_EX,
    );
    putenv('COMPOSER_VENDOR_DIR=build/deps');
    $blockedInstall = $execute(
        ['composer', 'install', '--no-interaction', '--no-progress'],
        $customConsumer,
    );
    $customOptIn = $execute(
        [
            'composer',
            'config',
            '--no-plugins',
            'allow-plugins.pestphp/pest-plugin',
            'true',
        ],
        $customConsumer,
    );
    $customInstall = $execute(
        ['composer', 'install', '--no-interaction', '--no-progress'],
        $customConsumer,
    );
    putenv('COMPOSER_VENDOR_DIR');
    $customBin = $customConsumer.'/build/deps/bin/';
    $customVersion = $execute([PHP_BINARY, $customBin.'drove', '--version'], $customConsumer);
    $customInstaller = $execute(
        [PHP_BINARY, $customBin.'drove-install-native', '--help'],
        $customConsumer,
    );
    $customRun = $execute(
        [PHP_BINARY, $customBin.'drove', '--pest', 'tests/SmokeTest.php'],
        $customConsumer,
    );
    $customPest = $execute(
        [PHP_BINARY, $customBin.'pest', '--colors=never', 'tests/SmokeTest.php'],
        $customConsumer,
    );
    $expect(
        $blockedInstall['exit'] !== 0
            && str_contains(
                $blockedInstall['stderr'],
                'pestphp/pest-plugin contains a Composer plugin',
            )
            && str_contains($blockedInstall['stderr'], 'allow-plugins')
            && $customOptIn['exit'] === 0
            && $customInstall['exit'] === 0
            && $customVersion['exit'] === 0
            && str_starts_with($customVersion['stdout'], 'Drove 0.4.')
            && $customInstaller['exit'] === 0
            && $customRun['exit'] === 0
            && str_contains($customRun['stdout'], 'custom vendor passes')
            && $customPest['exit'] === 0,
        'A custom Composer vendor-dir broke the installed binaries: '
            .$blockedInstall['stderr'].$customOptIn['stderr']
            .$customInstall['stderr'].$customVersion['stderr']
            .$customInstaller['stderr'].$customRun['stderr'].$customPest['stderr'],
    );

    $missingReplayValue = $run('--replay', '--parallel', 'tests/FastTest.php');
    $expect(
        $missingReplayValue['exit'] === 2
            && str_contains($missingReplayValue['stderr'], 'one replay artifact path'),
        'A missing separated replay path consumed the next option.',
    );

    $pluginMarker = $workspace.'/plugin.log';
    $successReplay = $workspace.'/success.json';
    putenv('DROVE_PLUGIN_PROOF='.$pluginMarker);
    $success = $run('--replay='.$successReplay, 'tests/FastTest.php');
    putenv('DROVE_PLUGIN_PROOF');
    $pluginEvents = file($pluginMarker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $successArtifact = $readJson($successReplay);
    $expect(
        $success['exit'] === 0
            && is_array($pluginEvents)
            && count(array_filter(
                $pluginEvents,
                static fn (string $event): bool => str_starts_with($event, 'boot:'),
            )) === 1
            && in_array('plan:1', $pluginEvents, true)
            && in_array('run:0', $pluginEvents, true)
            && ($successArtifact['kind'] ?? null) === 'run'
            && ($successArtifact['result']['exit_code'] ?? null) === 0
            && is_string($successArtifact['plan']['sha256'] ?? null)
            && ($successArtifact['plan']['tests'] ?? null) === 3
            && ($successArtifact['result']['counts']['passed'] ?? null) === 3
            && is_int($successArtifact['memory_peak_bytes'] ?? null)
            && $successArtifact['memory_peak_bytes'] > 0
            && ($successArtifact['memory_peak_sample_count'] ?? null) === 4
            && (fileperms($successReplay) & 0777) === 0600,
        'Plugin observers or the successful replay artifact drifted: '.$success['stderr'],
    );

    $pluginFailureReplay = $workspace.'/plugin-failure.json';
    putenv('DROVE_PLUGIN_FAIL=plan');
    $pluginFailure = $run(
        '--replay-on-failure='.$pluginFailureReplay,
        'tests/FastTest.php',
    );
    putenv('DROVE_PLUGIN_FAIL');
    $pluginFailureArtifact = $readJson($pluginFailureReplay);
    $expect(
        $pluginFailure['exit'] === 1
            && str_contains($pluginFailure['stderr'], 'Drove beta observer failure.')
            && ($pluginFailureArtifact['kind'] ?? null) === 'crash'
            && ($pluginFailureArtifact['crash']['class'] ?? null) === RuntimeException::class
            && is_int($pluginFailureArtifact['memory_peak_bytes'] ?? null)
            && $pluginFailureArtifact['memory_peak_bytes'] > 0
            && ($pluginFailureArtifact['memory_peak_sample_count'] ?? null) === 1,
        'A failing plugin observer did not fail fast with stable crash metadata.',
    );

    $environmentPluginMarker = $workspace.'/environment-plugin.log';
    $environmentProviderMarker = $workspace.'/environment-provider.log';
    $environmentBeforeAllMarker = $workspace.'/environment-before-all.log';
    $environmentReplay = $workspace.'/environment-replay.json';
    putenv('DROVE_PLUGIN_PROOF='.$environmentPluginMarker);
    putenv('DROVE_ENV_PROVIDER_MARKER='.$environmentProviderMarker);
    putenv('DROVE_ENV_BEFORE_ALL_MARKER='.$environmentBeforeAllMarker);
    $environmentPreflight = $execute([
        PHP_BINARY,
        __DIR__.'/environment-preflight.php',
        '--replay='.$environmentReplay,
        'unsupported/environment/FirstTest.php',
        'unsupported/environment/SecondTest.php',
    ]);
    putenv('DROVE_PLUGIN_PROOF');
    putenv('DROVE_ENV_PROVIDER_MARKER');
    putenv('DROVE_ENV_BEFORE_ALL_MARKER');
    $environmentPluginEvents = file(
        $environmentPluginMarker,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
    );
    $expect(
        $environmentPreflight['exit'] === 2
            && str_contains(
                $environmentPreflight['stderr'],
                'Environment resource database (proof-leaf) cannot isolate sibling or mixed scope dispatches.',
            )
            && ! file_exists($environmentProviderMarker)
            && ! file_exists($environmentBeforeAllMarker)
            && ! file_exists($environmentReplay)
            && is_array($environmentPluginEvents)
            && count(array_filter(
                $environmentPluginEvents,
                static fn (string $event): bool => str_starts_with($event, 'boot:'),
            )) === 1
            && count(array_filter(
                $environmentPluginEvents,
                static fn (string $event): bool => str_starts_with($event, 'plan:'),
            )) === 0,
        'Unsafe environment topology crossed the runner preflight boundary: '
            .$environmentPreflight['stdout'].$environmentPreflight['stderr'],
    );

    $environmentSafePluginMarker = $workspace.'/environment-safe-plugin.log';
    $environmentSafeReplay = $workspace.'/environment-safe-replay.json';
    putenv('DROVE_PLUGIN_PROOF='.$environmentSafePluginMarker);
    $environmentSafe = $execute([
        PHP_BINARY,
        __DIR__.'/environment-preflight.php',
        '--replay='.$environmentSafeReplay,
        'unsupported/environment/FirstTest.php',
    ]);
    putenv('DROVE_PLUGIN_PROOF');
    $environmentSafeEvents = file(
        $environmentSafePluginMarker,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
    );
    $environmentSafeArtifact = $readJson($environmentSafeReplay);
    $expect(
        $environmentSafe['exit'] === 0
            && is_array($environmentSafeEvents)
            && in_array('environment:1', $environmentSafeEvents, true)
            && ($environmentSafeArtifact['plan']['tests'] ?? null) === 1
            && ($environmentSafeArtifact['plan']['scopes'] ?? null) === 2
            && ($environmentSafeArtifact['plan']['environment']['schema'] ?? null) === 1
            && ($environmentSafeArtifact['plan']['environment']['coordination'] ?? null)
                === 'best-effort'
            && ($environmentSafeArtifact['plan']['environment']['resources']['database']
                ?? null) === [
                    'kind' => 'database',
                    'provider' => 'proof-leaf',
                    'capabilities' => ['leaf-isolated'],
                ],
        'The environment schema was not attached before plugin inspection: '
            .$environmentSafe['stdout'].$environmentSafe['stderr'],
    );

    $successHash = hash_file('sha256', $successReplay);
    $noOverwrite = $run('--replay='.$successReplay, 'tests/FastTest.php');
    $expect(
        $noOverwrite['exit'] === 2
            && hash_file('sha256', $successReplay) === $successHash
            && str_contains($noOverwrite['stderr'], 'will not overwrite'),
        'Replay no-overwrite protection drifted.',
    );

    $successOnlyFailure = $workspace.'/success-only-failure.json';
    $onlyFailureSuccess = $run(
        '--replay-on-failure='.$successOnlyFailure,
        'tests/FastTest.php',
    );
    $expect(
        $onlyFailureSuccess['exit'] === 0 && ! file_exists($successOnlyFailure),
        'A successful run wrote a failure-only replay artifact.',
    );

    $failureReplay = $workspace.'/failure.json';
    $failure = $run('--replay-on-failure='.$failureReplay, 'tests/FailingTest.php');
    $failureArtifact = $readJson($failureReplay);
    $expect(
        $failure['exit'] === 1
            && ($failureArtifact['result']['exit_code'] ?? null) === 1
            && ($failureArtifact['result']['failures'][0]['kind'] ?? null)
                === 'assertion_failure'
            && ! str_contains((string) file_get_contents($failureReplay), 'fails conventionally'),
        'The failing replay artifact exposed output or lost its failure kind.',
    );

    $redactionPath = $workspace.'/redaction.json';
    $redaction = Artifact::create(
        __DIR__,
        $redactionPath,
        false,
        ['drove', '--api-token=do-not-record'],
        1,
        0,
    );
    $redaction->recordPlan([
        'environment' => [
            'schema' => 1,
            'coordination' => 'best-effort',
            'secret' => 'environment-secret-do-not-record',
            'resources' => [
                'database' => [
                    'kind' => 'database',
                    'provider' => 'mysql://environment-secret-do-not-record',
                    'capabilities' => ['leaf-isolated'],
                    'limitations' => ['environment-secret-do-not-record'],
                    'secret' => 'environment-secret-do-not-record',
                ],
            ],
        ],
        'root' => [
            'id' => 'scope:root',
            'type' => 'suite',
            'tests' => [],
            'children' => [],
        ],
    ]);
    $redaction->writeRun([
        'status' => 'passed',
        'exit_code' => 0,
        'tests' => [],
    ]);
    $redacted = (string) file_get_contents($redactionPath);
    $expect(
        str_contains($redacted, '--api-token=[REDACTED]')
            && ! str_contains($redacted, 'do-not-record')
            && ($readJson($redactionPath)['plan']['environment']['resources']['database']
                ?? null) === [
                    'kind' => 'database',
                    'provider' => '[REDACTED]',
                    'capabilities' => ['leaf-isolated'],
                ],
        'Replay argument or environment projection redaction drifted.',
    );

    $phase('timeout and crash classification');
    $timeoutReplay = $workspace.'/timeout.json';
    $timeout = $run(
        '--drove-timeout-ms=50',
        '--replay-on-failure='.$timeoutReplay,
        'tests/SlowTest.php',
    );
    $timeoutArtifact = $readJson($timeoutReplay);
    $expect(
        $timeout['exit'] === 1
            && ($timeoutArtifact['result']['failures'][0]['kind'] ?? null) === 'timeout',
        'The installed global test timeout drifted: '.$timeout['stderr'],
    );

    $crashReplay = $workspace.'/crash.json';
    $crash = $run(
        '--replay-on-failure='.$crashReplay,
        'unsupported/CrashingTest.php',
    );
    $crashArtifact = $readJson($crashReplay);
    $expect(
        $crash['exit'] === 1
            && ($crashArtifact['result']['failures'][0]['kind'] ?? null)
                === 'signal_termination'
            && ($crashArtifact['result']['failures'][0]['signal'] ?? null) === SIGKILL,
        'A killed test child was not classified and replayed.',
    );

    $phase('signal interruption cleanup');
    $interruptionMarker = $workspace.'/escaped';
    $interruptionReplay = $workspace.'/interruption.json';
    putenv('DROVE_INTERRUPTION_MARKER='.$interruptionMarker);
    $command = [
        PHP_BINARY,
        __DIR__.'/vendor/bin/drove',
        '--pest',
        '--replay-on-failure='.$interruptionReplay,
        'unsupported/InterruptionTest.php',
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__);
    $expect(is_resource($process), 'Could not start the interruption proof.');
    fclose($pipes[0]);
    $processStatus = proc_get_status($process);
    $readyDeadline = hrtime(true) + 3_000_000_000;

    while (! file_exists($interruptionMarker.'.ready') && hrtime(true) < $readyDeadline) {
        usleep(1_000);
    }

    $expect(
        ($processStatus['running'] ?? false)
            && is_int($processStatus['pid'] ?? null)
            && file_exists($interruptionMarker.'.ready')
            && posix_kill($processStatus['pid'], SIGINT),
        'Could not interrupt the installed Drove process.',
    );
    $interruptionStdout = stream_get_contents($pipes[1]);
    $interruptionStderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $finalProcessStatus = proc_get_status($process);
    $interruptionExit = proc_close($process);

    if ($interruptionExit === -1 && ! ($finalProcessStatus['running'] ?? true)) {
        $interruptionExit = $finalProcessStatus['exitcode'] ?? -1;
    }

    putenv('DROVE_INTERRUPTION_MARKER');
    usleep(1_000_000);
    $interruptionArtifact = $readJson($interruptionReplay);
    $expect(
        $interruptionExit === 1
            && ! file_exists($interruptionMarker)
            && ($interruptionArtifact['result']['failures'][0]['kind'] ?? null)
                === 'user_interruption'
            && ($interruptionArtifact['result']['failures'][0]['signal'] ?? null) === SIGINT,
        'Signal cleanup or interruption replay drifted: '
            .$interruptionStdout.$interruptionStderr,
    );

    $phase('coverage aggregation');
    $coveragePaths = [
        'baseline' => $workspace.'/baseline.cov',
        'c1' => $workspace.'/c1.cov',
        'c4' => $workspace.'/c4.cov',
    ];
    $coverageRuns = [
        'baseline' => $execute([
            PHP_BINARY,
            __DIR__.'/vendor/bin/phpunit',
            '--configuration=phpunit.xml',
            '--coverage-php='.$coveragePaths['baseline'],
            'tests/CoverageAggregationTest.php',
        ]),
        'c1' => $run(
            '--parallel',
            '--processes=1',
            '--coverage-php='.$coveragePaths['c1'],
            'tests/CoverageAggregationTest.php',
        ),
        'c4' => $run(
            '--parallel',
            '--processes=4',
            '--coverage-php='.$coveragePaths['c4'],
            'tests/CoverageAggregationTest.php',
        ),
    ];

    foreach ($coverageRuns as $name => $coverageRun) {
        $expect(
            $coverageRun['exit'] === 0 && file_exists($coveragePaths[$name]),
            $name.' coverage did not complete: '.$coverageRun['stderr'],
        );
    }

    $coverageProjection = static function (string $path): array {
        $serialized = require $path;
        $lines = $serialized['codeCoverage']->lineCoverage();

        foreach ($lines as &$fileLines) {
            foreach ($fileLines as &$testIds) {
                if (is_array($testIds)) {
                    sort($testIds, SORT_STRING);
                }
            }
        }

        unset($fileLines, $testIds);

        return $lines;
    };
    $baselineCoverage = $coverageProjection($coveragePaths['baseline']);
    $expect(
        $baselineCoverage !== []
            && $coverageProjection($coveragePaths['c1']) === $baselineCoverage
            && $coverageProjection($coveragePaths['c4']) === $baselineCoverage,
        'Merged Drove coverage drifted from PHPUnit or changed with concurrency.',
    );

    fwrite(STDOUT, json_encode([
        'status' => 'passed',
        'version' => trim($version['stdout']),
        'coverage' => ['phpunit', 'drove-c1', 'drove-c4'],
        'failure_kinds' => [
            'assertion_failure',
            'timeout',
            'signal_termination',
            'user_interruption',
        ],
        'plugin_hooks' => $pluginEvents,
        'plugin_failure' => $pluginFailureArtifact,
        'environment_preflight' => 'rejected-before-lifecycle',
        'replay' => 'metadata-only-no-overwrite',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
} finally {
    putenv('DROVE_PLUGIN_PROOF');
    putenv('DROVE_PLUGIN_FAIL');
    putenv('DROVE_ENV_PROVIDER_MARKER');
    putenv('DROVE_ENV_BEFORE_ALL_MARKER');
    putenv('DROVE_INTERRUPTION_MARKER');

    if ($originalPluginCache === false) {
        @unlink($pluginCache);
    } else {
        file_put_contents($pluginCache, $originalPluginCache, LOCK_EX);
    }

    $remove = static function (string $directory) use (&$remove): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.') {
                continue;
            }
            if ($entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                $remove($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    };
    $remove($workspace);
}
