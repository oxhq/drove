<?php

declare(strict_types=1);

use Drove\Extension\Contracts\Reporter;
use Drove\Extension\ContributionKind;
use Drove\Extension\Diagnostic;
use Drove\Extension\Discovery;
use Drove\Extension\ExtensionException;
use Drove\Extension\ExtensionSet;
use Drove\Extension\Loader;
use Drove\Extension\PlanView;
use Drove\Extension\Registry;
use Drove\Extension\RunSummary;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\PcntlScheduler;
use Drove\Kernel\Scheduler;
use Drove\Native\DeclarationRegistry;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Drove\Replay\Artifact;
use DroveNativePhaseTwo\ZetaEntrypoint;

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

$fixtureAutoloads = [];
spl_autoload_register(static function (string $class) use (&$fixtureAutoloads): void {
    if (! str_starts_with($class, 'DroveNativePhaseTwo\\')) {
        return;
    }

    $fixtureAutoloads[] = $class;
    require_once __DIR__.'/fixtures.php';
});

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$temporaryFiles = [];
$write = static function (mixed $value, bool $encode = true) use (&$temporaryFiles): string {
    $path = tempnam(sys_get_temp_dir(), 'drove-native-phase-2-');

    if ($path === false) {
        throw new RuntimeException('Could not create Phase 2 temporary metadata.');
    }

    $contents = $encode
        ? json_encode($value, JSON_THROW_ON_ERROR)
        : (string) $value;

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not write Phase 2 temporary metadata.');
    }

    $temporaryFiles[] = $path;

    return $path;
};
$package = static function (
    string $id,
    string $entrypoint,
    array $contributions,
    array $configuration = [],
    int $apiMinimum = 1,
    int $apiMaximum = 1,
    string $type = 'library',
    array $autoload = [],
): array {
    return [
        'name' => $id,
        'version' => '1.0.0',
        'type' => $type,
        'autoload' => $autoload,
        'extra' => [
            'drove' => [
                'extension' => [
                    'schema' => 1,
                    'id' => $id,
                    'entrypoint' => $entrypoint,
                    'api' => ['min' => $apiMinimum, 'max' => $apiMaximum],
                    'contributions' => $contributions,
                    'configuration' => $configuration,
                ],
            ],
        ],
    ];
};
$discover = static fn (array $packages): array => new Discovery()->fromInstalledJson(
    $write(['packages' => $packages]),
);
$diagnostics = [];
$expectDiagnostic = static function (
    string $name,
    Diagnostic $expected,
    Closure $operation,
) use (&$diagnostics, $assert): void {
    try {
        $operation();
    } catch (ExtensionException $exception) {
        $assert(
            $exception->diagnostic === $expected,
            sprintf('%s emitted %s instead of %s.', $name, $exception->diagnostic->value, $expected->value),
        );
        $assert(
            str_starts_with($exception->getMessage(), '['.$expected->value.'] '),
            $name.' did not prefix its diagnostic message with the stable code.',
        );
        $diagnostics[$name] = $exception->diagnostic->value;

        return;
    }

    throw new RuntimeException($name.' did not emit an extension diagnostic.');
};

