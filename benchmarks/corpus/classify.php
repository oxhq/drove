<?php

declare(strict_types=1);

use Drove\Bridge\CompatibilityRegistry;
use Drove\Bridge\CompatibilityStatus;
use Drove\Migration\Finding;
use Drove\Migration\Migrator;
use Drove\Migration\Scanner;

$arguments = array_slice($argv, 1);

if (count($arguments) < 3) {
    fwrite(
        STDERR,
        "usage: classify.php CORPUS CHECKOUT OUTPUT [--cohort-root=PATH] [--discovery-xml=PATH]\n",
    );
    exit(2);
}

$corpusId = array_shift($arguments);
$checkout = realpath((string) array_shift($arguments));
$output = (string) array_shift($arguments);
$cohortRoot = null;
$providedDiscovery = null;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--cohort-root=')) {
        $cohortRoot = realpath(substr($argument, strlen('--cohort-root=')));

        continue;
    }

    if (str_starts_with($argument, '--discovery-xml=')) {
        $providedDiscovery = realpath(substr($argument, strlen('--discovery-xml=')));

        continue;
    }

    fail("unknown classification argument $argument");
}

if (! is_string($checkout) || ! is_dir($checkout)) {
    fail('classification checkout does not exist');
}

$cohortRoot ??= $checkout;

if (! is_string($cohortRoot) || ! is_dir($cohortRoot)) {
    fail('classification cohort root does not exist');
}

$droveSource = realpath(getenv('DROVE_SOURCE') ?: dirname(__DIR__, 2));

if (! is_string($droveSource)
    || ! is_file("$droveSource/vendor/autoload.php")
    || ! is_file("$droveSource/resources/drove-bridge-compatibility.json")) {
    fail('DROVE_SOURCE must name an installed Drove checkout');
}

require "$droveSource/vendor/autoload.php";

$manifestPath = getenv('CORPUS_MANIFEST') ?: __DIR__.'/manifest.json';
$manifest = decodeJsonFile($manifestPath);
$corpus = null;

foreach ($manifest['corpora'] ?? [] as $candidate) {
    if (($candidate['id'] ?? null) === $corpusId) {
        $corpus = $candidate;

        break;
    }
}

if (! is_array($corpus)) {
    fail("unknown corpus $corpusId");
}

$fixture = ($corpus['fixture'] ?? false) === true;

if ($fixture && getenv('CORPUS_ALLOW_FIXTURE') !== '1') {
    fail('fixture corpus requires CORPUS_ALLOW_FIXTURE=1');
}

$commit = $fixture
    ? (string) ($corpus['commit'] ?? '')
    : gitCommit($checkout);

if ($commit !== ($corpus['commit'] ?? null)) {
    fail("corpus commit mismatch: expected {$corpus['commit']}, found $commit");
}

$revision = getenv('DROVE_EXPECTED_REVISION') ?: '';

if (preg_match('/^[0-9a-f]{40}$/D', $revision) !== 1) {
    fail('DROVE_EXPECTED_REVISION must be an exact commit');
}

$hostedImage = getenv('CORPUS_HOSTED_IMAGE') ?: '';
$gate = $manifest['full_suite_gate'] ?? null;

if (! is_array($gate)
    || $hostedImage !== ($gate['hosted_image'] ?? null)
    || ($gate['statuses'] ?? null) !== ['supported', 'unsupported', 'bridge-only']) {
    fail('classification environment does not match the full-suite gate');
}

$discovery = $corpus['full_suite_discovery'] ?? null;

if (! is_array($discovery)
    || ($discovery['mode'] ?? null) !== 'phpunit-list-tests-xml'
    || ! is_string($discovery['runner'] ?? null)
    || ! is_string($discovery['configuration'] ?? null)
    || ! is_array($discovery['case_surfaces'] ?? null)
    || ! is_array($discovery['cohorts'] ?? null)) {
    fail("$corpusId has an invalid full-suite discovery contract");
}

$phpMemoryLimit = $discovery['php_memory_limit'] ?? null;

if ($phpMemoryLimit !== null
    && (! is_string($phpMemoryLimit)
        || preg_match('/^(?:-1|[1-9][0-9]*[KMG])$/D', $phpMemoryLimit) !== 1)) {
    fail("$corpusId has an invalid discovery PHP memory limit");
}

$nodeRuntime = $discovery['node_runtime'] ?? null;
$nodeRuntimeEvidence = null;

if ($nodeRuntime !== null) {
    if (! is_array($nodeRuntime)
        || ! is_string($nodeRuntime['minimum_version'] ?? null)
        || ! is_string($nodeRuntime['source_playwright'] ?? null)
        || ! is_string($nodeRuntime['playwright'] ?? null)
        || ($nodeRuntime['install'] ?? null) !== 'npm-ci-ignore-scripts'
        || ($nodeRuntime['browser_download'] ?? null) !== false) {
        fail("$corpusId has an invalid discovery Node runtime contract");
    }

    $identityFiles = [
        "$checkout/package.json" => $nodeRuntime['source_package_sha256'] ?? null,
        "$checkout/package-lock.json" => $nodeRuntime['source_lock_sha256'] ?? null,
    ];

    foreach (['overlay_package', 'overlay_lock'] as $field) {
        $path = normalizePath((string) ($nodeRuntime[$field] ?? ''));

        if ($path === '' || str_starts_with($path, '/')
            || in_array('..', explode('/', $path), true)) {
            fail("$corpusId discovery Node $field path is invalid");
        }

        $identityFiles["$droveSource/benchmarks/corpus/$path"] =
            $nodeRuntime["{$field}_sha256"] ?? null;
    }

    foreach ($identityFiles as $path => $expectedHash) {
        if (! is_string($expectedHash)
            || preg_match('/^[0-9a-f]{64}$/D', $expectedHash) !== 1
            || ! is_file($path)
            || hash_file('sha256', $path) !== $expectedHash) {
            fail("$corpusId discovery Node dependency identity drifted: $path");
        }
    }

    $sourceNodeLock = decodeJsonFile("$checkout/package-lock.json");
    $overlayNodeLock = decodeJsonFile(
        "$droveSource/benchmarks/corpus/"
        .normalizePath($nodeRuntime['overlay_lock']),
    );

    if (($sourceNodeLock['packages']['node_modules/playwright']['version'] ?? null)
            !== $nodeRuntime['source_playwright']
        || ($overlayNodeLock['packages']['node_modules/playwright']['version'] ?? null)
            !== $nodeRuntime['playwright']) {
        fail("$corpusId discovery Playwright lock contract drifted");
    }

    [$nodeOutput, $nodeExit] = runCommand(['node', '--version'], $checkout);
    $nodeVersion = ltrim(trim($nodeOutput), 'v');
    $playwrightPath = "$checkout/node_modules/.bin/playwright";
    [$playwrightOutput, $playwrightExit] = is_file($playwrightPath)
        ? runCommand([$playwrightPath, '--version'], $checkout)
        : ['', 1];
    $playwrightVersion = preg_replace('/^Version\s+/', '', trim($playwrightOutput));

    if ($nodeExit !== 0
        || version_compare($nodeVersion, $nodeRuntime['minimum_version'], '<')
        || $playwrightExit !== 0
        || $playwrightVersion !== $nodeRuntime['playwright']) {
        fail("$corpusId discovery Node runtime does not match its pinned contract");
    }

    $nodeRuntimeEvidence = [
        ...$nodeRuntime,
        'observed_node' => $nodeVersion,
        'observed_playwright' => $playwrightVersion,
    ];
}

