<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];
$profileName = $arguments[1] ?? null;
$inputRoot = $arguments[2] ?? getcwd();
$root = is_string($inputRoot) ? realpath($inputRoot) : false;
$lockOnly = in_array('--lock-only', $arguments, true);
$injectForbiddenSymbol = in_array('--inject-forbidden-symbol', $arguments, true);

$profiles = [
    'filament' => [
        'error_prefix' => 'DROVE_NATIVE_FILAMENT_ENVIRONMENT_INVALID',
        'commit' => 'e9348b2e3792088ee877068116b6c1e1559a7df8',
        'manifest_sha256' => '959030145f05b9942e0e94682a5cb75f47187ba8dc8edb4187f1a4767eebfd51',
        'lock_sha256' => '692fd29da45143fa5069aba64b93fd307cc58aecdc971d8447038418276e1cde',
        'package_name' => 'drove/native-filament-corpus-runtime',
        'allow_plugins' => false,
        'package_count' => 118,
        'baseline_source' => 'git',
        'baseline_path' => 'composer.lock',
        'baseline_shared_packages' => 106,
        'allowed_extra_packages' => [
            'filament/actions',
            'filament/filament',
            'filament/forms',
            'filament/infolists',
            'filament/notifications',
            'filament/query-builder',
            'filament/schemas',
            'filament/spatie-laravel-settings-plugin',
            'filament/tables',
            'filament/widgets',
            'oxhq/drove',
            'oxhq/drove-laravel',
        ],
        'path_reference_exceptions' => ['filament/support'],
        'path_package_mirrors' => [
            [
                'package' => 'filament/support',
                'source_root' => 'corpus',
                'source' => 'packages/support',
                'installed' => 'vendor/filament/support',
                'paths' => ['composer.json', 'src'],
            ],
            [
                'package' => 'filament/spatie-laravel-settings-plugin',
                'source_root' => 'corpus',
                'source' => 'packages/spatie-laravel-settings-plugin',
                'installed' => 'vendor/filament/spatie-laravel-settings-plugin',
                'paths' => ['composer.json', 'src'],
            ],
            [
                'package' => 'oxhq/drove',
                'source_root' => 'drove',
                'source' => '',
                'installed' => 'vendor/oxhq/drove',
                'paths' => ['composer.json', 'src', 'resources'],
            ],
            [
                'package' => 'oxhq/drove-laravel',
                'source_root' => 'drove',
                'source' => 'packages/drove-laravel',
                'installed' => 'vendor/oxhq/drove-laravel',
                'paths' => ['composer.json', 'src'],
            ],
        ],
        'anchors' => [
            'fakerphp/faker' => ['v1.24.1', 'e0ee18eb1e6dc3cda3ce9fd97e5a0689a88a64b5'],
            'laravel/framework' => ['v13.15.0', '7e23b2aa4e1133a43835c93a810b4bedc40e425b'],
            'livewire/livewire' => ['v3.8.1', '0a7c2ec6c5c958cab32870ce5a7836a2fca0b459'],
            'mockery/mockery' => ['1.6.12', '1f4efdd7d3beafe9807b08156dfcb176d18f1699'],
            'oxhq/drove' => ['0.4.0-alpha.1', null],
            'oxhq/drove-laravel' => ['0.4.0-alpha.1', null],
            'staudenmeir/belongs-to-through' => ['v2.18', 'accb37643cf4829319617923877d11b55558a099'],
        ],
        'forbidden_package_prefixes' => [
            'brianium/',
            'nunomaduro/',
            'orchestra/',
            'paratestphp/',
            'pestphp/',
            'phpunit/',
        ],
        'forbidden_vendor_directories' => [
            'brianium',
            'nunomaduro',
            'orchestra',
            'paratestphp',
            'pestphp',
            'phpunit',
        ],
        'forbidden_namespace_prefixes' => [
            'Drove\\Bridge\\',
            'Drove\\Pest\\',
            'Orchestra\\Testbench\\',
            'ParaTest\\',
            'Pest\\',
            'PHPUnit\\',
            'Termwind\\',
        ],
        'forbidden_path_fragments' => [
            '/vendor/brianium/',
            '/vendor/nunomaduro/',
            '/vendor/orchestra/',
            '/vendor/paratestphp/',
            '/vendor/pestphp/',
            '/vendor/phpunit/',
        ],
        'forbidden_binary_pattern' => '/(?:paratest|pest|phpunit|testbench)/i',
        'allowed_dormant_binaries' => ['pest'],
        'allowed_virtual_packages' => ['nunomaduro/termwind', 'pestphp/pest'],
        'forbidden_symbols' => [
            'Drove\\Bridge\\Pest\\Bootstrap',
            'Drove\\Bridge\\Pest\\Bridge',
            'Drove\\Bridge\\PhpUnit\\Bridge',
            'Drove\\Bridge\\PhpUnitSuiteLowerer',
            'Drove\\Laravel\\TestbenchBridge',
            'Drove\\Pest\\Runner',
            'Drove\\Pest\\ScopeCompiler',
            'Drove\\Pest\\TestCaseRuntime',
            'NunoMaduro\\Collision\\Provider',
            'Orchestra\\Testbench\\TestCase',
            'ParaTest\\Runners\\PHPUnit\\Runner',
            'Pest\\Kernel',
            'PHPUnit\\Framework\\TestCase',
            'Termwind\\Termwind',
        ],
        'required_classes' => [
            'Faker\\Generator',
            'Illuminate\\Foundation\\Application',
            'Drove\\Native\\Runner',
            'Drove\\Laravel\\PackageApplicationRuntime',
            'Livewire\\LivewireServiceProvider',
            'Filament\\Support\\SupportServiceProvider',
            'Filament\\Forms\\Components\\TextInput',
            'Filament\\SpatieLaravelSettingsPluginServiceProvider',
            'Filament\\Tests\\Fixtures\\Livewire\\Livewire',
            'Mockery',
            'Znck\\Eloquent\\Traits\\BelongsToThrough',
        ],
        'clean_paths' => ['packages', 'tests'],
        'selection_directory' => 'tests/src/Support',
        'selection_files' => 39,
        'selection_sha256' => 'a626719abe7c93e77909888deeeccb1b1a7ea6bceed1cb5eea551e39acb82bcf',
    ],
    'invoiceshelf' => [
        'error_prefix' => 'DROVE_NATIVE_INVOICESHELF_ENVIRONMENT_INVALID',
        'commit' => '403a4d67225a153838ec126c484339abf60229d1',
        'manifest_sha256' => '6eca5cc5ae25f81162cd4bb8d3fed0f21e0871766cf4c5024bb0d2e0ad11a955',
        'lock_sha256' => '6477476ad0edf972e7110aa0e1d4d748b91138e051e8e273c92cae4f8ab2f194',
        'package_name' => 'invoiceshelf/invoiceshelf',
        'allow_plugins' => [
            'php-http/discovery' => true,
            'wikimedia/composer-merge-plugin' => true,
        ],
        'package_count' => 124,
        'baseline_source' => 'git',
        'baseline_path' => 'composer.lock',
        'baseline_shared_packages' => 122,
        'allowed_extra_packages' => [
            'oxhq/drove',
            'oxhq/drove-laravel',
        ],
        'path_reference_exceptions' => [],
        'path_package_mirrors' => [
            [
                'package' => 'oxhq/drove',
                'source_root' => 'drove',
                'source' => '',
                'installed' => 'vendor/oxhq/drove',
                'paths' => ['composer.json', 'src', 'resources'],
            ],
            [
                'package' => 'oxhq/drove-laravel',
                'source_root' => 'drove',
                'source' => 'packages/drove-laravel',
                'installed' => 'vendor/oxhq/drove-laravel',
                'paths' => ['composer.json', 'src'],
            ],
        ],
        'anchors' => [
            'dompdf/dompdf' => ['v3.1.5', 'f11ead23a8a76d0ff9bbc6c7c8fd7e05ca328496'],
            'laravel/framework' => ['v13.15.0', '7e23b2aa4e1133a43835c93a810b4bedc40e425b'],
            'mockery/mockery' => ['1.6.12', '1f4efdd7d3beafe9807b08156dfcb176d18f1699'],
            'mtdowling/jmespath.php' => ['2.8.0', 'a2a865e05d5f420b50cc2f85bb78d565db12a6bc'],
            'oxhq/drove' => ['0.4.0-alpha.1', null],
            'oxhq/drove-laravel' => ['0.4.0-alpha.1', null],
            'spatie/laravel-medialibrary' => ['11.21.0', 'd6e2595033ffd130d4dd5d124510ab3304794c44'],
        ],
        'forbidden_package_prefixes' => [
            'brianium/',
            'nunomaduro/collision',
            'orchestra/',
            'paratestphp/',
            'pestphp/',
            'phpunit/',
        ],
        'forbidden_vendor_directories' => [
            'brianium',
            'nunomaduro/collision',
            'orchestra',
            'paratestphp',
            'pestphp',
            'phpunit',
        ],
        'forbidden_namespace_prefixes' => [
            'Drove\\Bridge\\',
            'Drove\\Pest\\',
            'Orchestra\\Testbench\\',
            'ParaTest\\',
            'Pest\\',
            'PHPUnit\\',
        ],
        'forbidden_path_fragments' => [
            '/vendor/brianium/',
            '/vendor/nunomaduro/collision/',
            '/vendor/orchestra/',
            '/vendor/paratestphp/',
            '/vendor/pestphp/',
            '/vendor/phpunit/',
        ],
        'forbidden_binary_pattern' => '/(?:paratest|pest|phpunit|testbench)/i',
        'allowed_dormant_binaries' => ['pest'],
        'allowed_virtual_packages' => ['pestphp/pest'],
        'forbidden_symbols' => [
            'Drove\\Bridge\\Pest\\Bootstrap',
            'Drove\\Bridge\\Pest\\Bridge',
            'Drove\\Bridge\\PhpUnit\\Bridge',
            'Drove\\Bridge\\PhpUnitSuiteLowerer',
            'Drove\\Laravel\\TestbenchBridge',
            'Drove\\Pest\\Runner',
            'Drove\\Pest\\ScopeCompiler',
            'Drove\\Pest\\TestCaseRuntime',
            'NunoMaduro\\Collision\\Provider',
            'Orchestra\\Testbench\\TestCase',
            'ParaTest\\Runners\\PHPUnit\\Runner',
            'Pest\\Kernel',
            'PHPUnit\\Framework\\TestCase',
        ],
        'required_classes' => [
            'Illuminate\\Foundation\\Application',
            'Drove\\Native\\Runner',
            'Drove\\Laravel\\ApplicationRuntime',
            'Mockery',
        ],
        'clean_paths' => ['app', 'bootstrap', 'config', 'database', 'Modules', 'tests'],
        'selection_directory' => null,
        'selection_files' => null,
        'selection_sha256' => null,
    ],
];

