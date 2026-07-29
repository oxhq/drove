<?php

declare(strict_types=1);

$run = static function (string ...$arguments): array {
    $command = [PHP_BINARY, __DIR__.'/vendor/bin/drove', ...$arguments];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the installed Drove CLI.');
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
$expect = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$cases = [
    'path' => $run('tests/OtherTest.php'),
    'filter' => $run('tests/FastTest.php', '--filter=alpha'),
    'group' => $run('tests', '--group=slow'),
    'exclude_group' => $run('tests/FastTest.php', '--exclude-group=slow'),
    'testsuite' => $run('--testsuite=Other'),
    'parallel' => $run('--parallel', '--processes=2', 'tests/FastTest.php'),
    'failure' => $run('tests/FailingTest.php'),
    'coverage' => $run('--coverage'),
    'coverage_text' => $run('--coverage-text'),
    'process_isolation' => $run('--process-isolation'),
];

foreach (['path', 'filter', 'group', 'exclude_group', 'testsuite', 'parallel'] as $name) {
    $expect($cases[$name]['exit'] === 0, $name.' did not exit successfully: '.$cases[$name]['stderr']);
}

$expect(
    str_contains($cases['path']['stdout'], 'gamma other')
        && ! str_contains($cases['path']['stdout'], 'alpha fast'),
    'Path selection drifted.',
);
$expect(
    str_contains($cases['filter']['stdout'], 'alpha fast')
        && str_contains($cases['filter']['stdout'], 'alpha-output')
        && ! str_contains($cases['filter']['stdout'], 'beta slow'),
    'Filter selection or stdout rendering drifted.',
);
$expect(
    str_contains($cases['group']['stdout'], 'beta slow')
        && ! str_contains($cases['group']['stdout'], 'alpha fast'),
    'Group selection drifted.',
);
$expect(
    str_contains($cases['exclude_group']['stdout'], 'alpha fast')
        && ! str_contains($cases['exclude_group']['stdout'], 'beta slow'),
    'Excluded group selection drifted.',
);
$expect(
    str_contains($cases['testsuite']['stdout'], 'gamma other')
        && ! str_contains($cases['testsuite']['stdout'], 'alpha fast'),
    'Testsuite selection drifted.',
);
$alpha = strpos($cases['parallel']['stdout'], 'alpha fast');
$beta = strpos($cases['parallel']['stdout'], 'beta slow');
$expect(
    $alpha !== false && $beta !== false && $alpha < $beta,
    'Parallel rendering drifted from discovery order.',
);
$expect(
    $cases['failure']['exit'] === 1
        && str_contains($cases['failure']['stdout'], 'fails conventionally'),
    'Test failures did not use exit code 1.',
);
$expect(
    $cases['coverage']['exit'] === 2
        && str_contains($cases['coverage']['stderr'], '--coverage mode is not supported yet'),
    'Unsupported coverage did not use an explicit exit-2 diagnostic.',
);
$expect(
    $cases['coverage_text']['exit'] === 2
        && str_contains($cases['coverage_text']['stderr'], '--coverage-text mode is not supported yet'),
    'Unsupported coverage output did not use an explicit exit-2 diagnostic.',
);
$expect(
    $cases['process_isolation']['exit'] === 2
        && str_contains($cases['process_isolation']['stderr'], '--process-isolation mode is not supported yet'),
    'Unsupported process isolation did not use an explicit exit-2 diagnostic.',
);

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'selections' => ['path', 'filter', 'group', 'exclude-group', 'testsuite'],
    'exit_codes' => [
        'passed' => $cases['path']['exit'],
        'failed' => $cases['failure']['exit'],
        'unsupported' => $cases['coverage']['exit'],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
