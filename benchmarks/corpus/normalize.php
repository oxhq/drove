<?php

declare(strict_types=1);

if ($argc !== 16) {
    fwrite(
        STDERR,
        "usage: normalize.php CORPUS RUNNER COHORT PROCESSES SELECTED_FILES REVISION EXIT RUN RAW MEASUREMENT REPLAY|- SOURCE_IDENTITY CLASSIFICATION CASE_EVIDENCE|- OUTPUT\n",
    );
    exit(2);
}

[
    ,
    $corpus,
    $runner,
    $cohort,
    $processes,
    $selectedFiles,
    $revision,
    $exitCode,
    $run,
    $rawPath,
    $measurementPath,
    $replayPath,
    $sourceIdentityPath,
    $classificationPath,
    $caseEvidencePath,
    $outputPath,
] = $argv;

$manifestPath = getenv('CORPUS_MANIFEST') ?: __DIR__.'/manifest.json';
$manifest = decodeFile($manifestPath);
$corpusManifest = null;

foreach ($manifest['corpora'] ?? [] as $candidate) {
    if (($candidate['id'] ?? null) === $corpus) {
        $corpusManifest = $candidate;

        break;
    }
}

if (! is_array($corpusManifest)) {
    throw new RuntimeException("Unknown corpus $corpus");
}

$classification = decodeFile($classificationPath);
$expectedCaseIds = classificationCaseIds(
    $classification,
    $corpusManifest,
    $corpus,
    $cohort,
);
$identity = $corpusManifest['runner_identity'][$runner] ?? null;

if (! is_array($identity)) {
    throw new RuntimeException("Unknown runner identity $corpus/$runner");
}

foreach (['command', 'executable', 'frontend', 'runtime', 'state'] as $field) {
    if (! is_string($identity[$field] ?? null) || $identity[$field] === '') {
        throw new RuntimeException("$corpus/$runner runner identity has invalid $field");
    }
}

if (! is_array($identity['arguments'] ?? null)
    || ! array_is_list($identity['arguments'])
    || array_any(
        $identity['arguments'],
        static fn (mixed $argument): bool => ! is_string($argument)
            || $argument === '',
    )
    || ($runner === 'drove' && $identity['arguments'] !== ['--pest'])
    || ($runner === 'baseline' && $identity['arguments'] !== [])) {
    throw new RuntimeException("$corpus/$runner runner identity has invalid arguments");
}

$processCount = positiveInteger($processes, 'PROCESSES');
$selectedFileCount = positiveInteger($selectedFiles, 'SELECTED_FILES');
$runIndex = positiveInteger($run, 'RUN');
$commandExit = nonNegativeInteger($exitCode, 'EXIT');
$raw = file_get_contents($rawPath);

if ($raw === false) {
    throw new RuntimeException("Cannot read $rawPath");
}

$measurement = decodeFile($measurementPath);

if (($measurement['schema_version'] ?? null) !== 1
    || ($measurement['clock'] ?? null) !== 'monotonic'
    || ($measurement['memory_source'] ?? null) !== 'cgroup-v2:/sys/fs/cgroup/memory.peak'
    || ($measurement['runner_executable'] ?? null) !== $identity['executable']) {
    throw new RuntimeException('Corpus measurement metadata is invalid.');
}

if (($measurement['exit_code'] ?? null) !== $commandExit) {
    throw new RuntimeException('Corpus measurement exit code does not match the recorded command.');
}

$wallMs = $measurement['wall_ms'] ?? null;
$containerPeak = $measurement['container_peak_memory_bytes'] ?? null;
$platform = $measurement['platform'] ?? null;

if (! is_numeric($wallMs) || ! is_finite((float) $wallMs) || (float) $wallMs <= 0) {
    throw new RuntimeException('Corpus wall_ms must be a positive finite number.');
}

if (! is_int($containerPeak) || $containerPeak < 1) {
    throw new RuntimeException('Corpus container_peak_memory_bytes must be a positive integer.');
}

if (! is_array($platform)) {
    throw new RuntimeException('Corpus platform metadata is missing.');
}

foreach (['os_family', 'os', 'architecture', 'php'] as $field) {
    if (! is_string($platform[$field] ?? null) || $platform[$field] === '') {
        throw new RuntimeException("Corpus platform has invalid $field");
    }
}