if (! is_string($profileName) || ! isset($profiles[$profileName])) {
    throw new RuntimeException('DROVE_NATIVE_ENVIRONMENT_INVALID: unknown environment profile.');
}

$profile = $profiles[$profileName];
$prefix = $profile['error_prefix'];

$fail = static function (string $message) use ($prefix): never {
    throw new RuntimeException("$prefix: $message");
};

if (! is_string($root) || ! is_dir($root)) {
    $fail('corpus root is missing.');
}

$readJson = static function (string $path) use ($fail): array {
    $contents = is_file($path) ? file_get_contents($path) : false;

    if (! is_string($contents)) {
        $fail("unreadable $path.");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        $fail("$path is not an object.");
    }

    return $decoded;
};

$decodeJson = static function (string $contents, string $label) use ($fail): array {
    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        $fail("$label is not an object.");
    }

    return $decoded;
};

/** @return array<string, array{version: string, source_reference: ?string, dist_reference: ?string}> */
$packageMap = static function (array $lock) use ($fail): array {
    $production = $lock['packages'] ?? null;
    $development = $lock['packages-dev'] ?? null;

    if (! is_array($production) || ! is_array($development)) {
        $fail('a dependency lock has malformed package lists.');
    }

    $map = [];

    foreach ([...$production, ...$development] as $package) {
        $name = is_array($package) ? ($package['name'] ?? null) : null;
        $version = is_array($package) ? ($package['version'] ?? null) : null;
        $reference = is_array($package) ? ($package['source']['reference'] ?? null) : null;
        $distReference = is_array($package) ? ($package['dist']['reference'] ?? null) : null;

        if (! is_string($name)
            || ! is_string($version)
            || (! is_string($reference) && $reference !== null)
            || (! is_string($distReference) && $distReference !== null)) {
            $fail('a locked package is malformed.');
        }

        $map[$name] = [
            'version' => $version,
            'source_reference' => $reference,
            'dist_reference' => $distReference,
        ];
    }

    ksort($map, SORT_STRING);

    return $map;
};

