<?php

declare(strict_types=1);

require __DIR__.'/case-parity.php';

$nonserialPath = $argv[1] ?? null;
$serialPath = $argv[2] ?? null;
$output = $argv[3] ?? __DIR__.'/baseline-cases.json';

if ($nonserialPath === null || $serialPath === null) {
    fwrite(STDERR, "Usage: generate-baseline.php NONSERIAL_JUNIT SERIAL_JUNIT [OUTPUT]\n");
    exit(2);
}

$nonserial = nativeFilamentBaselineRowsFromJunit($nonserialPath);
$serial = nativeFilamentBaselineRowsFromJunit($serialPath);
nativeFilamentCaseParityAssert(count($nonserial) === 677, 'The independent nonserial baseline case count diverged.');
nativeFilamentCaseParityAssert(count($serial) === 28, 'The independent serial baseline case count diverged.');
nativeFilamentCaseParityAssert(array_sum(array_column($nonserial, 'assertions')) === 1016, 'The independent nonserial assertion count diverged.');
nativeFilamentCaseParityAssert(array_sum(array_column($serial, 'assertions')) === 34, 'The independent serial assertion count diverged.');
$baseline = [
    'schema' => 1,
    'corpus' => 'filamentphp/filament',
    'commit' => 'e9348b2e3792088ee877068116b6c1e1559a7df8',
    'runner' => [
        'executable' => 'vendor/bin/pest',
        'package' => 'pestphp/pest',
        'version' => 'v4.7.3',
        'phpunit' => '12.5.29',
        'testbench' => 'v11.1.0',
        'composer_lock_git_blob_sha1' => '485810b09e5fea4a8609b72be78474848cc4ca13',
        'composer_lock_sha256' => 'f03b2f38dff3b91c2b6a09eadf00c7c516db252064304a6ad78f86b3dd9faf32',
        'vendor_mode' => 'independent-upstream-lock',
    ],
    'normalization' => 'source path + Pest declaration + canonical dataset key; display labels are runner-specific; exact status/assertions/stdout/stderr',
    'cohorts' => [
        'nonserial' => [
            'cases' => count($nonserial),
            'assertions' => array_sum(array_column($nonserial, 'assertions')),
            'semantic_sha256' => nativeFilamentCaseRowsHash($nonserial),
            'rows' => $nonserial,
        ],
        'serial' => [
            'cases' => count($serial),
            'assertions' => array_sum(array_column($serial, 'assertions')),
            'semantic_sha256' => nativeFilamentCaseRowsHash($serial),
            'rows' => $serial,
        ],
    ],
];
$contents = json_encode(
    $baseline,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
nativeFilamentCaseParityAssert(
    file_put_contents($output, $contents, LOCK_EX) === strlen($contents),
    'Could not write the independent Filament baseline.',
);
echo $output, PHP_EOL;
