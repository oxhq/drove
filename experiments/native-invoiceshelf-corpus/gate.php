<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

const NATIVE_INVOICESHELF_GATE_COMMIT = '403a4d67225a153838ec126c484339abf60229d1';

function nativeInvoiceShelfGateAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $command */
function nativeInvoiceShelfGateProcess(
    array $command,
    string $workingDirectory,
    int $timeout = 900,
    bool $visible = false,
): Process {
    $process = new Process($command, $workingDirectory);
    $process->setTimeout($timeout);
    $exitCode = $process->run($visible
        ? static function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        }
        : null);

    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            "Command failed (%d): %s\n%s%s",
            $exitCode,
            $process->getCommandLine(),
            $process->getOutput(),
            $process->getErrorOutput(),
        ));
    }

    return $process;
}

function nativeInvoiceShelfGateDroveRevision(string $root): ?string
{
    $expected = getenv('DROVE_EXPECTED_REVISION');

    if ($expected === false || $expected === '') {
        return null;
    }

    nativeInvoiceShelfGateAssert(preg_match('/^[0-9a-f]{40}$/D', $expected) === 1, 'DROVE_EXPECTED_REVISION must be an exact Drove commit.');
    $actual = trim(nativeInvoiceShelfGateProcess(['git', '-C', $root, 'rev-parse', 'HEAD'], $root)->getOutput());
    $status = nativeInvoiceShelfGateProcess([
        'git', '-C', $root, 'status', '--porcelain=v1', '--untracked-files=all',
    ], $root)->getOutput();
    nativeInvoiceShelfGateAssert($actual === $expected && trim($status) === '', 'The final InvoiceShelf evidence is not bound to a clean Drove revision.');

    return $expected;
}

/** @param list<string> $command */
function nativeInvoiceShelfGateRejectedProcess(
    array $command,
    string $workingDirectory,
    string $diagnostic,
): void {
    $process = new Process($command, $workingDirectory);
    $process->setTimeout(300);
    $process->run();

    nativeInvoiceShelfGateAssert(
        ! $process->isSuccessful()
            && $process->getOutput() === ''
            && str_contains($process->getErrorOutput(), $diagnostic),
        "A rejected InvoiceShelf command diverged.\n".$process->getOutput().$process->getErrorOutput(),
    );
}

function nativeInvoiceShelfGateVolume(string $source, string $target, bool $readOnly = false): string
{
    return $source.':'.$target.($readOnly ? ':ro' : '');
}

/** @return list<string> */
function nativeInvoiceShelfGateDocker(
    string $root,
    string $checkout,
    string $vendor,
    string $composerCache,
): array {
    return [
        'docker', 'run', '--rm',
        '--env', 'COMPOSER_CACHE_DIR=/composer-cache',
        '--volume', nativeInvoiceShelfGateVolume($root, '/drove', true),
        '--volume', nativeInvoiceShelfGateVolume($checkout, '/app'),
        '--volume', nativeInvoiceShelfGateVolume($vendor, '/app/vendor'),
        '--volume', nativeInvoiceShelfGateVolume($composerCache, '/composer-cache'),
        '--workdir', '/app',
    ];
}

function nativeInvoiceShelfGateTreeHash(string $root): string
{
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    $entries = [];

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $relative = (string) substr($path, strlen($normalizedRoot) + 1);

        if (str_starts_with($relative, '.git/') || str_starts_with($relative, 'vendor/')) {
            continue;
        }

        $digest = hash_file('sha256', $file->getPathname());
        nativeInvoiceShelfGateAssert(is_string($digest), 'Could not hash '.$relative.'.');
        $entries[$relative] = $digest;
    }

    ksort($entries, SORT_STRING);

    return hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** @return array<string, mixed> */
function nativeInvoiceShelfGateJson(string $json, string $source): array
{
    $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    nativeInvoiceShelfGateAssert(is_array($decoded), $source.' is not a JSON object.');

    return $decoded;
}