$replay = null;
$replayEnvironmentPlan = null;
$executionIdentity = null;
$caseEvidenceHash = null;
$observedLanes = 1;
$observedLanesSource = 'single_process_baseline';

if ($runner === 'drove') {
    if ($replayPath === '-' || $caseEvidencePath !== '-') {
        throw new RuntimeException(
            'Drove corpus results require replay metadata and no baseline case evidence.',
        );
    }

    $decodedReplay = decodeFile($replayPath);
    $replayDuration = $decodedReplay['duration_ms'] ?? null;
    $replayMemory = $decodedReplay['memory_peak_bytes'] ?? null;
    $replayEnvironmentPlan = $decodedReplay['plan']['environment'] ?? null;
    $observedLanes = $decodedReplay['result']['observed_concurrency']['global'] ?? null;
    $planHash = $decodedReplay['plan']['sha256'] ?? null;
    $plannedTests = $decodedReplay['plan']['tests'] ?? null;
    $completionOrder = $decodedReplay['result']['completion_order'] ?? null;
    $caseIdentity = $decodedReplay['plan']['case_identity'] ?? null;

    if (($decodedReplay['schema'] ?? null) !== 1
        || ($decodedReplay['kind'] ?? null) !== 'run'
        || ($decodedReplay['command']['processes'] ?? null) !== $processCount
        || ($decodedReplay['result']['exit_code'] ?? null) !== $commandExit
        || preg_match('/^[0-9a-f]{64}$/D', (string) $planHash) !== 1
        || ! is_int($plannedTests)
        || $plannedTests < 1
        || ! is_array($completionOrder)
        || ! array_is_list($completionOrder)) {
        throw new RuntimeException('Drove replay identity does not match the recorded command.');
    }

    if (! is_int($observedLanes) || $observedLanes < 1) {
        throw new RuntimeException('Drove replay observed_concurrency.global is invalid.');
    }

    if (! matchesEnvironmentExpectation(
        $replayEnvironmentPlan,
        $corpusManifest['environment_expectation'] ?? null,
    )) {
        throw new RuntimeException('Drove replay environment plan does not match the corpus contract.');
    }

    if (! is_numeric($replayDuration)
        || ! is_finite((float) $replayDuration)
        || (float) $replayDuration < 0
        || ! is_int($replayMemory)
        || $replayMemory < 1) {
        throw new RuntimeException('Drove replay duration or PHP peak memory is invalid.');
    }

    foreach (['os_family', 'os', 'architecture', 'php'] as $field) {
        if (($decodedReplay['platform'][$field] ?? null) !== $platform[$field]) {
            throw new RuntimeException("Drove replay platform mismatch at $field");
        }
    }

    $terminalCaseIds = [];

    foreach ($completionOrder as $completedId) {
        if (! is_string($completedId) || $completedId === '') {
            throw new RuntimeException('Drove replay completion_order is invalid.');
        }

        if (str_starts_with($completedId, 'test:')) {
            $terminalCaseIds[] = $completedId;
        }
    }

    sort($terminalCaseIds, SORT_STRING);

    if (count($terminalCaseIds) !== $plannedTests
        || count(array_unique($terminalCaseIds)) !== count($terminalCaseIds)) {
        throw new RuntimeException(
            'Drove replay must contain one unique terminal ID per planned test.',
        );
    }

    $observedCaseIds = replayCaseIds(
        $caseIdentity,
        $terminalCaseIds,
        $plannedTests,
    );
    $executionIdentity = [
        'schema' => 1,
        'source' => 'drove-replay',
        'case_ids' => $observedCaseIds,
        'case_ids_sha256' => caseIdsHash($observedCaseIds),
        'plan_sha256' => $planHash,
        'planned_tests' => $plannedTests,
        'terminal_case_ids' => count($terminalCaseIds),
        'terminal_case_ids_sha256' => hash(
            'sha256',
            json_encode(
                $terminalCaseIds,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ),
    ];
    $observedLanesSource = 'drove_replay';
    $replay = [
        'duration_ms' => round((float) $replayDuration, 3),
        'php_peak_memory_bytes' => $replayMemory,
        'sha256' => hash_file('sha256', $replayPath),
    ];
} elseif ($processCount !== 1 || $replayPath !== '-' || $caseEvidencePath === '-') {
    throw new RuntimeException('Baseline corpus results must be a replay-free single-process run.');
} else {
    $observedCaseIds = junitCaseIds(
        $caseEvidencePath,
        $classification,
        $cohort,
    );
    $caseEvidenceHash = hash_file('sha256', $caseEvidencePath);
    $executionIdentity = [
        'schema' => 1,
        'source' => 'baseline-junit',
        'case_ids' => $observedCaseIds,
        'case_ids_sha256' => caseIdsHash($observedCaseIds),
        'plan_sha256' => null,
        'planned_tests' => count($observedCaseIds),
        'terminal_case_ids' => null,
        'terminal_case_ids_sha256' => null,
    ];
}

$plain = preg_replace('/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $raw);

if (! is_string($plain)) {
    throw new RuntimeException('Cannot normalize ANSI output.');
}

$summary = parseSummary($plain);

if ($runner === 'drove'
    && ($executionIdentity['terminal_case_ids'] ?? null)
        !== ($summary['tests'] ?? null)) {
    throw new RuntimeException(
        'Drove replay terminal IDs do not match the normalized test count.',
    );
}

if (($executionIdentity['planned_tests'] ?? null) !== ($summary['tests'] ?? null)) {
    throw new RuntimeException(
        'Observed case IDs do not match the normalized test count.',
    );
}

if (($executionIdentity['case_ids'] ?? null) !== $expectedCaseIds) {
    throw new RuntimeException(
        'Observed case IDs do not match the exact classification cohort.',
    );
}

$selectionMode = $corpusManifest['selection_mode'] ?? null;
$wholeSuite = $corpusManifest['whole_suite'] ?? null;
$sourceIdentity = decodeFile($sourceIdentityPath);

if (! is_string($selectionMode) || ! is_bool($wholeSuite)) {
    throw new RuntimeException("$corpus selection metadata is invalid.");
}

if (($sourceIdentity['schema_version'] ?? null) !== 1
    || ($sourceIdentity['corpus'] ?? null) !== $corpus
    || ($sourceIdentity['source_commit'] ?? null) !== ($corpusManifest['commit'] ?? null)
    || ($sourceIdentity['selection']['files'] ?? null) !== $selectedFileCount
    || ($sourceIdentity['configuration']['path'] ?? null)
        !== ($corpusManifest['execution_configuration'] ?? null)
    || ($sourceIdentity['configuration']['sha256'] ?? null)
        !== ($corpusManifest['execution_configuration_sha256'] ?? null)
    || ($sourceIdentity['composer_json_sha256'] ?? null)
        !== ($corpusManifest['dependency_overlay']['composer_json_sha256'] ?? null)
    || ($sourceIdentity['dependency_lock_sha256'] ?? null)
        !== ($corpusManifest['dependency_lock']['sha256'] ?? null)
    || ($sourceIdentity['source_sha256'] ?? null)
        !== sourceIdentityHash($sourceIdentity)) {
    throw new RuntimeException('Corpus source identity is invalid.');
}

$result = [
    'schema_version' => 3,
    'corpus' => $corpus,
    'runner' => $runner,
    'runner_identity' => $identity,
    'selection' => [
        'mode' => $selectionMode,
        'whole_suite' => $wholeSuite,
        'cohort' => $cohort,
        'selected_files' => $selectedFileCount,
    ],
    'requested_processes' => $processCount,
    'observed_lanes' => $observedLanes,
    'observed_lanes_source' => $observedLanesSource,
    'run' => $runIndex,
    'drove_revision' => $revision,
    'source' => $sourceIdentity,
    'execution_identity' => $executionIdentity,
    'environment_plan' => [
        'runtime' => $identity['runtime'],
        'state' => $identity['state'],
        'replay' => $replayEnvironmentPlan,
    ],
    'metrics' => [
        'wall_ms' => round((float) $wallMs, 3),
        'container_peak_memory_bytes' => $containerPeak,
        'replay_duration_ms' => $replay['duration_ms'] ?? null,
        'replay_php_peak_memory_bytes' => $replay['php_peak_memory_bytes'] ?? null,
    ],
    'platform' => $platform,
    'outcome' => [...$summary, 'exit' => $commandExit],
    'artifacts' => [
        'raw_sha256' => hash('sha256', $raw),
        'measurement_sha256' => hash_file('sha256', $measurementPath),
        'replay_sha256' => $replay['sha256'] ?? null,
        'case_evidence_sha256' => $caseEvidenceHash,
        'source_identity_sha256' => hash_file('sha256', $sourceIdentityPath),
    ],
];

$encoded = json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;

if (file_put_contents($outputPath, $encoded, LOCK_EX) === false) {
    throw new RuntimeException("Cannot write $outputPath");
}

/**
 * @param  array<string, mixed>  $classification
 * @param  array<string, mixed>  $corpusManifest
 * @return list<string>
 */
function classificationCaseIds(
    array $classification,
    array $corpusManifest,
    string $corpus,
    string $cohort,
): array {
    if (($classification['kind'] ?? null) !== 'full-suite-classification'
        || ($classification['corpus'] ?? null) !== $corpus
        || ($classification['repository'] ?? null) !== ($corpusManifest['repository'] ?? null)
        || ($classification['source_commit'] ?? null) !== ($corpusManifest['commit'] ?? null)
        || ! is_array($classification['cases'] ?? null)) {
        throw new RuntimeException('Corpus classification identity is invalid.');
    }

    $caseIds = [];

    foreach ($classification['cases'] as $case) {
        if (! is_array($case)
            || ! is_string($case['id'] ?? null)
            || ! is_array($case['execution_cohorts'] ?? null)) {
            throw new RuntimeException('Corpus classification case ledger is invalid.');
        }

        if (in_array($cohort, $case['execution_cohorts'], true)) {
            $caseIds[] = $case['id'];
        }
    }

    sort($caseIds, SORT_STRING);
    $declared = $classification['cohorts'][$cohort]['case_identity'] ?? null;

    if ($caseIds === []
        || ! is_array($declared)
        || ($declared['schema'] ?? null) !== 1
        || ($declared['case_ids'] ?? null) !== $caseIds
        || ($declared['case_ids_sha256'] ?? null) !== caseIdsHash($caseIds)) {
        throw new RuntimeException('Corpus classification cohort case identity is invalid.');
    }

    return $caseIds;
}

/**
 * @param  list<string>  $terminalCaseIds
 * @return list<string>
 */
function replayCaseIds(mixed $identity, array $terminalCaseIds, int $plannedTests): array
{
    if (! is_array($identity)
        || ($identity['schema'] ?? null) !== 1
        || ! is_array($identity['cases'] ?? null)
        || ! array_is_list($identity['cases'])
        || count($identity['cases']) !== $plannedTests) {
        throw new RuntimeException('Drove replay frontend case identity is invalid.');
    }

    $byExecutionId = [];
    $plannedFrontendIds = [];

    foreach ($identity['cases'] as $case) {
        $executionId = is_array($case) ? ($case['execution_id'] ?? null) : null;
        $frontendId = is_array($case) ? ($case['frontend_id'] ?? null) : null;

        if (! is_string($executionId)
            || $executionId === ''
            || isset($byExecutionId[$executionId])
            || ! is_string($frontendId)
            || $frontendId === '') {
            throw new RuntimeException('Drove replay contains an invalid case identity mapping.');
        }

        $byExecutionId[$executionId] = $frontendId;
        $plannedFrontendIds[] = $frontendId;
    }

    sort($plannedFrontendIds, SORT_STRING);

    if (count(array_unique($plannedFrontendIds)) !== $plannedTests
        || ($identity['frontend_ids_sha256'] ?? null) !== caseIdsHash($plannedFrontendIds)) {
        throw new RuntimeException('Drove replay frontend case identity is not unique or sealed.');
    }

    $observed = [];

    foreach ($terminalCaseIds as $executionId) {
        if (! isset($byExecutionId[$executionId])) {
            throw new RuntimeException('Drove completed an unplanned frontend case identity.');
        }

        $observed[] = $byExecutionId[$executionId];
    }

    sort($observed, SORT_STRING);

    if ($observed !== $plannedFrontendIds) {
        throw new RuntimeException('Drove did not complete its exact planned frontend case set.');
    }

    return $observed;
}

/**
 * @param  array<string, mixed>  $classification
 * @return list<string>
 */
function junitCaseIds(string $path, array $classification, string $cohort): array
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->load($path, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (! $loaded) {
        throw new RuntimeException('Baseline JUnit case evidence is missing or invalid.');
    }

    $casesBySource = [];

    foreach ($classification['cases'] as $case) {
        if (! is_array($case)) {
            continue;
        }
        if (! in_array($cohort, $case['execution_cohorts'] ?? [], true)) {
            continue;
        }
        $source = $case['source'] ?? null;
        $id = $case['id'] ?? null;

        if (! is_string($source) || ! is_string($id)) {
            throw new RuntimeException('Classification case source identity is invalid.');
        }

        $casesBySource[$source][$id] = true;
    }

    $observed = [];

    foreach ($document->getElementsByTagName('testcase') as $testcase) {
        if (! $testcase instanceof DOMElement) {
            continue;
        }

        $source = matchingSource(
            $testcase->getAttribute('file'),
            array_keys($casesBySource),
        );
        $name = $testcase->getAttribute('name');
        $class = $testcase->getAttribute('class');
        $expected = $casesBySource[$source] ?? [];
        $candidates = array_any(
            array_keys($expected),
            static fn (string $id): bool => str_starts_with($id, "pest:$source::"),
        )
            ? pestCaseCandidates($source, $name)
            : phpunitCaseCandidates($class, $name);
        $matches = array_values(array_filter(
            $candidates,
            static fn (string $id): bool => isset($expected[$id])
                && ! isset($observed[$id]),
        ));

        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Baseline JUnit case %s::%s does not identify one classified case.',
                $class,
                $name,
            ));
        }

        $observed[$matches[0]] = true;
    }

    $caseIds = array_keys($observed);
    sort($caseIds, SORT_STRING);

    return $caseIds;
}

