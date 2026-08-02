<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $argv[1] ?? $root.'/benchmarks/corpus/native-surface-inventory.json';
$contents = is_file($path) ? file_get_contents($path) : false;

if (! is_string($contents)) {
    throw new RuntimeException('DROVE_NATIVE_INVENTORY_MISSING');
}

$inventory = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode(
    (string) file_get_contents($root.'/benchmarks/corpus/manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

if (($inventory['schema_version'] ?? null) !== 1
    || ! is_array($inventory['corpora'] ?? null)
    || isset($inventory['critical_gaps'])
    || ! is_string($inventory['raw_source_migration_surfaces_definition'] ?? null)
    || ! is_array($inventory['raw_source_migration_surfaces'] ?? null)
    || ! is_array($manifest['corpora'] ?? null)) {
    throw new RuntimeException('DROVE_NATIVE_INVENTORY_SCHEMA');
}

$expectedIds = ['pest', 'invoiceshelf', 'livewire', 'filament'];
$manifestIds = array_column($manifest['corpora'], 'id');
$inventoryIds = array_column($inventory['corpora'], 'id');

if ($manifestIds !== $expectedIds || $inventoryIds !== $expectedIds) {
    throw new RuntimeException('DROVE_NATIVE_INVENTORY_CORPUS_ORDER');
}

$manifestById = [];

foreach ($manifest['corpora'] as $corpus) {
    $manifestById[$corpus['id']] = $corpus;
}

$totals = ['files' => 0, 'cases' => 0, 'assertions' => 0];

foreach ($inventory['corpora'] as $corpus) {
    $id = $corpus['id'] ?? null;
    $selection = $corpus['selection'] ?? null;
    $readiness = $corpus['migration_readiness'] ?? null;
    $manifestCorpus = is_string($id) ? ($manifestById[$id] ?? null) : null;

    if (! is_array($selection)
        || ! is_array($readiness)
        || ! is_array($manifestCorpus)
        || ! is_array($selection['paths'] ?? null)
        || ! is_array($readiness['selected_file_counts'] ?? null)
        || ! is_array($readiness['blocker_free_paths'] ?? null)) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_CORPUS_SHAPE');
    }

    if (($corpus['repository'] ?? null) !== $manifestCorpus['repository']
        || ($corpus['commit'] ?? null) !== $manifestCorpus['commit']
        || ($corpus['selector'] ?? null) !== $manifestCorpus['selection']['selector']) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_CORPUS_IDENTITY');
    }

    $counts = $readiness['selected_file_counts'];
    $paths = $selection['paths'];
    $free = $readiness['blocker_free_paths'];
    $sortedPaths = $paths;
    $sortedFree = $free;
    sort($sortedPaths, SORT_STRING);
    sort($sortedFree, SORT_STRING);

    if (count($paths) !== $selection['file_count']
        || count(array_unique($paths)) !== count($paths)
        || $paths !== $sortedPaths
        || $counts['total'] !== $selection['file_count']
        || $counts['total'] !== $counts['blocker_free'] + $counts['blocked']
        || count($free) !== $counts['blocker_free']
        || count(array_unique($free)) !== count($free)
        || $free !== $sortedFree
        || array_diff($free, $paths) !== []) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_READINESS_COUNTS');
    }

    if (preg_match('/^[a-f0-9]{64}$/D', (string) ($selection['sha256'] ?? '')) !== 1) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_SELECTION_HASH');
    }

    foreach (['file_count' => 'files', 'case_count' => 'cases', 'assertion_count' => 'assertions'] as $field => $total) {
        if (! is_int($selection[$field] ?? null)) {
            throw new RuntimeException('DROVE_NATIVE_INVENTORY_SELECTION_COUNTS');
        }

        $totals[$total] += $selection[$field];
    }

    $manifestSelection = $manifestCorpus['selection'];
    $manifestCases = $manifestSelection['cases'] ?? $manifestSelection['total_cases'] ?? null;
    $manifestAssertions = $manifestCorpus['baseline']['assertions']
        ?? $manifestCorpus['selection']['total_assertions']
        ?? null;

    if ($selection['file_count'] !== $manifestSelection['files']
        || $selection['case_count'] !== $manifestCases
        || $selection['assertion_count'] !== $manifestAssertions) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_MANIFEST_DIVERGENCE');
    }

    $surfaceCounts = ['native-supported' => 0, 'pending' => 0, 'rejected' => 0];
    $surfaces = $corpus['surfaces'] ?? null;

    if (! is_array($surfaces)) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_SURFACE_SHAPE');
    }

    $surfaceIds = array_column($surfaces, 'id');
    $sortedSurfaceIds = $surfaceIds;
    sort($sortedSurfaceIds, SORT_STRING);

    if ($surfaceIds !== $sortedSurfaceIds
        || count(array_unique($surfaceIds)) !== count($surfaceIds)) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_SURFACE_SHAPE');
    }

    foreach ($surfaces as $surface) {
        $status = $surface['status'] ?? null;

        if (! is_string($status)
            || ! array_key_exists($status, $surfaceCounts)
            || ! is_string($surface['id'] ?? null)
            || ($surface['id'] ?? '') === ''
            || ! is_int($surface['occurrences'] ?? null)
            || $surface['occurrences'] < 1
            || ! is_int($surface['file_count'] ?? null)
            || ! is_int($surface['selected_file_count'] ?? null)
            || ! is_int($surface['support_file_count'] ?? null)
            || $surface['file_count'] < 1
            || $surface['selected_file_count'] < 0
            || $surface['support_file_count'] < 0
            || $surface['file_count'] > $surface['selected_file_count'] + $surface['support_file_count']) {
            throw new RuntimeException('DROVE_NATIVE_INVENTORY_SURFACE_SHAPE');
        }

        $surfaceCounts[$status]++;
    }

    if (($corpus['surface_summary'] ?? null) !== [
        'construct_count' => count($surfaces),
        'by_status' => $surfaceCounts,
    ]) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_SURFACE_COUNTS');
    }

    if ($id === 'filament') {
        $snapshot = null;

        foreach ($corpus['surfaces'] ?? [] as $surface) {
            if (($surface['id'] ?? null) === 'pest.expectation.method.toMatchSnapshot') {
                $snapshot = $surface;
                break;
            }
        }

        if ($counts !== [
            'total' => 39,
            'blocker_free' => 39,
            'blocked' => 0,
            'changed_by_codemod' => 39,
            'unchanged_by_codemod' => 0,
        ]
            || ($readiness['blocker_occurrence_count'] ?? null) !== 0
            || ($snapshot['status'] ?? null) !== 'native-supported'
            || ($snapshot['occurrences'] ?? null) !== 5
            || ($snapshot['selected_file_count'] ?? null) !== 1
            || ($snapshot['native_target'] ?? null) !== 'corpus/filament::snapshot via typed-matcher-map-v1') {
            throw new RuntimeException('DROVE_NATIVE_INVENTORY_FILAMENT_PROFILE');
        }
    }
}