/** @return array<string, string> */
function nativeInvoiceShelfGateLockPackages(string $path): array
{
    $source = file_get_contents($path);
    $lock = is_string($source) ? json_decode($source, true, 512, JSON_THROW_ON_ERROR) : null;
    $production = is_array($lock) ? ($lock['packages'] ?? null) : null;
    $development = is_array($lock) ? ($lock['packages-dev'] ?? null) : null;

    if (! is_array($production) || ! is_array($development)) {
        throw new RuntimeException('The pinned InvoiceShelf dependency lock is malformed.');
    }

    $packages = [];

    foreach ([...$production, ...$development] as $package) {
        $name = is_array($package) ? ($package['name'] ?? null) : null;
        $version = is_array($package) ? ($package['version'] ?? null) : null;

        if (! is_string($name) || ! is_string($version)) {
            throw new RuntimeException('A pinned InvoiceShelf package is malformed.');
        }

        $packages[$name] = $version;
    }

    ksort($packages, SORT_STRING);

    return $packages;
}

/** @param array<string, mixed> $value */
function nativeInvoiceShelfGateWriteJson(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    nativeInvoiceShelfGateAssert(file_put_contents($path, $json) === strlen($json), 'Could not write '.$path.'.');
}

function nativeInvoiceShelfGateRemoveTree(string $path, string $temporaryRoot): void
{
    $root = realpath($temporaryRoot);
    $target = realpath($path);

    if (! is_string($root) || ! is_string($target)) {
        throw new RuntimeException('Could not resolve an InvoiceShelf gate cleanup path.');
    }

    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    $normalizedTarget = str_replace('\\', '/', $target);
    nativeInvoiceShelfGateAssert(
        str_starts_with($normalizedTarget, $normalizedRoot.'/'),
        'Refusing to remove a path outside the InvoiceShelf gate workspace.',
    );

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $entryPath = $entry->getPathname();

        if ($entry->isDir() && ! $entry->isLink()) {
            @chmod($entryPath, 0700);
            nativeInvoiceShelfGateAssert(@rmdir($entryPath), 'Could not remove '.$entryPath.'.');

            continue;
        }

        @chmod($entryPath, 0600);
        nativeInvoiceShelfGateAssert(@unlink($entryPath), 'Could not remove '.$entryPath.'.');
    }

    @chmod($target, 0700);
    nativeInvoiceShelfGateAssert(@rmdir($target), 'Could not remove '.$target.'.');
}

$root = dirname(__DIR__, 2);
$autoload = $root.'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Run composer install before the native InvoiceShelf gate.\n");
    exit(1);
}

require $autoload;

$checkoutInput = getenv('DROVE_NATIVE_INVOICESHELF_CHECKOUT') ?: $root.'/.temp/native-corpus-invoiceshelf';
$checkout = realpath($checkoutInput);
$artifactInput = getenv('DROVE_NATIVE_INVOICESHELF_ARTIFACT_DIR');
$image = getenv('DROVE_NATIVE_INVOICESHELF_IMAGE') ?: 'drove-native-invoiceshelf-gate';
$token = bin2hex(random_bytes(6));
$temporaryRoot = sys_get_temp_dir().'/drove-native-invoiceshelf-gate-'.$token;
$workspace = $temporaryRoot.'/checkout';
$baselineWorkspace = $temporaryRoot.'/baseline-checkout';
$vendor = 'drove-native-invoiceshelf-vendor-'.$token;
$baselineVendor = 'drove-native-invoiceshelf-baseline-vendor-'.$token;
$composerCache = $root.'/.temp/native-invoiceshelf-composer-cache';
$artifactDirectory = null;
$managedArtifacts = false;
$volumeCreated = false;
$baselineVolumeCreated = false;
$exitCode = 0;
$droveRevision = null;