/**
 * @param  list<string>  $sources
 */
function matchingSource(string $file, array $sources): string
{
    $normalized = str_replace('\\', '/', $file);

    if (preg_match('/^(.*\.php)(?:::.*)?$/isD', $normalized, $match) === 1) {
        $normalized = $match[1];
    }

    $matches = array_values(array_filter(
        $sources,
        static fn (string $source): bool => $normalized === $source
            || str_ends_with($normalized, '/'.$source),
    ));

    if (count($matches) !== 1) {
        throw new RuntimeException("Baseline JUnit file $file does not identify one classified source.");
    }

    return $matches[0];
}

/**
 * @return list<string>
 */
function pestCaseCandidates(string $source, string $name): array
{
    return array_map(
        static fn (array $parts): string => sprintf(
            'pest:%s::%s%s',
            $source,
            pestEvaluable($parts[0]),
            $parts[1] === null ? '' : '#'.$parts[1],
        ),
        displayNameCandidates($name),
    );
}

/**
 * @return list<string>
 */
function phpunitCaseCandidates(string $class, string $name): array
{
    return array_map(
        static fn (array $parts): string => sprintf(
            '%s::%s%s',
            $class,
            $parts[0],
            $parts[1] === null ? '' : '#'.$parts[1],
        ),
        displayNameCandidates($name),
    );
}