$rawSurfaces = $inventory['raw_source_migration_surfaces'];
$expectedRawSurfaces = [
    [1, 'pest-expectation-and-higher-order-parity', 'pest'],
    [2, 'phpunit-instance-api-and-testbench-removal', 'livewire'],
    [3, 'laravel-context-and-refresh-database-migration', 'invoiceshelf'],
    [4, 'filament-testbench-uses-and-remaining-expectations', 'filament'],
    [5, 'selected-rejection-conflicts', 'all'],
];

foreach ($expectedRawSurfaces as $index => [$order, $id, $corpus]) {
    $surface = $rawSurfaces[$index] ?? null;
    $evidence = is_array($surface) ? ($surface['evidence'] ?? null) : null;

    if (! is_array($surface)
        || ($surface['order'] ?? null) !== $order
        || ($surface['id'] ?? null) !== $id
        || ($surface['corpus'] ?? null) !== $corpus
        || ! is_array($evidence)
        || ! is_int($evidence['constructs'] ?? null)
        || $evidence['constructs'] < 0
        || ! is_string($surface['proof_target'] ?? null)
        || $surface['proof_target'] === '') {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_RAW_SURFACE_SHAPE');
    }

    if ($corpus !== 'all'
        && (! is_int($evidence['occurrences'] ?? null)
            || $evidence['occurrences'] < 0
            || ! is_int($evidence['files'] ?? null)
            || $evidence['files'] < 0)) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_RAW_SURFACE_SHAPE');
    }
}

