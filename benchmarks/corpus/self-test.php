<?php

declare(strict_types=1);

$directory = sys_get_temp_dir().'/drove-corpus-self-'.bin2hex(random_bytes(8));

if (! mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create corpus self-test directory.');
}

try {
    testFullSuiteClassification($directory);
    testMeasurementWrapper($directory);
    testBridgeModeGuard();

    $summary = "Tests: 71 skipped, 714 passed (785)\nAssertions: 1690\n";
    $baselinePath = normalizeFixture(
        $directory,
        'baseline',
        'baseline',
        1,
        $summary,
        returnPath: true,
    );
    $drovePaths = [];

    foreach ([1, 2, 4, 8, 16, 30] as $processes) {
        $drovePaths[$processes] = normalizeFixture(
            $directory,
            "drove-$processes",
            'drove',
            $processes,
            $summary,
            returnPath: true,
        );
    }

    $drovePath = $drovePaths[8];
    $normalized = json_decode(
        (string) file_get_contents($drovePath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (($normalized['schema_version'] ?? null) !== 3
        || ($normalized['outcome']['tests'] ?? null) !== 785
        || ($normalized['observed_lanes'] ?? null) !== 8
        || ($normalized['source']['source_commit'] ?? null)
            !== '6b2cd358e8a9d6d1abb93804b70e1c659bbc411b'
        || ($normalized['execution_identity']['planned_tests'] ?? null) !== 785
        || count($normalized['execution_identity']['case_ids'] ?? []) !== 785
        || ($normalized['execution_identity']['terminal_case_ids'] ?? null) !== 785
        || ($normalized['runner_identity']['arguments'] ?? null) !== ['--pest']
        || ($normalized['metrics']['replay_php_peak_memory_bytes'] ?? null) !== 33_554_432) {
        throw new RuntimeException('Normalizer omitted benchmark v3 source or telemetry evidence.');
    }

    $droveOnePath = $drovePaths[1];
    [$report, $exit] = runCommand([
        PHP_BINARY,
        __DIR__.'/verify.php',
        $baselinePath,
        ...array_values($drovePaths),
    ]);

    if ($exit !== 0) {
        throw new RuntimeException("Verifier self-test rejected matching results:\n$report");
    }

    $decodedReport = json_decode($report, true, flags: JSON_THROW_ON_ERROR);
    $groups = $decodedReport['diagnostic_comparisons']['groups'] ?? [];

    if (($decodedReport['diagnostic_comparisons']['thresholds_applied'] ?? null) !== false
        || ($decodedReport['drove_revision'] ?? null) !== str_repeat('a', 40)
        || ($decodedReport['platform']['php'] ?? null) !== PHP_VERSION
        || ($decodedReport['dynamic_bridge_load']['pest/nonserial']['unchanged_bridge_load_ratio']
            ?? null) != 1.0
        || count($groups) !== 7
        || ($groups[0]['runs'] ?? null) !== 1
        || ($groups[0]['runner_identity']['executable'] ?? null) !== 'bin/pest'
        || ($groups[0]['runner_identity']['arguments'] ?? null) !== []
        || ($groups[0]['outcome']['tests'] ?? null) !== 785
        || ($groups[0]['wall_ms']['min'] ?? null) !== ($groups[0]['wall_ms']['median'] ?? null)
        || ($groups[0]['wall_ms']['min'] ?? null) !== ($groups[0]['wall_ms']['max'] ?? null)) {
        throw new RuntimeException('Verifier did not emit diagnostic-only grouped summaries.');
    }

    [$completeReport, $completeExit] = runCommand([
        PHP_BINARY,
        __DIR__.'/verify.php',
        '--complete',
        $baselinePath,
        ...array_values($drovePaths),
    ]);

    if ($completeExit === 0 || ! str_contains($completeReport, 'complete report cohort set mismatch')) {
        throw new RuntimeException('Verifier accepted an incomplete full-corpus report.');
    }

    $originalDroveOne = (string) file_get_contents($droveOnePath);
    $sourceMismatch = json_decode(
        $originalDroveOne,
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $sourceMismatch['source']['selection']['sha256'] = str_repeat('d', 64);
    $sourceMismatch['source']['source_sha256'] = fixtureSourceIdentityHash(
        $sourceMismatch['source'],
    );
    file_put_contents(
        $droveOnePath,
        json_encode($sourceMismatch, JSON_THROW_ON_ERROR),
    );
    [$mismatchReport, $mismatchExit] = runCommand([
        PHP_BINARY,
        __DIR__.'/verify.php',
        $baselinePath,
        ...array_values($drovePaths),
    ]);

    if ($mismatchExit === 0
        || ! str_contains(
            $mismatchReport,
            'baseline/Drove source identity mismatch',
        )) {
        throw new RuntimeException('Verifier accepted different baseline/Drove source bytes.');
    }

    file_put_contents($droveOnePath, $originalDroveOne);
    $lockMismatch = json_decode(
        $originalDroveOne,
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $lockMismatch['source']['dependency_lock_sha256'] = str_repeat('e', 64);
    $lockMismatch['source']['source_sha256'] = fixtureSourceIdentityHash(
        $lockMismatch['source'],
    );
    file_put_contents(
        $droveOnePath,
        json_encode($lockMismatch, JSON_THROW_ON_ERROR),
    );
    [$lockReport, $lockExit] = runCommand([
        PHP_BINARY,
        __DIR__.'/verify.php',
        $baselinePath,
        ...array_values($drovePaths),
    ]);

    if ($lockExit === 0
        || ! str_contains($lockReport, 'source identity is invalid')) {
        throw new RuntimeException('Verifier accepted a non-manifest dependency lock.');
    }

    file_put_contents($droveOnePath, $originalDroveOne);
    $originalDroveResults = [];

    foreach ($drovePaths as $processes => $path) {
        $originalDroveResults[$processes] = (string) file_get_contents($path);
        $caseIdentityMismatch = json_decode(
            $originalDroveResults[$processes],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $caseIdentityMismatch['execution_identity']['case_ids'][0]
            = 'pest:fixture.php::__pest_evaluable_case_000';
        sort($caseIdentityMismatch['execution_identity']['case_ids'], SORT_STRING);
        $caseIdentityMismatch['execution_identity']['case_ids_sha256']
            = fixtureCaseIdsHash($caseIdentityMismatch['execution_identity']['case_ids']);
        file_put_contents(
            $path,
            json_encode($caseIdentityMismatch, JSON_THROW_ON_ERROR),
        );
    }

    [$identityReport, $identityExit] = runCommand([
        PHP_BINARY,
        __DIR__.'/verify.php',
        $baselinePath,
        ...array_values($drovePaths),
    ]);

    if ($identityExit === 0
        || ! str_contains(
            $identityReport,
            'baseline/Drove canonical case-ID set mismatch',
        )) {
        throw new RuntimeException(
            'Verifier accepted a jointly divergent Drove case-ID set.',
        );
    }

    foreach ($drovePaths as $processes => $path) {
        file_put_contents($path, $originalDroveResults[$processes]);
    }
    $impossible = json_decode(
        (string) file_get_contents($drovePath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $impossible['observed_lanes'] = 9;
    file_put_contents($drovePath, json_encode($impossible, JSON_THROW_ON_ERROR));
    [$report, $exit] = runCommand([
        PHP_BINARY,
        __DIR__.'/verify.php',
        $baselinePath,
        ...array_values($drovePaths),
    ]);

    if ($exit === 0
        || ! str_contains(
            $report,
            'observed_lanes must be within the requested process limit',
        )) {
        throw new RuntimeException('Verifier accepted impossible observed lane telemetry.');
    }
} finally {
    removeTree($directory);
}

fwrite(STDOUT, "corpus self-test passed\n");

function testFullSuiteClassification(string $directory): void
{
    $portable = "$directory/Portable.php";
    $unsupported = "$directory/Unsupported.php";
    $helper = "$directory/Helper.php";
    $bootstrap = "$directory/bootstrap.php";
    $bootstrapUnsupported = "$directory/BootstrapUnsupported.php";
    $generatedDirectory = "$directory/tests/Features";
    $generated = "$generatedDirectory/Generated.php";
    $vendorDirectory = "$directory/vendor";
    $vendorAutoload = "$vendorDirectory/autoload.php";
    $configurationDirectory = "$directory/config";
    $configuration = "$configurationDirectory/phpunit.xml";
    $discovery = "$directory/discovery.xml";
    $manifest = "$directory/manifest.json";
    $composer = "$directory/composer.json";
    $composerLock = "$directory/composer.lock";
    $firstOutput = "$directory/classification-first.json";
    $secondOutput = "$directory/classification-second.json";

    if (! mkdir($configurationDirectory, 0700)
        || ! mkdir($generatedDirectory, 0700, true)
        || ! mkdir($vendorDirectory, 0700)) {
        throw new RuntimeException(
            'Cannot create nested corpus classification fixture.',
        );
    }

    file_put_contents($portable, <<<'PHP'
<?php

require __DIR__.'/Helper.php';

test('portable one', fn () => expect(true)->toBe(true));
test('portable two', fn () => expect(2)->toEqual(2));
PHP);
    file_put_contents($helper, <<<'PHP'
<?php

test('helper dependency', fn () => true)->depends('portable one');
PHP);
    file_put_contents($unsupported, <<<'PHP'
<?php

test('dependency', fn () => expect(true)->toBe(true))->depends('portable one');
PHP);
    file_put_contents($bootstrap, <<<'PHP'
<?php

require __DIR__.'/BootstrapUnsupported.php';
PHP);
    file_put_contents($bootstrapUnsupported, <<<'PHP'
<?php

test('bootstrap dependency', fn () => true)->depends('portable one');
PHP);
    file_put_contents($generated, <<<'PHP'
<?php

test('generated path', fn () => expect(true)->toBeTrue());
PHP);
    file_put_contents($vendorAutoload, <<<'PHP'
<?php

// Simulated generated Composer autoloader.
PHP);
    file_put_contents($composer, json_encode([
        'autoload-dev' => [
            'files' => ['bootstrap.php'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($configuration, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="../vendor/autoload.php">
  <testsuites>
    <testsuite name="fixture">
      <file>../Portable.php</file>
      <file>../Unsupported.php</file>
      <file>../tests/Features/Generated.php</file>
    </testsuite>
  </testsuites>
</phpunit>
XML);
    $portableXml = htmlspecialchars($portable, ENT_XML1);
    $unsupportedXml = htmlspecialchars($unsupported, ENT_XML1);
    $generatedXml = htmlspecialchars(
        "$directory/src/Factories/TestCaseFactory.php(179) : eval()'d code",
        ENT_XML1,
    );
    file_put_contents($discovery, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testSuite xmlns="https://xml.phpunit.de/testSuite">
  <tests>
    <testClass name="P\\Portable" file="$portableXml">
      <testMethod id="P\\Portable::portable-one" name="portable-one"/>
      <testMethod id="P\\Portable::portable-two" name="portable-two"/>
    </testClass>
    <testClass name="P\\Unsupported" file="$unsupportedXml">
      <testMethod id="P\\Unsupported::dependency" name="dependency"/>
    </testClass>
    <testClass name="P\\Tests\\Features\\Generated" file="$generatedXml">
      <testMethod id="P\\Tests\\Features\\Generated::generated-path" name="generated-path"/>
    </testClass>
  </tests>
  <groups>
    <group name="default">
      <test id="P\\Portable::portable-one"/>
      <test id="P\\Portable::portable-two"/>
      <test id="P\\Unsupported::dependency"/>
      <test id="P\\Tests\\Features\\Generated::generated-path"/>
    </group>
  </groups>
</testSuite>
XML);
    $lockContents = json_encode([
        'packages' => [
            [
                'name' => 'phpunit/phpunit',
                'type' => 'library',
            ],
        ],
        'packages-dev' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($composerLock, $lockContents);
    file_put_contents($manifest, json_encode([
        'schema_version' => 5,
        'full_suite_gate' => [
            'artifact_schema' => 1,
            'hosted_image' => 'local-fixture',
            'statuses' => ['supported', 'unsupported', 'bridge-only'],
            'scanner_classification_ratio_min' => 1,
            'unchanged_bridge_load_ratio_min' => 0.8,
            'unchanged_or_idempotent_codemod_bridge_load_ratio_min' => 0.95,
            'parallel_processes' => [1, 2, 4, 8, 16, 30],
            'serial_processes' => [1],
            'hosted_status' => 'PENDING',
        ],
        'extension_surface_map' => [
            'package:phpunit/phpunit' => ['phpunit.test-case'],
        ],
        'corpora' => [
            [
                'id' => 'fixture',
                'fixture' => true,
                'repository' => 'fixture://local',
                'commit' => str_repeat('b', 40),
                'execution_configuration' => 'config/phpunit.xml',
                'execution_configuration_sha256' => hash_file(
                    'sha256',
                    $configuration,
                ),
                'dependency_lock' => [
                    'path' => 'composer.lock',
                    'sha256' => hash('sha256', $lockContents),
                ],
                'full_suite_discovery' => [
                    'mode' => 'phpunit-list-tests-xml',
                    'runner' => 'vendor/bin/pest',
                    'configuration' => 'config/phpunit.xml',
                    'configuration_sha256' => hash_file(
                        'sha256',
                        $configuration,
                    ),
                    'input_roots' => [
                        'Portable.php',
                        'Unsupported.php',
                        'tests/Features/Generated.php',
                    ],
                    'environment' => [],
                    'case_surfaces' => ['pest.portable.test'],
                    'cohorts' => [
                        'parallel' => [
                            'files' => ['Portable.php'],
                            'resource_mode' => 'parallel',
                            'calibrated_cases' => 2,
                            'include_groups' => [],
                            'exclude_groups' => [],
                        ],
                    ],
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $environment = [
        'CORPUS_ALLOW_FIXTURE' => '1',
        'CORPUS_HOSTED_IMAGE' => 'local-fixture',
        'CORPUS_MANIFEST' => $manifest,
        'DROVE_EXPECTED_REVISION' => str_repeat('a', 40),
        'DROVE_SOURCE' => dirname(__DIR__, 2),
    ];
    $previous = [];

    foreach ($environment as $name => $value) {
        $current = getenv($name);
        $previous[$name] = $current === false ? null : $current;
        putenv("$name=$value");
    }

    try {
        foreach ([$firstOutput, $secondOutput] as $output) {
            [$report, $exit] = runCommand([
                PHP_BINARY,
                __DIR__.'/classify.php',
                'fixture',
                $directory,
                $output,
                '--cohort-root='.$directory,
                '--discovery-xml='.$discovery,
            ]);

            if ($exit !== 0) {
                throw new RuntimeException(
                    "Full-suite classifier fixture failed:\n$report",
                );
            }
        }
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === null ? $name : "$name=$value");
        }
    }

    $first = (string) file_get_contents($firstOutput);
    $second = (string) file_get_contents($secondOutput);
    $classified = json_decode($first, true, flags: JSON_THROW_ON_ERROR);
    $inputs = array_column(
        $classified['inputs'] ?? [],
        null,
        'path',
    );

    if ($first !== $second
        || ($classified['kind'] ?? null) !== 'full-suite-classification'
        || ($classified['full_suite']['whole_suite'] ?? null) !== true
        || ($classified['full_suite']['source_inputs'] ?? null) !== 6
        || ($classified['full_suite']['discovered_cases'] ?? null) !== 4
        || ($classified['full_suite']['classified_cases'] ?? null) !== 4
        || ($classified['full_suite']['scanner_classification_ratio'] ?? null) != 1.0
        || ($classified['full_suite']['case_status_counts']['bridge-only'] ?? null) !== 3
        || ($classified['full_suite']['case_status_counts']['unsupported'] ?? null) !== 1
        || count($classified['full_suite']['external_dependencies'] ?? []) !== 1
        || ($classified['full_suite']['external_dependencies'][0]['path'] ?? null)
            !== 'vendor/autoload.php'
        || ($classified['full_suite']['classified_extension_surfaces'] ?? null) !== 1
        || ($classified['cohorts']['parallel']['whole_suite'] ?? null) !== false
        || ($classified['cohorts']['parallel']['classified_cases'] ?? null) !== 2
        || ($classified['cohorts']['parallel']['runtime_contract_cases'] ?? null) !== 2
        || count($classified['cohorts']['parallel']['case_identity']['case_ids'] ?? []) !== 2
        || ($classified['cohorts']['parallel']['case_identity']['case_ids_sha256'] ?? null)
            !== fixtureCaseIdsHash(
                $classified['cohorts']['parallel']['case_identity']['case_ids'] ?? [],
            )
        || ($classified['cohorts']['parallel']['native_migration_unchanged_ratio'] ?? null) != 0.0
        || ($classified['cohorts']['parallel']['native_migration_unchanged_or_idempotent_codemod_ratio'] ?? null) != 0.0
        || ($inputs['Portable.php']['runtime_status'] ?? null) !== 'bridge-only'
        || ($inputs['Portable.php']['native_migration']['direct_status'] ?? null) !== 'bridge-only'
        || ($inputs['Portable.php']['native_migration']['status'] ?? null) !== 'unsupported'
        || ! in_array(
            'DROVE_MIGRATION_UNSUPPORTED_DEPENDENCY',
            $inputs['Portable.php']['native_migration']['dependency_diagnostics'] ?? [],
            true,
        )
        || isset($inputs['vendor/autoload.php'])
        || ($inputs['Helper.php']['runtime_status'] ?? null) !== 'bridge-only'
        || ($inputs['BootstrapUnsupported.php']['runtime_status'] ?? null) !== 'unsupported'
        || ($inputs['tests/Features/Generated.php']['runtime_status'] ?? null) !== 'bridge-only'
        || ! in_array(
            'pest:tests/Features/Generated.php::generated-path',
            array_column($classified['cases'] ?? [], 'id'),
            true,
        )
        || ($classified['execution']['performed'] ?? null) !== false) {
        throw new RuntimeException(
            'Full-suite classifier fixture was incomplete or non-deterministic: '
            .json_encode([
                'identical' => $first === $second,
                'full_suite' => $classified['full_suite'] ?? null,
                'cohort' => $classified['cohorts']['parallel'] ?? null,
                'execution' => $classified['execution'] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    file_put_contents($unsupported, <<<'PHP'
<?php

$helper = __DIR__.'/Helper.php';
require $helper;

test('dependency', fn () => expect(true)->toBe(true));
PHP);
    $unselectedDynamicFirst = "$directory/classification-dynamic-unselected-first.json";
    $unselectedDynamicSecond = "$directory/classification-dynamic-unselected-second.json";

    foreach ($environment as $name => $value) {
        putenv("$name=$value");
    }

    try {
        foreach ([
            $unselectedDynamicFirst,
            $unselectedDynamicSecond,
        ] as $output) {
            [$dynamicReport, $dynamicExit] = runCommand([
                PHP_BINARY,
                __DIR__.'/classify.php',
                'fixture',
                $directory,
                $output,
                '--cohort-root='.$directory,
                '--discovery-xml='.$discovery,
            ]);

            if ($dynamicExit !== 0) {
                throw new RuntimeException(
                    "Classifier rejected an unselected dynamic include:\n"
                    .$dynamicReport,
                );
            }
        }
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === null ? $name : "$name=$value");
        }
    }

    $unselectedFirst = (string) file_get_contents($unselectedDynamicFirst);
    $unselectedSecond = (string) file_get_contents($unselectedDynamicSecond);
    $unselectedClassification = json_decode(
        $unselectedFirst,
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $unselectedInputs = array_column(
        $unselectedClassification['inputs'] ?? [],
        null,
        'path',
    );
    $unselectedCases = array_values(array_filter(
        $unselectedClassification['cases'] ?? [],
        static fn (array $case): bool => ($case['source'] ?? null)
            === 'Unsupported.php',
    ));
    $dynamicFindings = array_values(array_filter(
        $unselectedInputs['Unsupported.php']['findings'] ?? [],
        static fn (array $finding): bool => ($finding['diagnostic'] ?? null)
            === 'DROVE_CORPUS_UNSUPPORTED_DYNAMIC_INCLUDE',
    ));

    if ($unselectedFirst !== $unselectedSecond
        || ($unselectedInputs['Unsupported.php']['path'] ?? null)
            !== 'Unsupported.php'
        || ($unselectedInputs['Unsupported.php']['sha256'] ?? null)
            !== hash_file('sha256', $unsupported)
        || ($unselectedInputs['Unsupported.php']['runtime_status'] ?? null)
            !== 'unsupported'
        || ($unselectedInputs['Unsupported.php']['runtime_evidence'] ?? null)
            !== 'static-preflight'
        || ($unselectedInputs['Unsupported.php']['native_migration']['direct_status']
            ?? null) !== 'unsupported'
        || count($dynamicFindings) !== 1
        || ($dynamicFindings[0]['path'] ?? null) !== 'Unsupported.php'
        || ($dynamicFindings[0]['status'] ?? null) !== 'unsupported'
        || ($dynamicFindings[0]['line'] ?? null) !== 4
        || ($dynamicFindings[0]['column'] ?? null) !== 1
        || count($unselectedCases) !== 1
        || ($unselectedCases[0]['status'] ?? null) !== 'unsupported'
        || ($unselectedCases[0]['runtime_evidence'] ?? null)
            !== 'static-preflight'
        || ($unselectedCases[0]['execution_cohorts'] ?? null) !== []
        || ! in_array(
            'DROVE_CORPUS_UNSUPPORTED_DYNAMIC_INCLUDE',
            $unselectedCases[0]['diagnostics'] ?? [],
            true,
        )) {
        throw new RuntimeException(
            'Classifier did not preserve the unsupported dynamic include '
            .'diagnostic and source identity.',
        );
    }

    file_put_contents($portable, <<<'PHP'
<?php

$helper = __DIR__.'/Helper.php';
require $helper;

test('portable one', fn () => expect(true)->toBe(true));
test('portable two', fn () => expect(2)->toEqual(2));
PHP);
    $dynamicOutput = "$directory/classification-dynamic-selected.json";

    foreach ($environment as $name => $value) {
        putenv("$name=$value");
    }

    try {
        [$dynamicReport, $dynamicExit] = runCommand([
            PHP_BINARY,
            __DIR__.'/classify.php',
            'fixture',
            $directory,
            $dynamicOutput,
            '--cohort-root='.$directory,
            '--discovery-xml='.$discovery,
        ]);
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === null ? $name : "$name=$value");
        }
    }

    if ($dynamicExit === 0
        || ! str_contains(
            $dynamicReport,
            'DROVE_CORPUS_UNSUPPORTED_DYNAMIC_INCLUDE',
        )
        || ! str_contains(
            $dynamicReport,
            'Portable.php:4:1',
        )) {
        throw new RuntimeException(
            'Full-suite classifier did not fail closed on a selected dynamic include.',
        );
    }
}

function testMeasurementWrapper(string $directory): void
{
    $measurement = "$directory/wrapper.measurement.json";
    $raw = "$directory/wrapper.raw.log";
    [$output, $exit] = runCommand([
        PHP_BINARY,
        __DIR__.'/measure.php',
        $measurement,
        $raw,
        '--',
        PHP_BINARY,
        '-r',
        'fwrite(STDOUT, "wrapper-ok\n");',
    ]);
    $cgroupV2 = PHP_OS_FAMILY === 'Linux'
        && is_file('/sys/fs/cgroup/cgroup.controllers')
        && is_file('/sys/fs/cgroup/memory.peak');

    if (! $cgroupV2) {
        if ($exit === 0) {
            throw new RuntimeException('Measurement wrapper did not fail closed without cgroup v2.');
        }

        return;
    }

    if ($exit !== 0) {
        throw new RuntimeException("Measurement wrapper self-test failed:\n$output");
    }

    $decoded = json_decode(
        (string) file_get_contents($measurement),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (($decoded['clock'] ?? null) !== 'monotonic'
        || ($decoded['runner_executable'] ?? null) !== '[OTHER]'
        || ($decoded['wall_ms'] ?? 0) <= 0
        || ($decoded['container_peak_memory_bytes'] ?? 0) <= 0
        || file_get_contents($raw) !== "wrapper-ok\n") {
        throw new RuntimeException('Measurement wrapper emitted invalid telemetry.');
    }
}

function testBridgeModeGuard(): void
{
    $prefix = [
        PHP_BINARY,
        __DIR__.'/validate-command.php',
        'drove',
        '--',
        PHP_BINARY,
        'bin/drove',
    ];

    foreach ([
        [[], 'missing explicit bridge selector'],
        [['--native'], 'native selector'],
        [['--pest', '--pest'], 'duplicate bridge selector'],
    ] as [$arguments, $label]) {
        [$output, $exit] = runCommand([...$prefix, ...$arguments]);

        if ($exit !== 2
            || ! str_contains(
                $output,
                'exactly one explicit --pest bridge selector',
            )) {
            throw new RuntimeException(
                "Corpus record guard accepted a $label:\n$output",
            );
        }
    }

    [$output, $exit] = runCommand([...$prefix, '--pest']);

    if ($exit !== 0 || $output !== '') {
        throw new RuntimeException(
            "Corpus record guard rejected the explicit --pest bridge selector:\n$output",
        );
    }
}

/**
 * @return array<string, mixed>|string
 */
function normalizeFixture(
    string $directory,
    string $name,
    string $runner,
    int $processes,
    string $rawContents,
    bool $returnPath = false,
): array|string {
    $raw = "$directory/$name.raw.log";
    $measurement = "$directory/$name.measurement.json";
    $replay = "$directory/$name.replay.json";
    $sourceIdentity = "$directory/$name.source.json";
    $classification = "$directory/pest.execution-classification.json";
    $caseEvidence = "$directory/$name.cases.xml";
    $outputPath = "$directory/$name.json";
    $platform = [
        'os_family' => PHP_OS_FAMILY,
        'os' => PHP_OS,
        'architecture' => php_uname('m'),
        'php' => PHP_VERSION,
    ];
    file_put_contents($raw, $rawContents);
    file_put_contents($measurement, json_encode([
        'schema_version' => 1,
        'clock' => 'monotonic',
        'memory_source' => 'cgroup-v2:/sys/fs/cgroup/memory.peak',
        'runner_executable' => $runner === 'baseline' ? 'bin/pest' : 'bin/drove',
        'wall_ms' => $runner === 'baseline' ? 12.5 : 10.25,
        'container_peak_memory_bytes' => $runner === 'baseline' ? 67_108_864 : 134_217_728,
        'exit_code' => 0,
        'platform' => $platform,
    ], JSON_THROW_ON_ERROR));
    $frontendCaseIds = array_map(
        static fn (int $index): string => "pest:fixture.php::__pest_evaluable_case_$index",
        range(1, 785),
    );
    sort($frontendCaseIds, SORT_STRING);
    file_put_contents($classification, json_encode([
        'kind' => 'full-suite-classification',
        'corpus' => 'pest',
        'repository' => 'https://github.com/pestphp/pest.git',
        'source_commit' => '6b2cd358e8a9d6d1abb93804b70e1c659bbc411b',
        'cohorts' => [
            'nonserial' => [
                'case_identity' => [
                    'schema' => 1,
                    'case_ids' => $frontendCaseIds,
                    'case_ids_sha256' => fixtureCaseIdsHash($frontendCaseIds),
                ],
            ],
        ],
        'cases' => array_map(
            static fn (string $id): array => [
                'id' => $id,
                'source' => 'fixture.php',
                'execution_cohorts' => ['nonserial'],
            ],
            $frontendCaseIds,
        ),
    ], JSON_THROW_ON_ERROR));

    if ($runner === 'drove') {
        $terminalCaseIds = array_map(
            static fn (int $index): string => "test:fixture.php::case-$index",
            range(1, 785),
        );
        file_put_contents($replay, json_encode([
            'schema' => 1,
            'kind' => 'run',
            'duration_ms' => 9.75,
            'memory_peak_bytes' => 33_554_432,
            'platform' => $platform,
            'command' => ['processes' => $processes],
            'plan' => [
                'sha256' => str_repeat('f', 64),
                'tests' => 785,
                'case_identity' => [
                    'schema' => 1,
                    'cases' => array_map(
                        static fn (string $executionId, string $frontendId): array => [
                            'execution_id' => $executionId,
                            'frontend_id' => $frontendId,
                        ],
                        $terminalCaseIds,
                        $frontendCaseIds,
                    ),
                    'frontend_ids_sha256' => fixtureCaseIdsHash($frontendCaseIds),
                ],
                'environment' => null,
            ],
            'result' => [
                'exit_code' => 0,
                'observed_concurrency' => ['global' => $processes],
                'completion_order' => $terminalCaseIds,
            ],
        ], JSON_THROW_ON_ERROR));
    } else {
        $document = new DOMDocument('1.0', 'UTF-8');
        $suites = $document->appendChild($document->createElement('testsuites'));
        $suite = $suites->appendChild($document->createElement('testsuite'));

        foreach (range(1, 785) as $index) {
            $testcase = $suite->appendChild($document->createElement('testcase'));
            $testcase->setAttribute('name', "case $index");
            $testcase->setAttribute('file', 'fixture.php');
            $testcase->setAttribute('class', 'Fixture');
        }

        $document->save($caseEvidence);
    }

    $identity = [
        'schema_version' => 1,
        'corpus' => 'pest',
        'source_commit' => '6b2cd358e8a9d6d1abb93804b70e1c659bbc411b',
        'source_tree' => str_repeat('b', 40),
        'tracked_source_clean' => true,
        'tracked_dependency_overlays' => [],
        'composer_json_sha256' => '829d94ff9be2d44e8424b30a19d871390686f67e6b878ea06eef24ad3f2a34be',
        'selection' => [
            'files' => 140,
            'sha256' => str_repeat('c', 64),
        ],
        'configuration' => [
            'path' => 'phpunit.xml',
            'sha256' => '3e9a1223f11669f6f598e45ac406c45995a839be4a23a212554460b29fb0a8d5',
        ],
        'dependency_lock_sha256' => '99b2831268eeee015d424132b074d4979cedae283641eb8ea433ac98f0e07100',
    ];
    $identity['source_sha256'] = fixtureSourceIdentityHash($identity);
    file_put_contents(
        $sourceIdentity,
        json_encode($identity, JSON_THROW_ON_ERROR),
    );

    [$output, $exit] = runCommand([
        PHP_BINARY,
        __DIR__.'/normalize.php',
        'pest',
        $runner,
        'nonserial',
        (string) $processes,
        '140',
        str_repeat('a', 40),
        '0',
        '1',
        $raw,
        $measurement,
        $runner === 'drove' ? $replay : '-',
        $sourceIdentity,
        $classification,
        $runner === 'baseline' ? $caseEvidence : '-',
        $outputPath,
    ]);

    if ($exit !== 0) {
        throw new RuntimeException("Normalizer self-test failed:\n$output");
    }

    if ($returnPath) {
        return $outputPath;
    }

    return json_decode(
        (string) file_get_contents($outputPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * @param  array<string, mixed>  $identity
 */
function fixtureSourceIdentityHash(array $identity): string
{
    unset($identity['source_sha256']);

    return hash(
        'sha256',
        json_encode(
            canonicalFixture($identity),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
    );
}

/**
 * @param  list<string>  $caseIds
 */
function fixtureCaseIdsHash(array $caseIds): string
{
    return hash(
        'sha256',
        json_encode(
            $caseIds,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
    );
}

function canonicalFixture(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(canonicalFixture(...), $value);
    }

    ksort($value, SORT_STRING);

    foreach ($value as &$item) {
        $item = canonicalFixture($item);
    }

    return $value;
}

/**
 * @param  list<string>  $command
 * @return array{string, int}
 */
function runCommand(array $command): array
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        options: ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start corpus self-test command.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [$output, $exit];
}

function removeTree(string $directory): void
{
    $root = realpath($directory);

    if (! is_string($root)
        || ! str_starts_with(
            $root,
            rtrim(realpath(sys_get_temp_dir()) ?: '', DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR.'drove-corpus-self-',
        )) {
        throw new RuntimeException('Refusing to remove an unexpected self-test path.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
        ),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }

    rmdir($root);
}
