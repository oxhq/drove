<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once __DIR__.'/PestDatasetIdentity.php';

$datasetIdentityCases = [
    ['plain test', ['declaration' => 'plain test', 'suffix' => null], null],
    [
        'plain test with data set "(\'value\')"',
        ['declaration' => 'plain test', 'suffix' => '"(\'value\')"'],
        'index:3',
    ],
    [
        'plain test with data set "dataset "named key""',
        ['declaration' => 'plain test', 'suffix' => '"dataset "named key""'],
        'name:named%20key',
    ],
    [
        'plain test with data set "dataset "component" / (\'color\')"',
        ['declaration' => 'plain test', 'suffix' => '"dataset "component" / (\'color\')"'],
        'name:dataset%20%22component%22%20%2F%20%28%27color%27%29',
    ],
];

foreach ($datasetIdentityCases as [$name, $expectedSplit, $expectedKey]) {
    $split = NativePestDatasetIdentity::split($name);

    if ($split !== $expectedSplit
        || NativePestDatasetIdentity::key($split['suffix'], 3) !== $expectedKey) {
        throw new RuntimeException('DROVE_NATIVE_DATASET_IDENTITY_DIVERGED');
    }
}

$workflowPath = $root.'/.github/workflows/corpus.yml';
$workflow = file_get_contents($workflowPath);

if (! is_string($workflow)) {
    throw new RuntimeException('DROVE_NATIVE_LADDER_WORKFLOW_MISSING');
}

$forbiddenWorkflowReferences = [
    '--pest',
    'bin/pest',
    'vendor/bin/phpunit',
    'experiments/native-phase-6-bridges',
    'src/drove/bridge',
    'src/drove/pest',
    'testbenchbridge',
    'testsuitebuilder',
    'runbare',
    'setuptheenvironment',
    'setupthetestenvironment',
    'setupparalleltestingcallbacks',
];
$workflowNormalized = str_replace(["\r\n", "\r"], "\n", $workflow);
$workflowLower = strtolower($workflowNormalized);
$revisionBinding = 'DROVE_EXPECTED_REVISION: ${{ env.EXPECTED_SHA }}';
$workflowStep = static function (string $contents, int $indent, string $name): string {
    $prefix = str_repeat(' ', $indent).'- name: ';
    $marker = $prefix.$name."\n";

    if (substr_count($contents, $marker) !== 1) {
        throw new RuntimeException('DROVE_NATIVE_WORKFLOW_STEP_MISSING:'.$name);
    }

    $start = strpos($contents, $marker);

    if (! is_int($start)) {
        throw new RuntimeException('DROVE_NATIVE_WORKFLOW_STEP_MISSING:'.$name);
    }

    $next = strpos($contents, "\n".$prefix, $start + strlen($marker));

    return substr($contents, $start, is_int($next) ? $next - $start : null);
};

foreach ([
    'Run bridge-free Pest matrix',
    'Run bridge-free InvoiceShelf gate',
    'Run bridge-free Livewire gate',
    'Run final bridge-free Filament gate',
] as $step) {
    if (substr_count($workflowStep($workflowNormalized, 6, $step), $revisionBinding) !== 1) {
        throw new RuntimeException('DROVE_NATIVE_LADDER_REVISION_BINDING_MISSING:'.$step);
    }
}

if (str_contains($workflowStep($workflowNormalized, 6, 'Verify independent Pest baseline'), 'DROVE_EXPECTED_REVISION')) {
    throw new RuntimeException('DROVE_NATIVE_BASELINE_REVISION_BINDING_LEAK');
}

$nativeLaravelWorkflow = file_get_contents($root.'/.github/workflows/native-laravel.yml');

if (! is_string($nativeLaravelWorkflow)) {
    throw new RuntimeException('DROVE_NATIVE_LARAVEL_WORKFLOW_MISSING');
}

$nativeLaravelWorkflow = str_replace(["\r\n", "\r"], "\n", $nativeLaravelWorkflow);
$nativeLaravelGate = $workflowStep($nativeLaravelWorkflow, 4, 'Bridge-free pinned Livewire gate');

if (substr_count(
    $nativeLaravelGate,
    'DROVE_EXPECTED_REVISION: ${{ github.event.pull_request.head.sha || github.sha }}',
) !== 1 || str_contains($nativeLaravelGate, 'DROVE_NATIVE_LIVEWIRE_SKIP_BUILD=1')) {
    throw new RuntimeException('DROVE_NATIVE_LARAVEL_REVISION_BINDING_MISSING');
}

$foundWorkflowReferences = array_values(array_filter(
    $forbiddenWorkflowReferences,
    static fn (string $reference): bool => str_contains($workflowLower, $reference),
));

if ($foundWorkflowReferences !== []) {
    throw new RuntimeException(
        'DROVE_NATIVE_LADDER_BRIDGE_REFERENCE:'.implode(',', $foundWorkflowReferences),
    );
}

