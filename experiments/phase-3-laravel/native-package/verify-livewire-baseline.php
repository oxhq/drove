<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

function baselineAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function baselineCaseId(DOMElement $case): string
{
    $path = str_replace('\\', '/', $case->getAttribute('file'));
    $offset = strpos($path, '/src/');
    baselineAssert($offset !== false, 'The PHPUnit case does not identify a Livewire source file.');
    $source = substr($path, $offset + 1);
    $name = $case->getAttribute('name');
    $dataset = '';

    if (preg_match('/^(.*) with data set #(\d+)$/sD', $name, $match) === 1) {
        $name = $match[1];
        $dataset = '::dataset:index:'.$match[2];
    } elseif (preg_match('/^(.*) with data set "(.*)"$/sD', $name, $match) === 1) {
        $name = $match[1];
        $dataset = '::dataset:name:'.rawurlencode($match[2]);
    }

    return 'test:'.$source.'::'.rawurlencode($name).$dataset;
}

function baselineCaseStream(DOMElement $case, string $element): string
{
    $nodes = $case->getElementsByTagName($element);
    baselineAssert($nodes->length <= 1, 'The independent baseline emitted duplicate '.$element.' nodes.');
    $node = $nodes->item(0);

    return $node instanceof DOMElement ? $node->textContent : '';
}

try {
    $arguments = $_SERVER['argv'] ?? [];
    $project = $arguments[1] ?? null;
    $junit = $arguments[2] ?? null;
    $expectedPath = $arguments[3] ?? null;

    if (! is_string($project) || ! is_dir($project)) {
        throw new RuntimeException('The independent Livewire project is unavailable.');
    }

    if (! is_string($junit) || ! is_file($junit)) {
        throw new RuntimeException('The independent Livewire JUnit artifact is unavailable.');
    }

    if (! is_string($expectedPath) || ! is_file($expectedPath)) {
        throw new RuntimeException('The native Livewire baseline is unavailable.');
    }

    require $project.'/vendor/autoload.php';

    $packages = InstalledVersions::getInstalledPackages();
    $forbidden = array_values(array_filter(
        $packages,
        static fn (string $package): bool => str_starts_with($package, 'oxhq/')
            || str_starts_with($package, 'pestphp/'),
    ));
    baselineAssert($forbidden === [], 'The independent baseline installed Drove or Pest packages.');
    baselineAssert(InstalledVersions::isInstalled('phpunit/phpunit'), 'The independent baseline did not install PHPUnit.');
    baselineAssert(InstalledVersions::isInstalled('orchestra/testbench'), 'The independent baseline did not install Testbench.');
    baselineAssert(class_exists(TestCase::class), 'The independent PHPUnit runtime is not loadable.');
    baselineAssert(class_exists(Orchestra\Testbench\TestCase::class), 'The independent Testbench runtime is not loadable.');

    $expected = json_decode(
        (string) file_get_contents($expectedPath),
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    baselineAssert(
        is_array($expected)
            && ($expected['schema'] ?? null) === 2
            && is_array($expected['cases'] ?? null),
        'The native Livewire case baseline is invalid.',
    );
    $sourceMtimeEpoch = $expected['staging_source_mtime_epoch'] ?? null;
    $sourceFiles = $expected['files'] ?? null;
    $productionHelpers = $expected['production_helpers'] ?? null;
    $supportFiles = $expected['support_files'] ?? null;
    baselineAssert(
        is_int($sourceMtimeEpoch)
            && is_array($sourceFiles)
            && is_array($productionHelpers)
            && is_array($supportFiles),
        'The independent baseline staging-clock manifest is invalid.',
    );
    $clockPaths = array_values(array_unique([
        ...array_keys($sourceFiles),
        ...array_keys($productionHelpers),
        ...array_keys($supportFiles),
    ]));
    baselineAssert(count($clockPaths) === 51, 'The independent baseline staging-clock file set drifted.');

    foreach ($clockPaths as $path) {
        baselineAssert(is_string($path), 'The independent baseline staging-clock path is invalid.');
        $source = $project.'/'.$path;
        clearstatcache(true, $source);
        baselineAssert(
            filemtime($source) === $sourceMtimeEpoch,
            'The independent baseline staging clock drifted for '.$path.'.',
        );
    }

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->load($junit, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    baselineAssert($loaded, 'The independent PHPUnit JUnit artifact is invalid.');

    $cases = [];
    $assertions = 0;

    foreach ($document->getElementsByTagName('testcase') as $case) {
        $id = baselineCaseId($case);
        baselineAssert(! isset($cases[$id]), 'The independent baseline emitted a duplicate case ID.');
        $failed = $case->getElementsByTagName('failure')->length > 0
            || $case->getElementsByTagName('error')->length > 0;
        $incomplete = $case->getElementsByTagName('skipped')->length > 0;
        $cases[$id] = [
            'id' => $id,
            'status' => $failed ? 'failed' : ($incomplete ? 'incomplete' : 'passed'),
            'assertions' => (int) $case->getAttribute('assertions'),
            'stdout' => baselineCaseStream($case, 'system-out'),
            'stderr' => baselineCaseStream($case, 'system-err'),
        ];
        $assertions += $cases[$id]['assertions'];
    }

    ksort($cases, SORT_STRING);
    $expectedCases = $expected['cases'];
    usort($expectedCases, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
    baselineAssert(
        array_values($cases) === $expectedCases,
        'The independent PHPUnit case rows diverged from the committed baseline.',
    );
    baselineAssert($assertions === 1034, 'The independent PHPUnit assertion count diverged.');
    $statusCounts = array_count_values(array_column($cases, 'status'));
    baselineAssert(
        ($statusCounts['passed'] ?? null) === 285
            && ($statusCounts['incomplete'] ?? null) === 3
            && count($statusCounts) === 2,
        'The independent PHPUnit status counts diverged.',
    );

    $runtimeFiles = array_values(array_filter(
        array_map(static fn (string $path): string => str_replace('\\', '/', $path), get_included_files()),
        static fn (string $path): bool => str_contains(strtolower($path), '/vendor/oxhq/')
            || str_contains(strtolower($path), '/vendor/pestphp/'),
    ));
    baselineAssert($runtimeFiles === [], 'The independent baseline loaded Drove or Pest runtime files.');

    echo json_encode([
        'ok' => true,
        'corpus' => 'livewire/livewire',
        'runner' => 'phpunit',
        'runtime' => 'orchestra/testbench',
        'vendor_mode' => 'independent-ephemeral',
        'phpunit' => InstalledVersions::getPrettyVersion('phpunit/phpunit'),
        'testbench' => InstalledVersions::getPrettyVersion('orchestra/testbench'),
        'staging_source_mtime_epoch' => $sourceMtimeEpoch,
        'cases' => count($cases),
        'passed' => 285,
        'incomplete' => 3,
        'assertions' => $assertions,
        'case_rows_sha256' => hash('sha256', json_encode(array_values($cases), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'forbidden_packages' => [],
        'forbidden_runtime_files' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, 'DROVE_NATIVE_LIVEWIRE_BASELINE_FAILED '.$throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