$configuration = normalizePath($discovery['configuration']);
$configurationPath = "$checkout/$configuration";
$runner = normalizePath($discovery['runner']);
$runnerPath = "$checkout/$runner";

if (! is_file($configurationPath)) {
    fail("$corpusId discovery configuration does not exist: $configuration");
}

if (hash_file('sha256', $configurationPath)
    !== ($discovery['configuration_sha256'] ?? null)) {
    fail("$corpusId discovery configuration identity drifted: $configuration");
}

if ($providedDiscovery === null && ! is_file($runnerPath)) {
    fail("$corpusId discovery runner does not exist: $runner");
}

$sourceContract = sourceContract(
    $checkout,
    $configurationPath,
    is_array($discovery['input_roots'] ?? null)
        ? $discovery['input_roots']
        : [],
);
$expectedLockHash = $corpus['dependency_lock']['sha256'] ?? null;

foreach ($sourceContract['external_dependencies'] as $dependency) {
    if (! is_string($expectedLockHash)
        || preg_match('/^[0-9a-f]{64}$/D', $expectedLockHash) !== 1
        || $dependency['lock_sha256'] !== $expectedLockHash) {
        fail(
            "$corpusId external dependency {$dependency['path']} "
            .'is not covered by the pinned corpus lock',
        );
    }
}

if (! $fixture) {
    assertSourceInputsUnchanged($checkout, $sourceContract['roots']);
}

$temporaryDiscovery = null;

if ($providedDiscovery !== null) {
    $discoveryXml = $providedDiscovery;
} else {
    $temporaryDiscovery = sys_get_temp_dir()
        .'/drove-corpus-discovery-'.bin2hex(random_bytes(12)).'.xml';
    $command = [PHP_BINARY];

    if ($phpMemoryLimit !== null) {
        $command = [...$command, '-d', "memory_limit=$phpMemoryLimit"];
    }

    $command = [
        ...$command,
        $runnerPath,
        '--configuration='.$configurationPath,
        '--do-not-cache-result',
        '--no-logging',
        '--list-tests-xml',
        $temporaryDiscovery,
    ];
    [$commandOutput, $exit] = runCommand(
        $command,
        $checkout,
        is_array($discovery['environment'] ?? null)
            ? $discovery['environment']
            : [],
    );

    if ($exit !== 0 || ! is_file($temporaryDiscovery)) {
        @unlink($temporaryDiscovery);
        fail(
            "$corpusId full-suite discovery failed with exit $exit:\n"
            .trim($commandOutput),
        );
    }

    $discoveryXml = $temporaryDiscovery;
}

try {
    $discovered = parseDiscovery($discoveryXml, $checkout);
} finally {
    if ($temporaryDiscovery !== null) {
        @unlink($temporaryDiscovery);
    }
}

$sourcePaths = $sourceContract['files'];

foreach (array_keys($discovered['source_files']) as $path) {
    if (! isset($sourcePaths[$path])) {
        fail(
            "$corpusId discovered source $path is outside "
            .'the declared full-suite source contract',
        );
    }
}

ksort($sourcePaths, SORT_STRING);

$registry = CompatibilityRegistry::load(
    "$droveSource/resources/drove-bridge-compatibility.json",
);
$scanner = new Scanner($registry);
$migrator = new Migrator($scanner);
$caseSurfaces = [];

foreach ($discovery['case_surfaces'] as $surface) {
    if (! is_string($surface)) {
        fail("$corpusId case surface identifiers must be strings");
    }

    $caseSurfaces[$surface] = $registry->surface($surface);
}

$caseSurfaceStatus = restrictiveStatus(array_column(
    $caseSurfaces,
    'status',
));
$caseSurfaceDiagnostics = array_map(
    static fn (array $surface): string => $surface['diagnostic'],
    $caseSurfaces,
);
$caseSurfaceDiagnostics = array_values(array_unique($caseSurfaceDiagnostics));
sort($caseSurfaceDiagnostics, SORT_STRING);
$cohortSelections = [];
$runtimeContractPaths = [];

foreach ($discovery['cohorts'] as $cohort => $contract) {
    if (! is_string($cohort) || ! is_array($contract)) {
        fail("$corpusId has an invalid cohort classification contract");
    }

    $selectedFiles = cohortFiles(
        $contract,
        $cohortRoot,
        $droveSource,
    );
    $cohortSelections[$cohort] = [
        'contract' => $contract,
        'files' => $selectedFiles,
    ];

    foreach (array_keys($selectedFiles) as $path) {
        if (! isset($sourcePaths[$path])) {
            fail("$corpusId/$cohort selected source is outside the source contract: $path");
        }

        $runtimeContractPaths[$path] = true;

        foreach (transitiveDependencies(
            $path,
            $sourceContract['dependencies'],
        ) as $dependency) {
            $runtimeContractPaths[$dependency] = true;
        }
    }
}

foreach ($sourceContract['dynamic_includes'] as $path => $includes) {
    if (! isset($runtimeContractPaths[$path])
        && ! isset($sourceContract['always_loaded_files'][$path])) {
        continue;
    }

    $include = $includes[0];

    fail(sprintf(
        'DROVE_CORPUS_UNSUPPORTED_DYNAMIC_INCLUDE: runtime source %s:%d:%d '
            .'contains a non-deterministic include expression',
        $path,
        $include['line'],
        $include['column'],
    ));
}

$directInputs = [];

