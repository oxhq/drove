<?php

declare(strict_types=1);

$root = __DIR__;
$manifestPath = getenv('CORPUS_MANIFEST') ?: "$root/manifest.json";
$manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$registryPath = dirname(__DIR__, 2).'/resources/drove-bridge-compatibility.json';
$registry = json_decode(
    (string) file_get_contents($registryPath),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$registryHash = hash('sha256', json_encode(
    canonical($registry),
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
));
$errors = [];

if (($manifest['schema_version'] ?? null) !== 5) {
    $errors[] = 'manifest schema_version must be 5';
}

if (($manifest['ladder'] ?? null) !== ['pest', 'invoiceshelf', 'livewire', 'filament']) {
    $errors[] = 'manifest ladder must keep Filament last';
}

if (($manifest['candidate_version'] ?? null) !== '0.4.0-alpha.2'
    || ($manifest['evaluation_version'] ?? null) !== '0.4.0-alpha.1'
    || ($manifest['release_overlay_version'] ?? null)
        !== ($manifest['evaluation_version'] ?? null)) {
    $errors[] = 'manifest candidate/evaluation version contract is invalid';
}

$gate = $manifest['full_suite_gate'] ?? null;

if (! is_array($gate)
    || ($gate['artifact_schema'] ?? null) !== 1
    || ($gate['hosted_image'] ?? null) !== 'ubuntu-24.04'
    || ($gate['statuses'] ?? null) !== ['supported', 'unsupported', 'bridge-only']
    || ($gate['scanner_classification_ratio_min'] ?? null) !== 1
    || ($gate['unchanged_bridge_load_ratio_min'] ?? null) !== 0.8
    || ($gate['unchanged_or_idempotent_codemod_bridge_load_ratio_min'] ?? null) !== 0.95
    || ($gate['parallel_processes'] ?? null) !== [1, 2, 4, 8, 16, 30]
    || ($gate['serial_processes'] ?? null) !== [1]
    || ($gate['hosted_status'] ?? null) !== 'PENDING') {
    $errors[] = 'manifest full-suite gate contract is invalid';
}

if (($manifest['classifications'] ?? null) === null
    || array_keys($manifest['classifications']) !== [
        'supported',
        'unsupported',
        'bridge-only',
    ]) {
    $errors[] = 'manifest classification vocabulary is invalid';
}

$surfaceMap = $manifest['extension_surface_map'] ?? null;

if (! is_array($surfaceMap) || $surfaceMap === []) {
    $errors[] = 'manifest extension surface map is empty';
} else {
    foreach ($surfaceMap as $id => $surfaces) {
        if (! is_string($id) || ! is_array($surfaces) || $surfaces === []) {
            $errors[] = 'manifest extension surface map contains an invalid entry';

            continue;
        }

        foreach ($surfaces as $surface) {
            if (! is_string($surface)
                || ! isset($registry['surfaces'][$surface])) {
                $errors[] = "manifest extension $id names unknown surface "
                    .json_encode($surface);
            }
        }
    }
}

$corpora = [];
$calibratedCases = [
    'pest' => 797,
    'invoiceshelf' => 202,
    'livewire' => 288,
    'filament' => 705,
];

foreach ($manifest['corpora'] ?? [] as $corpus) {
    $id = $corpus['id'] ?? '';
    $corpora[$id] = $corpus;

    if (! preg_match('/^[0-9a-f]{40}$/', (string) ($corpus['commit'] ?? ''))) {
        $errors[] = "$id does not have an exact commit";
    }

    if (($corpus['selection_mode'] ?? null) !== 'curated'
        || ($corpus['whole_suite'] ?? null) !== false) {
        $errors[] = "$id must declare a curated, partial-suite selection";
    }

    $executionConfiguration = $corpus['execution_configuration'] ?? null;

    if (! is_string($executionConfiguration)
        || $executionConfiguration === ''
        || str_contains($executionConfiguration, '\\')
        || str_starts_with($executionConfiguration, '/')
        || in_array('..', explode('/', $executionConfiguration), true)) {
        $errors[] = "$id execution configuration is invalid";
    }

    if (preg_match(
        '/^[0-9a-f]{64}$/D',
        (string) ($corpus['execution_configuration_sha256'] ?? ''),
    ) !== 1) {
        $errors[] = "$id execution configuration hash is invalid";
    }

    if (preg_match(
        '/^[0-9a-f]{64}$/D',
        (string) ($corpus['full_suite_discovery']['configuration_sha256'] ?? ''),
    ) !== 1) {
        $errors[] = "$id discovery configuration hash is invalid";
    }

    if (($corpus['gate_status'] ?? null) !== 'CURATED_PROVEN_FULL_SUITE_PENDING'
        || ($corpus['classification'] ?? null) !== 'bridge-only') {
        $errors[] = "$id overstates its pre-hosted full-suite status";
    }

    foreach (['baseline', 'drove'] as $runner) {
        $identity = $corpus['runner_identity'][$runner] ?? null;

        if (! is_array($identity)
            || array_keys($identity) !== [
                'command',
                'executable',
                'arguments',
                'frontend',
                'runtime',
                'state',
            ]) {
            $errors[] = "$id $runner runner identity is invalid";

            continue;
        }

        foreach (['command', 'executable', 'frontend', 'runtime', 'state'] as $field) {
            $value = $identity[$field] ?? null;

            if (! is_string($value) || $value === '') {
                $errors[] = "$id $runner runner identity has invalid $field";
            }
        }

        if (! is_array($identity['arguments'])
            || ! array_is_list($identity['arguments'])
            || array_any(
                $identity['arguments'],
                static fn (mixed $argument): bool => ! is_string($argument)
                    || $argument === '',
            )
            || ($runner === 'drove' && $identity['arguments'] !== ['--pest'])
            || ($runner === 'baseline' && $identity['arguments'] !== [])) {
            $errors[] = "$id $runner runner identity has invalid arguments";
        }
    }

    $expectation = $corpus['environment_expectation'] ?? null;
    $state = $corpus['runner_identity']['drove']['state'] ?? null;

    if (($state === 'none' && $expectation !== null)
        || ($state !== 'none'
            && (! is_array($expectation)
                || ! is_string($expectation['coordination'] ?? null)
                || ! is_string($expectation['database']['provider'] ?? null)
                || ! is_array($expectation['database']['capabilities'] ?? null)))) {
        $errors[] = "$id environment expectation is invalid";
    }

    if (! isset($corpus['dependency_lock'])) {
        $errors[] = "$id does not declare a dependency lock";
    } elseif (isset($corpus['dependency_lock'])) {
        $lock = $root.'/'.$corpus['dependency_lock']['path'];
        $actual = is_file($lock) ? hash_file('sha256', $lock) : null;

        if ($actual !== $corpus['dependency_lock']['sha256']) {
            $errors[] = "$id dependency lock checksum mismatch";
        }
    }

    $overlay = $corpus['dependency_overlay'] ?? null;

    if (! is_array($overlay)
        || preg_match(
            '/^[0-9a-f]{64}$/D',
            (string) ($overlay['composer_json_sha256'] ?? ''),
        ) !== 1
        || ! is_array($overlay['tracked_paths'] ?? null)
        || array_any(
            $overlay['tracked_paths'] ?? [],
            static fn (mixed $path): bool => ! in_array(
                $path,
                ['composer.json', 'composer.lock'],
                true,
            ),
        )
        || count(array_unique($overlay['tracked_paths'] ?? []))
            !== count($overlay['tracked_paths'] ?? [])) {
        $errors[] = "$id dependency overlay contract is invalid";
    }

    $discovery = $corpus['full_suite_discovery'] ?? null;

    if (! is_array($discovery)
        || ($discovery['mode'] ?? null) !== 'phpunit-list-tests-xml'
        || ! is_string($discovery['runner'] ?? null)
        || ! is_string($discovery['configuration'] ?? null)
        || ! is_array($discovery['input_roots'] ?? null)
        || $discovery['input_roots'] === []
        || array_any(
            $discovery['input_roots'],
            static fn (mixed $root): bool => ! is_string($root) || $root === '',
        )
        || ! is_array($discovery['case_surfaces'] ?? null)
        || ! is_array($discovery['cohorts'] ?? null)
        || $discovery['cohorts'] === []) {
        $errors[] = "$id full-suite discovery contract is invalid";

        continue;
    }

    foreach ($discovery['case_surfaces'] as $surface) {
        if (! is_string($surface)
            || ! isset($registry['surfaces'][$surface])) {
            $errors[] = "$id full-suite discovery names an unknown case surface";
        }
    }

    $matrix = $corpus['hosted_gate_matrix'] ?? null;
    $matrixCohorts = is_array($matrix) ? array_keys($matrix) : [];
    $classificationCohorts = array_keys($discovery['cohorts']);
    sort($matrixCohorts, SORT_STRING);
    sort($classificationCohorts, SORT_STRING);

    if (! is_array($matrix)
        || $matrixCohorts !== $classificationCohorts) {
        $errors[] = "$id classification and execution cohort sets differ";

        continue;
    }

    foreach ($discovery['cohorts'] as $cohort => $contract) {
        $resourceMode = $contract['resource_mode'] ?? null;
        $expectedProcesses = $resourceMode === 'parallel'
            ? ($gate['parallel_processes'] ?? null)
            : ($resourceMode === 'serial'
                ? ($gate['serial_processes'] ?? null)
                : null);

        if (! is_array($contract)
            || ! is_int($contract['calibrated_cases'] ?? null)
            || $contract['calibrated_cases'] < 1
            || ! is_array($contract['include_groups'] ?? null)
            || ! is_array($contract['exclude_groups'] ?? null)
            || ($matrix[$cohort]['baseline'] ?? null) !== [1]
            || ($matrix[$cohort]['drove'] ?? null) !== $expectedProcesses) {
            $errors[] = "$id/$cohort process or classification contract is invalid";
        }
    }

    $declaredCalibrated = match ($id) {
        'filament' => $corpus['selection']['total_cases'] ?? null,
        default => $corpus['selection']['cases'] ?? null,
    };

    if ($declaredCalibrated !== ($calibratedCases[$id] ?? null)) {
        $errors[] = "$id does not preserve its calibrated case count";
    }
}

$arguments = array_slice($argv, 1);

if ($arguments === ['--manifest-only']) {
    finish($errors, ['manifest' => 'valid', 'corpora' => array_keys($corpora)]);
}

$complete = ($arguments[0] ?? null) === '--complete';

if ($complete) {
    array_shift($arguments);
}

if ($arguments === []) {
    fwrite(STDERR, "usage: verify.php --manifest-only | [--complete] RESULT.json...\n");
    exit(2);
}

$results = [];
$classifications = [];
$classificationOrder = [];
$revisions = [];
$platforms = [];
$resultFiles = 0;

foreach ($arguments as $path) {
    $contents = file_get_contents($path);

    if ($contents === false) {
        $errors[] = "$path cannot be read";

        continue;
    }

    try {
        $result = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = "$path is not valid JSON: {$exception->getMessage()}";

        continue;
    }

    if (! is_array($result)) {
        $errors[] = "$path does not contain a JSON object";

        continue;
    }

    if (($result['kind'] ?? null) === 'full-suite-classification') {
        $id = is_string($result['corpus'] ?? null) ? $result['corpus'] : '';
        $corpus = $corpora[$id] ?? null;

        if ($corpus === null) {
            $errors[] = "$path names unknown classification corpus $id";

            continue;
        }

        if (isset($classifications[$id])) {
            $errors[] = "$path duplicates classification corpus $id";

            continue;
        }

        foreach (classificationErrors(
            $result,
            $corpus,
            $gate,
            $registry,
            $registryHash,
            hash_file('sha256', $manifestPath),
        ) as $error) {
            $errors[] = "$path: $error";
        }

        $classifications[$id] = $result;
        $classificationOrder[] = $id;

        if (preg_match(
            '/^[0-9a-f]{40}$/',
            (string) ($result['drove_revision'] ?? ''),
        ) === 1) {
            $revisions[$result['drove_revision']] = true;
        }

        continue;
    }

    $resultFiles++;
    $id = is_string($result['corpus'] ?? null) ? $result['corpus'] : '';
    $runner = is_string($result['runner'] ?? null) ? $result['runner'] : '';
    $selection = is_array($result['selection'] ?? null) ? $result['selection'] : [];
    $cohort = is_string($selection['cohort'] ?? null) ? $selection['cohort'] : '';
    $key = "$id/$cohort";
    $corpus = $corpora[$id] ?? null;

    if (($result['schema_version'] ?? null) !== 3) {
        $errors[] = "$path schema_version must be 3";
    }

    if ($corpus === null) {
        $errors[] = "$path names unknown corpus $id";

        continue;
    }

    if (! in_array($runner, ['baseline', 'drove'], true)) {
        $errors[] = "$path names unknown runner $runner";

        continue;
    }

    $results[$key][$runner][] = $result;
    $expectedFiles = $corpus['selection'][$cohort]['files']
        ?? $corpus['selection']['files']
        ?? null;
    $expectedSelection = [
        'mode' => $corpus['selection_mode'],
        'whole_suite' => $corpus['whole_suite'],
        'cohort' => $cohort,
        'selected_files' => $expectedFiles,
    ];

    if ($selection !== $expectedSelection) {
        $errors[] = "$path selection metadata mismatch";
    }

    if (($result['runner_identity'] ?? null) !== ($corpus['runner_identity'][$runner] ?? null)) {
        $errors[] = "$path runner identity mismatch";
    }

    $sourceIdentity = $result['source'] ?? null;

    if (! validSourceIdentity(
        $sourceIdentity,
        $corpus,
        is_int($expectedFiles) ? $expectedFiles : null,
    )) {
        $errors[] = "$path source identity is invalid";
    }

    $identity = $corpus['runner_identity'][$runner] ?? [];
    $environmentPlan = $result['environment_plan'] ?? null;

    if (! is_array($environmentPlan)
        || ($environmentPlan['runtime'] ?? null) !== ($identity['runtime'] ?? null)
        || ($environmentPlan['state'] ?? null) !== ($identity['state'] ?? null)
        || ($runner === 'baseline' && ($environmentPlan['replay'] ?? null) !== null)
        || ($runner === 'drove'
            && ! matchesEnvironmentExpectation(
                $environmentPlan['replay'] ?? null,
                $corpus['environment_expectation'] ?? null,
            ))) {
        $errors[] = "$path environment plan mismatch";
    }

    if (! preg_match('/^[0-9a-f]{40}$/', (string) ($result['drove_revision'] ?? ''))) {
        $errors[] = "$path does not record an exact Drove revision";
    } else {
        $revisions[$result['drove_revision']] = true;
    }

    $requested = $result['requested_processes'] ?? null;
    $observed = $result['observed_lanes'] ?? null;
    $source = $result['observed_lanes_source'] ?? null;

    if (! is_int($requested) || $requested < 1) {
        $errors[] = "$path requested_processes must be a positive integer";
    }

    if (! is_int($observed)
        || $observed < 1
        || ! is_int($requested)
        || $observed > $requested) {
        $errors[] = "$path observed_lanes must be within the requested process limit";
    }

    if (($runner === 'drove' && $source !== 'drove_replay')
        || ($runner === 'baseline' && ($source !== 'single_process_baseline' || $requested !== 1))) {
        $errors[] = "$path observed lane source is invalid";
    }

    $run = $result['run'] ?? null;

    if (! is_int($run) || $run < 1) {
        $errors[] = "$path run must be a positive integer";
    }

    $metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];

    $wallMs = $metrics['wall_ms'] ?? null;
    $replayDuration = $metrics['replay_duration_ms'] ?? null;

    if ((! is_int($wallMs) && ! is_float($wallMs))
        || ! is_finite((float) $wallMs)
        || $wallMs <= 0
        || ! is_int($metrics['container_peak_memory_bytes'] ?? null)
        || $metrics['container_peak_memory_bytes'] < 1) {
        $errors[] = "$path wall or container memory metric is invalid";
    }

    if ($runner === 'drove'
        && ((! is_int($replayDuration) && ! is_float($replayDuration))
            || ! is_finite((float) $replayDuration)
            || $replayDuration < 0
            || ! is_int($metrics['replay_php_peak_memory_bytes'] ?? null)
            || $metrics['replay_php_peak_memory_bytes'] < 1)) {
        $errors[] = "$path replay metrics are invalid";
    } elseif ($runner === 'baseline'
        && (($metrics['replay_duration_ms'] ?? null) !== null
            || ($metrics['replay_php_peak_memory_bytes'] ?? null) !== null)) {
        $errors[] = "$path baseline must not claim replay metrics";
    }

    $platform = $result['platform'] ?? null;

    if (! is_array($platform)
        || count(array_filter(
            array_intersect_key($platform, array_flip(['os_family', 'os', 'architecture', 'php'])),
            fn (mixed $value): bool => is_string($value) && $value !== '',
        )) !== 4) {
        $errors[] = "$path platform metadata is invalid";
    }

    if (is_array($result['platform'] ?? null)) {
        $platforms[json_encode($result['platform'], JSON_THROW_ON_ERROR)] = true;
    }

    $expected = expected($corpus, $runner, $cohort);
    $actual = $result['outcome'] ?? [];
    $executionIdentity = $result['execution_identity'] ?? null;

    if ($expected === []) {
        $errors[] = "$path names unknown runner/cohort $runner/$cohort";
    }

    foreach ($expected as $field => $value) {
        if (($actual[$field] ?? null) !== $value) {
            $errors[] = "$path $field: expected $value, found ".json_encode($actual[$field] ?? null);
        }
    }

    if (isset($actual['parse_error'])) {
        $errors[] = "$path: {$actual['parse_error']}";
    }

    if (! is_array($executionIdentity)
        || ($executionIdentity['schema'] ?? null) !== 1
        || ! is_array($executionIdentity['case_ids'] ?? null)
        || ! array_is_list($executionIdentity['case_ids'])
        || count($executionIdentity['case_ids']) !== ($actual['tests'] ?? null)
        || count(array_unique($executionIdentity['case_ids'])) !== count($executionIdentity['case_ids'])
        || ($executionIdentity['case_ids'] ?? null)
            !== sortedStrings($executionIdentity['case_ids'] ?? [])
        || ($executionIdentity['case_ids_sha256'] ?? null)
            !== caseIdsHash($executionIdentity['case_ids'] ?? [])
        || ($executionIdentity['planned_tests'] ?? null) !== ($actual['tests'] ?? null)) {
        $errors[] = "$path execution case identity is invalid";
    } elseif ($runner === 'baseline') {
        if (($executionIdentity['source'] ?? null) !== 'baseline-junit'
            || ($executionIdentity['plan_sha256'] ?? null) !== null
            || ($executionIdentity['terminal_case_ids'] ?? null) !== null
            || ($executionIdentity['terminal_case_ids_sha256'] ?? null) !== null
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                (string) ($result['artifacts']['case_evidence_sha256'] ?? ''),
            ) !== 1) {
            $errors[] = "$path baseline execution identity is invalid";
        }
    } elseif (($executionIdentity['source'] ?? null) !== 'drove-replay'
        || preg_match(
            '/^[0-9a-f]{64}$/D',
            (string) ($executionIdentity['plan_sha256'] ?? ''),
        ) !== 1
        || ($executionIdentity['terminal_case_ids'] ?? null) !== ($actual['tests'] ?? null)
        || preg_match(
            '/^[0-9a-f]{64}$/D',
            (string) ($executionIdentity['terminal_case_ids_sha256'] ?? ''),
        ) !== 1
        || ($result['artifacts']['case_evidence_sha256'] ?? null) !== null) {
        $errors[] = "$path Drove execution identity is invalid";
    }
}