/** @param list<string> $parameters */
$git = static function (array $parameters) use ($root, $fail): string {
    $command = ['git', '-C', $root];
    array_push($command, ...$parameters);
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
        $fail('Git could not start.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || ! is_string($stdout)) {
        $fail('Git failed: '.trim((string) $stderr));
    }

    return $stdout;
};

$manifestPath = $root.'/composer.json';
$lockPath = $root.'/composer.lock';
$manifest = $readJson($manifestPath);
$lock = $readJson($lockPath);
$manifestSha256 = hash_file('sha256', $manifestPath);
$lockSha256 = hash_file('sha256', $lockPath);
$nativePackages = $packageMap($lock);
$developmentPackages = $lock['packages-dev'] ?? null;

if ($manifestSha256 !== $profile['manifest_sha256']
    || $lockSha256 !== $profile['lock_sha256']
    || ($manifest['name'] ?? null) !== $profile['package_name']
    || ($manifest['config']['allow-plugins'] ?? null) !== $profile['allow_plugins']
    || ! is_array($developmentPackages)
    || $developmentPackages !== []
    || count($nativePackages) !== $profile['package_count']) {
    $fail('the dependency lock diverged.');
}

$loadBaseline = static function (string $source, string $path) use ($git, $fail): string {
    if ($source === 'git') {
        return $git(['show', 'HEAD:'.$path]);
    }

    if ($source === 'file') {
        $contents = file_get_contents(__DIR__.'/locks/'.$path);

        if (is_string($contents)) {
            return $contents;
        }
    }

    $fail('the baseline dependency lock authority is invalid or unreadable.');
};

