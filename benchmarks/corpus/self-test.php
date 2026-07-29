<?php

declare(strict_types=1);

$samples = [
    "  Tests:    71 skipped, 726 passed (1718 assertions)\n" => [
        'tests' => 797,
        'passed' => 726,
        'skipped' => 71,
        'assertions' => 1718,
    ],
    "Tests: 71 skipped, 726 passed (797)\nAssertions: 1718\n" => [
        'tests' => 797,
        'passed' => 726,
        'skipped' => 71,
        'assertions' => 1718,
    ],
    "Tests: 288, Assertions: 1034, Incomplete: 3.\n" => [
        'tests' => 288,
        'passed' => 285,
        'incomplete' => 3,
        'assertions' => 1034,
    ],
    "OK (36 tests, 68 assertions)\n" => [
        'tests' => 36,
        'passed' => 36,
        'assertions' => 68,
    ],
];

foreach ($samples as $sample => $expected) {
    $raw = tempnam(sys_get_temp_dir(), 'drove-corpus-raw-');
    $json = tempnam(sys_get_temp_dir(), 'drove-corpus-json-');

    if ($raw === false || $json === false) {
        throw new RuntimeException('Cannot create corpus self-test files.');
    }

    try {
        file_put_contents($raw, $sample);
        $command = array_map(
            escapeshellarg(...),
            [
                PHP_BINARY,
                __DIR__.'/normalize.php',
                'fixture',
                'drove',
                'full',
                '8',
                '1',
                str_repeat('a', 40),
                '0',
                $raw,
                $json,
            ],
        );
        exec(implode(' ', $command), $output, $exit);

        if ($exit !== 0) {
            throw new RuntimeException('Normalizer self-test command failed.');
        }

        $actual = json_decode((string) file_get_contents($json), true, flags: JSON_THROW_ON_ERROR);

        foreach ($expected as $field => $value) {
            if ($actual['outcome'][$field] !== $value) {
                throw new RuntimeException("$field: expected $value, found {$actual['outcome'][$field]}");
            }
        }
    } finally {
        @unlink($raw);
        @unlink($json);
    }
}

$baseline = tempnam(sys_get_temp_dir(), 'drove-corpus-baseline-');
$drove = tempnam(sys_get_temp_dir(), 'drove-corpus-drove-');

if ($baseline === false || $drove === false) {
    throw new RuntimeException('Cannot create verifier self-test files.');
}

try {
    $result = [
        'schema_version' => 1,
        'corpus' => 'pest',
        'cohort' => 'nonserial',
        'selected_files' => 140,
        'drove_revision' => str_repeat('a', 40),
        'outcome' => [
            'tests' => 785,
            'passed' => 714,
            'failed' => 0,
            'errors' => 0,
            'skipped' => 71,
            'incomplete' => 0,
            'risky' => 0,
            'warnings' => 0,
            'assertions' => 1690,
            'exit' => 0,
        ],
    ];

    file_put_contents($baseline, json_encode([
        ...$result,
        'runner' => 'baseline',
        'processes' => 1,
    ], JSON_THROW_ON_ERROR));
    file_put_contents($drove, json_encode([
        ...$result,
        'runner' => 'drove',
        'processes' => 8,
    ], JSON_THROW_ON_ERROR));

    $command = array_map(
        escapeshellarg(...),
        [PHP_BINARY, __DIR__.'/verify.php', $baseline, $drove],
    );
    exec(implode(' ', $command), $output, $exit);

    if ($exit !== 0) {
        throw new RuntimeException('Verifier self-test rejected matching results.');
    }

    $result['outcome']['passed']--;
    file_put_contents($drove, json_encode([
        ...$result,
        'runner' => 'drove',
        'processes' => 8,
    ], JSON_THROW_ON_ERROR));
    exec(implode(' ', $command), $output, $exit);

    if ($exit === 0) {
        throw new RuntimeException('Verifier self-test accepted a semantic divergence.');
    }
} finally {
    @unlink($baseline);
    @unlink($drove);
}

fwrite(STDOUT, "corpus self-test passed\n");