$semanticFields = [
    'tests',
    'passed',
    'failed',
    'errors',
    'skipped',
    'incomplete',
    'risky',
    'warnings',
    'assertions',
    'exit',
];
$bridgeLoad = [];

foreach ($results as $key => $runners) {
    if (! isset($runners['baseline'], $runners['drove'])) {
        $errors[] = "$key must contain baseline and Drove results";

        continue;
    }

    $canonical = array_intersect_key(
        $runners['baseline'][0]['outcome'] ?? [],
        array_flip($semanticFields),
    );
    $sourceIdentities = [];

    foreach (['baseline', 'drove'] as $runner) {
        foreach ($runners[$runner] as $result) {
            $actual = array_intersect_key(
                $result['outcome'] ?? [],
                array_flip($semanticFields),
            );

            if ($canonical !== $actual) {
                $errors[] = "$key baseline/Drove semantic mismatch";
            }

            $source = $result['source'] ?? null;

            if (is_array($source)
                && is_string($source['source_commit'] ?? null)
                && is_string($source['source_sha256'] ?? null)) {
                $sourceIdentities[
                    $source['source_commit'].'/'.$source['source_sha256']
                ] = true;
            }
        }
    }

    if (count($sourceIdentities) !== 1) {
        $errors[] = "$key baseline/Drove source identity mismatch";
    }

    [$id, $cohort] = explode('/', $key, 2);
    $caseIdentities = [];
    $droveExecutionIdentities = [];

    foreach (['baseline', 'drove'] as $runner) {
        foreach ($runners[$runner] as $result) {
            $identity = $result['execution_identity'] ?? null;

            if (! is_array($identity)) {
                continue;
            }

            $caseIds = $identity['case_ids'] ?? null;

            if (is_array($caseIds)) {
                $caseIdentities[json_encode(
                    $caseIds,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )] = true;
            }

            if ($runner === 'drove') {
                $droveExecutionIdentities[json_encode(
                    $identity,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )] = true;
            }
        }
    }

    if (count($caseIdentities) !== 1) {
        $errors[] = "$key baseline/Drove canonical case-ID set mismatch";
    }

    if (count($droveExecutionIdentities) !== 1) {
        $errors[] = "$key Drove plan or terminal case-ID set changed across process cells";
    }

    $droveExecutionIdentity = count($droveExecutionIdentities) === 1
        ? json_decode(
            array_key_first($droveExecutionIdentities),
            true,
            flags: JSON_THROW_ON_ERROR,
        )
        : null;
    $calibratedCases = $corpora[$id]['full_suite_discovery']['cohorts'][$cohort]['calibrated_cases']
        ?? null;
    $classifiedIdentity = $classifications[$id]['cohorts'][$cohort]['case_identity']
        ?? null;
    $classifiedCaseIds = is_array($classifiedIdentity)
        ? ($classifiedIdentity['case_ids'] ?? null)
        : null;
    $executedCaseIds = count($caseIdentities) === 1
        ? json_decode(
            array_key_first($caseIdentities),
            true,
            flags: JSON_THROW_ON_ERROR,
        )
        : null;

    if (isset($classifications[$id])
        && (! is_array($classifiedCaseIds)
            || $classifiedCaseIds !== $executedCaseIds)) {
        $errors[] = "$key discovery/execution canonical case-ID set mismatch";
    }

    $loadedCases = is_array($executedCaseIds)
        && (! isset($classifications[$id])
            || (is_array($classifiedCaseIds)
                && $executedCaseIds === $classifiedCaseIds))
            ? count($executedCaseIds)
            : 0;
    $unchangedBridgeRatio = is_int($calibratedCases) && $calibratedCases > 0
        ? round($loadedCases / $calibratedCases, 6)
        : 0.0;
    $bridgeLoad[$key] = [
        'evidence' => 'exact-source-normalized-execution',
        'idempotent_codemod_applied' => false,
        'drove_execution_identity' => $droveExecutionIdentity,
        'calibrated_cases' => $calibratedCases,
        'unchanged_bridge_loaded_cases' => $loadedCases,
        'unchanged_bridge_load_ratio' => $unchangedBridgeRatio,
        'unchanged_or_idempotent_codemod_bridge_loaded_cases' => $loadedCases,
        'unchanged_or_idempotent_codemod_bridge_load_ratio' => $unchangedBridgeRatio,
    ];

    if ($unchangedBridgeRatio < ($gate['unchanged_bridge_load_ratio_min'] ?? 1)
        || $unchangedBridgeRatio
            < ($gate['unchanged_or_idempotent_codemod_bridge_load_ratio_min'] ?? 1)) {
        $errors[] = "$key misses its dynamic unchanged bridge-load threshold";
    }

    $matrix = $corpora[$id]['hosted_gate_matrix'][$cohort] ?? null;

    if (! is_array($matrix)) {
        $errors[] = "$key does not declare a hosted process matrix";

        continue;
    }

    $runCounts = [];

    foreach (['baseline', 'drove'] as $runner) {
        $byProcess = [];

        foreach ($runners[$runner] as $result) {
            $processes = $result['requested_processes'] ?? null;
            $run = $result['run'] ?? null;

            if (is_int($processes) && is_int($run)) {
                $byProcess[$processes][] = $run;
            }
        }

        $actualProcesses = array_map(intval(...), array_keys($byProcess));
        sort($actualProcesses);
        $expectedProcesses = $matrix[$runner] ?? [];
        sort($expectedProcesses);

        if ($actualProcesses !== $expectedProcesses) {
            $errors[] = "$key $runner process matrix mismatch";
        }

        foreach ($byProcess as $processes => $runs) {
            $runCounts[] = count($runs);
            $unique = array_values(array_unique($runs));
            sort($runs);
            sort($unique);

            if (count($unique) !== count($runs)
                || $unique !== range(1, count($unique))) {
                $errors[] = "$key $runner/$processes runs must be unique and contiguous from 1";
            }
        }
    }

    if (count(array_unique($runCounts)) !== 1) {
        $errors[] = "$key process cells must contain the same run count";
    }
}

