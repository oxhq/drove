<?php

declare(strict_types=1);

require __DIR__.'/CaseSemantics.php';

$checkout = realpath($argv[1] ?? '');
$junit = realpath($argv[2] ?? '');
$output = $argv[3] ?? null;

if (! is_string($checkout) || ! is_dir($checkout) || ! is_string($junit) || ! is_file($junit)) {
    throw new RuntimeException('Usage: normalize-baseline.php <checkout> <junit.xml> [output.json]');
}

$baselineSource = file_get_contents(__DIR__.'/baseline.json');
$baseline = is_string($baselineSource)
    ? json_decode($baselineSource, true, 16, JSON_THROW_ON_ERROR)
    : null;
$files = is_array($baseline) ? ($baseline['files'] ?? null) : null;

if (! is_array($files)
    || ! array_is_list($files)
    || array_filter($files, static fn (mixed $file): bool => ! is_string($file)) !== []) {
    throw new RuntimeException('The InvoiceShelf file baseline is invalid.');
}

$document = new DOMDocument;

if (! $document->load($junit)) {
    throw new RuntimeException('The InvoiceShelf JUnit baseline is invalid.');
}

$cases = NativeInvoiceShelfCaseSemantics::fromJunit($document, $files);
$manifest = $checkout.'/composer.json';
$lock = $checkout.'/composer.lock';
$commit = trim((string) shell_exec('git -C '.escapeshellarg($checkout).' rev-parse HEAD'));
$result = [
    'schema' => 1,
    'corpus' => 'InvoiceShelf/InvoiceShelf',
    'commit' => $commit,
    'runner' => [
        'pest' => 'v4.4.3',
        'phpunit' => '12.5.14',
    ],
    'command' => 'php vendor/bin/pest --configuration=phpunit.xml --colors=never --log-junit=invoiceshelf-pest-baseline.xml '.implode(' ', $files),
    'manifest_sha256' => hash_file('sha256', $manifest),
    'lock_sha256' => hash_file('sha256', $lock),
    'normalization' => 'source path, declaration name, typed dataset key, status, assertion count, stdout, stderr',
    'tests' => count($cases),
    'passed' => count(array_filter($cases, static fn (array $case): bool => $case['status'] === 'passed')),
    'assertions' => array_sum(array_column($cases, 'assertions')),
    'case_semantics_sha256' => hash('sha256', json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
    'cases' => $cases,
];
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

if (is_string($output) && $output !== '') {
    if (file_put_contents($output, $json) !== strlen($json)) {
        throw new RuntimeException('Could not write the normalized InvoiceShelf baseline.');
    }
} else {
    echo $json;
}
