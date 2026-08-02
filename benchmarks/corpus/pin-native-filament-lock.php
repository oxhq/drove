<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$nativePath = $root.'/benchmarks/corpus/locks/filament-native.lock';
$corpusRoot = isset($argv[2]) ? realpath($argv[2]) : false;
$native = json_decode((string) file_get_contents($nativePath), true, 512, JSON_THROW_ON_ERROR);

if (! is_string($corpusRoot) || ! is_dir($corpusRoot.'/.git')) {
    throw new RuntimeException('The pinned Filament checkout is required as the second argument.');
}

$command = ['git', '-C', $corpusRoot, 'show', 'e9348b2e3792088ee877068116b6c1e1559a7df8:composer.lock'];
$process = proc_open(
    $command,
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    options: ['bypass_shell' => true],
);

if (! is_resource($process)) {
    throw new RuntimeException('Git could not read the pinned Filament lock.');
}

fclose($pipes[0]);
$baselineContents = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

if (proc_close($process) !== 0 || ! is_string($baselineContents)) {
    throw new RuntimeException('Git could not read the pinned Filament lock: '.trim((string) $stderr));
}

$baseline = json_decode($baselineContents, true, 512, JSON_THROW_ON_ERROR);
$installedPath = ($argv[1] ?? '').'/composer/installed.json';
$installed = is_file($installedPath)
    ? json_decode((string) file_get_contents($installedPath), true, 512, JSON_THROW_ON_ERROR)
    : ['packages' => []];
$baselinePackages = [];
$installedPackages = [];

foreach ($baseline['packages-dev'] ?? [] as $package) {
    if (is_array($package) && is_string($package['name'] ?? null)) {
        $baselinePackages[$package['name']] = $package;
    }
}

foreach ($installed['packages'] ?? [] as $package) {
    if (! is_array($package) || ! is_string($package['name'] ?? null)) {
        continue;
    }

    foreach (['dev', 'install-path', 'installation-source', 'version_normalized'] as $key) {
        unset($package[$key]);
    }

    $installedPackages[$package['name']] = $package;
}

$packages = [];
$pinned = 0;
$preserved = 0;
$paths = 0;

foreach ($native['packages'] ?? [] as $package) {
    if (! is_array($package) || ! is_string($package['name'] ?? null)) {
        throw new RuntimeException('The native Filament lock contains an invalid package.');
    }

    if (($package['dist']['type'] ?? null) === 'path') {
        $packages[] = $package;
        $paths++;

        continue;
    }

    if (isset($installedPackages[$package['name']])) {
        $packages[] = $installedPackages[$package['name']];
        $preserved++;
    } else {
        $packages[] = $baselinePackages[$package['name']]
            ?? throw new RuntimeException('The baseline Filament lock is missing '.$package['name'].'.');
        $pinned++;
    }
}

usort($packages, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
$native['packages'] = $packages;
$encoded = json_encode($native, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

if (file_put_contents($nativePath, $encoded) !== strlen($encoded)) {
    throw new RuntimeException('The pinned native Filament lock could not be written.');
}

echo json_encode([
    'packages' => count($packages),
    'installed_preserved' => $preserved,
    'baseline_added' => $pinned,
    'path_packages' => $paths,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