foreach (array_keys($sourcePaths) as $path) {
    $absolute = "$checkout/$path";

    if (! is_file($absolute) || ! is_readable($absolute)) {
        fail("$corpusId source input is unreadable: $path");
    }

    $source = file_get_contents($absolute);

    if (! is_string($source)) {
        fail("$corpusId source input cannot be read: $path");
    }

    $dynamicIncludeFindings = array_map(
        static fn (array $include): Finding => new Finding(
            'corpus.dynamic-include',
            CompatibilityStatus::Unsupported,
            'dynamic include expression',
            $path,
            $include['line'],
            $include['column'],
            'DROVE_CORPUS_UNSUPPORTED_DYNAMIC_INCLUDE',
            null,
        ),
        $sourceContract['dynamic_includes'][$path] ?? [],
    );
    $findings = [
        ...$scanner->scan($source, $path),
        ...$dynamicIncludeFindings,
    ];
    $first = $migrator->migrate($source, $path);
    $second = $migrator->migrate($first->source, $path);
    $idempotent = ! $second->changed() && $second->source === $first->source;
    $beforeStatus = restrictiveStatus([
        ...array_column($caseSurfaces, 'status'),
        ...array_map(
            static fn (Finding $finding): string => $finding->status->value,
            $findings,
        ),
    ]);
    $afterFindings = [
        ...$scanner->scan($first->source, $path),
        ...$dynamicIncludeFindings,
    ];
    $afterStatus = restrictiveStatus([
        ...array_column($caseSurfaces, 'status'),
        ...array_map(
            static fn (Finding $finding): string => $finding->status->value,
            $afterFindings,
        ),
    ]);
    $item = [
        'path' => $path,
        'sha256' => hash('sha256', $source),
        'runtime_status' => isset($runtimeContractPaths[$path])
            ? $caseSurfaceStatus
            : $beforeStatus,
        'runtime_evidence' => isset($runtimeContractPaths[$path])
            ? 'exact-source-execution-contract'
            : 'static-preflight',
        'dependencies' => $sourceContract['dependencies'][$path] ?? [],
        'native_migration' => [
            'direct_status' => $beforeStatus,
            'status' => $beforeStatus,
            'dependency_diagnostics' => [],
            'direct_loadable_unchanged' => $beforeStatus !== 'unsupported',
            'direct_loadable_after_idempotent_codemod' => $idempotent
                && $afterStatus !== 'unsupported',
            'loadable_unchanged' => $beforeStatus !== 'unsupported',
            'loadable_after_idempotent_codemod' => $idempotent
                && $afterStatus !== 'unsupported',
        ],
        'codemod' => [
            'changed' => $first->changed(),
            'result_sha256' => $first->resultHash,
            'second_pass_identical' => $idempotent,
            'applied' => $first->applied,
        ],
        'findings' => array_map(
            static fn (Finding $finding): array => $finding->jsonSerialize(),
            $findings,
        ),
    ];
    $directInputs[$path] = $item;
}

$inputs = [];
$inputByPath = [];

foreach (array_keys($directInputs) as $path) {
    $dependencyPaths = transitiveDependencies(
        $path,
        $sourceContract['dependencies'],
    );
    $related = [$path, ...$dependencyPaths];
    $item = $directInputs[$path];
    $item['native_migration']['status'] = restrictiveStatus(array_map(
        static fn (string $relatedPath): string => $directInputs[$relatedPath]['native_migration']['direct_status'],
        $related,
    ));
    $item['native_migration']['loadable_unchanged'] = array_all(
        $related,
        static fn (string $relatedPath): bool => $directInputs[$relatedPath]['native_migration']['direct_loadable_unchanged'],
    );
    $item['native_migration']['loadable_after_idempotent_codemod'] = array_all(
        $related,
        static fn (string $relatedPath): bool => $directInputs[$relatedPath]['native_migration']['direct_loadable_after_idempotent_codemod'],
    );
    $dependencyDiagnostics = [];

    foreach ($dependencyPaths as $dependencyPath) {
        foreach ($directInputs[$dependencyPath]['findings'] as $finding) {
            $dependencyDiagnostics[] = $finding['diagnostic'];
        }
    }

    $dependencyDiagnostics = array_values(array_unique($dependencyDiagnostics));
    sort($dependencyDiagnostics, SORT_STRING);
    $item['native_migration']['dependency_diagnostics'] = $dependencyDiagnostics;
    $inputs[] = $item;
    $inputByPath[$path] = $item;
}

$cases = [];
$caseById = [];

foreach ($discovered['cases'] as $case) {
    $source = resolveCaseSource(
        $case['class'],
        $case['file'],
        $checkout,
        $inputByPath,
    );

    if (! is_string($source) || ! isset($inputByPath[$source])) {
        fail("$corpusId discovery case {$case['id']} has no resolvable source input");
    }

    $input = $inputByPath[$source];
    $executionCohorts = [];

    foreach ($cohortSelections as $cohort => $selection) {
        $contract = $selection['contract'];

        if (! isset($selection['files'][$source])) {
            continue;
        }

        $include = $contract['include_groups'] ?? [];
        $exclude = $contract['exclude_groups'] ?? [];

        if (($include === [] || array_intersect($include, $case['groups']) !== [])
            && array_intersect($exclude, $case['groups']) === []) {
            $executionCohorts[] = $cohort;
        }
    }

    sort($executionCohorts, SORT_STRING);
    $migrationStatus = restrictiveStatus([
        $caseSurfaceStatus,
        $input['native_migration']['status'],
    ]);
    $migrationDiagnostics = array_values(array_unique([
        ...$caseSurfaceDiagnostics,
        ...array_column($input['findings'], 'diagnostic'),
        ...$input['native_migration']['dependency_diagnostics'],
    ]));
    sort($migrationDiagnostics, SORT_STRING);
    $hasExecutionContract = $executionCohorts !== [];
    $item = [
        'id' => stableCaseId($case['id'], $case['class'], $source),
        'source' => $source,
        'groups' => $case['groups'],
        'status' => $hasExecutionContract
            ? $caseSurfaceStatus
            : $migrationStatus,
        'diagnostics' => $hasExecutionContract
            ? $caseSurfaceDiagnostics
            : $migrationDiagnostics,
        'runtime_evidence' => $hasExecutionContract
            ? 'exact-source-execution-contract'
            : 'static-preflight',
        'execution_cohorts' => $executionCohorts,
        'native_migration' => [
            'status' => $migrationStatus,
            'diagnostics' => $migrationDiagnostics,
            'loadable_unchanged' => $caseSurfaceStatus !== 'unsupported'
                && $input['native_migration']['loadable_unchanged'],
            'loadable_after_idempotent_codemod' => $caseSurfaceStatus !== 'unsupported'
                && $input['native_migration']['loadable_after_idempotent_codemod'],
        ],
    ];

    if (isset($caseById[$item['id']])) {
        fail("$corpusId discovery emitted duplicate case {$item['id']}");
    }

    $cases[] = $item;
    $caseById[$item['id']] = $item;
}