/**
 * @return list<array{string, string|null}>
 */
function displayNameCandidates(string $name): array
{
    $names = array_values(array_unique([
        $name,
        str_replace('{@*}', '*/', $name),
    ]));
    $candidates = [];

    foreach ($names as $candidate) {
        $candidates[] = [$candidate, null];

        if (preg_match('/^(.*) with data set #(\d+)$/sD', $candidate, $match) === 1) {
            $candidates[] = [$match[1], $match[2]];
        }

        if (preg_match('/^(.*) with data set "(.*)"$/sD', $candidate, $match) === 1) {
            $candidates[] = [$match[1], $match[2]];
        }
    }

    return $candidates;
}

function pestEvaluable(string $description): string
{
    $description = str_replace('_', '__', $description);
    $description = '__pest_evaluable_'.str_replace(' ', '_', $description);

    return (string) preg_replace('/[^a-zA-Z0-9_\x80-\xff]/', '_', $description);
}

/**
 * @param  list<string>  $caseIds
 */
function caseIdsHash(array $caseIds): string
{
    return hash(
        'sha256',
        json_encode(
            $caseIds,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
    );
}

/**
 * @return array<string, mixed>
 */
function decodeFile(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read $path");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("$path does not contain a JSON object.");
    }

    return $decoded;
}

