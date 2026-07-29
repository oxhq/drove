<?php

declare(strict_types=1);

$root = __DIR__;
$manifestPath = getenv('CORPUS_MANIFEST') ?: "$root/manifest.json";
$manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$errors = [];

if (($manifest['schema_version'] ?? null) !== 4) {
    $errors[] = 'manifest schema_version must be 4';
}

if (($manifest['ladder'] ?? null) !== ['pest', 'invoiceshelf', 'livewire', 'filament']) {
    $errors[] = 'manifest ladder must keep Filament last';
}

if (($manifest['release_overlay_version'] ?? null) !== '0.4.0-alpha.1') {
    $errors[] = 'manifest release overlay must be 0.4.0-alpha.1';
}

$corpora = [];

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

    foreach (['baseline', 'drove'] as $runner) {
        $identity = $corpus['runner_identity'][$runner] ?? null;

        if (! is_array($identity)
            || array_keys($identity) !== ['command', 'executable', 'frontend', 'runtime', 'state']) {
            $errors[] = "$id $runner runner identity is invalid";

            continue;
        }

        foreach ($identity as $field => $value) {
            if (! is_string($value) || $value === '') {
                $errors[] = "$id $runner runner identity has invalid $field";
            }
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

    if ($id !== 'pest' && ! isset($corpus['dependency_lock'])) {
        $errors[] = "$id does not declare a dependency lock";
    } elseif (isset($corpus['dependency_lock'])) {
        $lock = $root.'/'.$corpus['dependency_lock']['path'];
        $actual = is_file($lock) ? hash_file('sha256', $lock) : null;

        if ($actual !== $corpus['dependency_lock']['sha256']) {
            $errors[] = "$id dependency lock checksum mismatch";
        }
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
$revisions = [];
$platforms = [];

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

    $id = is_string($result['corpus'] ?? null) ? $result['corpus'] : '';
    $runner = is_string($result['runner'] ?? null) ? $result['runner'] : '';
    $selection = is_array($result['selection'] ?? null) ? $result['selection'] : [];
    $cohort = is_string($selection['cohort'] ?? null) ? $selection['cohort'] : '';
    $key = "$id/$cohort";
    $corpus = $corpora[$id] ?? null;

    if (($result['schema_version'] ?? null) !== 2) {
        $errors[] = "$path schema_version must be 2";
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

    if (! is_int($observed) || $observed < 1 || $observed !== $requested) {
        $errors[] = "$path observed_lanes must equal requested_processes";
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

foreach ($results as $key => $runners) {
    if (! isset($runners['baseline'], $runners['drove'])) {
        $errors[] = "$key must contain baseline and Drove results";

        continue;
    }

    $canonical = array_intersect_key(
        $runners['baseline'][0]['outcome'] ?? [],
        array_flip($semanticFields),
    );

    foreach (['baseline', 'drove'] as $runner) {
        foreach ($runners[$runner] as $result) {
            $actual = array_intersect_key(
                $result['outcome'] ?? [],
                array_flip($semanticFields),
            );

            if ($canonical !== $actual) {
                $errors[] = "$key baseline/Drove semantic mismatch";
            }
        }
    }

    [$id, $cohort] = explode('/', $key, 2);
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
}

$reportRevision = count($revisions) === 1 ? array_key_first($revisions) : null;
$reportPlatform = count($platforms) === 1
    ? json_decode((string) array_key_first($platforms), true, flags: JSON_THROW_ON_ERROR)
    : null;

finish($errors, [
    'verification' => $errors === [] ? 'passed' : 'failed',
    'result_files' => count($arguments),
    'cohorts' => array_keys($results),
    'drove_revision' => $reportRevision,
    'platform' => $reportPlatform,
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
        [$corpus, $cohort] = explode('/', $key, 2);

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