usort(
    $cases,
    static fn (array $left, array $right): int => $left['id'] <=> $right['id'],
);

$extensions = discoverExtensions(
    $checkout,
    $configurationPath,
    $manifest['extension_surface_map'] ?? null,
    $registry,
);
$cohorts = [];

foreach ($cohortSelections as $cohort => $selection) {
    $contract = $selection['contract'];
    $selectedFiles = $selection['files'];
    $selected = array_values(array_filter(
        $cases,
        static fn (array $case): bool => in_array(
            $cohort,
            $case['execution_cohorts'],
            true,
        ),
    ));
    $expectedCases = $contract['calibrated_cases'] ?? null;

    if (! is_int($expectedCases) || count($selected) !== $expectedCases) {
        fail(sprintf(
            '%s/%s calibrated case mismatch: expected %s, discovered %d',
            $corpusId,
            $cohort,
            json_encode($expectedCases),
            count($selected),
        ));
    }

    $migrationUnchanged = count(array_filter(
        $selected,
        static fn (array $case): bool => $case['native_migration']['loadable_unchanged'] === true,
    ));
    $migrationCodemodded = count(array_filter(
        $selected,
        static fn (array $case): bool => $case['native_migration']['loadable_unchanged'] === true
            || $case['native_migration']['loadable_after_idempotent_codemod'] === true,
    ));
    $caseIds = array_column($selected, 'id');
    sort($caseIds, SORT_STRING);
    $cohorts[$cohort] = [
        'selection_mode' => 'curated',
        'whole_suite' => false,
        'resource_mode' => $contract['resource_mode'] ?? null,
        'selected_files' => count($selectedFiles),
        'calibrated_cases' => $expectedCases,
        'classified_cases' => count($selected),
        'runtime_contract_cases' => count($selected),
        'case_identity' => [
            'schema' => 1,
            'case_ids' => $caseIds,
            'case_ids_sha256' => hash(
                'sha256',
                json_encode(
                    $caseIds,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            ),
        ],
        'native_migration_unchanged_cases' => $migrationUnchanged,
        'native_migration_unchanged_ratio' => ratio(
            $migrationUnchanged,
            count($selected),
        ),
        'native_migration_unchanged_or_idempotent_codemod_cases' => $migrationCodemodded,
        'native_migration_unchanged_or_idempotent_codemod_ratio' => ratio(
            $migrationCodemodded,
            count($selected),
        ),
    ];
}

ksort($cohorts, SORT_STRING);
$statusCounts = statusCounts(array_column($cases, 'status'));
$inputRuntimeStatusCounts = statusCounts(array_column($inputs, 'runtime_status'));
$inputMigrationStatusCounts = statusCounts(array_map(
    static fn (array $input): string => $input['native_migration']['status'],
    $inputs,
));
$extensionStatusCounts = statusCounts(array_column($extensions, 'status'));
$classifiedInputs = count($inputs);
$classifiedCases = count($cases);
$artifact = [
    'schema_version' => 1,
    'kind' => 'full-suite-classification',
    'corpus' => $corpusId,
    'repository' => $corpus['repository'],
    'source_commit' => $commit,
    'drove_revision' => $revision,
    'platform' => [
        'hosted_image' => $hostedImage,
        'os_family' => PHP_OS_FAMILY,
        'os' => PHP_OS,
        'architecture' => php_uname('m'),
        'php' => PHP_VERSION,
    ],
    'manifest' => [
        'schema_version' => $manifest['schema_version'],
        'sha256' => hash_file('sha256', $manifestPath),
    ],
    'registry' => [
        'schema' => $registry->manifest()['schema'],
        'version' => $registry->manifest()['registry_version'],
        'sha256' => $registry->hash(),
        'statuses' => $gate['statuses'],
    ],
    'full_suite' => [
        'discovery_mode' => 'phpunit-list-tests-xml',
        'runner' => $runner,
        'configuration' => $configuration,
        'configuration_sha256' => hash_file('sha256', $configurationPath),
        'discovery_runtime' => [
            'php_memory_limit' => $phpMemoryLimit,
            'node' => $nodeRuntimeEvidence,
        ],
        'selection_mode' => 'discovered',
        'whole_suite' => true,
        'source_inputs' => $classifiedInputs,
        'classified_inputs' => $classifiedInputs,
        'scanner_classification_ratio' => ratio(
            $classifiedInputs,
            $classifiedInputs,
        ),
        'discovered_cases' => $classifiedCases,
        'classified_cases' => $classifiedCases,
        'case_status_counts' => $statusCounts,
        'input_runtime_status_counts' => $inputRuntimeStatusCounts,
        'native_migration_input_status_counts' => $inputMigrationStatusCounts,
        'external_dependencies' => $sourceContract['external_dependencies'],
        'extension_surfaces' => count($extensions),
        'classified_extension_surfaces' => count($extensions),
        'extension_status_counts' => $extensionStatusCounts,
    ],
    'cohorts' => $cohorts,
    'execution' => [
        'performed' => false,
        'claim' => 'classification-preflight-only',
    ],
    'cases' => $cases,
    'inputs' => $inputs,
    'extensions' => $extensions,
];

$encoded = json_encode(
    $artifact,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
$outputDirectory = dirname($output);

if (! is_dir($outputDirectory)
    && ! mkdir($outputDirectory, 0777, true)
    && ! is_dir($outputDirectory)) {
    fail("cannot create classification output directory $outputDirectory");
}

if (file_put_contents($output, $encoded) !== strlen($encoded)) {
    fail("cannot write classification artifact $output");
}

fwrite(STDOUT, json_encode([
    'classification' => 'passed',
    'corpus' => $corpusId,
    'source_commit' => $commit,
    'source_inputs' => $classifiedInputs,
    'discovered_cases' => $classifiedCases,
    'classified_extension_surfaces' => count($extensions),
    'cohorts' => array_map(
        static fn (array $cohort): array => [
            'calibrated_cases' => $cohort['calibrated_cases'],
            'runtime_contract_cases' => $cohort['runtime_contract_cases'],
            'native_migration_unchanged_ratio' => $cohort['native_migration_unchanged_ratio'],
            'native_migration_unchanged_or_idempotent_codemod_ratio' => $cohort['native_migration_unchanged_or_idempotent_codemod_ratio'],
        ],
        $cohorts,
    ),
]).PHP_EOL);

/**
 * @return array<string, mixed>
 */
function decodeJsonFile(string $path): array
{
    $contents = is_file($path) ? file_get_contents($path) : false;

    if (! is_string($contents)) {
        fail("cannot read JSON file $path");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        fail("$path must contain a JSON object");
    }

    return $decoded;
}

/**
 * @param  list<string>  $inputRoots
 * @return array{
 *     roots: list<string>,
 *     files: array<string, true>,
 *     dependencies: array<string, list<string>>,
 *     dynamic_includes: array<string, list<array{line: int, column: int}>>,
 *     always_loaded_files: array<string, true>,
 *     external_dependencies: list<array{
 *         path: string,
 *         lock: string,
 *         lock_sha256: string
 *     }>
 * }
 */
function sourceContract(
    string $checkout,
    string $configuration,
    array $inputRoots,
): array {
    $document = new DOMDocument;

    if (! @$document->load($configuration, LIBXML_NONET)) {
        fail('cannot parse corpus PHPUnit configuration');
    }

    $xpath = new DOMXPath($document);
    $configurationDirectory = dirname($configuration);
    $roots = [];
    $files = [];
    $excludes = [];
    $alwaysLoadedRoots = [];

    foreach ($xpath->query('//*[local-name()="testsuite"]/*[local-name()="exclude"]') ?: [] as $node) {
        $excludes[] = configuredRelativePath(
            trim($node->textContent),
            $configurationDirectory,
            $checkout,
            false,
        );
    }

    foreach ($xpath->query('//*[local-name()="testsuite"]/*[local-name()="directory"]') ?: [] as $node) {
        $root = configuredRelativePath(
            trim($node->textContent),
            $configurationDirectory,
            $checkout,
            true,
        );
        $suffix = $node instanceof DOMElement && $node->hasAttribute('suffix')
            ? $node->getAttribute('suffix')
            : 'Test.php';
        $roots[] = $root;
        $absolute = "$checkout/$root";

        if (! is_dir($absolute)) {
            fail("configured test directory does not exist: $root");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $absolute,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
            ),
        );

        foreach ($iterator as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            if (! str_ends_with((string) $entry->getFilename(), $suffix)) {
                continue;
            }
            $path = relativePath($entry->getPathname(), $checkout);

            if (! excluded($path, $excludes)) {
                $files[$path] = true;
            }
        }
    }

    foreach ($xpath->query('//*[local-name()="testsuite"]/*[local-name()="file"]') ?: [] as $node) {
        $path = configuredRelativePath(
            trim($node->textContent),
            $configurationDirectory,
            $checkout,
            true,
        );
        $roots[] = $path;

        if (! excluded($path, $excludes)) {
            $files[$path] = true;
        }
    }

    foreach ($inputRoots as $declaredRoot) {
        if (! is_string($declaredRoot) || $declaredRoot === '') {
            fail('full-suite input roots must be non-empty strings');
        }

        $root = configuredRelativePath(
            $declaredRoot,
            $checkout,
            $checkout,
            true,
        );
        $roots[] = $root;
        $absolute = "$checkout/$root";

        if (is_file($absolute)) {
            if (str_ends_with($absolute, '.php')) {
                $files[$root] = true;
            }

            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $absolute,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
            ),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && str_ends_with((string) $entry->getFilename(), '.php')) {
                $files[relativePath($entry->getPathname(), $checkout)] = true;
            }
        }
    }

    $phpunit = $document->documentElement;

    if ($phpunit instanceof DOMElement && $phpunit->hasAttribute('bootstrap')) {
        $bootstrap = configuredRelativePath(
            $phpunit->getAttribute('bootstrap'),
            $configurationDirectory,
            $checkout,
            true,
        );
        $files[$bootstrap] = true;
        $alwaysLoadedRoots[$bootstrap] = true;
    }

    $composerPath = "$checkout/composer.json";

    if (is_file($composerPath)) {
        $composer = decodeJsonFile($composerPath);

        foreach ([
            ...($composer['autoload']['files'] ?? []),
            ...($composer['autoload-dev']['files'] ?? []),
        ] as $autoloadFile) {
            if (! is_string($autoloadFile)) {
                fail('Composer autoload files must be strings');
            }

            $path = configuredRelativePath(
                $autoloadFile,
                $checkout,
                $checkout,
                true,
            );
            $files[$path] = true;
            $alwaysLoadedRoots[$path] = true;
        }
    }

    $externalDependencies = [];
    $dependencies = [];
    $dynamicIncludes = [];

    foreach (array_keys($files) as $path) {
        if (! str_starts_with($path, 'vendor/')) {
            continue;
        }

        $externalDependencies[$path] = vendorDependency($path, $checkout);
        unset($files[$path]);
    }

    $pending = array_keys($files);
    $visited = [];

    while ($pending !== []) {
        $entrypoint = array_shift($pending);

        if (isset($visited[$entrypoint])) {
            continue;
        }

        $visited[$entrypoint] = true;
        $dependencies[$entrypoint] = [];

        $requiredFiles = staticRequiredFiles(
            "$checkout/$entrypoint",
            $checkout,
        );

        if ($requiredFiles['dynamic_includes'] !== []) {
            $dynamicIncludes[$entrypoint] = $requiredFiles['dynamic_includes'];
        }

        foreach ($requiredFiles['files'] as $required) {
            if (str_starts_with($required, 'vendor/')) {
                $externalDependencies[$required] = vendorDependency(
                    $required,
                    $checkout,
                );

                continue;
            }

            $dependencies[$entrypoint][] = $required;

            if (! isset($files[$required])) {
                $files[$required] = true;
                $pending[] = $required;
            }
        }

        $dependencies[$entrypoint] = array_values(array_unique(
            $dependencies[$entrypoint],
        ));
        sort($dependencies[$entrypoint], SORT_STRING);
    }

    $roots = array_values(array_unique($roots));
    sort($roots, SORT_STRING);
    ksort($files, SORT_STRING);
    ksort($dependencies, SORT_STRING);
    ksort($dynamicIncludes, SORT_STRING);
    ksort($externalDependencies, SORT_STRING);
    $alwaysLoadedFiles = [];

    foreach (array_keys($alwaysLoadedRoots) as $path) {
        if (! isset($files[$path])) {
            continue;
        }

        $alwaysLoadedFiles[$path] = true;

        foreach (transitiveDependencies($path, $dependencies) as $dependency) {
            $alwaysLoadedFiles[$dependency] = true;
        }
    }

    ksort($alwaysLoadedFiles, SORT_STRING);

    if ($roots === [] || $files === []) {
        fail('full-suite source contract is empty');
    }

    return [
        'roots' => $roots,
        'files' => $files,
        'dependencies' => $dependencies,
        'dynamic_includes' => $dynamicIncludes,
        'always_loaded_files' => $alwaysLoadedFiles,
        'external_dependencies' => array_values($externalDependencies),
    ];
}

