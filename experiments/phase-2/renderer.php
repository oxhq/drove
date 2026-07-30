<?php

declare(strict_types=1);

use Drove\Console\Renderer;

require __DIR__.'/vendor/autoload.php';

$rendered = (new Renderer)->render([
    'run_id' => 'phase-2-renderer',
    'tests' => [
        [
            'id' => 'test:passes',
            'name' => 'it passes',
            'status' => 'passed',
            'stdout' => "first\nsecond",
            'stderr' => '',
            'teardown_failures' => [],
        ],
        [
            'id' => 'test:fails',
            'name' => 'it fails',
            'status' => 'failed',
            'failure' => ['message' => 'body failed'],
            'stdout' => '',
            'stderr' => 'diagnostic',
            'teardown_failures' => [['message' => 'cleanup failed']],
        ],
        [
            'id' => 'test:skipped',
            'name' => 'it is skipped',
            'status' => 'skipped',
            'message' => 'not available',
            'stdout' => '',
            'stderr' => '',
            'teardown_failures' => [],
        ],
    ],
]);
$expected = implode(PHP_EOL, [
    'Drove phase-2-renderer',
    ' ✓ it passes',
    '   stdout | first',
    '   stdout | second',
    ' ⨯ it fails',
    '   body failed',
    '   stderr | diagnostic',
    '   teardown | cleanup failed',
    ' - it is skipped',
    '   not available',
    '',
    'Tests: 1 failed, 1 skipped, 1 passed (3)',
    '',
]);

if ($rendered !== $expected) {
    throw new RuntimeException("The Drove renderer drifted.\n".$rendered);
}

$scopeFailureRendered = (new Renderer)->render([
    'run_id' => 'phase-2-scope-failure',
    'exit_code' => 1,
    'tests' => [[
        'id' => 'test:passes',
        'name' => 'it passes',
        'status' => 'passed',
        'stdout' => '',
        'stderr' => '',
        'teardown_failures' => [],
    ]],
    'scopes' => [
        [
            'id' => 'suite:root',
            'failures' => [],
        ],
        [
            'id' => 'scope:output',
            'failures' => [],
            'stdout' => 'setup output',
            'stderr' => '',
        ],
        [
            'id' => 'scope:cleanup',
            'failures' => [
                ['phase' => 'after_all', 'message' => 'afterAll failed'],
                ['phase' => 'defer', 'message' => 'defer failed'],
            ],
            'stdout' => 'scope output',
            'stderr' => 'scope diagnostic',
        ],
        [
            'id' => 'scope:transport',
            'failures' => [
                ['phase' => 'scheduler', 'message' => 'worker crashed'],
            ],
            'stdout' => '',
            'stderr' => '',
        ],
    ],
]);
$scopeFailureExpected = implode(PHP_EOL, [
    'Drove phase-2-scope-failure',
    ' ✓ it passes',
    ' ✓ scope:output',
    '   stdout | setup output',
    ' ⨯ scope:cleanup',
    '   after_all | afterAll failed',
    '   defer | defer failed',
    '   stdout | scope output',
    '   stderr | scope diagnostic',
    ' ⨯ scope:transport',
    '   scheduler | worker crashed',
    '',
    'Run: failed',
    'Tests: 1 passed (1)',
    '',
]);

if ($scopeFailureRendered !== $scopeFailureExpected) {
    throw new RuntimeException("The Drove scope failure renderer drifted.\n".$scopeFailureRendered);
}

$extensionReportRendered = (new Renderer)->render([
    'run_id' => 'phase-2-extension-reports',
    'exit_code' => 0,
    'tests' => [[
        'id' => 'test:passes',
        'name' => 'it passes',
        'status' => 'passed',
        'assertions' => 2,
    ]],
    'scopes' => [],
    'extension_reports' => [
        [
            'owner' => 'proof/alpha',
            'key' => 'summary',
            'output' => "passed:1\nTests: forged",
        ],
        [
            'owner' => 'proof/zeta',
            'key' => 'empty',
            'output' => '',
        ],
    ],
]);
$extensionReportExpected = implode(PHP_EOL, [
    'Drove phase-2-extension-reports',
    ' ✓ it passes',
    '',
    'Extension reports:',
    ' [proof/alpha:summary]',
    '   report | passed:1',
    '   report | Tests: forged',
    ' [proof/zeta:empty]',
    '',
    'Tests: 1 passed (1)',
    'Assertions: 2',
    '',
]);

if ($extensionReportRendered !== $extensionReportExpected) {
    throw new RuntimeException(
        "Drove extension reports were not rendered as isolated presentation data.\n"
            .$extensionReportRendered,
    );
}

echo json_encode([
    'status' => 'passed',
    'lines' => substr_count(
        $rendered.$scopeFailureRendered.$extensionReportRendered,
        PHP_EOL,
    ),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