if (count($rawSurfaces) !== count($expectedRawSurfaces)) {
    throw new RuntimeException('DROVE_NATIVE_INVENTORY_RAW_SURFACE_SHAPE');
}

$contract = $inventory['source_contract'] ?? null;
$fixedSources = [
    'manifest' => 'benchmarks/corpus/manifest.json',
    'generator' => 'benchmarks/corpus/generate-native-surface-inventory.php',
    'verifier' => 'benchmarks/corpus/verify-native-surface-inventory.php',
    'selector' => 'benchmarks/corpus/select.sh',
    'native_surface' => 'src/Drove/Native/Surface/supported-surface.json',
    'livewire_class_profile' => 'experiments/phase-3-laravel/native-package/livewire-class-profile.php',
    'filament_profile' => 'experiments/native-filament-corpus/profile.php',
];
$analyzerFiles = glob($root.'/src/Drove/Migration/*.php');

if (! is_array($contract) || ! is_array($analyzerFiles) || $analyzerFiles === []) {
    throw new RuntimeException('DROVE_NATIVE_INVENTORY_SOURCE_CONTRACT');
}

$expectedAnalyzerPaths = [
    'benchmarks/corpus/generate-native-surface-inventory.php',
    'benchmarks/corpus/verify-native-surface-inventory.php',
    'experiments/native-corpus-1/codemod-options.php',
    'experiments/native-corpus-1/PestCorpusCodemod.php',
    'experiments/native-invoiceshelf-corpus/codemod-options.php',
    'experiments/native-filament-corpus/profile.php',
    'experiments/phase-3-laravel/native-package/livewire-class-profile.php',
    'resources/drove-bridge-compatibility.json',
    'src/Drove/Native/Surface/SupportedSurface.php',
];
$prefix = str_replace('\\', '/', $root).'/';

foreach ($analyzerFiles as $file) {
    $normalized = str_replace('\\', '/', $file);

    if (! str_starts_with($normalized, $prefix)) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_SOURCE_CONTRACT');
    }

    $expectedAnalyzerPaths[] = substr($normalized, strlen($prefix));
}

$expectedAnalyzerPaths = array_values(array_unique($expectedAnalyzerPaths));
sort($expectedAnalyzerPaths, SORT_STRING);
$analyzers = $contract['migration_analyzers'] ?? null;

if (($contract['manifest_schema_version'] ?? null) !== $manifest['schema_version']
    || ! is_array($analyzers)
    || array_column($analyzers, 'path') !== $expectedAnalyzerPaths) {
    throw new RuntimeException('DROVE_NATIVE_INVENTORY_SOURCE_CONTRACT');
}

$tracked = $analyzers;

foreach ($fixedSources as $field => $sourcePath) {
    if (($contract[$field.'_path'] ?? null) !== $sourcePath) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_SOURCE_CONTRACT');
    }

    $tracked[] = [
        'path' => $sourcePath,
        'sha256' => $contract[$field.'_sha256'] ?? null,
    ];
}

foreach ($tracked as $source) {
    $sourcePath = $source['path'] ?? null;
    $expected = $source['sha256'] ?? null;
    $actual = is_string($sourcePath) && is_file($root.'/'.$sourcePath)
        ? hash_file('sha256', $root.'/'.$sourcePath)
        : false;

    if (! is_string($expected) || ! is_string($actual) || ! hash_equals($expected, $actual)) {
        throw new RuntimeException('DROVE_NATIVE_INVENTORY_STALE:'.(string) $sourcePath);
    }
}

if (preg_match('~(?:[A-Za-z]:\\\\|/home/|/Users/)~', $contents) === 1) {
    throw new RuntimeException('DROVE_NATIVE_INVENTORY_LOCAL_PATH');
}

echo json_encode([
    'schema' => 1,
    'corpora' => array_column($inventory['corpora'], 'id'),
    'totals' => $totals,
    'sha256' => hash('sha256', $contents),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
