<?php

declare(strict_types=1);

$pest = realpath($argv[1] ?? '');
$junit = realpath($argv[2] ?? '');
$output = $argv[3] ?? __DIR__.'/baseline.json';

if (! is_string($pest) || ! is_dir($pest) || ! is_string($junit) || ! is_file($junit)) {
    throw new RuntimeException('Usage: generate-baseline.php <pest-root> <junit.xml> [output.json]');
}

$paths = require __DIR__.'/cohort.php';
require_once dirname(__DIR__, 2).'/benchmarks/corpus/PestDatasetIdentity.php';
$document = new DOMDocument;

if (! $document->load($junit)) {
    throw new RuntimeException('The Pest JUnit baseline is invalid.');
}

$xpath = new DOMXPath($document);
$nodes = $xpath->query('//testcase');

if ($nodes === false) {
    throw new RuntimeException('The Pest JUnit baseline has no cases.');
}

$cases = [];
$files = [];
$datasetOrdinals = [];

foreach ($nodes as $node) {
    if (! $node instanceof DOMElement) {
        continue;
    }

    $file = str_replace('\\', '/', explode('::', $node->getAttribute('file'), 2)[0]);

    if (! in_array($file, $paths, true)) {
        continue;
    }

    $name = $node->getAttribute('name');
    $scopes = [];

    while (preg_match('/^`([^`]+)` → (.+)$/uD', $name, $match) === 1) {
        $scopes[] = $match[1];
        $name = $match[2];
    }

    $identity = NativePestDatasetIdentity::split($name);
    $declaration = $identity['declaration'];
    $prefix = 'test:file:'.$file.'::';

    foreach ($scopes as $scope) {
        $prefix .= 'describe:'.rawurlencode($scope).'::';
    }

    $prefix .= rawurlencode($declaration);

    if ($identity['suffix'] !== null) {
        $key = $file."\0".implode("\0", $scopes)."\0".$declaration;
        $ordinal = $datasetOrdinals[$key] ?? 0;
        $datasetKey = NativePestDatasetIdentity::key($identity['suffix'], $ordinal);

        if (str_starts_with((string) $datasetKey, 'index:')) {
            $datasetOrdinals[$key] = $ordinal + 1;
        }

        $prefix .= '::dataset:'.$datasetKey;
    }

    if ($node->getElementsByTagName('failure')->length > 0
        || $node->getElementsByTagName('error')->length > 0) {
        throw new RuntimeException('The independent Pest baseline contains a failing case: '.$name);
    }

    $status = $node->getElementsByTagName('skipped')->length > 0 ? 'skipped' : 'passed';
    $assertions = (int) $node->getAttribute('assertions');
    $stdoutNode = $node->getElementsByTagName('system-out')->item(0);
    $stderrNode = $node->getElementsByTagName('system-err')->item(0);
    $cases[] = [
        'id' => $prefix,
        'status' => $status,
        'assertions' => $assertions,
        'stdout' => $stdoutNode instanceof DOMElement ? $stdoutNode->textContent : '',
        'stderr' => $stderrNode instanceof DOMElement ? $stderrNode->textContent : '',
    ];
    $files[$file]['tests'] = ($files[$file]['tests'] ?? 0) + 1;
    $files[$file][$status] = ($files[$file][$status] ?? 0) + 1;
    $files[$file]['assertions'] = ($files[$file]['assertions'] ?? 0) + $assertions;
}

if (array_keys($files) !== $paths) {
    throw new RuntimeException('The Pest JUnit files diverged from the pinned cohort.');
}

foreach ($files as &$file) {
    $file = [
        'tests' => $file['tests'],
        'passed' => $file['passed'] ?? 0,
        'skipped' => $file['skipped'] ?? 0,
        'assertions' => $file['assertions'],
    ];
}

unset($file);
usort($cases, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

if (count(array_column($cases, 'id')) !== count(array_unique(array_column($cases, 'id')))) {
    throw new RuntimeException('The Pest JUnit baseline contains duplicate canonical case identities.');
}

$git = static function (string $path) use ($pest): string {
    $process = proc_open(
        ['git', '-C', $pest, 'show', 'HEAD:'.$path],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        options: ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Git could not read the pinned Pest source.');
    }

    fclose($pipes[0]);
    $source = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0 || ! is_string($source)) {
        throw new RuntimeException('Git could not read '.$path.': '.trim((string) $error));
    }

    return $source;
};

$sources = [];

foreach ($paths as $path) {
    $sources[$path] = hash('sha256', $git($path));
}

$baseline = [
    'schema' => 2,
    'runner' => 'Pest 5.0.1',
    'commit' => trim((string) shell_exec('git -C '.escapeshellarg($pest).' rev-parse HEAD')),
    'command' => 'php bin/pest --configuration=phpunit.xml --log-junit=baseline.xml '.implode(' ', $paths),
    'normalization' => 'source path, nested describe scopes, declaration name, typed dataset key, status, assertion count, stdout, and stderr',
    'dependency_lock_sha256' => hash_file(
        'sha256',
        dirname(__DIR__, 2).'/benchmarks/corpus/locks/pest-baseline.lock',
    ),
    'tests' => array_sum(array_column($files, 'tests')),
    'passed' => array_sum(array_column($files, 'passed')),
    'skipped' => array_sum(array_column($files, 'skipped')),
    'assertions' => array_sum(array_column($files, 'assertions')),
    'exit_code' => 0,
    'files' => $files,
    'sources' => $sources,
    'cases' => $cases,
];
$encoded = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

if (file_put_contents($output, $encoded) !== strlen($encoded)) {
    throw new RuntimeException('The normalized Pest baseline could not be written.');
}