/**
 * @return array{path: string, lock: string, lock_sha256: string}
 */
function vendorDependency(string $path, string $checkout): array
{
    $lock = "$checkout/composer.lock";
    $hash = is_file($lock) ? hash_file('sha256', $lock) : false;

    if (! is_string($hash)) {
        fail(
            "DROVE_CORPUS_VENDOR_DEPENDENCY_WITHOUT_LOCK: $path "
            .'requires an exact composer.lock',
        );
    }

    return [
        'path' => $path,
        'lock' => 'composer.lock',
        'lock_sha256' => $hash,
    ];
}

/**
 * @param  array<string, list<string>>  $dependencies
 * @return list<string>
 */
function transitiveDependencies(string $path, array $dependencies): array
{
    $pending = $dependencies[$path] ?? [];
    $visited = [$path => true];

    while ($pending !== []) {
        $dependency = array_shift($pending);

        if (isset($visited[$dependency])) {
            continue;
        }

        if (! isset($dependencies[$dependency])) {
            fail("source dependency $dependency is absent from the scanner ledger");
        }

        $visited[$dependency] = true;
        $pending = [...$pending, ...$dependencies[$dependency]];
    }

    unset($visited[$path]);
    $result = array_keys($visited);
    sort($result, SORT_STRING);

    return $result;
}

