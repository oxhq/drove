<?php

declare(strict_types=1);

use Drove\Laravel\Proof\PinnedGitCheckout;

const LIVEWIRE_FAULT_COMMIT = '9c1450739d30c9b0b223ad6512be2a33f8f62f96';

function faultAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function faultTreeHash(string $path): string
{
    if (! is_dir($path)) {
        return hash('sha256', 'missing');
    }

    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($path) + 1));
        $hash = $entry->isDir() ? 'directory' : hash_file('sha256', $entry->getPathname());
        faultAssert(is_string($hash), 'Could not hash fault-proof tree entry.');
        $entries[$relative] = $hash;
    }

    ksort($entries, SORT_STRING);

    return hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** @return list<string> */
function faultArtifacts(): array
{
    $paths = [];

    foreach ([
        sys_get_temp_dir().'/drove-native-livewire-*',
        sys_get_temp_dir().'/drove-native-package-*',
    ] as $pattern) {
        $matches = glob($pattern);

        if (is_array($matches)) {
            array_push($paths, ...$matches);
        }
    }

    sort($paths, SORT_STRING);

    return $paths;
}

/**
 * @param  array<string, string>  $overrides
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function faultRun(string $script, array $overrides): array
{
    $environment = getenv();

    foreach ($overrides as $name => $value) {
        $environment[$name] = $value;
    }
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__.'/'.$script],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        __DIR__,
        $environment,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start '.$script.' fault proof.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    faultAssert(is_string($stdout) && is_string($stderr), 'Could not read '.$script.' fault proof output.');

    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

$vendor = getenv('DROVE_NATIVE_VENDOR');
$storage = getenv('DROVE_NATIVE_PACKAGE_STORAGE');

if (! is_string($vendor) || $vendor === '' || ! is_file($vendor.'/autoload.php')) {
    fwrite(STDERR, "DROVE_NATIVE_VENDOR must identify the clean installed vendor.\n");
    exit(1);
}

if (! is_string($storage) || $storage === '') {
    fwrite(STDERR, "DROVE_NATIVE_PACKAGE_STORAGE must identify the isolated proof storage.\n");
    exit(1);
}

require $vendor.'/autoload.php';
require __DIR__.'/PinnedGitCheckout.php';

try {
    $checkout = PinnedGitCheckout::fromEnvironment(
        'DROVE_NATIVE_LIVEWIRE_CHECKOUT',
        LIVEWIRE_FAULT_COMMIT,
    );
    $preparedViews = $storage.'/framework/views';
    faultAssert(
        is_dir($preparedViews) || mkdir($preparedViews, 0700, true),
        'Could not prepare fault-proof storage.',
    );
    $application = __DIR__.'/application';
    $applicationHash = faultTreeHash($application);
    $storageHash = faultTreeHash($storage);
    $artifacts = faultArtifacts();
    $cases = [];

    foreach ([
        'proof.php' => 'DROVE_NATIVE_PACKAGE_PROOF_FAILED',
        'proof-livewire-cohort.php' => 'DROVE_NATIVE_LIVEWIRE_COHORT_FAILED',
        'proof-livewire-full.php' => 'DROVE_NATIVE_LIVEWIRE_FULL_FAILED',
    ] as $script => $prefix) {
        $result = faultRun($script, ['DROVE_NATIVE_PACKAGE_FAULT_AFTER_BOOT' => '1']);
        $expected = $prefix.' RuntimeException: DROVE_NATIVE_PACKAGE_INJECTED_POST_BOOT_FAULT'.PHP_EOL;
        faultAssert($result['exit_code'] === 1, $script.' fault injection did not exit 1.');
        faultAssert($result['stdout'] === '', $script.' fault injection wrote unexpected stdout.');
        faultAssert($result['stderr'] === $expected, $script.' fault stderr drifted: '.$result['stderr']);
        faultAssert(faultTreeHash($application) === $applicationHash, $script.' mutated the application tree.');
        faultAssert(faultTreeHash($storage) === $storageHash, $script.' mutated isolated storage.');
        faultAssert(faultArtifacts() === $artifacts, $script.' leaked a temporary stage or evidence file.');
        $checkout->assertExactAndClean();
        $cases[] = [
            'script' => $script,
            'fault' => 'post-boot',
            'exit_code' => $result['exit_code'],
            'stderr' => rtrim($result['stderr'], "\r\n"),
            'application_integrity' => $applicationHash,
            'storage_integrity' => $storageHash,
            'temporary_artifacts' => count($artifacts),
        ];
    }

    foreach ([
        'class' => 'DROVE_NATIVE_LIVEWIRE_FULL_EXTERNAL_TEST_RUNTIME_CLASS_LOADED',
        'file' => 'DROVE_NATIVE_LIVEWIRE_FULL_EXTERNAL_TEST_RUNTIME_FILE_LOADED',
    ] as $fault => $diagnostic) {
        $result = faultRun('proof-livewire-full.php', [
            'DROVE_NATIVE_LIVEWIRE_RUNTIME_EVIDENCE_FAULT' => $fault,
        ]);
        $expected = 'DROVE_NATIVE_LIVEWIRE_FULL_FAILED RuntimeException: '.$diagnostic.PHP_EOL;
        faultAssert($result['exit_code'] === 1, 'Runtime '.$fault.' fault injection did not exit 1.');
        faultAssert($result['stdout'] === '', 'Runtime '.$fault.' fault injection wrote unexpected stdout.');
        faultAssert($result['stderr'] === $expected, 'Runtime '.$fault.' fault stderr drifted: '.$result['stderr']);
        faultAssert(faultTreeHash($application) === $applicationHash, 'Runtime '.$fault.' fault mutated the application tree.');
        faultAssert(faultTreeHash($storage) === $storageHash, 'Runtime '.$fault.' fault mutated isolated storage.');
        faultAssert(faultArtifacts() === $artifacts, 'Runtime '.$fault.' fault leaked a temporary artifact.');
        $checkout->assertExactAndClean();
        $cases[] = [
            'script' => 'proof-livewire-full.php',
            'fault' => 'runtime-'.$fault,
            'exit_code' => $result['exit_code'],
            'stderr' => rtrim($result['stderr'], "\r\n"),
            'application_integrity' => $applicationHash,
            'storage_integrity' => $storageHash,
            'temporary_artifacts' => count($artifacts),
        ];
    }

    foreach ([
        'body' => [
            'diagnostic' => 'DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FORBIDDEN_CLASS kind=test',
            'task_kind' => 'test',
            'state_rows' => ['prepared', 'scope', 'test'],
        ],
        'defer' => [
            'diagnostic' => 'DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FORBIDDEN_FILE kind=test',
            'task_kind' => 'test',
            'state_rows' => ['prepared', 'scope', 'test'],
        ],
        'after-all' => [
            'diagnostic' => 'DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FORBIDDEN_CLASS kind=scope',
            'task_kind' => 'scope',
            'state_rows' => ['prepared', 'scope'],
        ],
    ] as $scenario => $expected) {
        $result = faultRun('proof-livewire-runtime-audit-fault.php', [
            'DROVE_NATIVE_LIVEWIRE_RUNTIME_AUDIT_FAULT' => $scenario,
        ]);
        faultAssert($result['exit_code'] === 0, 'Runtime lifecycle '.$scenario.' fault proof did not exit 0: '.$result['stderr']);
        faultAssert($result['stderr'] === '', 'Runtime lifecycle '.$scenario.' fault proof wrote unexpected stderr.');
        $decoded = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        faultAssert(
            is_array($decoded)
                && ($decoded['ok'] ?? null) === true
                && ($decoded['scenario'] ?? null) === $scenario
                && ($decoded['lifecycle_phase'] ?? null) === $scenario
                && ($decoded['diagnostic'] ?? null) === $expected['diagnostic']
                && ($decoded['task_kind'] ?? null) === $expected['task_kind']
                && ($decoded['audit_rows'] ?? null) === 3
                && ($decoded['audit_task_kinds'] ?? null) === ['root', 'scope', 'test']
                && ($decoded['fault_pid_bound_to_phase'] ?? null) === true
                && ($decoded['state_rows'] ?? null) === $expected['state_rows']
                && ($decoded['run_status'] ?? null) === 'failed'
                && ($decoded['external_test_runtime_packages'] ?? null) === 0,
            'Runtime lifecycle '.$scenario.' fault proof drifted.',
        );
        faultAssert(faultTreeHash($application) === $applicationHash, 'Runtime lifecycle '.$scenario.' fault mutated the application tree.');
        faultAssert(faultTreeHash($storage) === $storageHash, 'Runtime lifecycle '.$scenario.' fault mutated isolated storage.');
        faultAssert(faultArtifacts() === $artifacts, 'Runtime lifecycle '.$scenario.' fault leaked a temporary artifact.');
        $checkout->assertExactAndClean();
        $cases[] = [
            'script' => 'proof-livewire-runtime-audit-fault.php',
            'fault' => 'runtime-lifecycle-'.$scenario,
            'exit_code' => $result['exit_code'],
            'diagnostic' => $decoded['diagnostic'],
            'task_kind' => $decoded['task_kind'],
            'fault_pid_bound_to_phase' => true,
            'state_rows' => $decoded['state_rows'],
            'application_integrity' => $applicationHash,
            'storage_integrity' => $storageHash,
            'temporary_artifacts' => count($artifacts),
        ];
    }

    echo json_encode([
        'ok' => true,
        'faults' => $cases,
        'checkout' => [
            'commit' => LIVEWIRE_FAULT_COMMIT,
            'mode' => 'read-only-git-checkout',
            'clean_after_faults' => true,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, 'DROVE_NATIVE_LIVEWIRE_FAULT_GATE_FAILED '.$throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