/**
 * @param  array<string, mixed>  $identity
 */
function sourceIdentityHash(array $identity): string
{
    unset($identity['source_sha256']);

    return hash(
        'sha256',
        json_encode(
            canonical($identity),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ),
    );
}

function canonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(canonical(...), $value);
    }

    ksort($value, SORT_STRING);

    foreach ($value as &$item) {
        $item = canonical($item);
    }

    return $value;
}

function positiveInteger(string $value, string $name): int
{
    if (preg_match('/^[1-9]\d*$/', $value) !== 1 || (int) $value < 1) {
        throw new RuntimeException("$name must be a positive integer.");
    }

    return (int) $value;
}

function nonNegativeInteger(string $value, string $name): int
{
    if (preg_match('/^\d+$/', $value) !== 1) {
        throw new RuntimeException("$name must be a non-negative integer.");
    }

    return (int) $value;
}

function matchesEnvironmentExpectation(mixed $plan, mixed $expectation): bool
{
    if ($expectation === null) {
        return $plan === null;
    }

    if (! is_array($plan)
        || ! is_array($expectation)
        || ($plan['schema'] ?? null) !== 1
        || ($plan['coordination'] ?? null) !== ($expectation['coordination'] ?? null)
        || ! is_array($plan['resources']['database'] ?? null)
        || ! is_array($expectation['database'] ?? null)) {
        return false;
    }

    $database = $plan['resources']['database'];

    return ($database['kind'] ?? null) === 'database'
        && ($database['provider'] ?? null) === ($expectation['database']['provider'] ?? null)
        && ($database['capabilities'] ?? null) === ($expectation['database']['capabilities'] ?? null);
}