$baselineContents = $loadBaseline($profile['baseline_source'], $profile['baseline_path']);

$baselinePackages = $packageMap($decodeJson($baselineContents, 'baseline dependency lock'));
$sharedNames = array_values(array_intersect(array_keys($baselinePackages), array_keys($nativePackages)));
$drift = [];
$pathReferenceExceptions = [];

foreach ($sharedNames as $name) {
    if ($baselinePackages[$name] === $nativePackages[$name]) {
        continue;
    }

    $baselinePackage = $baselinePackages[$name];
    $nativePackage = $nativePackages[$name];

    if (in_array($name, $profile['path_reference_exceptions'], true)
        && $baselinePackage['version'] === $nativePackage['version']
        && $baselinePackage['source_reference'] === $nativePackage['source_reference']
        && is_string($baselinePackage['dist_reference'])
        && $nativePackage['dist_reference'] === null) {
        $pathReferenceExceptions[$name] = [
            'baseline_dist_reference' => $baselinePackage['dist_reference'],
            'native_dist_reference' => null,
        ];

        continue;
    }

    $drift[$name] = ['baseline' => $baselinePackage, 'native' => $nativePackage];
}

$extraPackages = array_values(array_diff(array_keys($nativePackages), array_keys($baselinePackages)));
sort($extraPackages, SORT_STRING);

