<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

const NATIVE_FILAMENT_GATE_COMMIT = 'e9348b2e3792088ee877068116b6c1e1559a7df8';
const NATIVE_FILAMENT_GATE_SOURCE_IDENTITY = '5fd143c98bba4b5d11edb59c398c2512d21da02c68278ecbfd56b65c9a8b3e4c';
const NATIVE_FILAMENT_GATE_DATABASE_SHA256 = 'aeadc3ffd22d14d9a0e9e9ec5e72ddd120b9ed2f1aa91d2f440247aa041a3586';
const NATIVE_FILAMENT_GATE_SNAPSHOT_TREE_SHA256 = 'dd10ad77be045ab69f21457b315b044a1f4359603f1a206e1c995f3cf3bb3b34';
const NATIVE_FILAMENT_GATE_NONSERIAL_PEST_IDS_SHA256 = 'fcd63a2c6a2028c3b6c46bae3d11ee66f8216b21d53d8631b091ecf7f1d96aa3';
const NATIVE_FILAMENT_GATE_SERIAL_PEST_IDS_SHA256 = 'ce521a8b6ce9e55f43fb27adf9024f06e9bc489bc92849c84a36a03153739df0';
const NATIVE_FILAMENT_GATE_COMPOSER_LOCK_BLOB_SHA1 = '485810b09e5fea4a8609b72be78474848cc4ca13';
const NATIVE_FILAMENT_GATE_COMPOSER_LOCK_SHA256 = 'f03b2f38dff3b91c2b6a09eadf00c7c516db252064304a6ad78f86b3dd9faf32';

function nativeFilamentGateAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $command */
function nativeFilamentGateProcess(
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

function nativeFilamentGateDroveRevision(string $root): ?string
{
    $expected = getenv('DROVE_EXPECTED_REVISION');

    if ($expected === false || $expected === '') {
        return null;
    }

    nativeFilamentGateAssert(preg_match('/^[0-9a-f]{40}$/D', $expected) === 1, 'DROVE_EXPECTED_REVISION must be an exact Drove commit.');
    $actual = trim(nativeFilamentGateProcess(['git', '-C', $root, 'rev-parse', 'HEAD'], $root)->getOutput());
    $status = nativeFilamentGateProcess([
        'git', '-C', $root, 'status', '--porcelain=v1', '--untracked-files=all',
    ], $root)->getOutput();
    nativeFilamentGateAssert($actual === $expected && trim($status) === '', 'The final Filament evidence is not bound to a clean Drove revision.');

    return $expected;
}

function nativeFilamentGateVolume(string $source, string $target, bool $readOnly = false): string
{
    return $source.':'.$target.($readOnly ? ':ro' : '');
}

/** @return array<string, mixed> */
function nativeFilamentGateJson(string $contents, string $source): array
{
    $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
    nativeFilamentGateAssert(is_array($decoded), $source.' is not a JSON object.');

    return $decoded;
}

/** @return array<string, mixed> */
function nativeFilamentGateJsonFile(string $path, string $source): array
{
    $contents = is_file($path) ? file_get_contents($path) : false;

    if (! is_string($contents)) {
        throw new RuntimeException($source.' is unreadable.');
    }

    return nativeFilamentGateJson($contents, $source);
}

/** @return array{git_blob_sha1: string, worktree_canonical_sha256: string, normalization: string} */
function nativeFilamentGateLockIdentity(string $checkout, string $root): array
{
    $source = file_get_contents($checkout.'/composer.lock');

    if (! is_string($source)) {
        throw new RuntimeException('The Filament Composer lock is unreadable.');
    }

    $canonical = str_replace("\r\n", "\n", $source);
    nativeFilamentGateAssert(! str_contains($canonical, "\r"), 'The Filament Composer lock contains unsupported line endings.');

    return [
        'git_blob_sha1' => trim(nativeFilamentGateProcess([
            'git', '-C', $checkout, 'rev-parse', 'HEAD:composer.lock',
        ], $root)->getOutput()),
        'worktree_canonical_sha256' => hash('sha256', $canonical),
        'normalization' => 'CRLF-to-LF-only',
    ];
}

/** @param array<string, mixed> $value */
function nativeFilamentGateWriteJson(string $path, array $value): void
{
    $contents = json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
    nativeFilamentGateAssert(
        file_put_contents($path, $contents, LOCK_EX) === strlen($contents),
        'Could not write '.$path.'.',
    );
}

function nativeFilamentGateRemoveTree(string $path, string $temporaryRoot): void
{
    $root = realpath($temporaryRoot);
    $target = realpath($path);

    if (! is_string($root) || ! is_string($target)) {
        throw new RuntimeException('Could not resolve a Filament gate cleanup path.');
    }

    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    $normalizedTarget = str_replace('\\', '/', $target);
    nativeFilamentGateAssert(
        str_starts_with($normalizedTarget, $normalizedRoot.'/'),
        'Refusing to remove a path outside the Filament gate workspace.',
    );
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $entryPath = $entry->getPathname();

        if ($entry->isDir() && ! $entry->isLink()) {
            @chmod($entryPath, 0700);
            nativeFilamentGateAssert(@rmdir($entryPath), 'Could not remove '.$entryPath.'.');

            continue;
        }

        @chmod($entryPath, 0600);
        nativeFilamentGateAssert(@unlink($entryPath), 'Could not remove '.$entryPath.'.');
    }

    @chmod($target, 0700);
    nativeFilamentGateAssert(@rmdir($target), 'Could not remove '.$target.'.');
}

