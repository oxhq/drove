<?php

declare(strict_types=1);

$root = __DIR__;
$manifestPath = "$root/manifest.json";
$manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$errors = [];

if (($manifest['schema_version'] ?? null) !== 3) {
    $errors[] = 'manifest schema_version must be 3';
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

if ($argc === 2 && $argv[1] === '--manifest-only') {
    finish($errors, ['manifest' => 'valid', 'corpora' => array_keys($corpora)]);
}

if ($argc < 2) {
    fwrite(STDERR, "usage: verify.php --manifest-only | RESULT.json...\n");
    exit(2);
}

$results = [];
$revisions = [];

foreach (array_slice($argv, 1) as $path) {
    $result = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $id = $result['corpus'] ?? '';
    $cohort = $result['cohort'] ?? 'full';
    $runner = $result['runner'] ?? '';
    $key = "$id/$cohort";
    $results[$key][$runner][] = $result;
    $corpus = $corpora[$id] ?? null;

    if ($corpus === null) {
        $errors[] = "$path names unknown corpus $id";

        continue;
    }

    $expectedFiles = $cohort === 'full'
        ? ($corpus['selection']['files'] ?? null)
        : ($corpus['selection'][$cohort]['files'] ?? null);

    if (($result['selected_files'] ?? null) !== $expectedFiles) {
        $errors[] = "$path selected_files mismatch";
    }

    if (! preg_match('/^[0-9a-f]{40}$/', (string) ($result['drove_revision'] ?? ''))) {
        $errors[] = "$path does not record an exact Drove revision";
    } else {
        $revisions[$result['drove_revision']] = true;
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

$semanticFields = ['tests', 'passed', 'failed', 'errors', 'skipped', 'incomplete', 'risky', 'warnings', 'assertions', 'exit'];

foreach ($results as $key => $runners) {
    if (! isset($runners['baseline'], $runners['drove'])) {
        $errors[] = "$key must contain baseline and Drove results";

        continue;
    }

    $baseline = array_intersect_key($runners['baseline'][0]['outcome'], array_flip($semanticFields));

    foreach ($runners['drove'] as $result) {
        $drove = array_intersect_key($result['outcome'], array_flip($semanticFields));

        if ($baseline !== $drove) {
            $errors[] = "$key baseline/Drove semantic mismatch at {$result['processes']} processes";
        }
    }

    [$id, $cohort] = explode('/', $key, 2);
    $matrix = $corpora[$id]['hosted_gate_matrix'][$cohort] ?? null;

    if (! is_array($matrix)) {
        $errors[] = "$key does not declare a hosted process matrix";

        continue;
    }

    foreach (['baseline', 'drove'] as $runner) {
        $actualProcesses = array_column($runners[$runner], 'processes');
        sort($actualProcesses);
        $expectedProcesses = $matrix[$runner] ?? [];
        sort($expectedProcesses);

        if ($actualProcesses !== $expectedProcesses) {
            $errors[] = "$key $runner process matrix mismatch";
        }
    }
}

if (count($revisions) !== 1) {
    $errors[] = 'all result files must record the same Drove revision';
}

finish($errors, [
    'verification' => $errors === [] ? 'passed' : 'failed',
    'result_files' => $argc - 1,
    'cohorts' => array_keys($results),
]);

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
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    exit($errors === [] ? 0 : 1);
}