if (count($sharedNames) !== $profile['baseline_shared_packages']
    || $drift !== []
    || array_keys($pathReferenceExceptions) !== $profile['path_reference_exceptions']
    || $extraPackages !== $profile['allowed_extra_packages']) {
    $fail('baseline package parity diverged.');
}

foreach ($profile['anchors'] as $package => [$version, $reference]) {
    $anchor = $nativePackages[$package] ?? null;

    if (! is_array($anchor)
        || $anchor['version'] !== $version
        || $anchor['source_reference'] !== $reference) {
        $fail("runtime package anchor $package diverged.");
    }
}

$forbiddenPackages = array_values(array_filter(
    array_keys($nativePackages),
    static fn (string $package): bool => array_any(
        $profile['forbidden_package_prefixes'],
        static fn (string $forbidden): bool => str_starts_with($package, $forbidden),
    ),
));

if ($forbiddenPackages !== []) {
    $fail('forbidden_packages is not empty.');
}

$evidence = [
    'schema' => 1,
    'profile' => $profileName,
    'mode' => $lockOnly ? 'lock-only' : 'installed',
    'commit' => $profile['commit'],
    'manifest_sha256' => $manifestSha256,
    'lock_sha256' => $lockSha256,
    'packages' => count($nativePackages),
    'baseline_shared_packages' => count($sharedNames),
    'baseline_drift' => [],
    'baseline_path_reference_exceptions' => $pathReferenceExceptions,
    'allowed_extra_packages' => $extraPackages,
    'anchors' => $profile['anchors'],
    'path_package_content' => [],
    'forbidden_packages' => [],
    'forbidden_virtual_packages' => [],
    'forbidden_vendor_paths' => [],
    'forbidden_autoload_prefixes' => [],
    'forbidden_autoload_files' => [],
    'forbidden_binaries' => [],
    'allowed_dormant_binaries' => [],
    'allowed_virtual_packages' => [],
    'forbidden_runtime_symbols' => [],
    'forbidden_runtime_files' => [],
];

$cleanCommand = ['status', '--porcelain', '--', ...$profile['clean_paths']];

if (trim($git(['rev-parse', 'HEAD'])) !== $profile['commit'] || trim($git($cleanCommand)) !== '') {
    $fail('pinned sources diverged.');
}

if ($lockOnly) {
    echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

    exit(0);
}