if (count($revisions) !== 1) {
    $errors[] = 'all result files must record the same Drove revision';
}

if (count($platforms) !== 1) {
    $errors[] = 'all result files must record the same platform';
}

$ladder = $manifest['ladder'] ?? [];

if ($classificationOrder !== []
    && $classificationOrder !== array_slice($ladder, 0, count($classificationOrder))) {
    $errors[] = 'full-suite classifications must follow the corpus ladder';
}

if ($complete) {
    $expectedCohorts = [];

    foreach ($corpora as $id => $corpus) {
        foreach (array_keys($corpus['hosted_gate_matrix'] ?? []) as $cohort) {
            $expectedCohorts[] = "$id/$cohort";
        }
    }

    $actualCohorts = array_keys($results);
    sort($actualCohorts);
    sort($expectedCohorts);

    if ($actualCohorts !== $expectedCohorts) {
        $errors[] = 'complete report cohort set mismatch';
    }

    if ($classificationOrder !== $ladder
        || array_keys($classifications) !== $ladder) {
        $errors[] = 'complete report requires Pest through final non-skippable Filament classification';
    }
}

$reportRevision = count($revisions) === 1 ? array_key_first($revisions) : null;
$reportPlatform = count($platforms) === 1
    ? json_decode(array_key_first($platforms), true, flags: JSON_THROW_ON_ERROR)
    : null;

