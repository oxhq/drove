<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

const GATE_LIVEWIRE_COMMIT = '9c1450739d30c9b0b223ad6512be2a33f8f62f96';

function gateAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $command */
function gateProcess(array $command, string $workingDirectory, int $timeout = 300): Process
{
    $process = new Process($command, $workingDirectory);
    $process->setTimeout($timeout);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(sprintf(
            "Command failed (%d): %s\n%s%s",
            $process->getExitCode(),
            $process->getCommandLine(),
            $process->getOutput(),
            $process->getErrorOutput(),
        ));
    }

    return $process;
}

function gateDroveRevision(string $root): ?string
{
    $expected = getenv('DROVE_EXPECTED_REVISION');

    if ($expected === false || $expected === '') {
        return null;
    }

    gateAssert(preg_match('/^[0-9a-f]{40}$/D', $expected) === 1, 'DROVE_EXPECTED_REVISION must be an exact Drove commit.');
    $actual = trim(gateProcess(['git', '-C', $root, 'rev-parse', 'HEAD'], $root)->getOutput());
    $status = gateProcess([
        'git', '-C', $root, 'status', '--porcelain=v1', '--untracked-files=all',
    ], $root)->getOutput();
    gateAssert($actual === $expected && trim($status) === '', 'The final Livewire evidence is not bound to a clean Drove revision.');

    return $expected;
}

/** @param list<string> $command */
function gateVisibleProcess(array $command, string $workingDirectory, int $timeout = 900): void
{
    $process = new Process($command, $workingDirectory);
    $process->setTimeout($timeout);
    $exitCode = $process->run(static function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    });

    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            'Command failed (%d): %s',
            $exitCode,
            $process->getCommandLine(),
        ));
    }
}

function gateDirectory(string $parent, string $name): string
{
    $path = $parent.'/'.$name;
    gateAssert(mkdir($path, 0700, true), 'Could not create gate directory '.$path.'.');

    return $path;
}