/**
 * @return array{
 *     cases: list<array{id: string, class: string, file: string|null, groups: list<string>}>,
 *     source_files: array<string, true>
 * }
 */
function parseDiscovery(string $path, string $checkout): array
{
    $document = new DOMDocument;

    if (! @$document->load($path, LIBXML_NONET)) {
        fail('cannot parse PHPUnit full-suite discovery XML');
    }

    $xpath = new DOMXPath($document);
    $groups = [];

    foreach ($xpath->query('//*[local-name()="groups"]/*[local-name()="group"]') ?: [] as $group) {
        if (! $group instanceof DOMElement) {
            continue;
        }

        $name = $group->getAttribute('name');

        foreach ($xpath->query('./*[local-name()="test"]', $group) ?: [] as $test) {
            if ($test instanceof DOMElement && $test->getAttribute('id') !== '') {
                $groups[$test->getAttribute('id')][] = $name;
            }
        }
    }

    $cases = [];
    $sourceFiles = [];

    foreach ($xpath->query('//*[local-name()="testClass"]') ?: [] as $class) {
        if (! $class instanceof DOMElement) {
            continue;
        }

        $className = $class->getAttribute('name');
        $file = $class->getAttribute('file');
        $realSource = realSourcePath($file, $checkout);

        if ($realSource !== null) {
            $sourceFiles[$realSource] = true;
        }

        foreach ($xpath->query('./*[local-name()="testMethod"]', $class) ?: [] as $method) {
            if (! $method instanceof DOMElement) {
                continue;
            }
            if ($method->getAttribute('id') === '') {
                continue;
            }
            $id = $method->getAttribute('id');
            $caseGroups = array_values(array_unique($groups[$id] ?? []));
            sort($caseGroups, SORT_STRING);
            $cases[] = [
                'id' => $id,
                'class' => $className,
                'file' => $file !== '' ? $file : null,
                'groups' => $caseGroups,
            ];
        }
    }

    if ($cases === []) {
        fail('full-suite discovery did not find any cases');
    }

    return ['cases' => $cases, 'source_files' => $sourceFiles];
}

/**
 * @param  array<string, array<string, mixed>>  $inputs
 */
function resolveCaseSource(
    string $class,
    ?string $file,
    string $checkout,
    array $inputs,
): ?string {
    if (is_string($file)) {
        $source = realSourcePath($file, $checkout);

        if ($source !== null && isset($inputs[$source])) {
            return $source;
        }
    }

    if (str_starts_with($class, 'P\\')) {
        $candidate = str_replace('\\', '/', substr($class, 2)).'.php';

        if (isset($inputs[$candidate])) {
            return $candidate;
        }

        $matches = array_values(array_filter(
            array_keys($inputs),
            static fn (string $path): bool => strtolower($path) === strtolower($candidate),
        ));

        if (count($matches) === 1) {
            return $matches[0];
        }

        $matches = [];

        foreach (array_keys($inputs) as $path) {
            $identity = pestGeneratedClassIdentity($path);

            if ($identity['class'] === $class
                || ($identity['invalid_prefix'] !== null
                    && str_starts_with($class, $identity['invalid_prefix']))) {
                $matches[] = $path;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }
    }

    $parts = explode('\\', $class);
    $basename = end($parts).'.php';
    $matches = array_values(array_filter(
        array_keys($inputs),
        static fn (string $path): bool => basename($path) === $basename,
    ));

    return count($matches) === 1 ? $matches[0] : null;
}

/**
 * Pest's generated PHP class names are presentation details and may contain a
 * random suffix when the source basename has no valid identifier characters.
 * Classification IDs instead anchor generated cases to the pinned source path.
 */
function stableCaseId(string $id, string $class, string $source): string
{
    $prefix = "$class::";

    if (! str_starts_with($class, 'P\\') || ! str_starts_with($id, $prefix)) {
        return $id;
    }

    return "pest:$source::".substr($id, strlen($prefix));
}

/**
 * @return array{class: string|null, invalid_prefix: string|null}
 */
function pestGeneratedClassIdentity(string $path): array
{
    if (! str_ends_with($path, '.php')) {
        return ['class' => null, 'invalid_prefix' => null];
    }

    $normalized = str_replace('\\', '/', $path);
    $basename = basename($normalized, '.php');
    $dot = strpos($basename, '.');

    if ($dot !== false) {
        $basename = substr($basename, 0, $dot);
    }

    $directory = dirname(ucfirst($normalized));
    $relative = ($directory === '.' ? '' : "$directory/").$basename;
    $relative = str_replace('/', '\\', $relative);
    $relative = (string) preg_replace(
        '/%[a-fA-F0-9][a-fA-F0-9]/',
        '',
        $relative,
    );
    $relative = str_replace(['\\\'', '\\"'], '', $relative);
    $relative = (string) preg_replace('/[^\p{L}\p{N}\\\\]/u', '', $relative);
    $parts = explode('\\', $relative);
    $className = array_pop($parts);
    $namespace = implode('\\', $parts);

    if ($className === '') {
        return [
            'class' => null,
            'invalid_prefix' => 'P\\'
                .($namespace === '' ? '' : "$namespace\\")
                .'InvalidTestName',
        ];
    }

    return [
        'class' => 'P\\'.($namespace === '' ? '' : "$namespace\\").$className,
        'invalid_prefix' => null,
    ];
}

/**
 * @param  array<string, mixed>|null  $map
 * @return list<array{
 *     id: string,
 *     surface: string,
 *     status: string,
 *     diagnostic: string
 * }>
 */
function discoverExtensions(
    string $checkout,
    string $configuration,
    ?array $map,
    CompatibilityRegistry $registry,
): array {
    if (! is_array($map)) {
        fail('manifest extension_surface_map must be an object');
    }

    $ids = [];
    $lockPath = "$checkout/composer.lock";

    if (is_file($lockPath)) {
        $lock = decodeJsonFile($lockPath);

        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
            if (! is_array($package)) {
                continue;
            }
            if (! is_string($package['name'] ?? null)) {
                continue;
            }
            $name = $package['name'];
            $key = "package:$name";
            $pestPlugins = $package['extra']['pest']['plugins'] ?? [];

            if (isset($map[$key])
                || $name === 'phpunit/phpunit'
                || $name === 'pestphp/pest-plugin'
                || is_array($pestPlugins) && $pestPlugins !== []
                || str_starts_with($name, 'orchestra/testbench')) {
                $ids[$key] = true;
            }

            foreach ($pestPlugins as $plugin) {
                if (is_string($plugin)) {
                    $ids["pest-plugin-class:$plugin"] = true;
                }
            }
        }
    }

    $composerPath = "$checkout/composer.json";

    if (is_file($composerPath)) {
        $composer = decodeJsonFile($composerPath);

        foreach ($composer['extra']['pest']['plugins'] ?? [] as $plugin) {
            if (is_string($plugin)) {
                $ids["pest-plugin-class:$plugin"] = true;
            }
        }
    }

    $document = new DOMDocument;

    if (! @$document->load($configuration, LIBXML_NONET)) {
        fail('cannot parse PHPUnit extensions');
    }

    $xpath = new DOMXPath($document);

    foreach ($xpath->query('//*[local-name()="extensions"]/*[@class]') ?: [] as $extension) {
        if ($extension instanceof DOMElement) {
            $ids['phpunit-extension:'.$extension->getAttribute('class')] = true;
        }
    }

    ksort($ids, SORT_STRING);
    $extensions = [];

    foreach (array_keys($ids) as $id) {
        $surfaces = $map[$id] ?? null;

        if (! is_array($surfaces) || $surfaces === []) {
            fail("extension surface $id is not classified by the manifest");
        }

        foreach ($surfaces as $surface) {
            if (! is_string($surface)) {
                fail("extension surface mapping for $id is invalid");
            }

            $definition = $registry->surface($surface);
            $extensions[] = [
                'id' => $id,
                'surface' => $surface,
                'status' => $definition['status'],
                'diagnostic' => $definition['diagnostic'],
            ];
        }
    }

    usort(
        $extensions,
        static fn (array $left, array $right): int => [
            $left['id'],
            $left['surface'],
        ] <=> [
            $right['id'],
            $right['surface'],
        ],
    );

    return $extensions;
}