finish($errors, [
    'verification' => $errors === [] ? 'passed' : 'failed',
    'result_files' => $resultFiles,
    'cohorts' => array_keys($results),
    'full_suite_classifications' => array_map(
        static fn (array $classification): array => [
            'corpus' => $classification['corpus'],
            'source_commit' => $classification['source_commit'],
            'discovered_cases' => $classification['full_suite']['discovered_cases'],
            'classified_cases' => $classification['full_suite']['classified_cases'],
            'scanner_classification_ratio' => $classification['full_suite']['scanner_classification_ratio'],
            'cohorts' => $classification['cohorts'],
            'execution_performed' => $classification['execution']['performed'],
        ],
        array_values($classifications),
    ),
    'drove_revision' => $reportRevision,
    'platform' => $reportPlatform,
    'dynamic_bridge_load' => $bridgeLoad,
    'diagnostic_comparisons' => [
        'performance_claim' => 'none',
        'thresholds_applied' => false,
        'groups' => diagnosticGroups($results),
    ],
]);

/**
 * @return list<array<string, mixed>>
 */
function diagnosticGroups(array $results): array
{
    $groups = [];

    foreach ($results as $key => $runners) {
        [$corpus, $cohort] = explode('/', (string) $key, 2);

        foreach ($runners as $runner => $runnerResults) {
            $byProcesses = [];

            foreach ($runnerResults as $result) {
                $processes = $result['requested_processes'] ?? 0;
                $byProcesses[$processes][] = $result;
            }

            foreach ($byProcesses as $processes => $cell) {
                usort($cell, fn (array $left, array $right): int => $left['run'] <=> $right['run']);
                $groups[] = [
                    'corpus' => $corpus,
                    'cohort' => $cohort,
                    'runner' => $runner,
                    'runner_identity' => $cell[0]['runner_identity'] ?? null,
                    'selection' => $cell[0]['selection'] ?? null,
                    'environment_plan' => $cell[0]['environment_plan'] ?? null,
                    'execution_identity' => $cell[0]['execution_identity'] ?? null,
                    'requested_processes' => (int) $processes,
                    'observed_lanes' => $cell[0]['observed_lanes'] ?? null,
                    'runs' => count($cell),
                    'outcome' => $cell[0]['outcome'] ?? null,
                    'wall_ms' => summarize(array_column($cell, 'metrics', 'run'), 'wall_ms'),
                    'container_peak_memory_bytes' => summarize(
                        array_column($cell, 'metrics', 'run'),
                        'container_peak_memory_bytes',
                    ),
                    'replay_duration_ms' => summarize(
                        array_column($cell, 'metrics', 'run'),
                        'replay_duration_ms',
                    ),
                    'replay_php_peak_memory_bytes' => summarize(
                        array_column($cell, 'metrics', 'run'),
                        'replay_php_peak_memory_bytes',
                    ),
                ];
            }
        }
    }

    usort(
        $groups,
        fn (array $left, array $right): int => [
            $left['corpus'],
            $left['cohort'],
            $left['runner'] === 'baseline' ? 0 : 1,
            $left['requested_processes'],
        ] <=> [
            $right['corpus'],
            $right['cohort'],
            $right['runner'] === 'baseline' ? 0 : 1,
            $right['requested_processes'],
        ],
    );

    return $groups;
}

