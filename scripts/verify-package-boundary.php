<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composerPath = $argv[1] ?? $root.'/composer.json';
$registryPath = $argv[2] ?? $root.'/resources/drove-bridge-compatibility.json';

$readJson = static function (string $path): array {
    $contents = is_file($path) ? file_get_contents($path) : false;

    if (! is_string($contents)) {
        throw new RuntimeException(sprintf('Package boundary input %s is unreadable.', $path));
    }

    $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($value)) {
        throw new RuntimeException(sprintf('Package boundary input %s is not an object.', $path));
    }

    return $value;
};

$composer = $readJson($composerPath);
$registry = $readJson($registryPath);
$boundary = $composer['extra']['drove'] ?? null;
$bridge = is_array($boundary) ? ($boundary['bridge-only'] ?? null) : null;

if (! is_array($boundary)
    || ($boundary['native-entrypoint'] ?? null) !== 'bin/drove'
    || ! is_array($bridge)
    || ($registry['bridges']['pest']['status'] ?? null) !== 'bridge-only') {
    throw new RuntimeException('DROVE_PACKAGE_BOUNDARY_INVALID: native and Pest bridge identities are not explicit.');
}

$unsupportedSurfaces = [
    'pest.architecture' => 'DROVE_MIGRATION_UNSUPPORTED_ARCHITECTURE',
    'pest.mutation-testing' => 'DROVE_COMPATIBILITY_UNSUPPORTED_MUTATION_TESTING',
];

foreach ($unsupportedSurfaces as $surface => $diagnostic) {
    $entry = $registry['surfaces'][$surface] ?? null;

    if (! is_array($entry)
        || ($entry['status'] ?? null) !== 'unsupported'
        || ($entry['diagnostic'] ?? null) !== $diagnostic) {
        throw new RuntimeException(sprintf(
            'DROVE_PACKAGE_BOUNDARY_INVALID: %s lost its stable unsupported diagnostic.',
            $surface,
        ));
    }
}

$expectedBridgeMetadata = [
    'autoload-files' => ['src/Functions.php', 'src/Pest.php'],
    'binaries' => ['bin/drove --pest', 'bin/pest'],
    'composer-plugins' => ['pestphp/pest-plugin'],
    'namespaces' => ['Pest\\'],
    'replaces' => ['pestphp/pest'],
];

if ($bridge !== $expectedBridgeMetadata) {
    throw new RuntimeException('DROVE_PACKAGE_BOUNDARY_INVALID: bridge-only Composer metadata changed.');
}

$require = $composer['require'] ?? [];
$requireDev = $composer['require-dev'] ?? [];
$suggest = $composer['suggest'] ?? [];
$conflict = $composer['conflict'] ?? [];
$autoload = $composer['autoload'] ?? [];
$autoloadDev = $composer['autoload-dev'] ?? [];
$files = $autoload['files'] ?? [];
$namespaces = $autoload['psr-4'] ?? [];
$devFiles = $autoloadDev['files'] ?? [];
$devNamespaces = $autoloadDev['psr-4'] ?? [];
$binaries = $composer['bin'] ?? [];
$replaces = $composer['replace'] ?? [];
$plugins = $composer['extra']['pest']['plugins'] ?? [];
$bridgePackages = [
    'brianium/paratest',
    'nunomaduro/collision',
    'nunomaduro/termwind',
    'pestphp/pest-plugin',
    'phpunit/phpunit',
    'symfony/process',
];

if (! is_array($require)
    || ! is_array($requireDev)
    || ! is_array($suggest)
    || $conflict !== []
    || ! is_array($files)
    || ! is_array($namespaces)
    || ! is_array($devFiles)
    || ! is_array($devNamespaces)
    || ! is_array($binaries)
    || ! is_array($plugins)
    || $files !== ['src/Drove/Native/functions.php']
    || array_key_exists('Pest\\', $namespaces)
    || array_intersect($files, $bridge['autoload-files']) !== []
    || array_values(array_intersect($devFiles, $bridge['autoload-files'])) !== $bridge['autoload-files']
    || ! array_key_exists('Pest\\', $devNamespaces)
    || ! in_array('bin/drove', $binaries, true)
    || ! in_array('bin/pest', $binaries, true)
    || $replaces !== ['pestphp/pest' => '5.0.1']
    || array_any(
        array_keys($require),
        static fn (mixed $package): bool => is_string($package)
            && str_starts_with($package, 'pestphp/'),
    )
    || array_intersect(array_keys($require), $bridgePackages) !== []
    || array_diff($bridgePackages, array_keys($requireDev)) !== []
    || ($requireDev['phpunit/phpunit'] ?? null) !== '13.2.4'
    || array_diff($bridgePackages, array_keys($suggest)) !== []) {
    throw new RuntimeException('DROVE_PACKAGE_BOUNDARY_INVALID: Composer entrypoints no longer match their declared roles.');
}

$unsupportedPlugins = [
    'pestphp/pest-plugin-arch',
    'pestphp/pest-plugin-mutate',
    'pestphp/pest-plugin-profanity',
];

foreach ($unsupportedPlugins as $package) {
    if (array_key_exists($package, $require) || ! array_key_exists($package, $requireDev)) {
        throw new RuntimeException(sprintf(
            'DROVE_PACKAGE_BOUNDARY_INVALID: %s must remain development-only.',
            $package,
        ));
    }
}

foreach ($plugins as $plugin) {
    if (! is_string($plugin)
        || preg_match('/^Pest\\\\(?:Arch|Mutate|Profanity)\\\\/', $plugin) === 1) {
        throw new RuntimeException('DROVE_PACKAGE_BOUNDARY_INVALID: an unsupported plugin is registered at runtime.');
    }
}

$summary = [
    'schema' => 1,
    'native_entrypoint' => $boundary['native-entrypoint'],
    'bridge_only' => $bridge,
    'optional_bridge_packages' => $bridgePackages,
    'development_only_plugins' => $unsupportedPlugins,
    'unsupported_surfaces' => $unsupportedSurfaces,
    'composer_sha256' => hash_file('sha256', $composerPath),
];

echo json_encode(
    $summary,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