function gateRemoveTree(string $path, string $temporaryRoot): void
{
    $resolvedRoot = realpath($temporaryRoot);
    $resolved = realpath($path);
    $normalizedRoot = is_string($resolvedRoot) ? str_replace('\\', '/', $resolvedRoot) : null;
    $normalized = is_string($resolved) ? str_replace('\\', '/', $resolved) : null;

    if (
        ! is_string($normalizedRoot)
        || ! is_string($normalized)
        || ! str_starts_with($normalized, $normalizedRoot.'/')
    ) {
        throw new RuntimeException('Refusing to remove a path outside the Livewire gate workspace.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $entryPath = $entry->getPathname();

        if ($entry->isLink()) {
            gateAssert(@unlink($entryPath), 'Could not remove gate link '.$entryPath.'.');

            continue;
        }

        if ($entry->isDir()) {
            gateAssert(@chmod($entryPath, 0700), 'Could not make gate directory removable '.$entryPath.'.');
            gateAssert(@rmdir($entryPath), 'Could not remove gate directory '.$entryPath.'.');

            continue;
        }

        gateAssert(@chmod($entryPath, 0600), 'Could not make gate file removable '.$entryPath.'.');
        gateAssert(@unlink($entryPath), 'Could not remove gate file '.$entryPath.'.');
    }

    gateAssert(@chmod($resolved, 0700), 'Could not make gate root removable '.$resolved.'.');
    gateAssert(@rmdir($resolved), 'Could not remove gate root '.$resolved.'.');
}

function gateVolume(string $host, string $container, bool $readOnly = false): string
{
    return $host.':'.$container.($readOnly ? ':ro' : '');
}

/** @return array<string, mixed> */
function gateJson(string $path): array
{
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException('Could not read '.$path.'.');
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    gateAssert(is_array($decoded), $path.' does not contain a JSON object.');

    return $decoded;
}

/**
 * @param  array<string, mixed>  $lock
 * @return array<string, array{version: string, source: ?string, dist: ?string}>
 */
function gateLockPackages(array $lock): array
{
    $production = $lock['packages'] ?? null;
    $development = $lock['packages-dev'] ?? null;
    gateAssert(is_array($production) && is_array($development), 'A Livewire dependency lock is malformed.');
    $packages = [];

    foreach ([...$production, ...$development] as $package) {
        $name = is_array($package) ? ($package['name'] ?? null) : null;
        $version = is_array($package) ? ($package['version'] ?? null) : null;
        $source = is_array($package) ? ($package['source']['reference'] ?? null) : null;
        $dist = is_array($package) ? ($package['dist']['reference'] ?? null) : null;
        gateAssert(
            is_string($name)
                && is_string($version)
                && (is_string($source) || $source === null)
                && (is_string($dist) || $dist === null),
            'A locked Livewire package is malformed.',
        );
        $packages[$name] = compact('version', 'source', 'dist');
    }

    ksort($packages, SORT_STRING);

    return $packages;
}

/** @return array<string, mixed> */
function gateDependencyEvidence(string $root, string $checkout): array
{
    $locks = $root.'/benchmarks/corpus/locks';
    $baselineManifestPath = $locks.'/livewire-baseline.composer.json';
    $baselineLockPath = $locks.'/livewire-baseline.lock';
    $nativeManifestPath = $locks.'/livewire-native.composer.json';
    $nativeLockPath = $locks.'/livewire-native.lock';
    $baselineManifest = gateJson($baselineManifestPath);
    $nativeManifest = gateJson($nativeManifestPath);
    $pinnedManifest = json_decode(
        gateProcess(['git', '-C', $checkout, 'show', 'HEAD:composer.json'], $root)->getOutput(),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    gateAssert(is_array($pinnedManifest) && $baselineManifest === $pinnedManifest, 'The independent baseline manifest diverged from pinned Livewire.');
    gateAssert(
        $nativeManifest === gateJson($root.'/experiments/phase-3-laravel/native-package/composer.json'),
        'The native Livewire manifest mirror diverged.',
    );

    $baselineLock = gateJson($baselineLockPath);
    $nativeLock = gateJson($nativeLockPath);
    $baseline = gateLockPackages($baselineLock);
    $native = gateLockPackages($nativeLock);
    $shared = array_intersect_key($baseline, $native);
    $drift = [];

    foreach ($shared as $package => $identity) {
        if ($identity !== $native[$package]) {
            $drift[$package] = ['baseline' => $identity, 'native' => $native[$package]];
        }
    }

    $extras = array_values(array_diff(array_keys($native), array_keys($baseline)));
    sort($extras, SORT_STRING);
    $forbiddenBaseline = array_values(array_filter(
        array_keys($baseline),
        static fn (string $package): bool => str_starts_with($package, 'oxhq/')
            || str_starts_with($package, 'pestphp/'),
    ));
    $forbiddenNative = array_values(array_filter(
        array_keys($native),
        static fn (string $package): bool => str_starts_with($package, 'phpunit/')
            || str_starts_with($package, 'orchestra/')
            || str_starts_with($package, 'pestphp/')
            || str_starts_with($package, 'brianium/'),
    ));

    gateAssert(count($baseline) === 121 && count($baselineLock['packages-dev']) === 48, 'The independent Livewire baseline graph drifted.');
    gateAssert(count($native) === 77 && $nativeLock['packages-dev'] === [], 'The native Livewire runtime graph drifted.');
    gateAssert(count($shared) === 74 && $drift === [], 'Shared Livewire production dependencies drifted.');
    gateAssert($extras === ['livewire/livewire', 'oxhq/drove', 'oxhq/drove-laravel'], 'Native Livewire package extras drifted.');
    gateAssert($forbiddenBaseline === [], 'The independent Livewire baseline installed Drove or Pest.');
    gateAssert($forbiddenNative === [], 'The native Livewire graph installed an external test runtime.');
    gateAssert(($baseline['phpunit/phpunit']['version'] ?? null) === '12.5.33', 'The independent PHPUnit anchor drifted.');
    gateAssert(($baseline['orchestra/testbench']['version'] ?? null) === 'v11.1.0', 'The independent Testbench anchor drifted.');
    gateAssert(($native['livewire/livewire']['source'] ?? null) === GATE_LIVEWIRE_COMMIT, 'The native Livewire source anchor drifted.');
    gateAssert(($native['laravel/framework']['version'] ?? null) === 'v13.23.0', 'The shared Laravel anchor drifted.');

    return [
        'baseline_manifest_sha256' => hash_file('sha256', $baselineManifestPath),
        'baseline_lock_sha256' => hash_file('sha256', $baselineLockPath),
        'baseline_packages' => count($baseline),
        'baseline_runner' => 'phpunit/phpunit 12.5.33',
        'baseline_runtime' => 'orchestra/testbench v11.1.0',
        'native_manifest_sha256' => hash_file('sha256', $nativeManifestPath),
        'native_lock_sha256' => hash_file('sha256', $nativeLockPath),
        'native_packages' => count($native),
        'shared_packages' => count($shared),
        'shared_version_source_dist_drift' => [],
        'native_extra_packages' => $extras,
        'separate_vendors' => true,
    ];
}

function gateBaseline(string $image, string $checkout, string $root): Process
{
    $command = [
        'docker', 'run', '--rm',
        '--volume', gateVolume($checkout, '/livewire', true),
        $image,
        'sh', '-lc', <<<'SH'
set -eu
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT
tar --exclude=.git -C /livewire -cf - . | tar -xf - -C "$stage"
cp /drove/benchmarks/corpus/locks/livewire-baseline.composer.json "$stage/composer.json"
cp /drove/benchmarks/corpus/locks/livewire-baseline.lock "$stage/composer.lock"
composer --working-dir="$stage" install \
    --no-interaction --no-progress --no-scripts --prefer-dist --quiet
source_mtime_epoch=$(php -r '
    $baseline = json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR);
    $epoch = $baseline["staging_source_mtime_epoch"] ?? null;
    if (! is_int($epoch)) {
        exit(1);
    }
    echo $epoch;
' /drove/experiments/phase-3-laravel/native-package/livewire-full-baseline.json)
commit_mtime_epoch=$(git -c safe.directory=/livewire -C /livewire show -s --format=%ct HEAD)
test "$source_mtime_epoch" = "$commit_mtime_epoch"
php -r '
    $baseline = json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR);
    $paths = array_values(array_unique([
        ...array_keys($baseline["files"] ?? []),
        ...array_keys($baseline["production_helpers"] ?? []),
        ...array_keys($baseline["support_files"] ?? []),
    ]));
    $epoch = (int) $argv[3];

    if (count($paths) !== 51) {
        exit(1);
    }

    foreach ($paths as $relative) {
        if (! is_string($relative)
            || $relative === ""
            || str_starts_with($relative, "/")
            || preg_match("#(^|/)\\.\\.(/|$)#", $relative) === 1) {
            exit(1);
        }

        $path = $argv[2]."/".$relative;

        if (! is_file($path) || ! touch($path, $epoch)) {
            exit(1);
        }

        clearstatcache(true, $path);

        if (filemtime($path) !== $epoch) {
            exit(1);
        }
    }
' /drove/experiments/phase-3-laravel/native-package/livewire-full-baseline.json \
    "$stage" "$source_mtime_epoch"
cd "$stage"
find src -type f -name '*UnitTest.php' -print | LC_ALL=C sort | sed -n '1,20p' > selected
test "$(wc -l < selected | tr -d '[:space:]')" = 20
DUSK_DRIVER_URL=http://127.0.0.1:9515 \
    php vendor/bin/phpunit --configuration=phpunit.xml.dist \
        --do-not-cache-result --colors=never --log-junit=livewire.xml \
        $(cat selected) > phpunit.log 2>&1
php /drove/experiments/phase-3-laravel/native-package/verify-livewire-baseline.php \
    "$stage" "$stage/livewire.xml" \
    /drove/experiments/phase-3-laravel/native-package/livewire-full-baseline.json
SH,
    ];

    return gateProcess($command, $root, 300);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function gateDocker(
    string $image,
    string $vendor,
    string $checkout,
    array $arguments,
    string $root,
    ?string $artifactDirectory = null,
    array $environment = [],
    bool $mountTooling = false,
): Process {
    $command = [
        'docker', 'run', '--rm',
        '--tmpfs', '/drove-storage',
        '--env', 'DROVE_NATIVE_PACKAGE_STORAGE=/drove-storage',
        '--env', 'DROVE_NATIVE_VENDOR=/vendor',
        '--env', 'DROVE_NATIVE_LIVEWIRE_CHECKOUT=/livewire',
        '--volume', gateVolume($vendor, '/vendor'),
        '--volume', gateVolume($checkout, '/livewire', true),
    ];

    if ($mountTooling) {
        $command[] = '--volume';
        $command[] = gateVolume($root.'/vendor/phpstan/phpstan/phpstan.phar', '/phpstan.phar', true);
    }

    foreach ($environment as $name => $value) {
        $command[] = '--env';
        $command[] = $name.'='.$value;
    }

    if (is_string($artifactDirectory)) {
        $command[] = '--volume';
        $command[] = gateVolume($artifactDirectory, '/artifacts', true);
    }

    array_push($command, $image, ...$arguments);

    return gateProcess($command, $root, 300);
}

$root = dirname(__DIR__, 3);
$autoload = $root.'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Run composer install before the native Livewire gate.\n");
    exit(1);
}

require $autoload;

$token = bin2hex(random_bytes(6));
$temporaryRoot = gateDirectory(sys_get_temp_dir(), 'drove-native-livewire-gate-'.$token);
$vendor = 'drove-native-livewire-vendor-'.$token;
$managedCheckout = false;
$managedArtifacts = false;
$checkout = getenv('DROVE_NATIVE_LIVEWIRE_CHECKOUT');
$artifactDirectory = getenv('DROVE_NATIVE_LIVEWIRE_ARTIFACT_DIR');
$image = getenv('DROVE_NATIVE_LIVEWIRE_IMAGE');
$image = is_string($image) && $image !== '' ? $image : 'drove-native-livewire-gate';
$exitCode = 0;
$droveRevision = null;

try {
    $droveRevision = gateDroveRevision($root);
    gateAssert(
        $droveRevision === null || getenv('DROVE_NATIVE_LIVEWIRE_SKIP_BUILD') !== '1',
        'Final Livewire evidence requires an image built from the bound Drove revision.',
    );
    gateProcess(['docker', 'volume', 'create', $vendor], $root);

    if (getenv('DROVE_NATIVE_LIVEWIRE_SKIP_BUILD') !== '1') {
        gateVisibleProcess([
            'docker', 'build',
            '--file', $root.'/experiments/phase-3-laravel/Dockerfile',
            '--tag', $image,
            $root,
        ], $root);
    }

    if (! is_string($checkout) || $checkout === '') {
        $checkout = $temporaryRoot.'/livewire';
        $managedCheckout = true;
        gateProcess([
            'git', 'clone', '--filter=blob:none', '--no-checkout',
            'https://github.com/livewire/livewire.git',
            $checkout,
        ], $root, 180);
        gateProcess(['git', '-C', $checkout, 'config', 'core.autocrlf', 'false'], $root);
        gateProcess(['git', '-C', $checkout, 'checkout', '--detach', GATE_LIVEWIRE_COMMIT], $root, 180);
    }

    $resolvedCheckout = realpath($checkout);

    if (! is_string($resolvedCheckout) || ! is_dir($resolvedCheckout)) {
        throw new RuntimeException('The Livewire checkout is unavailable.');
    }

    $checkout = $resolvedCheckout;
    gateAssert(
        trim(gateProcess(['git', '-C', $checkout, 'rev-parse', 'HEAD'], $root)->getOutput()) === GATE_LIVEWIRE_COMMIT,
        'The Livewire checkout commit drifted.',
    );
    gateAssert(
        trim(gateProcess(['git', '-C', $checkout, 'status', '--porcelain=v1', '--untracked-files=all'], $root)->getOutput()) === '',
        'The Livewire checkout is dirty.',
    );
    $dependencies = gateDependencyEvidence($root, $checkout);

    if (! is_string($artifactDirectory) || $artifactDirectory === '') {
        $artifactDirectory = gateDirectory($temporaryRoot, 'artifacts');
        $managedArtifacts = true;
    } else {
        gateAssert(is_dir($artifactDirectory) || mkdir($artifactDirectory, 0700, true), 'Could not create the artifact directory.');
        $resolvedArtifacts = realpath($artifactDirectory);

        if (! is_string($resolvedArtifacts)) {
            throw new RuntimeException('Could not resolve the artifact directory.');
        }

        $artifactDirectory = $resolvedArtifacts;
    }

    $gateStatic = gateProcess([
        PHP_BINARY,
        $root.'/vendor/phpstan/phpstan/phpstan.phar',
        'analyse',
        '--configuration='.$root.'/experiments/phase-3-laravel/native-package/phpstan.neon',
        '--no-progress',
        $root.'/experiments/phase-3-laravel/native-package/gate.php',
        $root.'/experiments/phase-3-laravel/native-package/verify-livewire-baseline.php',
    ], $root);
    fwrite(STDOUT, $gateStatic->getOutput());

    $baseline = gateBaseline($image, $checkout, $root);
    $baselineOutput = $baseline->getOutput();
    $baselineResult = json_decode($baselineOutput, true, 16, JSON_THROW_ON_ERROR);
    gateAssert(is_array($baselineResult), 'The independent Livewire baseline summary is invalid.');
    gateAssert(
        file_put_contents($artifactDirectory.'/livewire-baseline-c1.json', $baselineOutput) === strlen($baselineOutput),
        'Could not write the independent Livewire baseline artifact.',
    );

    gateVisibleProcess([
        'docker', 'run', '--rm',
        '--env', 'COMPOSER_VENDOR_DIR=/vendor',
        '--volume', gateVolume($vendor, '/vendor'),
        $image,
        'sh', '-lc',
        'set -eu; '
            .'cmp native-package/composer.json /drove/benchmarks/corpus/locks/livewire-native.composer.json; '
            .'cp /drove/benchmarks/corpus/locks/livewire-native.lock native-package/composer.lock; '
            .'composer --working-dir=native-package install --no-dev --no-interaction '
            .'--no-progress --no-scripts --prefer-dist --quiet',
    ], $root, 300);

    $static = gateDocker($image, $vendor, $checkout, [
        'sh', '-lc', <<<'SH'
set -eu
test ! -e native-package/vendor
ln -s /vendor native-package/vendor
trap 'rm /drove/experiments/phase-3-laravel/native-package/vendor' EXIT
cd native-package
php /phpstan.phar analyse --configuration=phpstan.neon --no-progress --debug \
    --memory-limit=512M LivewirePackageContext.php PinnedGitCheckout.php \
    ProofCounter.php compare-livewire-cohort.php proof-livewire-cohort.php \
    proof-livewire-faults.php proof-livewire-full.php \
    proof-livewire-runtime-audit-fault.php proof.php \
    ../../../packages/drove-laravel/src/LaravelResponse.php \
    ../../../packages/drove-laravel/src/LaravelTestContext.php \
    ../../../packages/drove-laravel/src/PackageApplicationRuntime.php
SH,
    ], $root, null, [], true);
    fwrite(STDOUT, $static->getOutput());

    $full = gateDocker($image, $vendor, $checkout, ['php', 'native-package/proof-livewire-full.php'], $root);
    gateAssert(
        file_put_contents($artifactDirectory.'/livewire-full-c1.json', $full->getOutput()) === strlen($full->getOutput()),
        'Could not write the full Livewire proof artifact.',
    );
    $faults = gateDocker($image, $vendor, $checkout, ['php', 'native-package/proof-livewire-faults.php'], $root);
    gateAssert(
        file_put_contents($artifactDirectory.'/livewire-faults.json', $faults->getOutput()) === strlen($faults->getOutput()),
        'Could not write the Livewire fault artifact.',
    );
    $matrixPaths = [];

    foreach ([1, 2, 4, 8, 16, 30] as $processes) {
        $summary = gateDocker($image, $vendor, $checkout, [
            'php', 'native-package/proof-livewire-cohort.php',
        ], $root, null, ['DROVE_NATIVE_LIVEWIRE_PROCESSES' => (string) $processes]);
        $path = $artifactDirectory.'/livewire-cohort-c'.$processes.'.json';
        $output = $summary->getOutput();
        $decoded = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        gateAssert(is_array($decoded), 'Livewire C'.$processes.' summary is invalid.');
        gateAssert(file_put_contents($path, $output) === strlen($output), 'Could not write '.$path.'.');
        $matrixPaths[] = '/artifacts/'.basename($path);
    }

    $compare = gateDocker(
        $image,
        $vendor,
        $checkout,
        ['php', 'native-package/compare-livewire-cohort.php', ...$matrixPaths],
        $root,
        $artifactDirectory,
    );
    gateAssert(
        file_put_contents($artifactDirectory.'/livewire-cohort-matrix.json', $compare->getOutput()) === strlen($compare->getOutput()),
        'Could not write the Livewire cohort matrix artifact.',
    );
    $fullResult = json_decode($full->getOutput(), true, 16, JSON_THROW_ON_ERROR);
    gateAssert(is_array($fullResult), 'The full native Livewire summary is invalid.');
    gateAssert(
        is_string($baselineResult['case_rows_sha256'] ?? null)
            && ($baselineResult['case_rows_sha256'] ?? null) === ($fullResult['case_rows_sha256'] ?? null),
        'The independent and native Livewire case-row hashes diverged.',
    );
    gateAssert(
        is_int($baselineResult['staging_source_mtime_epoch'] ?? null)
            && ($baselineResult['staging_source_mtime_epoch'] ?? null) === ($fullResult['staging_source_mtime_epoch'] ?? null),
        'The independent and native Livewire staging clocks diverged.',
    );
    gateAssert(gateDroveRevision($root) === $droveRevision, 'The Drove revision changed during the Livewire gate.');
    $result = [
        'ok' => true,
        'drove_revision' => $droveRevision,
        'dependencies' => $dependencies,
        'independent_baseline' => $baselineResult,
        'full' => $fullResult,
        'case_rows_parity' => true,
        'faults' => json_decode($faults->getOutput(), true, 16, JSON_THROW_ON_ERROR),
        'parallel_safe_matrix' => json_decode($compare->getOutput(), true, 16, JSON_THROW_ON_ERROR),
        'static_analysis' => 'passed',
    ];
    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    gateAssert(
        file_put_contents($artifactDirectory.'/livewire-native-gate.json', $encoded) === strlen($encoded),
        'Could not write the aggregate Livewire gate artifact.',
    );
    echo $encoded;
} catch (Throwable $throwable) {
    fwrite(STDERR, 'DROVE_NATIVE_LIVEWIRE_GATE_FAILED '.$throwable::class.': '.$throwable->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    $cleanupFailures = [];

    if ($managedArtifacts && is_string($artifactDirectory) && is_dir($artifactDirectory)) {
        try {
            gateRemoveTree($artifactDirectory, $temporaryRoot);
        } catch (Throwable $throwable) {
            $cleanupFailures[] = $throwable->getMessage();
        }
    }

    if ($managedCheckout && is_string($checkout) && is_dir($checkout)) {
        try {
            gateRemoveTree($checkout, $temporaryRoot);
        } catch (Throwable $throwable) {
            $cleanupFailures[] = $throwable->getMessage();
        }
    }

    try {
        gateProcess(['docker', 'volume', 'rm', $vendor], $root);
    } catch (Throwable $throwable) {
        $cleanupFailures[] = $throwable->getMessage();
    }

    if (is_dir($temporaryRoot)) {
        try {
            gateAssert(@rmdir($temporaryRoot), 'Could not remove gate workspace '.$temporaryRoot.'.');
        } catch (Throwable $throwable) {
            $cleanupFailures[] = $throwable->getMessage();
        }
    }

    if ($cleanupFailures !== []) {
        fwrite(STDERR, 'DROVE_NATIVE_LIVEWIRE_CLEANUP_FAILED '.implode(' | ', $cleanupFailures).PHP_EOL);
        $exitCode = 1;
    }
}

exit($exitCode);