/**
 * @param  array<string, mixed>  $contract
 * @return array<string, true>
 */
function cohortFiles(
    array $contract,
    string $root,
    string $droveSource,
): array {
    if (is_array($contract['files'] ?? null)) {
        $files = $contract['files'];
    } elseif (is_string($contract['selector'] ?? null)) {
        [$output, $exit] = runCommand([
            'sh',
            "$droveSource/benchmarks/corpus/select.sh",
            $contract['selector'],
            $root,
        ]);

        if ($exit !== 0) {
            fail("cohort selector {$contract['selector']} failed:\n".trim($output));
        }

        $files = preg_split('/\R/u', trim($output)) ?: [];
    } else {
        fail('cohort contract requires selector or files');
    }

    $selected = [];

    foreach ($files as $file) {
        if (! is_string($file)) {
            continue;
        }
        if (trim($file) === '') {
            continue;
        }
        $path = normalizePath(trim($file));

        if (! is_file("$root/$path")) {
            fail("cohort source file does not exist: $path");
        }

        $selected[$path] = true;
    }

    ksort($selected, SORT_STRING);

    return $selected;
}

/**
 * @param  list<string>  $statuses
 */
function restrictiveStatus(array $statuses): string
{
    $rank = ['supported' => 0, 'bridge-only' => 1, 'unsupported' => 2];
    $status = 'supported';

    foreach ($statuses as $candidate) {
        if (! is_string($candidate) || ! isset($rank[$candidate])) {
            fail('classification encountered an unknown status');
        }

        if ($rank[$candidate] > $rank[$status]) {
            $status = $candidate;
        }
    }

    return $status;
}

/**
 * @param  list<string>  $statuses
 * @return array{supported: int, unsupported: int, bridge-only: int}
 */
function statusCounts(array $statuses): array
{
    $counts = ['supported' => 0, 'unsupported' => 0, 'bridge-only' => 0];

    foreach ($statuses as $status) {
        if (! is_string($status) || ! array_key_exists($status, $counts)) {
            fail('classification encountered an unknown status');
        }

        $counts[$status]++;
    }

    return $counts;
}

function ratio(int $numerator, int $denominator): float
{
    if ($denominator < 1) {
        fail('classification ratio denominator must be positive');
    }

    return round($numerator / $denominator, 6);
}

/**
 * @param  list<string>  $roots
 */
function assertSourceInputsUnchanged(string $checkout, array $roots): void
{
    [$output, $exit] = runCommand([
        'git',
        '-C',
        $checkout,
        'status',
        '--porcelain',
        '--',
        ...$roots,
    ]);

    if ($exit !== 0 || trim($output) !== '') {
        fail("full-suite source inputs differ from the pinned commit:\n".trim($output));
    }
}

function gitCommit(string $checkout): string
{
    [$output, $exit] = runCommand([
        'git',
        '-C',
        $checkout,
        'rev-parse',
        'HEAD',
    ]);
    $commit = trim($output);

    if ($exit !== 0 || preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
        fail('classification checkout does not have an exact Git commit');
    }

    return $commit;
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $environment
 * @return array{string, int}
 */
function runCommand(
    array $command,
    ?string $workingDirectory = null,
    array $environment = [],
): array {
    $previous = [];

    foreach ($environment as $name => $value) {
        if (! is_string($name) || ! is_string($value)
            || preg_match('/^[A-Z_][A-Z0-9_]*$/D', $name) !== 1) {
            fail('classification environment contract is invalid');
        }

        $current = getenv($name);
        $previous[$name] = $current === false ? null : $current;
        putenv("$name=$value");
    }

    try {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
            options: ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            fail('cannot start corpus classification command');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === null ? $name : "$name=$value");
        }
    }

    return [$output, $exit];
}