$root = dirname(__DIR__, 2);
$autoload = $root.'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Run composer install before the native Filament gate.\n");
    exit(1);
}

require $autoload;

$checkoutInput = getenv('DROVE_NATIVE_FILAMENT_CHECKOUT') ?: $root.'/.temp/native-corpus-filament';
$checkout = realpath($checkoutInput);
$artifactInput = getenv('DROVE_NATIVE_FILAMENT_ARTIFACT_DIR');
$image = getenv('DROVE_NATIVE_FILAMENT_IMAGE') ?: 'drove-native-filament-gate';
$token = bin2hex(random_bytes(6));
$temporaryRoot = sys_get_temp_dir().'/drove-native-filament-gate-'.$token;
$workspace = $temporaryRoot.'/checkout';
$baselineWorkspace = $temporaryRoot.'/baseline-checkout';
$vendor = 'drove-native-filament-vendor-'.$token;
$baselineVendor = 'drove-native-filament-pest-vendor-'.$token;
$composerCache = $root.'/.temp/native-filament-composer-cache';
$artifactDirectory = null;
$managedArtifacts = false;
$volumeCreated = false;
$baselineVolumeCreated = false;
$exitCode = 0;
$droveRevision = null;

try {
    if (! is_string($checkout)) {
        throw new RuntimeException('The pinned Filament checkout is unavailable.');
    }

    $droveRevision = nativeFilamentGateDroveRevision($root);
    nativeFilamentGateAssert(
        $droveRevision === null || getenv('DROVE_NATIVE_FILAMENT_SKIP_BUILD') !== '1',
        'Final Filament evidence requires an image built from the bound Drove revision.',
    );

    nativeFilamentGateAssert(mkdir($temporaryRoot, 0700), 'Could not create the Filament gate workspace.');
    nativeFilamentGateAssert(
        is_dir($composerCache) || mkdir($composerCache, 0700, true),
        'Could not create the Composer cache directory.',
    );
    nativeFilamentGateAssert(
        trim(nativeFilamentGateProcess(['git', '-C', $checkout, 'rev-parse', 'HEAD'], $root)->getOutput())
            === NATIVE_FILAMENT_GATE_COMMIT,
        'The Filament checkout commit drifted.',
    );
    nativeFilamentGateAssert(
        trim(nativeFilamentGateProcess([
            'git', '-C', $checkout, 'status', '--porcelain=v1', '--untracked-files=all',
        ], $root)->getOutput()) === '',
        'The Filament checkout is dirty.',
    );
    nativeFilamentGateProcess([
        'git', 'clone', '--quiet', '--no-hardlinks', '--no-checkout', $checkout, $workspace,
    ], $root, 180);
    nativeFilamentGateProcess([
        'git', 'clone', '--quiet', '--no-hardlinks', '--no-checkout', $checkout, $baselineWorkspace,
    ], $root, 180);

    if (PHP_OS_FAMILY === 'Windows') {
        foreach ([$workspace, $baselineWorkspace] as $windowsCheckout) {
            nativeFilamentGateProcess([
                'git', '-C', $windowsCheckout, 'config', 'core.autocrlf', 'false',
            ], $root);
            nativeFilamentGateProcess([
                'git', '-C', $windowsCheckout, 'config', 'core.filemode', 'false',
            ], $root);
            nativeFilamentGateProcess([
                'git', '-C', $windowsCheckout, 'config', 'core.longpaths', 'true',
            ], $root);
        }
    }

    foreach ([$workspace, $baselineWorkspace] as $cleanCheckout) {
        nativeFilamentGateProcess([
            'git', '-C', $cleanCheckout, 'checkout', '--quiet', '--detach', NATIVE_FILAMENT_GATE_COMMIT,
        ], $root, 180);
    }

    $expectedBaselineLockIdentity = [
        'git_blob_sha1' => NATIVE_FILAMENT_GATE_COMPOSER_LOCK_BLOB_SHA1,
        'worktree_canonical_sha256' => NATIVE_FILAMENT_GATE_COMPOSER_LOCK_SHA256,
        'normalization' => 'CRLF-to-LF-only',
    ];
    $baselineLockBeforeInstall = nativeFilamentGateLockIdentity($baselineWorkspace, $root);
    nativeFilamentGateAssert(
        $baselineLockBeforeInstall === $expectedBaselineLockIdentity,
        'The independent Pest Filament lock identity drifted before install.',
    );

    if (! is_string($artifactInput) || $artifactInput === '') {
        $artifactDirectory = $temporaryRoot.'/artifacts';
        nativeFilamentGateAssert(mkdir($artifactDirectory, 0700), 'Could not create the artifact directory.');
        $managedArtifacts = true;
    } else {
        nativeFilamentGateAssert(
            is_dir($artifactInput) || mkdir($artifactInput, 0700, true),
            'Could not create the artifact directory.',
        );
        $resolvedArtifacts = realpath($artifactInput);

        if (! is_string($resolvedArtifacts)) {
            throw new RuntimeException('Could not resolve the artifact directory.');
        }

        $artifactDirectory = $resolvedArtifacts;
    }

    if (getenv('DROVE_NATIVE_FILAMENT_SKIP_BUILD') !== '1') {
        nativeFilamentGateProcess([
            'docker', 'build', '--tag', 'drove-phase-three-laravel',
            '--file', $root.'/experiments/phase-3-laravel/Dockerfile', $root,
        ], $root, 1200, true);
        $revision = trim(nativeFilamentGateProcess(['git', 'rev-parse', 'HEAD'], $root)->getOutput());
        nativeFilamentGateProcess([
            'docker', 'build', '--tag', $image,
            '--build-arg', 'DROVE_REVISION='.$revision,
            '--file', $root.'/benchmarks/corpus/Dockerfile', $root,
        ], $root, 1200, true);
    }

    nativeFilamentGateProcess(['docker', 'volume', 'create', $vendor], $root);
    $volumeCreated = true;
    nativeFilamentGateProcess(['docker', 'volume', 'create', $baselineVendor], $root);
    $baselineVolumeCreated = true;
    $docker = [
        'docker', 'run', '--rm',
        '--env', 'COMPOSER_CACHE_DIR=/composer-cache',
        '--volume', nativeFilamentGateVolume($root, '/drove', true),
        '--volume', nativeFilamentGateVolume($workspace, '/app'),
        '--volume', nativeFilamentGateVolume($vendor, '/app/vendor'),
        '--volume', nativeFilamentGateVolume($composerCache, '/composer-cache'),
        '--workdir', '/app',
    ];
    $baselineDocker = [
        'docker', 'run', '--rm',
        '--env', 'APP_ENV=self-testing',
        '--env', 'APP_KEY=base64:yk+bUVuZa1p86Dqjk9OjVK2R1pm6XHxC6xEKFq8utH0=',
        '--env', 'DB_CONNECTION=testing',
        '--env', 'COMPOSER_CACHE_DIR=/composer-cache',
        '--volume', nativeFilamentGateVolume($root, '/drove', true),
        '--volume', nativeFilamentGateVolume($baselineWorkspace, '/app'),
        '--volume', nativeFilamentGateVolume($baselineVendor, '/app/vendor'),
        '--volume', nativeFilamentGateVolume($composerCache, '/composer-cache'),
        '--volume', nativeFilamentGateVolume($artifactDirectory, '/artifacts'),
        '--workdir', '/app',
    ];

    nativeFilamentGateProcess([
        ...$baselineDocker,
        $image,
        'composer', 'install', '--no-interaction', '--no-progress', '--no-scripts',
        '--prefer-dist', '--optimize-autoloader',
    ], $root, 1200);
    $baselineLockAfterInstall = nativeFilamentGateLockIdentity($baselineWorkspace, $root);
    nativeFilamentGateAssert(
        $baselineLockAfterInstall === $baselineLockBeforeInstall,
        'The independent Pest Filament lock identity changed during install.',
    );

    foreach ([
        ['cohort' => 'nonserial', 'group' => '--exclude-group=serial'],
        ['cohort' => 'serial', 'group' => '--group=serial'],
    ] as $baselineCell) {
        $cohort = $baselineCell['cohort'];
        $group = $baselineCell['group'];
        $script = <<<'SH'
set -eu
selection=$(mktemp)
trap 'rm -f -- "$selection"' EXIT HUP INT TERM
sh /drove/benchmarks/corpus/select.sh filament /app > "$selection"
php /drove/benchmarks/corpus/measure.php \
    "/artifacts/filament-pest-${DROVE_FILAMENT_BASELINE_COHORT}-c1.measurement.json" \
    "/artifacts/filament-pest-${DROVE_FILAMENT_BASELINE_COHORT}-c1.raw.log" -- \
    php -d auto_prepend_file= vendor/bin/pest --configuration=phpunit.xml.dist \
        "$DROVE_FILAMENT_BASELINE_GROUP" \
        "--log-junit=/artifacts/filament-pest-${DROVE_FILAMENT_BASELINE_COHORT}-c1.xml" \
        $(cat "$selection")
SH;
        nativeFilamentGateProcess([
            ...$baselineDocker,
            '--env', 'DROVE_FILAMENT_BASELINE_COHORT='.$cohort,
            '--env', 'DROVE_FILAMENT_BASELINE_GROUP='.$group,
            '--entrypoint', 'sh',
            $image,
            '-lc', $script,
        ], $root, 1200);
    }

    $baselineEvidence = nativeFilamentGateJson(
        nativeFilamentGateProcess([
            ...$baselineDocker,
            $image,
            'php', '-d', 'auto_prepend_file=',
            '/drove/experiments/native-filament-corpus/verify-baseline.php',
            '/app',
            '/artifacts/filament-pest-nonserial-c1.xml',
            '/artifacts/filament-pest-serial-c1.xml',
            '/drove/experiments/native-filament-corpus/baseline-cases.json',
        ], $root)->getOutput(),
        'The independent Pest Filament baseline evidence',
    );
    nativeFilamentGateAssert(
        ($baselineEvidence['ok'] ?? null) === true
            && ($baselineEvidence['runner'] ?? null) === 'pestphp/pest'
            && ($baselineEvidence['vendor_mode'] ?? null) === 'independent-upstream-lock'
            && ($baselineEvidence['lock_identity'] ?? null) === $baselineLockAfterInstall
            && ($baselineEvidence['cohorts']['nonserial'] ?? null) === [
                'cases' => 677,
                'assertions' => 1016,
                'semantic_sha256' => '39a3fbbad932ea49affec324a73f33682a856dc24115715024e093ec3c796fc5',
            ]
            && ($baselineEvidence['cohorts']['serial'] ?? null) === [
                'cases' => 28,
                'assertions' => 34,
                'semantic_sha256' => '871ef826767e5b4d02348b77616df39de88e324bbbb1dd1d74a2875e34659209',
            ]
            && ($baselineEvidence['forbidden_packages'] ?? null) === []
            && ($baselineEvidence['forbidden_runtime_files'] ?? null) === [],
        'The independent Pest Filament baseline diverged.',
    );
    nativeFilamentGateWriteJson(
        $artifactDirectory.'/filament-pest-independent-baseline.json',
        $baselineEvidence,
    );
    $committedBaseline = nativeFilamentGateJsonFile(
        $root.'/experiments/native-filament-corpus/baseline-cases.json',
        'The committed Filament case baseline',
    );

    nativeFilamentGateProcess([
        ...$docker,
        '--env', 'CORPUS_DISPOSABLE=1',
        '--env', 'DROVE_SOURCE=/drove',
        '--entrypoint', 'sh',
        $image,
        '-lc', 'sh /drove/benchmarks/corpus/prepare-native-filament.sh',
    ], $root, 1200, true);
    $environmentProcess = nativeFilamentGateProcess([
        ...$docker,
        '--env', 'DROVE_SOURCE=/drove',
        $image,
        'php', '-d', 'auto_prepend_file=',
        '/drove/benchmarks/corpus/verify-native-composer-environment.php', 'filament', '/app',
    ], $root);
    $environment = nativeFilamentGateJson(
        $environmentProcess->getOutput(),
        'The native Filament environment evidence',
    );
    $pathPackageContent = $environment['path_package_content'] ?? null;
    nativeFilamentGateAssert(
        ($environment['profile'] ?? null) === 'filament'
            && ($environment['mode'] ?? null) === 'installed'
            && ($environment['packages'] ?? null) === 118
            && ($environment['baseline_shared_packages'] ?? null) === 106
            && ($environment['required_symbol_negative_probe'] ?? null)
                === 'Drove\\Corpus\\DefinitelyMissingRequiredSymbol'
            && ($environment['required_symbols']['Znck\\Eloquent\\Traits\\BelongsToThrough'] ?? null)
                === 'trait'
            && ($environment['allowed_extra_packages'] ?? null) === [
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
            ]
            && is_array($pathPackageContent)
            && array_keys($pathPackageContent) === [
                'filament/support',
                'filament/spatie-laravel-settings-plugin',
                'oxhq/drove',
                'oxhq/drove-laravel',
            ]
            && ($environment['forbidden_packages'] ?? null) === []
            && ($environment['forbidden_vendor_paths'] ?? null) === []
            && ($environment['forbidden_runtime_symbols'] ?? null) === []
            && ($environment['selection'] ?? null) === [
                'files' => 39,
                'sha256' => 'a626719abe7c93e77909888deeeccb1b1a7ea6bceed1cb5eea551e39acb82bcf',
            ],
        'The native Filament dependency proof diverged.',
    );
    nativeFilamentGateWriteJson($artifactDirectory.'/filament-native-environment.json', $environment);

    $diagnosis = nativeFilamentGateJson(
        nativeFilamentGateProcess([
            ...$docker,
            $image,
            'php', '-d', 'auto_prepend_file=',
            '/drove/experiments/native-filament-corpus/diagnose.php', '/app',
        ], $root)->getOutput(),
        'The native Filament migration diagnosis',
    );
    nativeFilamentGateAssert(
        ($diagnosis['files'] ?? null) === 39
            && ($diagnosis['ready'] ?? null) === 39
            && ($diagnosis['blocked'] ?? null) === []
            && is_array($diagnosis['codemods'] ?? null)
            && count($diagnosis['codemods']) === 39,
        'The native Filament migration diagnosis diverged.',
    );
    nativeFilamentGateWriteJson($artifactDirectory.'/filament-native-diagnosis.json', $diagnosis);
    $guardFaults = [];

    foreach (['class', 'interface', 'trait', 'file'] as $faultKind) {
        $guardFault = new Process([
            ...$docker,
            '--env', 'DROVE_NATIVE_FILAMENT_COHORT=serial',
            '--env', 'DROVE_NATIVE_FILAMENT_PROCESSES=1',
            '--env', 'DROVE_NATIVE_FILAMENT_DEBUG_NAME=it returns the view set in the property',
            '--env', 'DROVE_NATIVE_FILAMENT_FAULT_RUNTIME_GUARD='.$faultKind,
            '--env', 'DROVER_LIBRARY=/usr/local/lib/libdrover.so',
            $image,
            'php', '-d', 'auto_prepend_file=',
            '/drove/experiments/native-filament-corpus/proof.php', '/app', '/drove',
        ], $root);
        $guardFault->setTimeout(300);
        $guardFault->run();
        nativeFilamentGateAssert(
            ! $guardFault->isSuccessful()
                && $guardFault->getOutput() === ''
                && str_contains(
                    $guardFault->getErrorOutput(),
                    'DROVE_NATIVE_FILAMENT_EXTERNAL_RUNTIME_LOADED',
                ),
            'The native Filament runtime guard accepted an injected bridge '.$faultKind.'.',
        );
        $guardFaults[] = [
            'kind' => $faultKind,
            'detected' => true,
            'exit_code' => $guardFault->getExitCode(),
        ];
    }

    nativeFilamentGateWriteJson($artifactDirectory.'/filament-native-runtime-guard.json', [
        'ok' => true,
        'namespace' => 'Drove\\Bridge\\',
        'faults' => $guardFaults,
    ]);
    $runs = [];

    foreach ([
        ['cohort' => 'nonserial', 'processes' => 1],
        ['cohort' => 'nonserial', 'processes' => 2],
        ['cohort' => 'nonserial', 'processes' => 4],
        ['cohort' => 'nonserial', 'processes' => 8],
        ['cohort' => 'nonserial', 'processes' => 16],
        ['cohort' => 'nonserial', 'processes' => 30],
        ['cohort' => 'serial', 'processes' => 1],
    ] as $cell) {
        $cohort = $cell['cohort'];
        $processes = $cell['processes'];
        $name = 'filament-native-'.$cohort.'-c'.$processes;
        $rawPath = $artifactDirectory.'/'.$name.'.raw.json';
        $measurementPath = $artifactDirectory.'/'.$name.'.measurement.json';
        nativeFilamentGateProcess([
            ...$docker,
            '--volume', nativeFilamentGateVolume($artifactDirectory, '/artifacts'),
            '--env', 'DROVE_NATIVE_FILAMENT_COHORT='.$cohort,
            '--env', 'DROVE_NATIVE_FILAMENT_PROCESSES='.$processes,
            '--env', 'DROVER_LIBRARY=/usr/local/lib/libdrover.so',
            $image,
            'php', '/drove/benchmarks/corpus/measure.php',
            '/artifacts/'.$name.'.measurement.json',
            '/artifacts/'.$name.'.raw.json',
            '--',
            'php', '-d', 'auto_prepend_file=',
            '/drove/experiments/native-filament-corpus/proof.php', '/app', '/drove',
        ], $root, 1200);
        $summary = nativeFilamentGateJsonFile($rawPath, 'Filament '.$cohort.' C'.$processes);
        $measurement = nativeFilamentGateJsonFile(
            $measurementPath,
            'Filament '.$cohort.' C'.$processes.' measurement',
        );
        $expectedNative = $cohort === 'nonserial'
            ? ['cases' => 677, 'assertions' => 1016]
            : ['cases' => 28, 'assertions' => 34];
        $expectedLegacyIdHash = $cohort === 'nonserial'
            ? NATIVE_FILAMENT_GATE_NONSERIAL_PEST_IDS_SHA256
            : NATIVE_FILAMENT_GATE_SERIAL_PEST_IDS_SHA256;
        $expectedBaseline = [
            ...$expectedNative,
            'pest_case_ids_sha256' => $expectedLegacyIdHash,
        ];
        $expectedCaseRows = $committedBaseline['cohorts'][$cohort]['rows'] ?? null;
        $expectedSemanticHash = $committedBaseline['cohorts'][$cohort]['semantic_sha256'] ?? null;
        $expectedSnapshots = $cohort === 'nonserial' ? 170 : 0;
        $phases = $summary['phases_ms'] ?? null;
        $expectedScheduler = [
            'schema' => 1,
            'forks' => $expectedNative['cases'],
            'scope_workers' => 0,
            'executor_workers' => $expectedNative['cases'],
            'process_anchors' => 0,
            'peak_live_pids' => min($expectedNative['cases'], 2 * $processes),
            'peak_outstanding_tasks' => min($expectedNative['cases'], 2 * $processes),
            'outstanding_task_limit' => 2 * $processes,
        ];
        $expectedBarrierScheduler = [
            'schema' => 1,
            'forks' => $processes,
            'scope_workers' => 0,
            'executor_workers' => $processes,
            'process_anchors' => 0,
            'peak_live_pids' => $processes,
            'peak_outstanding_tasks' => $processes,
            'outstanding_task_limit' => 2 * $processes,
        ];
        $expectedBarrier = [
            'requested' => $processes,
            'observed' => $processes,
            'cases' => $processes,
            'one_fork_per_case' => true,
            'scheduler' => $expectedBarrierScheduler,
        ];
        nativeFilamentGateAssert(
            ($summary['ok'] ?? null) === true
                && ($summary['commit'] ?? null) === NATIVE_FILAMENT_GATE_COMMIT
                && ($summary['cohort'] ?? null) === $cohort
                && ($summary['processes'] ?? null) === $processes
                && is_array($phases)
                && array_keys($phases) === ['preparation', 'planning', 'execution', 'verification']
                && array_all($phases, static fn (mixed $milliseconds): bool => is_int($milliseconds) || is_float($milliseconds))
                && array_all($phases, static fn (int|float $milliseconds): bool => $milliseconds >= 0)
                && $phases['execution'] > 0
                && ($summary['native'] ?? null) === $expectedNative
                && ($summary['baseline'] ?? null) === $expectedBaseline
                && is_array($expectedCaseRows)
                && ($summary['case_parity']['fields'] ?? null) === [
                    'id', 'status', 'assertions', 'stdout', 'stderr',
                ]
                && ($summary['case_parity']['cases'] ?? null) === $expectedNative['cases']
                && ($summary['case_parity']['semantic_sha256'] ?? null) === $expectedSemanticHash
                && ($summary['case_parity']['baseline_runner'] ?? null) === 'pestphp/pest'
                && ($summary['case_parity']['rows'] ?? null) === $expectedCaseRows
                && ($summary['one_fork_per_case'] ?? null) === true
                && ($summary['observed_concurrency'] ?? null) === $processes
                && ($summary['concurrency_barrier'] ?? null) === $expectedBarrier
                && ($summary['scheduler'] ?? null) === $expectedScheduler
                && ($summary['snapshot_provider'] ?? null) === [
                    'tracked' => 170,
                    'matched' => $expectedSnapshots,
                    'read_only_tree_sha256' => NATIVE_FILAMENT_GATE_SNAPSHOT_TREE_SHA256,
                ]
                && ($summary['prepared_database_sha256'] ?? null) === NATIVE_FILAMENT_GATE_DATABASE_SHA256
                && ($summary['prepared_database']['before_execution_sha256'] ?? null)
                    === NATIVE_FILAMENT_GATE_DATABASE_SHA256
                && ($summary['source_identity_sha256'] ?? null) === NATIVE_FILAMENT_GATE_SOURCE_IDENTITY
                && ($summary['external_test_runtime'] ?? null) === [
                    'pest' => false,
                    'phpunit' => false,
                    'testbench' => false,
                    'project_test_case' => false,
                ]
                && ($summary['residue'] ?? null) === [
                    'database_copies' => 0,
                    'resource_tree_unchanged' => true,
                ]
                && ($measurement['exit_code'] ?? null) === 0
                && is_numeric($measurement['wall_ms'] ?? null)
                && $measurement['wall_ms'] > 0
                && is_int($measurement['container_peak_memory_bytes'] ?? null)
                && $measurement['container_peak_memory_bytes'] > 0,
            'The native Filament '.$cohort.' C'.$processes.' proof diverged.',
        );
        nativeFilamentGateWriteJson($artifactDirectory.'/'.$name.'.json', $summary);
        $runs[] = [
            'cohort' => $cohort,
            'processes' => $processes,
            'cases' => $expectedNative['cases'],
            'assertions' => $expectedNative['assertions'],
            'phases_ms' => $phases,
            'one_fork_per_case' => true,
            'observed_concurrency' => $summary['observed_concurrency'],
            'concurrency_barrier' => $summary['concurrency_barrier'],
            'case_semantic_sha256' => $expectedSemanticHash,
            'wall_ms' => $measurement['wall_ms'],
            'container_peak_memory_bytes' => $measurement['container_peak_memory_bytes'],
            'scheduler' => $summary['scheduler'],
        ];
    }

    nativeFilamentGateAssert(
        trim(nativeFilamentGateProcess([
            'git', '-C', $workspace, 'status', '--porcelain', '--', 'packages', 'tests',
        ], $root)->getOutput()) === '',
        'Filament package or test sources changed during the native gate.',
    );
    nativeFilamentGateAssert(
        trim(nativeFilamentGateProcess([
            'git', '-C', $baselineWorkspace, 'status', '--porcelain', '--', 'packages', 'tests',
        ], $root)->getOutput()) === '',
        'The independent Pest baseline changed Filament package or test sources.',
    );
    nativeFilamentGateAssert(
        trim(nativeFilamentGateProcess([
            'git', '-C', $checkout, 'status', '--porcelain=v1', '--untracked-files=all',
        ], $root)->getOutput()) === '',
        'The source Filament checkout changed during the native gate.',
    );
    $baselineMeasurements = [
        'nonserial' => nativeFilamentGateJsonFile(
            $artifactDirectory.'/filament-pest-nonserial-c1.measurement.json',
            'The independent Pest nonserial measurement',
        ),
        'serial' => nativeFilamentGateJsonFile(
            $artifactDirectory.'/filament-pest-serial-c1.measurement.json',
            'The independent Pest serial measurement',
        ),
    ];

    foreach ($baselineMeasurements as $cohort => $measurement) {
        nativeFilamentGateAssert(
            ($measurement['exit_code'] ?? null) === 0
                && is_numeric($measurement['wall_ms'] ?? null)
                && $measurement['wall_ms'] > 0
                && is_int($measurement['container_peak_memory_bytes'] ?? null)
                && $measurement['container_peak_memory_bytes'] > 0,
            'The independent Pest '.$cohort.' measurement is invalid.',
        );
    }

    nativeFilamentGateAssert(
        nativeFilamentGateDroveRevision($root) === $droveRevision,
        'The Drove revision changed during the Filament gate.',
    );
    $result = [
        'ok' => true,
        'corpus' => 'filamentphp/filament',
        'commit' => NATIVE_FILAMENT_GATE_COMMIT,
        'drove_revision' => $droveRevision,
        'selection' => ['files' => 39, 'cases' => 705, 'assertions' => 1050],
        'cohorts' => [
            'nonserial' => ['cases' => 677, 'assertions' => 1016],
            'serial' => ['cases' => 28, 'assertions' => 34],
        ],
        'environment' => [
            'packages' => 118,
            'pest_phpunit_testbench_packages' => 0,
            'independent_path_packages' => array_keys($pathPackageContent),
        ],
        'independent_pest_baseline' => [
            'pest' => $baselineEvidence['pest'],
            'phpunit' => $baselineEvidence['phpunit'],
            'testbench' => $baselineEvidence['testbench'],
            'vendor_mode' => $baselineEvidence['vendor_mode'],
            'lock_identity_before_install' => $baselineLockBeforeInstall,
            'lock_identity_after_install' => $baselineLockAfterInstall,
            'cohorts' => $baselineEvidence['cohorts'],
            'measurements' => $baselineMeasurements,
        ],
        'matrix' => $runs,
        'snapshots' => ['tracked' => 170, 'mode' => 'read-only typed matcher'],
        'one_fork_per_case' => true,
        'runtime_guard_fault_detected' => true,
        'prepared_database_unchanged' => true,
        'performance_interpretation' => false,
    ];
    nativeFilamentGateWriteJson($artifactDirectory.'/filament-native-gate.json', $result);
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
} catch (Throwable $failure) {
    fwrite(
        STDERR,
        'DROVE_NATIVE_FILAMENT_GATE_FAILED '.$failure::class.': '.$failure->getMessage().PHP_EOL,
    );
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
            nativeFilamentGateRemoveTree($path, $temporaryRoot);
        } catch (Throwable $failure) {
            $cleanupFailures[] = $failure->getMessage();
        }
    }

    if (is_dir($temporaryRoot) && ! @rmdir($temporaryRoot)) {
        $cleanupFailures[] = 'Temporary workspace remains: '.$temporaryRoot;
    }

    if ($cleanupFailures !== []) {
        fwrite(
            STDERR,
            'DROVE_NATIVE_FILAMENT_GATE_CLEANUP_FAILED '.implode(' | ', $cleanupFailures).PHP_EOL,
        );
        $exitCode = 1;
    }
}

exit($exitCode);
