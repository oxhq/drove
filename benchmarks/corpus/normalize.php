<?php

declare(strict_types=1);

if ($argc !== 10) {
    fwrite(STDERR, "usage: normalize.php CORPUS RUNNER COHORT PROCESSES SELECTED_FILES REVISION EXIT RAW OUTPUT\n");
    exit(2);
}

[, $corpus, $runner, $cohort, $processes, $selectedFiles, $revision, $exitCode, $rawPath, $outputPath] = $argv;
$raw = file_get_contents($rawPath);

if ($raw === false) {
    throw new RuntimeException("Cannot read $rawPath");
}

$plain = preg_replace('/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $raw);

if (! is_string($plain)) {
    throw new RuntimeException('Cannot normalize ANSI output.');
}

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

$result = [
    'schema_version' => 1,
    'corpus' => $corpus,
    'runner' => $runner,
    'cohort' => $cohort,
    'processes' => (int) $processes,
    'selected_files' => (int) $selectedFiles,
    'drove_revision' => $revision,
    'outcome' => [...$summary, 'exit' => (int) $exitCode],
    'raw_sha256' => hash('sha256', $raw),
];

$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

if (file_put_contents($outputPath, $encoded) === false) {
    throw new RuntimeException("Cannot write $outputPath");
}