function realSourcePath(string $path, string $checkout): ?string
{
    if ($path === '' || str_contains($path, "eval()'d code")) {
        return null;
    }

    $candidate = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/D', $path) === 1
        ? $path
        : "$checkout/$path";
    $real = realpath($candidate);

    if (! is_string($real)) {
        return null;
    }

    try {
        return relativePath($real, $checkout);
    } catch (RuntimeException) {
        return null;
    }
}

/**
 * @return array{
 *     files: list<string>,
 *     dynamic_includes: list<array{line: int, column: int}>
 * }
 */
function staticRequiredFiles(string $path, string $checkout): array
{
    $source = file_get_contents($path);

    if (! is_string($source)) {
        fail("cannot read executable bootstrap input $path");
    }

    $tokens = array_values(PhpToken::tokenize($source));
    $required = [];
    $dynamicIncludes = [];

    foreach ($tokens as $index => $token) {
        if (! $token->is([T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE])) {
            continue;
        }

        $start = $token->pos + strlen($token->text);
        $end = null;

        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            if ($tokens[$cursor]->text === ';') {
                $end = $tokens[$cursor]->pos;

                break;
            }
        }

        if (! is_int($end)) {
            fail("bootstrap include in $path has no deterministic statement boundary");
        }

        $expression = trim(substr($source, $start, $end - $start));
        $candidate = staticIncludeExpression($expression, $path);

        if ($candidate === null) {
            $dynamicIncludes[] = [
                'line' => $token->line,
                'column' => tokenColumn($source, $token->pos),
            ];

            continue;
        }

        $relative = configuredRelativePath(
            $candidate,
            $checkout,
            $checkout,
            true,
        );
        $required[$relative] = true;
    }

    ksort($required, SORT_STRING);

    return [
        'files' => array_keys($required),
        'dynamic_includes' => $dynamicIncludes,
    ];
}

function staticIncludeExpression(
    string $expression,
    string $owner,
): ?string {
    while (str_starts_with($expression, '(')
        && str_ends_with($expression, ')')) {
        $expression = trim(substr($expression, 1, -1));
    }

    if (preg_match(
        '/^([\'"])(.*)\\1$/sD',
        $expression,
        $matches,
    ) === 1) {
        return decodePhpString($matches[1], $matches[2]);
    }

    if (preg_match(
        '/^__DIR__\\s*\\.\\s*([\'"])(.*)\\1$/sD',
        $expression,
        $matches,
    ) === 1) {
        return dirname($owner).'/'.ltrim(
            decodePhpString($matches[1], $matches[2]),
            '/\\',
        );
    }

    if (preg_match(
        '/^dirname\\(\\s*__DIR__\\s*(?:,\\s*([1-9][0-9]*))?\\s*\\)'
            .'\\s*\\.\\s*([\'"])(.*)\\2$/sD',
        $expression,
        $matches,
    ) === 1) {
        $directory = dirname($owner);
        $levels = isset($matches[1]) && $matches[1] !== ''
            ? (int) $matches[1]
            : 1;

        for ($level = 0; $level < $levels; $level++) {
            $directory = dirname($directory);
        }

        return $directory.'/'.ltrim(
            decodePhpString($matches[2], $matches[3]),
            '/\\',
        );
    }

    return null;
}

function tokenColumn(string $source, int $offset): int
{
    $lineStart = strrpos(substr($source, 0, $offset), "\n");

    return $lineStart === false ? $offset + 1 : $offset - $lineStart;
}

function decodePhpString(string $quote, string $contents): string
{
    return $quote === "'"
        ? str_replace(['\\\\', "\\'"], ['\\', "'"], $contents)
        : stripcslashes($contents);
}

function configuredRelativePath(
    string $path,
    string $configurationDirectory,
    string $checkout,
    bool $mustExist,
): string {
    $candidate = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/D', $path) === 1
        ? $path
        : "$configurationDirectory/$path";
    $real = realpath($candidate);

    if (is_string($real)) {
        return relativePath($real, $checkout);
    }

    if ($mustExist) {
        fail("configured test path does not exist: $path");
    }

    $normalizedPath = lexicalPath($candidate);
    $normalizedRoot = rtrim(lexicalPath((string) realpath($checkout)), '/');
    $prefix = $normalizedRoot.'/';
    $comparedPath = PHP_OS_FAMILY === 'Windows'
        ? strtolower($normalizedPath)
        : $normalizedPath;
    $comparedPrefix = PHP_OS_FAMILY === 'Windows'
        ? strtolower($prefix)
        : $prefix;

    if (! str_starts_with($comparedPath, $comparedPrefix)) {
        fail("configured test path escapes the checkout: $path");
    }

    return substr($normalizedPath, strlen($prefix));
}

function relativePath(string $path, string $root): string
{
    $realPath = realpath($path);
    $realRoot = realpath($root);

    if (! is_string($realPath) || ! is_string($realRoot)) {
        fail('cannot resolve corpus path');
    }

    $normalizedPath = normalizePath($realPath);
    $normalizedRoot = rtrim(normalizePath($realRoot), '/');
    $prefix = $normalizedRoot.'/';

    if (! str_starts_with(
        PHP_OS_FAMILY === 'Windows'
            ? strtolower($normalizedPath)
            : $normalizedPath,
        PHP_OS_FAMILY === 'Windows'
            ? strtolower($prefix)
            : $prefix,
    )) {
        throw new RuntimeException('corpus source path escapes the checkout');
    }

    return substr($normalizedPath, strlen($prefix));
}

function lexicalPath(string $path): string
{
    $normalized = normalizePath($path);
    $prefix = '';

    if (preg_match('/^[A-Za-z]:\//D', $normalized) === 1) {
        $prefix = substr($normalized, 0, 3);
        $normalized = substr($normalized, 3);
    } elseif (str_starts_with($normalized, '/')) {
        $prefix = '/';
        $normalized = ltrim($normalized, '/');
    }

    $segments = [];

    foreach (explode('/', $normalized) as $segment) {
        if ($segment === '') {
            continue;
        }
        if ($segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($segments === []) {
                fail('configured path escapes its filesystem root');
            }

            array_pop($segments);

            continue;
        }

        $segments[] = $segment;
    }

    return $prefix.implode('/', $segments);
}

/**
 * @param  list<string>  $excludes
 */
function excluded(string $path, array $excludes): bool
{
    foreach ($excludes as $exclude) {
        $exclude = rtrim($exclude, '/');

        if ($path === $exclude || str_starts_with($path, "$exclude/")) {
            return true;
        }
    }

    return false;
}

function normalizePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));

    while (str_starts_with($path, './')) {
        $path = substr($path, 2);
    }

    return $path;
}

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