try {
    $alphaPackage = $package(
        'proof/alpha',
        'DroveNativePhaseTwo\\AlphaEntrypoint',
        ['cli', 'reporter', 'resource-provider', 'planner', 'context', 'matcher'],
        ['label' => ['type' => 'string', 'required' => true]],
    );
    $zetaPackage = $package(
        'proof/zeta',
        'DroveNativePhaseTwo\\ZetaEntrypoint',
        ['reporter'],
    );

    $expectDiagnostic(
        'discovery_io',
        Diagnostic::DiscoveryIo,
        static fn () => new Discovery()->fromInstalledJson(__DIR__.'/missing-installed.json'),
    );
    $expectDiagnostic(
        'discovery_json',
        Diagnostic::DiscoveryJson,
        static fn () => new Discovery()->fromInstalledJson($write('{', false)),
    );
    $invalidManifest = $alphaPackage;
    $invalidManifest['extra']['drove']['extension']['schema'] = 2;
    $expectDiagnostic(
        'manifest_invalid',
        Diagnostic::ManifestInvalid,
        static fn () => $discover([$invalidManifest]),
    );
    $expectDiagnostic(
        'composer_plugin_forbidden',
        Diagnostic::ComposerPluginForbidden,
        static fn () => $discover([$package(
            'proof/composer-plugin',
            'DroveNativePhaseTwo\\NeverAutoloadedEntrypoint',
            ['reporter'],
            type: 'composer-plugin',
        )]),
    );
    $expectDiagnostic(
        'autoload_files_forbidden',
        Diagnostic::AutoloadFilesForbidden,
        static fn () => $discover([$package(
            'proof/autoload-files',
            'DroveNativePhaseTwo\\NeverAutoloadedEntrypoint',
            ['reporter'],
            autoload: ['files' => ['bootstrap.php']],
        )]),
    );
    $expectDiagnostic(
        'api_incompatible',
        Diagnostic::ApiIncompatible,
        static fn () => $discover([$package(
            'proof/incompatible',
            'DroveNativePhaseTwo\\NeverAutoloadedEntrypoint',
            ['reporter'],
            apiMinimum: 2,
            apiMaximum: 2,
        )]),
    );
    $assert(
        $fixtureAutoloads === [],
        'Data-only discovery autoloaded an incompatible or forbidden extension entrypoint.',
    );
    $expectDiagnostic(
        'duplicate_id',
        Diagnostic::DuplicateId,
        static fn () => $discover([$alphaPackage, $alphaPackage]),
    );

    $forward = $discover([$zetaPackage, $alphaPackage]);
    $reverse = $discover([$alphaPackage, $zetaPackage]);
    $manifestJson = json_encode($forward, JSON_THROW_ON_ERROR);
    $assert(
        $manifestJson === json_encode($reverse, JSON_THROW_ON_ERROR),
        'Extension discovery changed with Composer package order.',
    );
    $byId = [];

    foreach ($forward as $manifest) {
        $byId[$manifest->id] = $manifest;
    }

    $expectDiagnostic(
        'configuration_invalid',
        Diagnostic::ConfigurationInvalid,
        static fn () => Loader::load([$byId['proof/alpha']], ['proof/alpha' => []]),
    );
    $assert(
        $fixtureAutoloads === [],
        'Configuration validation did not finish before entrypoint autoload.',
    );
    $laterInvalidConfiguration = $package(
        'proof/zeta-config',
        'DroveNativePhaseTwo\\ZetaEntrypoint',
        ['reporter'],
        ['required' => ['type' => 'string', 'required' => true]],
    );
    $allConfigurationRejectedBeforeAutoload = false;

    try {
        Loader::load(
            $discover([$alphaPackage, $laterInvalidConfiguration]),
            ['proof/alpha' => ['label' => 'blue']],
        );
    } catch (ExtensionException $exception) {
        $allConfigurationRejectedBeforeAutoload = $exception->diagnostic === Diagnostic::ConfigurationInvalid;
    }

    $assert(
        $allConfigurationRejectedBeforeAutoload && $fixtureAutoloads === [],
        'A later invalid configuration allowed an earlier entrypoint to load.',
    );

    $configuration = ['proof/alpha' => ['label' => 'blue']];
    $extensions = Loader::load($forward, $configuration);
    $reorderedExtensions = Loader::load(
        array_reverse($reverse),
        ['proof/alpha' => ['label' => 'blue']],
    );
    $assert(
        $extensions->planMetadata(new PlanView('Phase 2', 32, 2))
            === $reorderedExtensions->planMetadata(new PlanView('Phase 2', 32, 2)),
        'Extension registration changed with input order.',
    );

    $expectDiagnostic(
        'entrypoint_invalid',
        Diagnostic::EntrypointInvalid,
        static fn () => Loader::load($discover([$package(
            'proof/missing-entrypoint',
            'DroveNativePhaseTwo\\MissingEntrypoint',
            ['reporter'],
        )])),
    );
    $expectDiagnostic(
        'duplicate_contribution',
        Diagnostic::DuplicateContribution,
        static fn () => Loader::load($discover([$package(
            'proof/duplicate',
            'DroveNativePhaseTwo\\DuplicateEntrypoint',
            ['reporter'],
        )])),
    );
    $cliCollisionPackage = $package(
        'proof/cli-collision',
        'DroveNativePhaseTwo\\AlphaEntrypoint',
        ['cli', 'reporter', 'resource-provider', 'planner', 'context', 'matcher'],
        ['label' => ['type' => 'string', 'required' => true]],
    );
    $cliCollisionRejected = false;

    try {
        Loader::load(
            $discover([$alphaPackage, $cliCollisionPackage]),
            [
                'proof/alpha' => ['label' => 'blue'],
                'proof/cli-collision' => ['label' => 'green'],
            ],
        );
    } catch (ExtensionException $exception) {
        $cliCollisionRejected = $exception->diagnostic === Diagnostic::DuplicateContribution;
    }

    $assert($cliCollisionRejected, 'Duplicate global CLI option names were not rejected.');

    $lateRegistry = new Registry($byId['proof/zeta'], []);
    new ZetaEntrypoint()->register($lateRegistry);
    $lateRegistry->freeze();
    $expectDiagnostic(
        'late_registration',
        Diagnostic::LateRegistration,
        static fn () => $lateRegistry->registerReporter('late', new class implements Reporter
        {
            public function report(RunSummary $run): string
            {
                return $run->status;
            }
        }),
    );
    $expectDiagnostic(
        'untyped_contribution',
        Diagnostic::UntypedContribution,
        static fn () => Loader::load($discover([$package(
            'proof/untyped',
            'DroveNativePhaseTwo\\UntypedEntrypoint',
            ['reporter'],
        )])),
    );
    $expectDiagnostic(
        'registration_failed',
        Diagnostic::RegistrationFailed,
        static fn () => Loader::load($discover([$package(
            'proof/throws',
            'DroveNativePhaseTwo\\ThrowsEntrypoint',
            ['reporter'],
        )])),
    );
    $internalTypeErrorClassified = false;

    try {
        Loader::load($discover([$package(
            'proof/internal-type-error',
            'DroveNativePhaseTwo\\InternalTypeErrorEntrypoint',
            ['reporter'],
        )]));
    } catch (ExtensionException $exception) {
        $internalTypeErrorClassified = $exception->diagnostic === Diagnostic::RegistrationFailed;
    }

    $assert(
        $internalTypeErrorClassified,
        'An internal extension TypeError was misclassified as an untyped contribution.',
    );
    $expectDiagnostic(
        'missing_contribution',
        Diagnostic::MissingContribution,
        static fn () => Loader::load($discover([$package(
            'proof/empty',
            'DroveNativePhaseTwo\\EmptyEntrypoint',
            ['reporter'],
        )])),
    );
    $expectDiagnostic(
        'unknown_owner',
        Diagnostic::UnknownOwner,
        static fn () => $extensions->contextValue('proof/missing', 'label'),
    );
    $expectDiagnostic(
        'unknown_contribution',
        Diagnostic::UnknownContribution,
        static fn () => $extensions->contextValue('proof/alpha', 'missing'),
    );
    $expectDiagnostic(
        'configuration_missing',
        Diagnostic::ConfigurationMissing,
        static fn () => Loader::load($discover([$package(
            'proof/missing-config',
            'DroveNativePhaseTwo\\MissingConfigEntrypoint',
            ['reporter'],
        )])),
    );
    $expectDiagnostic(
        'contribution_failed',
        Diagnostic::ContributionFailed,
        static fn () => Loader::load($discover([$package(
            'proof/failing-reporter',
            'DroveNativePhaseTwo\\FailingReporterEntrypoint',
            ['reporter'],
        )]))->reports(new RunSummary('passed', 0, ['passed' => 1])),
    );
    $assert(
        array_values($diagnostics) === array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic->value,
            Diagnostic::cases(),
        ),
        'The Phase 2 proof did not cover every stable extension diagnostic.',
    );

    $green = Loader::load($forward, ['proof/alpha' => ['label' => 'green']]);
    $assert(
        $extensions->contextValue('proof/alpha', 'label') === 'blue'
            && $green->contextValue('proof/alpha', 'label') === 'green',
        'Extension configuration leaked between run-scoped registries.',
    );

    $suitePath = __DIR__.'/suite.php';
    $capture = static fn (ExtensionSet $set): DeclarationRegistry => Declarations::capture(
        static fn () => require $suitePath,
        $rootPath,
        'Drove native phase 2',
        $set,
    );
    $declarations = $capture($extensions);
    $plan = $declarations->plan();
    $assert(
        $plan === $capture($reorderedExtensions)->plan(),
        'The integrated native plan changed with extension registration order.',
    );
    $metadata = $plan['root']['metadata']['extensions'] ?? null;
    $assert(is_array($metadata), 'The native plan omitted extension metadata.');
    $alphaMetadata = $metadata['extensions'][0] ?? null;
    $assert(
        is_array($alphaMetadata)
            && ($alphaMetadata['id'] ?? null) === 'proof/alpha'
            && ($alphaMetadata['annotations']['test-count'] ?? null) === 32
            && ($alphaMetadata['resources']['cache']['kind'] ?? null) === 'cache'
            && ($alphaMetadata['resources']['cache']['capabilities'] ?? null) === ['shared-read-only'],
        'Typed planner or resource contributions were not compiled into the native plan.',
    );
    $assert(
        $extensions->executeCli('proof/alpha', 'label', 'requested')->output === 'blue:requested',
        'The typed CLI contribution returned an unexpected result.',
    );
    $cliOptions = $extensions->cliOptions();
    $assert(
        count($cliOptions) === 1
            && $cliOptions[0]['owner'] === 'proof/alpha'
            && $cliOptions[0]['key'] === 'label'
            && $cliOptions[0]['definition']->longName === 'phase-label',
        'The typed CLI contribution was not exposed to the parser in deterministic order.',
    );
    $contractFiles = glob($rootPath.'/src/Drove/Extension/Contracts/*.php');

    if ($contractFiles === false || $contractFiles === []) {
        throw new RuntimeException('Extension contracts were not found.');
    }

    foreach ([
        'CliInput.php',
        'CliOptionDefinition.php',
        'CliResult.php',
        'ExtensionSet.php',
        'MatchInput.php',
        'MatchResult.php',
        'PlanView.php',
        'Registry.php',
        'RunSummary.php',
    ] as $contractFile) {
        $contractFiles[] = $rootPath.'/src/Drove/Extension/'.$contractFile;
    }

    foreach ($contractFiles as $contractFile) {
        $source = file_get_contents($contractFile);

        if ($source === false) {
            throw new RuntimeException('Could not inspect extension contract '.$contractFile.'.');
        }

        foreach (['$argv', '$_server', 'exit(', 'die(', 'autoload'] as $forbiddenAuthority) {
            $assert(
                ! str_contains(strtolower($source), $forbiddenAuthority),
                sprintf('Extension contract %s exposes forbidden authority %s.', $contractFile, $forbiddenAuthority),
            );
        }
    }
    $fixtureSource = file_get_contents(__DIR__.'/fixtures.php');

    if (! is_string($fixtureSource)) {
        throw new RuntimeException('Could not inspect the Phase 2 extension fixture.');
    }

    foreach (['Drove\\Kernel\\', 'Pest\\', 'PHPUnit\\', 'Testbench'] as $forbiddenDependency) {
        $assert(
            stripos($fixtureSource, $forbiddenDependency) === false,
            'The Phase 2 extension fixture imports forbidden runtime surface '.$forbiddenDependency.'.',
        );
    }

    $processes = (int) (getenv('DROVE_NATIVE_PROCESSES') ?: 1);
    $requestedScheduler = getenv('DROVE_NATIVE_SCHEDULER') ?: 'auto';
    $assert($processes >= 1 && $processes <= 30, 'DROVE_NATIVE_PROCESSES must be between 1 and 30.');
    $assert(
        $requestedScheduler !== 'pcntl' || function_exists('pcntl_fork'),
        'The pcntl scheduler was requested but pcntl_fork() is unavailable.',
    );
    $forked = function_exists('pcntl_fork') && $requestedScheduler !== 'inline';
    $scheduler = $forked
        ? new PcntlScheduler('native-phase-2-c'.$processes, $processes)
        : new class implements Scheduler
        {
            public function runId(): string
            {
                return 'native-phase-2-inline';
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
    $run = new Runner($scheduler)->run($declarations);
    $assert($run['status'] === 'passed' && $run['exit_code'] === 0, 'The native extension run failed.');
    $assert(count($run['tests']) === 32, 'The native extension run did not execute 32 tests.');
    $assert(
        ($run['extension_reports'] ?? null) === [
            ['owner' => 'proof/alpha', 'key' => 'summary', 'output' => 'passed:32'],
            ['owner' => 'proof/zeta', 'key' => 'summary', 'output' => 'zeta:passed'],
        ],
        'Typed reporters did not receive the native run summary in deterministic order.',
    );
    $observedConcurrency = $run['observed_concurrency']['global'] ?? null;
    $assert(
        $observedConcurrency === ($forked ? min($processes, 32) : 1),
        'The native extension run observed unexpected concurrency.',
    );
    $executorPids = array_values(array_unique(array_map(
        static fn (array $test): mixed => $test['telemetry']['pid'] ?? null,
        $run['tests'],
    )));
    $assert(! in_array(null, $executorPids, true), 'A native extension result lost its executor PID.');

    if ($forked) {
        $assert(count($executorPids) === 32, 'Forked native execution reused a process between tests.');
        $assert(! in_array($parentPid, $executorPids, true), 'A test executed in the prepared parent.');
    }

    $emptyDeclarations = Declarations::capture(
        static function (): void {
            \Drove\Native\test('empty extension run', static function (): void {});
        },
        $rootPath,
        'Drove native phase 2 empty',
    );
    $emptyPlan = $emptyDeclarations->plan();
    $assert(
        ! isset($emptyPlan['root']['metadata']['extensions']),
        'Extension plan metadata leaked into a later empty capture.',
    );
    $emptyRun = new Runner($scheduler)->run($emptyDeclarations);
    $assert(
        ! array_key_exists('extension_reports', $emptyRun),
        'Extension reporters leaked into a later empty run.',
    );

    $replayPath = $write('', false);

    if (! unlink($replayPath)) {
        throw new RuntimeException('Could not prepare the Phase 2 replay path.');
    }

    $replay = Artifact::create($rootPath, $replayPath, false, [], $processes, 1_000);
    $replay->recordPlan($plan);
    $replay->writeRun($run);
    $replayContents = file_get_contents($replayPath);
    $replayPayload = is_string($replayContents)
        ? json_decode($replayContents, true, 64, JSON_THROW_ON_ERROR)
        : null;
    $replayExtensions = is_array($replayPayload)
        ? ($replayPayload['plan']['extensions'] ?? null)
        : null;
    $assert(
        $replayExtensions === [
            'schema' => 1,
            'extensions' => [
                ['id' => 'proof/alpha', 'api_version' => 1],
                ['id' => 'proof/zeta', 'api_version' => 1],
            ],
        ],
        'The replay artifact lost the negotiated extension API versions.',
    );

    $projection = LifecycleExecutor::semanticProjection($run);

    $executorMemory = array_values(array_filter(
        array_map(
            static fn (array $test): mixed => $test['telemetry']['memory_peak_bytes'] ?? null,
            $run['tests'],
        ),
        'is_int',
    ));
    $summary = [
        'schema' => 1,
        'ok' => true,
        'scheduler' => $forked ? 'pcntl' : 'inline',
        'processes' => $processes,
        'lanes' => $processes,
        'observed_concurrency' => $observedConcurrency,
        'test_count' => count($run['tests']),
        'executor_pids' => $executorPids,
        'fork_isolation_checked' => $forked,
        'wall_ms' => $run['duration_ms'],
        'parent_peak_memory_bytes' => memory_get_peak_usage(true),
        'executor_peak_memory_bytes' => $executorMemory === [] ? null : max($executorMemory),
        'executor_peak_memory_bytes_sum' => $executorMemory === [] ? null : array_sum($executorMemory),
        'manifest_hash' => hash('sha256', $manifestJson),
        'plan_hash' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)),
        'metadata_hash' => hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR)),
        'semantic_hash' => hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR)),
        'report_hash' => hash('sha256', json_encode($run['extension_reports'], JSON_THROW_ON_ERROR)),
        'diagnostic_hash' => hash('sha256', json_encode($diagnostics, JSON_THROW_ON_ERROR)),
        'replay_extensions_hash' => hash('sha256', json_encode($replayExtensions, JSON_THROW_ON_ERROR)),
        'negotiated_api_versions' => ['proof/alpha' => 1, 'proof/zeta' => 1],
        'diagnostics' => $diagnostics,
        'cross_run_leakage_checked' => true,
        'pre_autoload_rejection_checked' => true,
        'all_configuration_preflight_checked' => true,
        'contract_authority_guard_checked' => true,
        'fixture_dependency_guard_checked' => true,
        'cli_collision_checked' => true,
        'internal_type_error_classification_checked' => true,
        'typed_contributions_checked' => array_map(
            static fn (ContributionKind $kind): string => $kind->value,
            ContributionKind::cases(),
        ),
    ];

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach ($temporaryFiles as $temporaryFile) {
        @unlink($temporaryFile);
    }
}
