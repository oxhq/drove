<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;

$arguments = $_SERVER['argv'] ?? null;

if (! is_array($arguments)) {
    throw new RuntimeException('DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: command arguments are missing.');
}

$inputRoot = $arguments[1] ?? getcwd();
$root = is_string($inputRoot) ? realpath($inputRoot) : false;
$lockOnly = in_array('--lock-only', $arguments, true);

if (! is_string($root) || ! is_dir($root)) {
    throw new RuntimeException('DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: dependency root is missing.');
}

$readJson = static function (string $path): array {
    $contents = is_file($path) ? file_get_contents($path) : false;

    if (! is_string($contents)) {
        throw new RuntimeException("DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: unreadable $path.");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: $path is not an object.");
    }

    return $decoded;
};

$manifestPath = $root.'/composer.json';
$lockPath = $root.'/composer.lock';
$manifest = $readJson($manifestPath);
$lock = $readJson($lockPath);
$expectedRequire = [
    'php' => '^8.4.1',
    'ext-mbstring' => '*',
    'symfony/process' => '8.1.0',
];
$packages = $lock['packages'] ?? null;
$devPackages = $lock['packages-dev'] ?? null;

if (($manifest['name'] ?? null) !== 'drove/native-pest-corpus-dependencies'
    || ($manifest['require'] ?? null) !== $expectedRequire
    || ($manifest['config']['allow-plugins'] ?? null) !== false
    || ! is_array($packages)
    || ! is_array($devPackages)
    || $devPackages !== []
    || array_column($packages, 'name') !== ['symfony/process']
    || ($packages[0]['version'] ?? null) !== 'v8.1.0'
    || ($packages[0]['source']['reference'] ?? null) !== 'c4a9e58f235a6bf7f97ffbfedae2687353ac79e5') {
    throw new RuntimeException('DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: the dependency lock diverged.');
}

$evidence = [
    'schema' => 1,
    'mode' => $lockOnly ? 'lock-only' : 'installed',
    'manifest_sha256' => hash_file('sha256', $manifestPath),
    'lock_sha256' => hash_file('sha256', $lockPath),
    'packages' => ['symfony/process' => 'v8.1.0'],
    'handoff' => [
        'environment' => 'DROVE_NATIVE_CORPUS_VENDOR',
        'relative_path' => 'vendor',
    ],
    'forbidden_packages' => [],
    'forbidden_vendor_paths' => [],
    'forbidden_autoload_prefixes' => [],
    'forbidden_autoload_files' => [],
    'forbidden_binaries' => [],
    'forbidden_runtime_symbols' => [],
    'forbidden_runtime_files' => [],
];

if ($lockOnly) {
    echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

    exit(0);
}

$vendor = $root.'/vendor';
$composer = $vendor.'/composer';

foreach (['autoload.php', 'composer/ClassLoader.php', 'composer/autoload_psr4.php', 'composer/autoload_classmap.php', 'composer/installed.php'] as $relative) {
    if (! is_file($vendor.'/'.$relative)) {
        throw new RuntimeException("DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: vendor/$relative is missing.");
    }
}

$installed = require $composer.'/installed.php';
$installedVersions = is_array($installed) ? ($installed['versions'] ?? null) : null;
$installedPackages = is_array($installedVersions) ? array_keys($installedVersions) : [];
sort($installedPackages, SORT_STRING);

if ($installedPackages !== ['drove/native-pest-corpus-dependencies', 'symfony/process']) {
    throw new RuntimeException('DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: installed packages diverged.');
}

$forbiddenPackagePrefixes = ['brianium/', 'nunomaduro/', 'orchestra/', 'paratestphp/', 'pestphp/', 'phpunit/'];
$evidence['forbidden_packages'] = array_values(array_filter(
    $installedPackages,
    static fn (string $package): bool => array_any(
        $forbiddenPackagePrefixes,
        static fn (string $prefix): bool => str_starts_with($package, $prefix),
    ),
));
$evidence['forbidden_vendor_paths'] = array_values(array_filter(
    $forbiddenPackagePrefixes,
    static fn (string $prefix): bool => is_dir($vendor.'/'.rtrim($prefix, '/')),
));