/**
 * @param  array<int, array<string, mixed>>  $metrics
 * @return array{min: int|float, median: int|float, max: int|float}|null
 */
function summarize(array $metrics, string $field): ?array
{
    $values = [];

    foreach ($metrics as $metric) {
        $value = $metric[$field] ?? null;

        if (is_int($value) || is_float($value)) {
            $values[] = $value;
        }
    }

    if ($values === []) {
        return null;
    }

    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);
    $median = count($values) % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2;

    return [
        'min' => $values[0],
        'median' => $median,
        'max' => $values[array_key_last($values)],
    ];
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
 * @param  array<string, mixed>  $classification
 * @param  array<string, mixed>  $corpus
 * @param  array<string, mixed>  $gate
 * @param  array<string, mixed>  $registry
 * @return list<string>
 */
function classificationErrors(
    array $classification,
    array $corpus,
    array $gate,
    array $registry,
    string $registryHash,
    string $manifestHash,
): array {
    $errors = [];
    $statuses = ['supported', 'unsupported', 'bridge-only'];
    $full = $classification['full_suite'] ?? null;
    $platform = $classification['platform'] ?? null;

    if (($classification['schema_version'] ?? null) !== ($gate['artifact_schema'] ?? null)
        || ($classification['kind'] ?? null) !== 'full-suite-classification'
        || ($classification['corpus'] ?? null) !== ($corpus['id'] ?? null)
        || ($classification['repository'] ?? null) !== ($corpus['repository'] ?? null)
        || ($classification['source_commit'] ?? null) !== ($corpus['commit'] ?? null)
        || preg_match(
            '/^[0-9a-f]{40}$/D',
            (string) ($classification['drove_revision'] ?? ''),
        ) !== 1) {
        $errors[] = 'classification identity is invalid';
    }

    if (! is_array($platform)
        || ($platform['hosted_image'] ?? null) !== ($gate['hosted_image'] ?? null)
        || ($platform['os_family'] ?? null) !== 'Linux'
        || ! is_string($platform['os'] ?? null)
        || ! is_string($platform['architecture'] ?? null)
        || ! is_string($platform['php'] ?? null)) {
        $errors[] = 'classification platform is not the pinned hosted image';
    }

    if (($classification['manifest']['schema_version'] ?? null) !== 5
        || ($classification['manifest']['sha256'] ?? null) !== $manifestHash
        || ($classification['registry']['schema'] ?? null) !== ($registry['schema'] ?? null)
        || ($classification['registry']['version'] ?? null) !== ($registry['registry_version'] ?? null)
        || ($classification['registry']['sha256'] ?? null) !== $registryHash
        || ($classification['registry']['statuses'] ?? null) !== $statuses) {
        $errors[] = 'classification manifest or registry identity mismatch';
    }

    if (! is_array($full)
        || ($full['discovery_mode'] ?? null) !== 'phpunit-list-tests-xml'
        || ($full['runner'] ?? null) !== ($corpus['full_suite_discovery']['runner'] ?? null)
        || ($full['configuration'] ?? null) !== ($corpus['full_suite_discovery']['configuration'] ?? null)
        || ($full['configuration_sha256'] ?? null)
            !== ($corpus['full_suite_discovery']['configuration_sha256'] ?? null)
        || ($full['selection_mode'] ?? null) !== 'discovered'
        || ($full['whole_suite'] ?? null) !== true
        || ! is_int($full['source_inputs'] ?? null)
        || $full['source_inputs'] < 1
        || ($full['classified_inputs'] ?? null) !== $full['source_inputs']
        || ($full['scanner_classification_ratio'] ?? null) != 1.0
        || ! is_int($full['discovered_cases'] ?? null)
        || $full['discovered_cases'] < 1
        || ($full['classified_cases'] ?? null) !== $full['discovered_cases']
        || ! is_array($full['external_dependencies'] ?? null)
        || ! is_int($full['extension_surfaces'] ?? null)
        || $full['extension_surfaces'] < 1
        || ($full['classified_extension_surfaces'] ?? null) !== $full['extension_surfaces']) {
        $errors[] = 'classification does not cover the complete discovered suite';
    }

    $cases = $classification['cases'] ?? null;
    $inputs = $classification['inputs'] ?? null;
    $extensions = $classification['extensions'] ?? null;
    $externalDependencies = is_array($full)
        ? ($full['external_dependencies'] ?? null)
        : null;

    if (! is_array($cases)
        || count($cases) !== ($full['discovered_cases'] ?? null)) {
        $errors[] = 'classification case ledger count mismatch';
        $cases = [];
    }

    if (! is_array($inputs)
        || count($inputs) !== ($full['source_inputs'] ?? null)) {
        $errors[] = 'classification input ledger count mismatch';
        $inputs = [];
    }

    if (! is_array($extensions)
        || count($extensions) !== ($full['extension_surfaces'] ?? null)) {
        $errors[] = 'classification extension ledger count mismatch';
        $extensions = [];
    }

    if (! is_array($externalDependencies)) {
        $errors[] = 'classification external dependency ledger is invalid';
        $externalDependencies = [];
    }

    $externalPaths = [];
    $expectedLockHash = $corpus['dependency_lock']['sha256'] ?? null;

    foreach ($externalDependencies as $dependency) {
        $path = is_array($dependency) ? ($dependency['path'] ?? null) : null;

        if (! is_string($path)
            || ! str_starts_with($path, 'vendor/')
            || isset($externalPaths[$path])
            || ($dependency['lock'] ?? null) !== 'composer.lock'
            || ($dependency['lock_sha256'] ?? null) !== $expectedLockHash) {
            $errors[] = 'classification external dependency is not covered by the pinned lock';

            continue;
        }

        $externalPaths[$path] = true;
    }

    $caseIds = [];
    $caseStatuses = [];
    $caseLedger = [];

    foreach ($cases as $case) {
        $id = $case['id'] ?? null;
        $status = $case['status'] ?? null;
        $migration = $case['native_migration'] ?? null;

        if (! is_string($id) || $id === '' || isset($caseIds[$id])
            || ! is_string($case['source'] ?? null)
            || $case['source'] === ''
            || ! in_array($status, $statuses, true)
            || ! is_array($case['groups'] ?? null)
            || ! is_array($case['diagnostics'] ?? null)
            || $case['diagnostics'] === []
            || ! in_array(
                $case['runtime_evidence'] ?? null,
                ['exact-source-execution-contract', 'static-preflight'],
                true,
            )
            || ! is_array($case['execution_cohorts'] ?? null)
            || count(array_unique($case['execution_cohorts'] ?? []))
                !== count($case['execution_cohorts'] ?? [])
            || ! is_array($migration)
            || ! in_array($migration['status'] ?? null, $statuses, true)
            || ! is_array($migration['diagnostics'] ?? null)
            || $migration['diagnostics'] === []
            || ! is_bool($migration['loadable_unchanged'] ?? null)
            || ! is_bool($migration['loadable_after_idempotent_codemod'] ?? null)) {
            $errors[] = 'classification contains an invalid or duplicate case';

            continue;
        }

        $caseIds[$id] = true;
        $caseStatuses[] = $status;
        $caseLedger[] = $case;
    }

    $inputPaths = [];
    $inputStatuses = [];
    $inputMigrationStatuses = [];
    $inputLedger = [];

    foreach ($inputs as $input) {
        $path = $input['path'] ?? null;
        $status = $input['runtime_status'] ?? null;
        $migration = $input['native_migration'] ?? null;

        if (! is_string($path) || $path === '' || isset($inputPaths[$path])
            || preg_match('/^[0-9a-f]{64}$/D', (string) ($input['sha256'] ?? '')) !== 1
            || ! in_array($status, $statuses, true)
            || ! in_array(
                $input['runtime_evidence'] ?? null,
                ['exact-source-execution-contract', 'static-preflight'],
                true,
            )
            || ! is_array($input['dependencies'] ?? null)
            || ! is_array($input['findings'] ?? null)
            || ! is_array($migration)
            || ! in_array($migration['direct_status'] ?? null, $statuses, true)
            || ! in_array($migration['status'] ?? null, $statuses, true)
            || ! is_array($migration['dependency_diagnostics'] ?? null)
            || ! is_bool($migration['direct_loadable_unchanged'] ?? null)
            || ! is_bool($migration['direct_loadable_after_idempotent_codemod'] ?? null)
            || ! is_bool($migration['loadable_unchanged'] ?? null)
            || ! is_bool($migration['loadable_after_idempotent_codemod'] ?? null)
            || ($input['codemod']['second_pass_identical'] ?? null) !== true
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                (string) ($input['codemod']['result_sha256'] ?? ''),
            ) !== 1) {
            $errors[] = 'classification contains an invalid or non-idempotent input';

            continue;
        }

        $inputPaths[$path] = true;
        $inputStatuses[] = $status;
        $inputMigrationStatuses[] = $migration['status'];
        $inputLedger[$path] = $input;
    }

    foreach ($inputLedger as $path => $input) {
        $dependencies = $input['dependencies'];

        if (array_any(
            $dependencies,
            static fn (mixed $dependency): bool => ! is_string($dependency)
                || str_starts_with($dependency, 'vendor/')
                || ! isset($inputLedger[$dependency]),
        )) {
            $errors[] = "classification input $path has an invalid dependency edge";

            continue;
        }

        $dependencyPaths = transitiveInputDependencies(
            $path,
            array_map(
                static fn (array $candidate): array => $candidate['dependencies'],
                $inputLedger,
            ),
        );
        $related = [$path, ...$dependencyPaths];
        $expectedStatus = restrictiveClassificationStatus(array_map(
            static fn (string $relatedPath): string => $inputLedger[$relatedPath]['native_migration']['direct_status'],
            $related,
        ));
        $expectedUnchanged = array_all(
            $related,
            static fn (string $relatedPath): bool => $inputLedger[$relatedPath]['native_migration']['direct_loadable_unchanged'] === true,
        );
        $expectedCodemodded = array_all(
            $related,
            static fn (string $relatedPath): bool => $inputLedger[$relatedPath]['native_migration']['direct_loadable_after_idempotent_codemod'] === true,
        );
        $expectedDiagnostics = [];

        foreach ($dependencyPaths as $dependencyPath) {
            foreach ($inputLedger[$dependencyPath]['findings'] as $finding) {
                if (is_string($finding['diagnostic'] ?? null)) {
                    $expectedDiagnostics[] = $finding['diagnostic'];
                }
            }
        }

        $expectedDiagnostics = array_values(array_unique($expectedDiagnostics));
        sort($expectedDiagnostics, SORT_STRING);

        if ($input['native_migration']['status'] !== $expectedStatus
            || $input['native_migration']['loadable_unchanged'] !== $expectedUnchanged
            || $input['native_migration']['loadable_after_idempotent_codemod'] !== $expectedCodemodded
            || $input['native_migration']['dependency_diagnostics'] !== $expectedDiagnostics) {
            $errors[] = "classification input $path does not reflect its include graph";
        }
    }

    $caseSurfaceDefinitions = [];

    foreach ($corpus['full_suite_discovery']['case_surfaces'] ?? [] as $surface) {
        $definition = is_string($surface)
            ? ($registry['surfaces'][$surface] ?? null)
            : null;

        if (! is_array($definition)) {
            $errors[] = 'classification corpus declares an unknown case surface';

            continue;
        }

        $caseSurfaceDefinitions[] = $definition;
    }

    $caseSurfaceStatus = restrictiveClassificationStatus(array_map(
        static fn (array $definition): string => $definition['status'],
        $caseSurfaceDefinitions,
    ));
    $caseSurfaceDiagnostics = array_map(
        static fn (array $definition): string => $definition['diagnostic'],
        $caseSurfaceDefinitions,
    );
    $caseSurfaceDiagnostics = array_values(array_unique($caseSurfaceDiagnostics));
    sort($caseSurfaceDiagnostics, SORT_STRING);
    $knownCohorts = array_keys($corpus['full_suite_discovery']['cohorts'] ?? []);
    $runtimeContractInputPaths = [];

    foreach ($caseLedger as $case) {
        $source = $case['source'];
        $input = $inputLedger[$source] ?? null;

        if (! is_array($input)) {
            $errors[] = "classification case {$case['id']} has no source input";

            continue;
        }

        $executionCohorts = $case['execution_cohorts'];

        if (array_any(
            $executionCohorts,
            static fn (mixed $cohort): bool => ! is_string($cohort)
                || ! in_array($cohort, $knownCohorts, true),
        )) {
            $errors[] = "classification case {$case['id']} has an invalid execution cohort";

            continue;
        }

        $expectedMigrationStatus = restrictiveClassificationStatus([
            $caseSurfaceStatus,
            $input['native_migration']['status'],
        ]);
        $expectedMigrationDiagnostics = [
            ...$caseSurfaceDiagnostics,
            ...array_values(array_filter(
                array_column($input['findings'], 'diagnostic'),
                is_string(...),
            )),
            ...$input['native_migration']['dependency_diagnostics'],
        ];
        $expectedMigrationDiagnostics = array_values(array_unique(
            $expectedMigrationDiagnostics,
        ));
        sort($expectedMigrationDiagnostics, SORT_STRING);
        $surfaceLoadable = $caseSurfaceStatus !== 'unsupported';
        $hasExecutionContract = $executionCohorts !== [];
        $expectedRuntimeStatus = $hasExecutionContract
            ? $caseSurfaceStatus
            : $expectedMigrationStatus;
        $expectedRuntimeDiagnostics = $hasExecutionContract
            ? $caseSurfaceDiagnostics
            : $expectedMigrationDiagnostics;
        $expectedRuntimeEvidence = $hasExecutionContract
            ? 'exact-source-execution-contract'
            : 'static-preflight';

        if ($case['status'] !== $expectedRuntimeStatus
            || $case['diagnostics'] !== $expectedRuntimeDiagnostics
            || $case['runtime_evidence'] !== $expectedRuntimeEvidence
            || $case['native_migration']['status'] !== $expectedMigrationStatus
            || $case['native_migration']['diagnostics']
                !== $expectedMigrationDiagnostics
            || $case['native_migration']['loadable_unchanged'] !== (
                $surfaceLoadable
                && $input['native_migration']['loadable_unchanged']
            )
            || $case['native_migration']['loadable_after_idempotent_codemod'] !== (
                $surfaceLoadable
                && $input['native_migration']['loadable_after_idempotent_codemod']
            )) {
            $errors[] = "classification case {$case['id']} status does not match its source graph";
        }

        if ($hasExecutionContract) {
            $runtimeContractInputPaths[$source] = true;

            foreach (transitiveInputDependencies(
                $source,
                array_map(
                    static fn (array $candidate): array => $candidate['dependencies'],
                    $inputLedger,
                ),
            ) as $dependency) {
                $runtimeContractInputPaths[$dependency] = true;
            }
        }
    }

    foreach ($inputLedger as $path => $input) {
        $hasExecutionContract = isset($runtimeContractInputPaths[$path]);
        $expectedStatus = $hasExecutionContract
            ? $caseSurfaceStatus
            : $input['native_migration']['status'];
        $expectedEvidence = $hasExecutionContract
            ? 'exact-source-execution-contract'
            : 'static-preflight';

        if ($input['runtime_status'] !== $expectedStatus
            || $input['runtime_evidence'] !== $expectedEvidence) {
            $errors[] = "classification input $path conflates runtime and native migration status";
        }
    }

    $extensionKeys = [];
    $extensionStatuses = [];

    foreach ($extensions as $extension) {
        $id = $extension['id'] ?? null;
        $surface = $extension['surface'] ?? null;
        $definition = is_string($surface)
            ? ($registry['surfaces'][$surface] ?? null)
            : null;
        $key = is_string($id) && is_string($surface) ? "$id/$surface" : '';

        if ($key === '' || isset($extensionKeys[$key])
            || ! is_array($definition)
            || ($extension['status'] ?? null) !== ($definition['status'] ?? null)
            || ($extension['diagnostic'] ?? null) !== ($definition['diagnostic'] ?? null)) {
            $errors[] = 'classification contains an invalid extension surface';

            continue;
        }

        $extensionKeys[$key] = true;
        $extensionStatuses[] = $extension['status'];
    }

    if (($full['case_status_counts'] ?? null) !== countedStatuses($caseStatuses)
        || ($full['input_runtime_status_counts'] ?? null)
            !== countedStatuses($inputStatuses)
        || ($full['native_migration_input_status_counts'] ?? null)
            !== countedStatuses($inputMigrationStatuses)
        || ($full['extension_status_counts'] ?? null) !== countedStatuses($extensionStatuses)) {
        $errors[] = 'classification status totals do not match their ledgers';
    }

    $cohorts = $classification['cohorts'] ?? null;
    $contracts = $corpus['full_suite_discovery']['cohorts'] ?? [];
    $cohortKeys = is_array($cohorts) ? array_keys($cohorts) : [];
    $contractKeys = array_keys($contracts);
    sort($cohortKeys, SORT_STRING);
    sort($contractKeys, SORT_STRING);

    if (! is_array($cohorts) || $cohortKeys !== $contractKeys) {
        $errors[] = 'classification cohort set mismatch';
        $cohorts = [];
    }

    foreach ($contracts as $cohort => $contract) {
        $actual = $cohorts[$cohort] ?? null;
        $cases = is_array($actual) ? ($actual['classified_cases'] ?? null) : null;
        $contractCases = count(array_filter(
            $caseLedger,
            static fn (array $case): bool => in_array(
                $cohort,
                $case['execution_cohorts'],
                true,
            ),
        ));
        $contractCaseIds = array_column(array_filter(
            $caseLedger,
            static fn (array $case): bool => in_array(
                $cohort,
                $case['execution_cohorts'],
                true,
            ),
        ), 'id');
        sort($contractCaseIds, SORT_STRING);
        $caseIdentity = is_array($actual)
            ? ($actual['case_identity'] ?? null)
            : null;
        $unchanged = is_array($actual)
            ? ($actual['native_migration_unchanged_cases'] ?? null)
            : null;
        $codemodded = is_array($actual)
            ? ($actual['native_migration_unchanged_or_idempotent_codemod_cases'] ?? null)
            : null;
        $unchangedRatio = is_int($cases) && $cases > 0 && is_int($unchanged)
            ? round($unchanged / $cases, 6)
            : null;
        $codemodRatio = is_int($cases) && $cases > 0 && is_int($codemodded)
            ? round($codemodded / $cases, 6)
            : null;

        if (! is_array($actual)
            || ($actual['selection_mode'] ?? null) !== 'curated'
            || ($actual['whole_suite'] ?? null) !== false
            || ($actual['resource_mode'] ?? null) !== ($contract['resource_mode'] ?? null)
            || ! is_int($actual['selected_files'] ?? null)
            || $actual['selected_files'] < 1
            || ($actual['calibrated_cases'] ?? null) !== ($contract['calibrated_cases'] ?? null)
            || $cases !== ($contract['calibrated_cases'] ?? null)
            || ($actual['runtime_contract_cases'] ?? null) !== $contractCases
            || $contractCases !== ($contract['calibrated_cases'] ?? null)
            || ! is_array($caseIdentity)
            || ($caseIdentity['schema'] ?? null) !== 1
            || ($caseIdentity['case_ids'] ?? null) !== $contractCaseIds
            || ($caseIdentity['case_ids_sha256'] ?? null) !== caseIdsHash($contractCaseIds)
            || ($actual['native_migration_unchanged_ratio'] ?? null)
                != $unchangedRatio
            || ($actual['native_migration_unchanged_or_idempotent_codemod_ratio'] ?? null)
                != $codemodRatio
            || ! is_numeric($actual['native_migration_unchanged_ratio'] ?? null)
            || ! is_numeric(
                $actual['native_migration_unchanged_or_idempotent_codemod_ratio']
                    ?? null,
            )) {
            $errors[] = "$cohort classification misses its runtime contract or calibrated cases";
        }
    }

    if (($classification['execution']['performed'] ?? null) !== false
        || ($classification['execution']['claim'] ?? null) !== 'classification-preflight-only') {
        $errors[] = 'classification artifact falsely claims execution';
    }

    return $errors;
}

