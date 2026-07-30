<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/support.php';

try {
    $identity = nativePhaseFourIdentity();
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            dirname(__DIR__).'/proof-native-runtime.php',
        ],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__),
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the native Laravel provider preflight proof.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0
        || ! is_string($stdout)
        || ! is_string($stderr)
        || $stderr !== '') {
        throw new RuntimeException(
            'Native Laravel provider preflight failed: '
            .(is_string($stderr) ? trim($stderr) : 'missing stderr'),
        );
    }

    $preflight = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
    $cacheIsolation = is_array($preflight)
        ? ($preflight['preflight']['cache_isolation'] ?? null)
        : null;
    $envCacheIsolation = is_array($preflight)
        ? ($preflight['preflight']['env_cache_isolation'] ?? null)
        : null;

    if (! is_array($preflight)
        || ($preflight['status'] ?? null) !== 'passed'
        || ($preflight['preflight']['bootstrap_cold'] ?? null) !== true
        || ! is_string($preflight['preflight']['env_config_cache'] ?? null)
        || ! str_contains(
            $preflight['preflight']['env_config_cache'],
            'cached configuration',
        )
        || ($preflight['preflight']['env_config_cache_cold'] ?? null) !== true
        || ! is_string($preflight['preflight']['sentinel'] ?? null)
        || ! str_contains($preflight['preflight']['sentinel'], 'RejectedNativeProvider')
        || ! is_string($preflight['preflight']['config_provider'] ?? null)
        || ! str_contains(
            $preflight['preflight']['config_provider'],
            'NativeAdditionalProviderSentinel',
        )
        || ($preflight['preflight']['config_provider_cold'] ?? null) !== true
        || ! is_array($cacheIsolation)
        || array_keys($cacheIsolation) !== [
            'package_sentinel_cold',
            'services_sentinel_cold',
            'original_hashes_unchanged',
            'temporary_artifact_count',
            'environment_restored',
        ]
        || ($cacheIsolation['package_sentinel_cold'] ?? null) !== true
        || ($cacheIsolation['services_sentinel_cold'] ?? null) !== true
        || ($cacheIsolation['original_hashes_unchanged'] ?? null) !== true
        || ($cacheIsolation['temporary_artifact_count'] ?? null) !== 0
        || ($cacheIsolation['environment_restored'] ?? null) !== true
        || ! is_array($envCacheIsolation)
        || array_keys($envCacheIsolation) !== [
            'package_sentinel_cold',
            'services_sentinel_cold',
            'original_hashes_unchanged',
            'temporary_artifact_count',
            'environment_restored',
        ]
        || ($envCacheIsolation['package_sentinel_cold'] ?? null) !== true
        || ($envCacheIsolation['services_sentinel_cold'] ?? null) !== true
        || ($envCacheIsolation['original_hashes_unchanged'] ?? null) !== true
        || ($envCacheIsolation['temporary_artifact_count'] ?? null) !== 0
        || ($envCacheIsolation['environment_restored'] ?? null) !== true
        || ($preflight['native']['prepare_count'] ?? null) !== 1
        || ($preflight['native']['phpunit_loaded'] ?? null) !== false
        || ($preflight['native']['testbench_loaded'] ?? null) !== false) {
        throw new RuntimeException('Native Laravel provider sentinel returned false-green evidence.');
    }

    echo json_encode([
        'schema' => 1,
        'ok' => true,
        ...$identity,
        'provider_sentinel_checked' => true,
        'env_config_cache_checked' => true,
        'env_config_cache_cold_checked' => true,
        'config_provider_sentinel_checked' => true,
        'config_provider_cold_checked' => true,
        'cache_isolation_checked' => true,
        'env_cache_isolation_checked' => true,
        'bootstrap_cold_checked' => true,
        'phpunit_loaded' => false,
        'testbench_loaded' => false,
        'proof_hash' => hash('sha256', $stdout),
        'proof' => $preflight,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