$requiredWorkflowReferences = [
    '6b2cd358e8a9d6d1abb93804b70e1c659bbc411b',
    '403a4d67225a153838ec126c484339abf60229d1',
    '9c1450739d30c9b0b223ad6512be2a33f8f62f96',
    'e9348b2e3792088ee877068116b6c1e1559a7df8',
    'experiments/native-corpus-1/run.sh',
    'experiments/native-corpus-1/baseline.sh',
    'experiments/native-invoiceshelf-corpus/gate.php',
    'composer test:native-livewire-package',
    'experiments/native-filament-corpus/gate.php',
];
$missingWorkflowReferences = array_values(array_filter(
    $requiredWorkflowReferences,
    static fn (string $reference): bool => ! str_contains($workflowLower, $reference),
));

if ($missingWorkflowReferences !== []) {
    throw new RuntimeException(
        'DROVE_NATIVE_LADDER_REQUIRED_REFERENCE_MISSING:'.implode(',', $missingWorkflowReferences),
    );
}

$orderedJobs = [
    "  pest:\n",
    "  invoiceshelf:\n    name: InvoiceShelf native corpus\n    needs: pest\n",
    "  livewire:\n    name: Livewire native corpus\n    needs: invoiceshelf\n",
    "  filament:\n    name: Filament final native proof\n    needs: livewire\n",
];
$previousPosition = -1;

foreach ($orderedJobs as $job) {
    $position = strpos($workflowNormalized, $job);

    if (! is_int($position) || $position <= $previousPosition) {
        throw new RuntimeException('DROVE_NATIVE_LADDER_JOB_ORDER');
    }

    $previousPosition = $position;
}

$nativeLocks = [
    'filament' => 'benchmarks/corpus/locks/filament-native.lock',
    'invoiceshelf' => 'benchmarks/corpus/locks/invoiceshelf-native.lock',
    'livewire' => 'benchmarks/corpus/locks/livewire-native.lock',
    'pest' => 'benchmarks/corpus/locks/pest-native.lock',
];
$forbiddenPackagePrefixes = [
    'brianium/paratest',
    'nunomaduro/collision',
    'orchestra/',
    'pestphp/',
    'phpunit/',
];
$packageCounts = [];
$forbiddenPackages = [];

foreach ($nativeLocks as $corpus => $relativePath) {
    $contents = file_get_contents($root.'/'.$relativePath);

    if (! is_string($contents)) {
        throw new RuntimeException('DROVE_NATIVE_LADDER_LOCK_MISSING:'.$relativePath);
    }

    $lock = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    $production = $lock['packages'] ?? null;
    $development = $lock['packages-dev'] ?? null;

    if (! is_array($production) || ! is_array($development)) {
        throw new RuntimeException('DROVE_NATIVE_LADDER_LOCK_SHAPE:'.$relativePath);
    }

    $packages = [...$production, ...$development];
    $packageCounts[$corpus] = count($packages);

    if ($packages === []) {
        throw new RuntimeException('DROVE_NATIVE_LADDER_LOCK_EMPTY:'.$relativePath);
    }

    foreach ($packages as $package) {
        $name = is_array($package) ? ($package['name'] ?? null) : null;

        if (! is_string($name)) {
            throw new RuntimeException('DROVE_NATIVE_LADDER_LOCK_PACKAGE_SHAPE:'.$relativePath);
        }

        foreach ($forbiddenPackagePrefixes as $prefix) {
            if ($name === $prefix || str_starts_with($name, $prefix)) {
                $forbiddenPackages[] = $corpus.':'.$name;
            }
        }
    }
}

if ($forbiddenPackages !== []) {
    throw new RuntimeException(
        'DROVE_NATIVE_LADDER_BRIDGE_PACKAGE:'.implode(',', $forbiddenPackages),
    );
}

$baselineLockContents = file_get_contents($root.'/benchmarks/corpus/locks/pest-baseline.lock');

if (! is_string($baselineLockContents)) {
    throw new RuntimeException('DROVE_NATIVE_LADDER_BASELINE_LOCK_MISSING:pest');
}

$baselineLock = json_decode($baselineLockContents, true, 512, JSON_THROW_ON_ERROR);
$baselinePackages = [...($baselineLock['packages'] ?? []), ...($baselineLock['packages-dev'] ?? [])];
$baselinePackageNames = array_column($baselinePackages, 'name');
$baselineDrovePackages = array_values(array_filter(
    $baselinePackageNames,
    static fn (mixed $package): bool => is_string($package) && str_starts_with($package, 'oxhq/drove'),
));

if (! in_array('phpunit/phpunit', $baselinePackageNames, true)
    || ! in_array('pestphp/pest-plugin', $baselinePackageNames, true)
    || $baselineDrovePackages !== []) {
    throw new RuntimeException('DROVE_NATIVE_LADDER_BASELINE_LOCK_INVALID:pest');
}

echo json_encode([
    'schema' => 1,
    'workflow' => '.github/workflows/corpus.yml',
    'native_locks' => $packageCounts,
    'baseline_locks' => ['pest' => count($baselinePackages)],
    'revision_bound_workflow_gates' => 5,
    'missing_workflow_references' => [],
    'forbidden_workflow_references' => [],
    'forbidden_lock_packages' => [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