$psr4 = require $composer.'/autoload_psr4.php';
$classmap = require $composer.'/autoload_classmap.php';
$files = is_file($composer.'/autoload_files.php') ? require $composer.'/autoload_files.php' : [];

if (! is_array($psr4) || ! is_array($classmap) || ! is_array($files)) {
    throw new RuntimeException('DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: Composer autoload metadata is malformed.');
}

$forbiddenNamespacePrefixes = ['Drove\\Bridge\\', 'Drove\\Pest\\', 'Orchestra\\Testbench\\', 'Pest\\', 'PHPUnit\\'];
$forbiddenAutoloadPrefixes = [];

foreach (array_keys($psr4) as $prefix) {
    if (! is_string($prefix)) {
        throw new RuntimeException('DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: Composer PSR-4 metadata is malformed.');
    }

    if (array_any(
        $forbiddenNamespacePrefixes,
        static fn (string $forbidden): bool => str_starts_with($prefix, $forbidden),
    )) {
        $forbiddenAutoloadPrefixes[] = $prefix;
    }
}

$evidence['forbidden_autoload_prefixes'] = $forbiddenAutoloadPrefixes;
$forbiddenPathFragments = ['/brianium/', '/nunomaduro/', '/orchestra/', '/paratestphp/', '/pestphp/', '/phpunit/'];
$autoloadPaths = [
    ...array_values($classmap),
    ...array_values($files),
    ...array_merge(...array_values($psr4)),
];
$evidence['forbidden_autoload_files'] = array_values(array_filter(
    array_map(static fn (string $path): string => str_replace('\\', '/', $path), $autoloadPaths),
    static fn (string $path): bool => array_any(
        $forbiddenPathFragments,
        static fn (string $fragment): bool => str_contains(strtolower($path), $fragment),
    ),
));

$binaries = is_dir($vendor.'/bin')
    ? array_values(array_diff(scandir($vendor.'/bin') ?: [], ['.', '..']))
    : [];
sort($binaries, SORT_STRING);
$evidence['forbidden_binaries'] = array_values(array_filter(
    $binaries,
    static fn (string $binary): bool => preg_match('/(?:pest|phpunit|testbench|paratest)/i', $binary) === 1,
));

require $vendor.'/autoload.php';

if (! class_exists(Process::class)) {
    throw new RuntimeException('DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: Symfony Process is not loadable.');
}

$declaredSymbols = [
    ...get_declared_classes(),
    ...get_declared_interfaces(),
    ...get_declared_traits(),
];
$evidence['forbidden_runtime_symbols'] = array_values(array_filter(
    $declaredSymbols,
    static fn (string $symbol): bool => array_any(
        $forbiddenNamespacePrefixes,
        static fn (string $prefix): bool => str_starts_with($symbol, $prefix),
    ),
));
$evidence['forbidden_runtime_files'] = array_values(array_filter(
    array_map(static fn (string $path): string => str_replace('\\', '/', $path), get_included_files()),
    static fn (string $path): bool => str_contains(strtolower($path), '/src/drove/bridge/')
        || array_any(
            $forbiddenPathFragments,
            static fn (string $fragment): bool => str_contains(strtolower($path), $fragment),
        ),
));

foreach ([
    'forbidden_packages',
    'forbidden_vendor_paths',
    'forbidden_autoload_prefixes',
    'forbidden_autoload_files',
    'forbidden_binaries',
    'forbidden_runtime_symbols',
    'forbidden_runtime_files',
] as $field) {
    if ($evidence[$field] !== []) {
        throw new RuntimeException("DROVE_NATIVE_PEST_ENVIRONMENT_INVALID: $field is not empty.");
    }
}

$vendorMetadata = [$composer.'/installed.php', $composer.'/autoload_psr4.php', $composer.'/autoload_classmap.php'];

if (is_file($composer.'/autoload_files.php')) {
    $vendorMetadata[] = $composer.'/autoload_files.php';
}

$evidence['vendor_sha256'] = hash('sha256', implode("\n", array_map(
    static fn (string $path): string => basename($path).' '.hash_file('sha256', $path),
    $vendorMetadata,
)));

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