/**
 * @return array<string, int|string>
 */
function parseSummary(string $plain): array
{
    $summary = [
        'tests' => 0,
        'passed' => 0,
        'failed' => 0,
        'errors' => 0,
        'skipped' => 0,
        'incomplete' => 0,
        'risky' => 0,
        'warnings' => 0,
        'assertions' => 0,
    ];

    preg_match_all('/^\s*Tests:\s*(.+)$/mi', $plain, $matches);
    $line = trim((string) end($matches[1]));

    if ($line === ''
        && preg_match('/\bOK \((\d+) tests?,\s*(\d+) assertions?\)/i', $plain, $ok) === 1) {
        $summary['tests'] = (int) $ok[1];
        $summary['passed'] = (int) $ok[1];
        $summary['assertions'] = (int) $ok[2];
    } elseif ($line === '') {
        $summary['parse_error'] = 'No Tests summary was found.';
    } elseif (preg_match('/\bAssertions:\s*\d+/i', $line) === 1) {
        if (preg_match('/^(\d+)\s*,/', $line, $tests) === 1) {
            $summary['tests'] = (int) $tests[1];
        }

        $labels = [
            'assertions' => 'assertions',
            'failures' => 'failed',
            'errors' => 'errors',
            'skipped' => 'skipped',
            'incomplete' => 'incomplete',
            'risky' => 'risky',
            'warnings' => 'warnings',
        ];

        foreach ($labels as $label => $key) {
            if (preg_match('/\b'.preg_quote($label, '/').':\s*(\d+)/i', $line, $value) === 1) {
                $summary[$key] = (int) $value[1];
            }
        }

        $summary['passed'] = max(
            0,
            $summary['tests'] - $summary['failed'] - $summary['errors']
                - $summary['skipped'] - $summary['incomplete'] - $summary['risky'],
        );
    } else {
        $labels = [
            'passed' => 'passed',
            'failed' => 'failed',
            'error' => 'errors',
            'errors' => 'errors',
            'skipped' => 'skipped',
            'incomplete' => 'incomplete',
            'risky' => 'risky',
            'warning' => 'warnings',
            'warnings' => 'warnings',
        ];

        preg_match_all('/(\d+)\s+([a-z]+)/i', $line, $counts, PREG_SET_ORDER);

        foreach ($counts as $count) {
            $label = strtolower($count[2]);

            if (isset($labels[$label])) {
                $summary[$labels[$label]] += (int) $count[1];
            }
        }

        if (preg_match('/\((\d+)\s+assertions?\)/i', $line, $assertions) === 1) {
            $summary['assertions'] = (int) $assertions[1];
        }

        $summary['tests'] = array_sum(array_intersect_key(
            $summary,
            array_flip(['passed', 'failed', 'errors', 'skipped', 'incomplete', 'risky']),
        ));
    }

    preg_match_all('/^\s*Assertions:\s*(\d+)\s*$/mi', $plain, $assertionMatches);
    $assertions = end($assertionMatches[1]);

    if ($assertions !== false) {
        $summary['assertions'] = (int) $assertions;
    }

    return $summary;
}
