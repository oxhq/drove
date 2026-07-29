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

echo json_encode([
    'status' => 'passed',
    'lines' => substr_count($rendered, PHP_EOL),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