if (is_string($profile['selection_directory'])) {
    $selectionRoot = $root.'/'.$profile['selection_directory'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($selectionRoot, FilesystemIterator::SKIP_DOTS));
    $selectedPaths = [];

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $selectedPaths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }

    sort($selectedPaths, SORT_STRING);
    $selectionEntries = [];

    foreach ($selectedPaths as $path) {
        $source = $git(['show', 'HEAD:'.$path]);
        $workingSource = file_get_contents($root.'/'.$path);

        if (! is_string($workingSource) || $workingSource !== $source) {
            $fail("selected source $path diverged.");
        }

        $selectionEntries[] = ['path' => $path, 'sha256' => hash('sha256', $source)];
    }

    $selectionSha256 = hash('sha256', json_encode($selectionEntries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    if (count($selectedPaths) !== $profile['selection_files'] || $selectionSha256 !== $profile['selection_sha256']) {
        $fail('the selected source set diverged.');
    }

    $evidence['selection'] = ['files' => count($selectedPaths), 'sha256' => $selectionSha256];
}

$vendor = $root.'/vendor';
$composer = $vendor.'/composer';

/** @param list<string> $paths */
$treeDigest = static function (string $base, array $paths) use ($fail): string {
    $entries = [];

    foreach ($paths as $relative) {
        $target = $base.'/'.$relative;

        if (is_file($target)) {
            $entries[] = ['path' => $relative, 'sha256' => hash_file('sha256', $target)];

            continue;
        }

        if (! is_dir($target)) {
            $fail("path-package proof input $target is missing.");
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            $entries[] = ['path' => $path, 'sha256' => hash_file('sha256', $file->getPathname())];
        }
    }

    usort($entries, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

    return hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
};

foreach (['autoload.php', 'composer/autoload_psr4.php', 'composer/autoload_classmap.php', 'composer/installed.php'] as $relative) {
    if (! is_file($vendor.'/'.$relative)) {
        $fail("vendor/$relative is missing.");
    }
}

foreach ($profile['path_package_mirrors'] as $mirror) {
    $sourceRoot = $mirror['source_root'] === 'drove'
        ? (getenv('DROVE_SOURCE') ?: null)
        : $root;
    $installedRoot = $root.'/'.$mirror['installed'];

    if (! is_string($sourceRoot)
        || ! is_dir($sourceRoot)
        || is_link($installedRoot)
        || realpath($installedRoot) === realpath($sourceRoot.'/'.$mirror['source'])) {
        $fail("installed path package {$mirror['package']} is not an independent copy.");
    }

    $sourceDigest = $treeDigest(rtrim($sourceRoot.'/'.$mirror['source'], '/'), $mirror['paths']);
    $installedDigest = $treeDigest($installedRoot, $mirror['paths']);

    if ($sourceDigest !== $installedDigest) {
        $fail("installed path package {$mirror['package']} diverged from pinned source.");
    }

    $evidence['path_package_content'][$mirror['package']] = $sourceDigest;
}

$installed = require $composer.'/installed.php';
$installedVersions = is_array($installed) ? ($installed['versions'] ?? null) : null;
$installedPackages = [];
$virtualPackages = [];

if (! is_array($installedVersions)) {
    $fail('installed package metadata is malformed.');
}

foreach ($installedVersions as $name => $metadata) {
    if (! is_string($name) || ! is_array($metadata)) {
        $fail('installed package metadata is malformed.');
    }

    if (is_string($metadata['install_path'] ?? null)) {
        $installedPackages[] = $name;
    } elseif (isset($metadata['provided']) || isset($metadata['replaced'])) {
        $virtualPackages[] = $name;
    } else {
        $fail("installed package $name has no install path or virtual-package declaration.");
    }
}

$expectedInstalledPackages = [$profile['package_name'], ...array_keys($nativePackages)];
sort($installedPackages, SORT_STRING);
sort($expectedInstalledPackages, SORT_STRING);
sort($virtualPackages, SORT_STRING);

if ($installedPackages !== $expectedInstalledPackages) {
    $fail('installed packages diverged.');
}

$evidence['allowed_virtual_packages'] = array_values(array_intersect(
    $profile['allowed_virtual_packages'],
    $virtualPackages,
));
$evidence['forbidden_virtual_packages'] = array_values(array_filter(
    $virtualPackages,
    static fn (string $package): bool => ! in_array($package, $profile['allowed_virtual_packages'], true)
        && array_any(
            $profile['forbidden_package_prefixes'],
            static fn (string $forbidden): bool => str_starts_with($package, $forbidden),
        ),
));

if ($evidence['allowed_virtual_packages'] !== $profile['allowed_virtual_packages']
    || $evidence['forbidden_virtual_packages'] !== []) {
    $fail('installed virtual-package policy diverged.');
}

$evidence['forbidden_vendor_paths'] = array_values(array_filter(
    $profile['forbidden_vendor_directories'],
    static fn (string $directory): bool => is_dir($vendor.'/'.$directory),
));
$psr4 = require $composer.'/autoload_psr4.php';
$classmap = require $composer.'/autoload_classmap.php';
$files = is_file($composer.'/autoload_files.php') ? require $composer.'/autoload_files.php' : [];

if (! is_array($psr4) || ! is_array($classmap) || ! is_array($files)) {
    $fail('Composer autoload metadata is malformed.');
}

foreach (array_keys($psr4) as $namespace) {
    if (! is_string($namespace)) {
        $fail('Composer PSR-4 metadata is malformed.');
    }

    if (array_any(
        $profile['forbidden_namespace_prefixes'],
        static fn (string $forbidden): bool => str_starts_with($namespace, $forbidden),
    )) {
        $evidence['forbidden_autoload_prefixes'][] = $namespace;
    }
}

$autoloadPaths = [...array_values($classmap), ...array_values($files), ...array_merge(...array_values($psr4))];
$normalizePath = static fn (string $path): string => str_replace('\\', '/', $path);
$evidence['forbidden_autoload_files'] = array_values(array_filter(
    array_map($normalizePath, $autoloadPaths),
    static fn (string $path): bool => array_any(
        $profile['forbidden_path_fragments'],
        static fn (string $fragment): bool => str_contains(strtolower($path), $fragment),
    ),
));
$binaries = is_dir($vendor.'/bin') ? array_values(array_diff(scandir($vendor.'/bin') ?: [], ['.', '..'])) : [];
$evidence['allowed_dormant_binaries'] = array_values(array_intersect($profile['allowed_dormant_binaries'], $binaries));

if ($evidence['allowed_dormant_binaries'] !== $profile['allowed_dormant_binaries']) {
    $fail('the declared dormant migration binaries diverged.');
}

$evidence['forbidden_binaries'] = array_values(array_filter(
    $binaries,
    static fn (string $binary): bool => ! in_array($binary, $profile['allowed_dormant_binaries'], true)
        && preg_match($profile['forbidden_binary_pattern'], $binary) === 1,
));

require $vendor.'/autoload.php';

if ($injectForbiddenSymbol) {
    class_exists('Drove\\Bridge\\Pest\\Bridge');
}

$requiredSymbolExists = static fn (string $symbol): bool => class_exists($symbol)
    || interface_exists($symbol)
    || trait_exists($symbol);
$missingRequiredSymbol = 'Drove\\Corpus\\DefinitelyMissingRequiredSymbol';

if ($requiredSymbolExists($missingRequiredSymbol)) {
    $fail('the missing required-symbol probe was accepted.');
}

$evidence['required_symbol_negative_probe'] = $missingRequiredSymbol;
$evidence['required_symbols'] = [];

foreach ($profile['required_classes'] as $requiredClass) {
    if (! $requiredSymbolExists($requiredClass)) {
        $fail("$requiredClass is not loadable.");
    }

    $evidence['required_symbols'][$requiredClass] = trait_exists($requiredClass, false)
        ? 'trait'
        : (interface_exists($requiredClass, false) ? 'interface' : 'class');
}

$declaredSymbols = [
    ...get_declared_classes(),
    ...get_declared_interfaces(),
    ...get_declared_traits(),
];
$evidence['forbidden_runtime_symbols'] = array_values(array_unique([
    ...array_filter(
        $profile['forbidden_symbols'],
        static fn (string $symbol): bool => class_exists($symbol, false)
            || interface_exists($symbol, false)
            || trait_exists($symbol, false),
    ),
    ...array_filter(
        $declaredSymbols,
        static fn (string $symbol): bool => array_any(
            $profile['forbidden_namespace_prefixes'],
            static fn (string $forbidden): bool => str_starts_with($symbol, $forbidden),
        ),
    ),
]));
$evidence['forbidden_runtime_files'] = array_values(array_filter(
    array_map($normalizePath, get_included_files()),
    static fn (string $path): bool => str_contains(strtolower($path), '/src/drove/bridge/')
        || array_any(
            $profile['forbidden_path_fragments'],
            static fn (string $fragment): bool => str_contains(strtolower($path), $fragment),
        ),
));

foreach ([
    'forbidden_vendor_paths',
    'forbidden_autoload_prefixes',
    'forbidden_autoload_files',
    'forbidden_binaries',
    'forbidden_runtime_symbols',
    'forbidden_runtime_files',
] as $field) {
    if ($evidence[$field] !== []) {
        $fail("$field is not empty: ".json_encode($evidence[$field], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