/**
 * @param  array<string, list<string>>  $dependencies
 * @return list<string>
 */
function transitiveInputDependencies(string $path, array $dependencies): array
{
    $pending = $dependencies[$path] ?? [];
    $visited = [$path => true];

    while ($pending !== []) {
        $dependency = array_shift($pending);

        if (isset($visited[$dependency])) {
            continue;
        }

        if (! isset($dependencies[$dependency])) {
            return [];
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
 * @param  list<string>  $statuses
 */
function restrictiveClassificationStatus(array $statuses): string
{
    $rank = ['supported' => 0, 'bridge-only' => 1, 'unsupported' => 2];
    $status = 'supported';

    foreach ($statuses as $candidate) {
        if (isset($rank[$candidate]) && $rank[$candidate] > $rank[$status]) {
            $status = $candidate;
        }
    }

    return $status;
}

/**
 * @param  list<string>  $statuses
 * @return array{supported: int, unsupported: int, bridge-only: int}
 */
function countedStatuses(array $statuses): array
{
    $counts = ['supported' => 0, 'unsupported' => 0, 'bridge-only' => 0];

    foreach ($statuses as $status) {
        if (is_string($status) && isset($counts[$status])) {
            $counts[$status]++;
        }
    }

    return $counts;
}

/**
 * @param  array<string, mixed>  $corpus
 */
function validSourceIdentity(
    mixed $identity,
    array $corpus,
    ?int $expectedFiles,
): bool {
    if (! is_array($identity)
        || ($identity['schema_version'] ?? null) !== 1
        || ($identity['corpus'] ?? null) !== ($corpus['id'] ?? null)
        || ($identity['source_commit'] ?? null) !== ($corpus['commit'] ?? null)
        || preg_match(
            '/^[0-9a-f]{40}$/D',
            (string) ($identity['source_tree'] ?? ''),
        ) !== 1
        || ($identity['tracked_source_clean'] ?? null) !== true
        || ! is_array($identity['tracked_dependency_overlays'] ?? null)
        || ($identity['composer_json_sha256'] ?? null)
            !== ($corpus['dependency_overlay']['composer_json_sha256'] ?? null)
        || ($identity['selection']['files'] ?? null) !== $expectedFiles
        || preg_match(
            '/^[0-9a-f]{64}$/D',
            (string) ($identity['selection']['sha256'] ?? ''),
        ) !== 1
        || ($identity['configuration']['path'] ?? null)
            !== ($corpus['execution_configuration'] ?? null)
        || ($identity['configuration']['sha256'] ?? null)
            !== ($corpus['execution_configuration_sha256'] ?? null)
        || ($identity['dependency_lock_sha256'] ?? null)
            !== ($corpus['dependency_lock']['sha256'] ?? null)) {
        return false;
    }

    $overlayPaths = [];

    foreach ($identity['tracked_dependency_overlays'] as $overlay) {
        $path = is_array($overlay) ? ($overlay['path'] ?? null) : null;

        if (! in_array($path, ['composer.json', 'composer.lock'], true)
            || isset($overlayPaths[$path])
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                (string) ($overlay['sha256'] ?? ''),
            ) !== 1) {
            return false;
        }

        $overlayPaths[$path] = true;
    }

    $sortedPaths = array_keys($overlayPaths);
    sort($sortedPaths, SORT_STRING);

    if ($sortedPaths !== array_column(
        $identity['tracked_dependency_overlays'],
        'path',
    )
        || $sortedPaths !== ($corpus['dependency_overlay']['tracked_paths'] ?? null)) {
        return false;
    }

    $claimedHash = $identity['source_sha256'] ?? null;
    unset($identity['source_sha256']);

    return is_string($claimedHash)
        && preg_match('/^[0-9a-f]{64}$/D', $claimedHash) === 1
        && hash(
            'sha256',
            json_encode(
                canonical($identity),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ) === $claimedHash;
}

/**
 * @param  list<string>  $values
 * @return list<string>
 */
function sortedStrings(array $values): array
{
    sort($values, SORT_STRING);

    return $values;
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

/**
 * @return array<string, int>
 */
function expected(array $corpus, string $runner, string $cohort): array
{
    $selection = $corpus['selection'];
    $expected = $corpus[$runner] ?? null;

    if (! is_array($expected)) {
        return [];
    }

    if ($cohort !== 'full') {
        $expected = $expected[$cohort] ?? [];
        $tests = $selection[$cohort]['cases'] ?? null;
    } else {
        $tests = $expected['tests'] ?? $selection['cases'] ?? null;
    }

    return [
        'tests' => (int) $tests,
        'passed' => (int) ($expected['passed'] ?? 0),
        'failed' => (int) ($expected['failed'] ?? 0),
        'errors' => (int) ($expected['errors'] ?? 0),
        'skipped' => (int) ($expected['skipped'] ?? 0),
        'incomplete' => (int) ($expected['incomplete'] ?? 0),
        'risky' => (int) ($expected['risky'] ?? 0),
        'warnings' => (int) ($expected['warnings'] ?? 0),
        'assertions' => (int) ($expected['assertions'] ?? 0),
        'exit' => (int) ($expected['exit'] ?? 0),
    ];
}

function finish(array $errors, array $result): never
{
    $result['errors'] = $errors;
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($errors === [] ? 0 : 1);
}