try {
    if (! is_string($checkout)) {
        throw new RuntimeException('The pinned InvoiceShelf checkout is unavailable.');
    }

    $droveRevision = nativeInvoiceShelfGateDroveRevision($root);
    nativeInvoiceShelfGateAssert(
        $droveRevision === null || getenv('DROVE_NATIVE_INVOICESHELF_SKIP_BUILD') !== '1',
        'Final InvoiceShelf evidence requires an image built from the bound Drove revision.',
    );

    nativeInvoiceShelfGateAssert(mkdir($temporaryRoot, 0700), 'Could not create the InvoiceShelf gate workspace.');
    nativeInvoiceShelfGateAssert(
        is_dir($composerCache) || mkdir($composerCache, 0700, true),
        'Could not create the Composer cache directory.',
    );
    nativeInvoiceShelfGateAssert(
        trim(nativeInvoiceShelfGateProcess(['git', '-C', $checkout, 'rev-parse', 'HEAD'], $root)->getOutput())
            === NATIVE_INVOICESHELF_GATE_COMMIT,
        'The InvoiceShelf checkout commit drifted.',
    );
    nativeInvoiceShelfGateAssert(
        trim(nativeInvoiceShelfGateProcess([
            'git', '-C', $checkout, 'status', '--porcelain=v1', '--untracked-files=all',
        ], $root)->getOutput()) === '',
        'The InvoiceShelf checkout is dirty.',
    );
    nativeInvoiceShelfGateProcess([
        'git', 'clone', '--quiet', '--no-hardlinks', '--no-checkout', $checkout, $workspace,
    ], $root, 180);
    nativeInvoiceShelfGateProcess([
        'git', '-C', $workspace, 'checkout', '--quiet', '--detach', NATIVE_INVOICESHELF_GATE_COMMIT,
    ], $root, 180);
    nativeInvoiceShelfGateProcess([
        'git', 'clone', '--quiet', '--no-hardlinks', '--no-checkout', $checkout, $baselineWorkspace,
    ], $root, 180);
    nativeInvoiceShelfGateProcess([
        'git', '-C', $baselineWorkspace, 'checkout', '--quiet', '--detach', NATIVE_INVOICESHELF_GATE_COMMIT,
    ], $root, 180);

    if (! is_string($artifactInput) || $artifactInput === '') {
        $artifactDirectory = $temporaryRoot.'/artifacts';
        nativeInvoiceShelfGateAssert(mkdir($artifactDirectory, 0700), 'Could not create the artifact directory.');
        $managedArtifacts = true;
    } else {
        nativeInvoiceShelfGateAssert(
            is_dir($artifactInput) || mkdir($artifactInput, 0700, true),
            'Could not create the artifact directory.',
        );
        $resolvedArtifacts = realpath($artifactInput);

        if (! is_string($resolvedArtifacts)) {
            throw new RuntimeException('Could not resolve the artifact directory.');
        }

        $artifactDirectory = $resolvedArtifacts;
    }

    if (getenv('DROVE_NATIVE_INVOICESHELF_SKIP_BUILD') !== '1') {
        nativeInvoiceShelfGateProcess([
            'docker', 'build', '--tag', 'drove-phase-three-laravel',
            '--file', $root.'/experiments/phase-3-laravel/Dockerfile', $root,
        ], $root, 900, true);
        nativeInvoiceShelfGateProcess([
            'docker', 'build', '--tag', $image,
            '--build-arg', 'DROVE_REVISION='.trim(nativeInvoiceShelfGateProcess([
                'git', 'rev-parse', 'HEAD',
            ], $root)->getOutput()),
            '--file', $root.'/benchmarks/corpus/Dockerfile', $root,
        ], $root, 900, true);
    }

    nativeInvoiceShelfGateProcess([
        'docker', 'run', '--rm',
        '--volume', nativeInvoiceShelfGateVolume($composerCache, '/composer-cache'),
        '--entrypoint', 'sh',
        $image,
        '-lc', 'cp -a /root/.composer/cache/. /composer-cache/',
    ], $root);

    $baselinePackages = nativeInvoiceShelfGateLockPackages($baselineWorkspace.'/composer.lock');
    nativeInvoiceShelfGateAssert(
        hash_file('sha256', $baselineWorkspace.'/composer.json')
            === 'd698b896b72569a943689d79e1e42852ecd8accfd4072f655e02286bdb32fb00'
            && hash_file('sha256', $baselineWorkspace.'/composer.lock')
                === 'd208f91d6c45de03bb2684b76051db6de28a6983b1c454bbc999e9c171df0342'
            && count($baselinePackages) === 179
            && ($baselinePackages['pestphp/pest'] ?? null) === 'v4.4.3'
            && ($baselinePackages['phpunit/phpunit'] ?? null) === '12.5.14'
            && ($baselinePackages['pestphp/pest-plugin-laravel'] ?? null) === 'v4.1.0'
            && array_filter(
                array_keys($baselinePackages),
                static fn (string $package): bool => str_starts_with($package, 'oxhq/'),
            ) === [],
        'The independent InvoiceShelf baseline dependency graph diverged.',
    );
    nativeInvoiceShelfGateProcess(['docker', 'volume', 'create', $baselineVendor], $root);
    $baselineVolumeCreated = true;
    $baselineDocker = [
        'docker', 'run', '--rm',
        '--env', 'COMPOSER_CACHE_DIR=/composer-cache',
        '--volume', nativeInvoiceShelfGateVolume($baselineWorkspace, '/app'),
        '--volume', nativeInvoiceShelfGateVolume($baselineVendor, '/app/vendor'),
        '--volume', nativeInvoiceShelfGateVolume($composerCache, '/composer-cache'),
        '--volume', nativeInvoiceShelfGateVolume($artifactDirectory, '/artifacts'),
        '--workdir', '/app',
    ];
    nativeInvoiceShelfGateProcess([
        ...$baselineDocker,
        '--entrypoint', 'sh',
        $image,
        '-lc', <<<'SH'
set -eu
composer install --no-interaction --no-progress --no-scripts --prefer-dist --quiet
git checkout -- composer.lock
composer dump-autoload --no-interaction --no-scripts --optimize --quiet
php artisan package:discover --ansi >/dev/null
git checkout -- composer.lock
if [ -e vendor/oxhq ]; then
    echo 'The independent Pest baseline installed Drove.' >&2
    exit 1
fi
php -r '
    require "vendor/autoload.php";
    foreach (["Drove\\Native\\Runner", "Drove\\Bridge\\Pest\\Bridge", "Drove\\Pest\\Runner"] as $class) {
        if (class_exists($class)) {
            fwrite(STDERR, "Unexpected Drove baseline class: {$class}\n");
            exit(1);
        }
    }
'
SH,
    ], $root, 900);
    nativeInvoiceShelfGateAssert(
        hash_file('sha256', $baselineWorkspace.'/composer.lock')
            === 'd208f91d6c45de03bb2684b76051db6de28a6983b1c454bbc999e9c171df0342',
        'The independent InvoiceShelf install modified its pinned lock.',
    );
    nativeInvoiceShelfGateProcess([
        ...$baselineDocker,
        '--env', 'APP_KEY=base64:x5m/qWTRn07o2rXmBGZap8zkqzrPybJGqGbi2f8I7Pc=',
        '--env', 'NIGHTWATCH_ENABLED=false',
        '--env', 'PULSE_ENABLED=false',
        '--env', 'TELESCOPE_ENABLED=false',
        '--entrypoint', 'sh',
        $image,
        '-lc', <<<'SH'
set -eu
find tests/Unit tests/Feature/Customer -type f -name '*.php' -print | LC_ALL=C sort > /tmp/selection
test "$(wc -l < /tmp/selection | tr -d '[:space:]')" = 47
php vendor/bin/pest --configuration=phpunit.xml --colors=never --do-not-cache-result \
    --cache-directory=/tmp/drove-invoiceshelf-phpunit-cache \
    --log-junit=/artifacts/invoiceshelf-pest-baseline.xml $(cat /tmp/selection)
SH,
    ], $root, 900);
    $normalizedBaseline = nativeInvoiceShelfGateProcess([
        PHP_BINARY,
        $root.'/experiments/native-invoiceshelf-corpus/normalize-baseline.php',
        $baselineWorkspace,
        $artifactDirectory.'/invoiceshelf-pest-baseline.xml',
    ], $root);
    $independentBaseline = nativeInvoiceShelfGateJson(
        $normalizedBaseline->getOutput(),
        'The independent InvoiceShelf Pest baseline',
    );
    $committedBaselineSource = file_get_contents(
        $root.'/experiments/native-invoiceshelf-corpus/detailed-baseline.json',
    );

    if (! is_string($committedBaselineSource)) {
        throw new RuntimeException('The committed InvoiceShelf baseline is unreadable.');
    }

    $committedBaseline = nativeInvoiceShelfGateJson(
        $committedBaselineSource,
        'The committed InvoiceShelf baseline',
    );
    nativeInvoiceShelfGateAssert(
        $independentBaseline === $committedBaseline,
        'The real Pest InvoiceShelf case baseline diverged from the committed rows.',
    );
    nativeInvoiceShelfGateWriteJson(
        $artifactDirectory.'/invoiceshelf-pest-baseline.json',
        $independentBaseline,
    );

    nativeInvoiceShelfGateProcess(['docker', 'volume', 'create', $vendor], $root);
    $volumeCreated = true;
    $docker = nativeInvoiceShelfGateDocker($root, $workspace, $vendor, $composerCache);

    nativeInvoiceShelfGateProcess([
        ...$docker,
        '--env', 'CORPUS_DISPOSABLE=1',
        '--env', 'DROVE_SOURCE=/drove',
        '--entrypoint', 'sh',
        $image,
        '-lc', 'sh /drove/benchmarks/corpus/prepare-native-invoiceshelf.sh',
    ], $root, 900);
    $environmentProcess = nativeInvoiceShelfGateProcess([
        ...$docker,
        '--env', 'DROVE_SOURCE=/drove',
        $image,
        'php', '-d', 'auto_prepend_file=',
        '/drove/benchmarks/corpus/verify-native-composer-environment.php', 'invoiceshelf', '/app',
    ], $root);
    $environment = nativeInvoiceShelfGateJson(
        $environmentProcess->getOutput(),
        'The native InvoiceShelf environment evidence',
    );
    $pathPackageContent = $environment['path_package_content'] ?? null;
    nativeInvoiceShelfGateAssert(
        ($environment['profile'] ?? null) === 'invoiceshelf'
            && ($environment['mode'] ?? null) === 'installed'
            && ($environment['packages'] ?? null) === 124
            && ($environment['baseline_shared_packages'] ?? null) === 122
            && ($environment['allowed_extra_packages'] ?? null) === ['oxhq/drove', 'oxhq/drove-laravel']
            && ($environment['forbidden_packages'] ?? null) === []
            && ($environment['forbidden_vendor_paths'] ?? null) === []
            && is_array($pathPackageContent)
            && array_keys($pathPackageContent) === ['oxhq/drove', 'oxhq/drove-laravel'],
        'The native InvoiceShelf dependency proof diverged.',
    );
    nativeInvoiceShelfGateWriteJson($artifactDirectory.'/invoiceshelf-native-environment.json', $environment);

    nativeInvoiceShelfGateProcess([
        ...$docker,
        '--env', 'CORPUS_DISPOSABLE=1',
        '--env', 'DROVE_SOURCE=/drove',
        '--entrypoint', 'sh',
        $image,
        '-lc', 'sh /drove/benchmarks/corpus/test-native-composer-environment.sh invoiceshelf /app',
    ], $root);
    nativeInvoiceShelfGateRejectedProcess([
        ...$docker,
        '--env', 'DROVE_NATIVE_INVOICESHELF_FAULT=runtime-guard',
        $image,
        'php', '/drove/experiments/native-invoiceshelf-corpus/proof.php', '/app', '/drove',
    ], $root, 'DROVE_NATIVE_INVOICESHELF_EXTERNAL_TEST_RUNTIME_LOADED');
    $treeHash = nativeInvoiceShelfGateTreeHash($workspace);

    nativeInvoiceShelfGateProcess([
        ...$docker,
        '--env', 'DROVE_NATIVE_INVOICESHELF_FAULT=after-capture',
        '--entrypoint', 'sh',
        $image,
        '-lc', <<<'SH'
set -eu
before=$(find /tmp -maxdepth 1 -name 'drove-native-invoiceshelf-*' | LC_ALL=C sort)
if php /drove/experiments/native-invoiceshelf-corpus/proof.php /app /drove > /tmp/proof.stdout 2> /tmp/proof.stderr; then
    echo 'The injected native InvoiceShelf failure returned exit zero.' >&2
    exit 1
fi
if [ -s /tmp/proof.stdout ]; then
    echo 'The injected native InvoiceShelf failure wrote unexpected stdout.' >&2
    exit 1
fi
grep -F 'DROVE_NATIVE_INVOICESHELF_INJECTED_FAILURE_AFTER_CAPTURE' /tmp/proof.stderr >/dev/null
after=$(find /tmp -maxdepth 1 -name 'drove-native-invoiceshelf-*' | LC_ALL=C sort)
test "$before" = "$after"
SH,
    ], $root);
    nativeInvoiceShelfGateAssert(
        nativeInvoiceShelfGateTreeHash($workspace) === $treeHash,
        'The InvoiceShelf application tree changed after the injected failure.',
    );

    $runs = [];

    foreach ([1, 2, 4, 8, 16, 30] as $processes) {
        $run = nativeInvoiceShelfGateProcess([
            ...$docker,
            '--env', 'DROVE_NATIVE_INVOICESHELF_PROCESSES='.$processes,
            '--env', 'DROVER_LIBRARY=/usr/local/lib/libdrover.so',
            $image,
            'php', '/drove/experiments/native-invoiceshelf-corpus/proof.php', '/app', '/drove',
        ], $root);
        $summary = nativeInvoiceShelfGateJson($run->getOutput(), 'InvoiceShelf C'.$processes);
        nativeInvoiceShelfGateWriteJson(
            $artifactDirectory.'/invoiceshelf-native-c'.$processes.'.json',
            $summary,
        );
        $topology = $summary['scheduler']['topology'] ?? null;
        $observedPeakLanes = $summary['scheduler']['observed_peak_lanes'] ?? null;
        $runtimeAudits = $summary['runtime_audits'] ?? null;
        $treeHashAfter = nativeInvoiceShelfGateTreeHash($workspace);
        nativeInvoiceShelfGateAssert(
            ($summary['ok'] ?? null) === true
                && ($summary['native'] ?? null) === [
                    'files' => 47,
                    'cases' => 202,
                    'assertions' => 328,
                    'case_names_sha256' => 'fd5cde4956db4190ada9f6ae83bfe934377686ade020f17763ec6fcb2eaabc6a',
                ]
                && ($summary['source_identity_sha256'] ?? null) === 'cce8db4965fa15ccbda5036e65633ad85a4631dd98e00533c229380d06f7e930'
                && ($summary['case_semantics']['sha256'] ?? null) === '7159f3f05f154ffb9f0112f9f74d6a98147482b3d1fddbc5af8f392e4f79c057'
                && ($summary['case_semantics']['cases'] ?? null) === $committedBaseline['cases']
                && ($summary['scheduler']['requested_processes'] ?? null) === $processes
                && ($summary['scheduler']['runnable_cases'] ?? null) === 202
                && ($summary['scheduler']['telemetry_cases'] ?? null) === 202
                && ($summary['scheduler']['unique_executor_pids'] ?? null) === 202
                && ($summary['scheduler']['one_child_pid_per_case'] ?? null) === true
                && is_int($observedPeakLanes)
                && $observedPeakLanes >= 1
                && $observedPeakLanes <= min($processes, 202)
                && is_array($topology)
                && ($topology['executor_workers'] ?? null) === 202
                && is_int($topology['scope_workers'] ?? null)
                && ($topology['forks'] ?? null) === 202 + $topology['scope_workers']
                && ($topology['process_anchors'] ?? null) === 0
                && ($summary['descendant_checks'] ?? null) === $topology['forks']
                && ($summary['distinct_descendant_pids'] ?? null) === $topology['forks']
                && is_array($runtimeAudits)
                && ($runtimeAudits['checks'] ?? null) === $topology['forks'] + 1
                && ($runtimeAudits['unique_pids'] ?? null) === $topology['forks'] + 1
                && ($runtimeAudits['test_workers'] ?? null) === 202
                && ($runtimeAudits['scope_workers'] ?? null) === $topology['scope_workers']
                && ($runtimeAudits['root_workers'] ?? null) === 1
                && ($summary['prepared_database'] ?? null) === [
                    'format' => 'sqlite-logical-v1',
                    'sha256' => 'a0045ae4d7b5c7f0124843b5b1d14d12e62cf153823498bf2cfd8940cf0b606e',
                    'tables' => 48,
                    'columns' => 563,
                    'rows' => 161,
                    'migrations' => 155,
                    'addresses' => 0,
                ]
                && ($summary['database_preparation_clock'] ?? null) === '2000-01-01 00:00:00 UTC'
                && ($summary['database_digest_mutation'] ?? null) === [
                    'table' => 'settings',
                    'before_sha256' => 'a0045ae4d7b5c7f0124843b5b1d14d12e62cf153823498bf2cfd8940cf0b606e',
                    'mutated_sha256' => '4a5ae9eed139bf78d7d6a1c332241af02081c2dc8ef43838bcd29afceeaf7bf6',
                    'restored_sha256' => 'a0045ae4d7b5c7f0124843b5b1d14d12e62cf153823498bf2cfd8940cf0b606e',
                    'detected' => true,
                    'rollback_restored' => true,
                ]
                && ($summary['blocked_files'] ?? null) === []
                && ($summary['external_test_runtime'] ?? null) === [
                    'pest' => false,
                    'phpunit' => false,
                    'testbench' => false,
                    'project_base_test_case' => false,
                ]
                && $treeHashAfter === $treeHash,
            'The native InvoiceShelf C'.$processes.' proof diverged: '.json_encode([
                'summary' => $summary,
                'tree_before' => $treeHash,
                'tree_after' => $treeHashAfter,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $runs[$processes] = $summary;
    }

    $matrix = array_map(
        static fn (int $processes): array => [
            'requested_processes' => $processes,
            'observed_peak_lanes' => $runs[$processes]['scheduler']['observed_peak_lanes'],
            'runnable_cases' => $runs[$processes]['scheduler']['runnable_cases'],
            'telemetry_cases' => $runs[$processes]['scheduler']['telemetry_cases'],
            'unique_executor_pids' => $runs[$processes]['scheduler']['unique_executor_pids'],
            'distinct_descendant_pids' => $runs[$processes]['distinct_descendant_pids'],
            'one_child_pid_per_case' => $runs[$processes]['scheduler']['one_child_pid_per_case'],
            'topology' => $runs[$processes]['scheduler']['topology'],
            'runtime_audits' => $runs[$processes]['runtime_audits'],
            'semantic_match' => true,
        ],
        [1, 2, 4, 8, 16, 30],
    );
    nativeInvoiceShelfGateAssert(
        nativeInvoiceShelfGateDroveRevision($root) === $droveRevision,
        'The Drove revision changed during the InvoiceShelf gate.',
    );
    $result = [
        'ok' => true,
        'corpus' => 'InvoiceShelf/InvoiceShelf',
        'commit' => NATIVE_INVOICESHELF_GATE_COMMIT,
        'drove_revision' => $droveRevision,
        'environment' => $environment,
        'independent_baseline' => [
            'runner' => $independentBaseline['runner'],
            'manifest_sha256' => $independentBaseline['manifest_sha256'],
            'lock_sha256' => $independentBaseline['lock_sha256'],
            'packages' => count($baselinePackages),
            'contains_drove' => false,
            'case_semantics_sha256' => $independentBaseline['case_semantics_sha256'],
            'exact_native_match' => true,
        ],
        'selection' => $runs[1]['native'],
        'prepared_database' => $runs[1]['prepared_database'],
        'database_preparation_clock' => $runs[1]['database_preparation_clock'],
        'database_digest_mutation' => $runs[1]['database_digest_mutation'],
        'application_tree_sha256' => $treeHash,
        'fault_cleanup' => true,
        'matrix' => $matrix,
        'performance_interpretation' => false,
    ];
    nativeInvoiceShelfGateWriteJson($artifactDirectory.'/invoiceshelf-native-gate.json', $result);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $failure) {
    fwrite(STDERR, 'DROVE_NATIVE_INVOICESHELF_GATE_FAILED '.$failure::class.': '.$failure->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    $cleanupFailures = [];

    if ($volumeCreated) {
        $cleanup = new Process(['docker', 'volume', 'rm', $vendor], $root);
        $cleanup->run();

        if (! $cleanup->isSuccessful()) {
            $cleanupFailures[] = trim($cleanup->getErrorOutput());
        }
    }

    if ($baselineVolumeCreated) {
        $cleanup = new Process(['docker', 'volume', 'rm', $baselineVendor], $root);
        $cleanup->run();

        if (! $cleanup->isSuccessful()) {
            $cleanupFailures[] = trim($cleanup->getErrorOutput());
        }
    }

    foreach ([
        $managedArtifacts ? $artifactDirectory : null,
        is_dir($workspace) ? $workspace : null,
        is_dir($baselineWorkspace) ? $baselineWorkspace : null,
    ] as $path) {
        if (! is_string($path)) {
            continue;
        }

        try {
            nativeInvoiceShelfGateRemoveTree($path, $temporaryRoot);
        } catch (Throwable $failure) {
            $cleanupFailures[] = $failure->getMessage();
        }
    }

    if (is_dir($temporaryRoot) && ! @rmdir($temporaryRoot)) {
        $cleanupFailures[] = 'Temporary workspace remains: '.$temporaryRoot;
    }

    if ($cleanupFailures !== []) {
        fwrite(STDERR, 'DROVE_NATIVE_INVOICESHELF_GATE_CLEANUP_FAILED '.implode(' | ', $cleanupFailures).PHP_EOL);
        $exitCode = 1;
    }
}

exit($exitCode);
