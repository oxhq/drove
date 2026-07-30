<?php

declare(strict_types=1);

try {
    $arguments = $_SERVER['argv'] ?? [];

    if (count($arguments) !== 16) {
        fwrite(
            STDERR,
            'Usage: php compare.php memory-c1..c30 copy-c1..c30 faults.json preflight.json comparison.json'
            .PHP_EOL,
        );
        exit(2);
    }

    $read = static function (string $path): array {
        $contents = file_get_contents($path);

        if (! is_string($contents) || trim($contents) === '') {
            throw new RuntimeException('Native Laravel evidence is missing: '.$path);
        }

        $value = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);

        if (! is_array($value)) {
            throw new RuntimeException('Native Laravel evidence must be a JSON object: '.$path);
        }

        return $value;
    };
    $identity = static function (array $summary): string {
        $revision = $summary['evidence_revision'] ?? null;
        $platform = $summary['runtime_platform'] ?? null;
        $drover = $summary['drover_identity'] ?? null;

        if (! is_string($revision)
            || preg_match('/\A[a-f0-9]{40}\z/D', $revision) !== 1
            || ! is_array($platform)
            || array_keys($platform) !== ['os_family', 'os', 'architecture', 'php']
            || ($platform['os_family'] ?? null) !== 'Linux'
            || ! is_string($platform['os'] ?? null)
            || ! in_array($platform['architecture'] ?? null, ['amd64', 'x86_64'], true)
            || ! is_string($platform['php'] ?? null)
            || preg_match('/\A8\.4\./D', $platform['php']) !== 1
            || ! is_array($drover)
            || array_keys($drover) !== [
                'scheduler_class',
                'target',
                'library_sha256',
                'protocol_version',
                'protocol_max_frame_bytes',
            ]
            || ($drover['scheduler_class'] ?? null) !== 'Drove\\Kernel\\DroverScheduler'
            || ($drover['target'] ?? null) !== 'linux-gnu-x86_64'
            || ! is_string($drover['library_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $drover['library_sha256']) !== 1
            || ($drover['protocol_version'] ?? null) !== 1
            || ($drover['protocol_max_frame_bytes'] ?? null) !== 1_048_576) {
            throw new RuntimeException('Native Laravel execution identity is invalid.');
        }

        return json_encode([
            'revision' => $revision,
            'platform' => $platform,
            'drover' => $drover,
        ], JSON_THROW_ON_ERROR);
    };
    $hashes = [
        'plan_hash',
        'case_id_hash',
        'semantic_hash',
        'assertion_hash',
        'after_all_hash',
    ];
    $processCounts = [1, 2, 4, 8, 16, 30];
    $matrix = [];
    $matrixPaths = array_slice($arguments, 1, 12);

    foreach (['sqlite-memory', 'sqlite-copy'] as $providerIndex => $provider) {
        foreach ($processCounts as $processIndex => $processes) {
            $summary = $read($matrixPaths[($providerIndex * 6) + $processIndex]);
            $pids = $summary['test_pids'] ?? null;

            if (($summary['schema'] ?? null) !== 1
                || ($summary['ok'] ?? null) !== true
                || ($summary['provider'] ?? null) !== $provider
                || ($summary['scheduler'] ?? null) !== 'drover'
                || ($summary['processes'] ?? null) !== $processes
                || ($summary['lanes_requested'] ?? null) !== $processes
                || ($summary['lanes_observed'] ?? null) !== $processes
                || ($summary['file_count'] ?? null) !== 30
                || ($summary['test_count'] ?? null) !== 300
                || ($summary['status_counts'] ?? null) !== ['passed' => 300]
                || ($summary['assertion_count'] ?? null) !== 900
                || ($summary['cleanup_count'] ?? null) !== 300
                || ($summary['unique_test_pid_count'] ?? null) !== 300
                || ! is_array($pids)
                || ! array_is_list($pids)
                || count($pids) !== 300
                || count(array_unique($pids, SORT_REGULAR)) !== 300
                || array_any($pids, static fn (mixed $pid): bool => ! is_int($pid) || $pid <= 0)
                || ($summary['application_boot_count'] ?? null) !== 1
                || ($summary['prepare_count'] ?? null) !== 1
                || ($summary['adapter_boot_count'] ?? null) !== 1
                || ($summary['phpunit_loaded'] ?? null) !== false
                || ($summary['testbench_loaded'] ?? null) !== false
                || ($summary['after_all_count'] ?? null) !== 30
                || ($summary['after_all_prepared_only_checked'] ?? null) !== true
                || ($summary['source_hash_unchanged'] ?? null) !== true
                || ($summary['sqlite_artifact_count'] ?? null) !== 0
                || ($summary['generated_artifact_count'] ?? null) !== 0
                || ($summary['executor_memory_samples'] ?? null) !== 300
                || ! is_int($summary['executor_peak_memory_bytes'] ?? null)
                || $summary['executor_peak_memory_bytes'] <= 0
                || ! is_int($summary['parent_peak_memory_bytes'] ?? null)
                || $summary['parent_peak_memory_bytes'] <= 0) {
                throw new RuntimeException(
                    sprintf('Native Laravel %s C%d summary failed its gate.', $provider, $processes),
                );
            }

            foreach (['planning_ms', 'execution_ms', 'wall_ms', 'proof_wall_ms'] as $metric) {
                if ((! is_int($summary[$metric] ?? null) && ! is_float($summary[$metric] ?? null))
                    || $summary[$metric] <= 0) {
                    throw new RuntimeException('Native Laravel timing evidence is invalid.');
                }
            }

            foreach ($hashes as $hash) {
                if (! is_string($summary[$hash] ?? null)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $summary[$hash]) !== 1) {
                    throw new RuntimeException('Native Laravel '.$hash.' is invalid.');
                }
            }

            if ($provider === 'sqlite-memory') {
                if (($summary['source_hash_before'] ?? null) !== null
                    || ($summary['source_hash_after'] ?? null) !== null) {
                    throw new RuntimeException('SQLite memory claimed a file hash.');
                }
            } elseif (! is_string($summary['source_hash_before'] ?? null)
                || ! is_string($summary['source_hash_after'] ?? null)
                || ! hash_equals($summary['source_hash_before'], $summary['source_hash_after'])) {
                throw new RuntimeException('SQLite copy source hash evidence is invalid.');
            }

            $identity($summary);
            $matrix[] = $summary;
        }
    }

    foreach ($hashes as $hash) {
        if (count(array_unique(array_column($matrix, $hash))) !== 1) {
            throw new RuntimeException('Native Laravel '.$hash.' diverged across providers or lanes.');
        }
    }

    $faults = $read($arguments[13]);
    $faultCases = $faults['cases'] ?? null;

    if (($faults['schema'] ?? null) !== 1
        || ($faults['ok'] ?? null) !== true
        || ($faults['case_count'] ?? null) !== 8
        || ($faults['fault_scope'] ?? null) !== 'mixed'
        || ($faults['prepare_failure_case_count'] ?? null) !== 2
        || ($faults['provider_internal_partial_failure_case_count'] ?? null) !== 6
        || ! is_array($faultCases)
        || ! array_is_list($faultCases)
        || count($faultCases) !== 8
        || ! is_string($faults['case_hash'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $faults['case_hash']) !== 1) {
        throw new RuntimeException('Native Laravel fault matrix is invalid.');
    }

    $expectedFaults = [];

    foreach (['sqlite-memory', 'sqlite-copy'] as $provider) {
        foreach ([
            'prepare',
            'enter',
            'leave',
            'cleanup',
        ] as $fault) {
            $expectedFaults[] = $provider.'/'.$fault;
        }
    }

    $observedFaults = [];

    foreach ($faultCases as $case) {
        $provider = $case['provider'] ?? null;
        $fault = $case['fault'] ?? null;

        if (! is_string($provider)
            || ! is_string($fault)
            || ($case['ok'] ?? null) !== true
            || ($case['injection_kind'] ?? null) !== match ($fault) {
                'prepare' => 'prepare-closure',
                'enter' => 'before-provider-enter',
                'leave' => 'open-transaction-before-provider-leave',
                'cleanup' => 'before-provider-cleanup',
                default => null,
            }
            || ($case['fault_scope'] ?? null) !== ($fault === 'prepare'
                ? 'prepare-closure-before-provider-boot'
                : 'provider-internal-precondition')
            || ($case['internal_partial_provider_failure_checked'] ?? null) !== ($fault !== 'prepare')
            || ($case['fault_observed'] ?? null) !== true
            || ($case['failure_observed'] ?? null) !== true
            || ! is_string($case['failure_fragment'] ?? null)
            || trim($case['failure_fragment']) === ''
            || ($case['failure_fragment_checked'] ?? null) !== true
            || ! is_int($case['fault_injection_count'] ?? null)
            || $case['fault_injection_count'] !== 1
            || ($case['adapter_boot_count'] ?? null) !== ($fault === 'prepare' ? 0 : 1)
            || ($case['source_hash_unchanged'] ?? null) !== true
            || ($case['provider_residue_count_before_harness_cleanup'] ?? null)
                !== ($provider === 'sqlite-copy' && $fault === 'cleanup' ? 1 : 0)
            || ($case['harness_cleanup_required'] ?? null)
                !== ($provider === 'sqlite-copy' && $fault === 'cleanup')
            || ($case['sqlite_artifact_count'] ?? null) !== 0
            || ($case['generated_artifact_count'] ?? null) !== 0
            || ($case['phpunit_loaded'] ?? null) !== false
            || ($case['testbench_loaded'] ?? null) !== false) {
            throw new RuntimeException('Native Laravel fault case is invalid.');
        }

        $identity($case);
        $observedFaults[] = $provider.'/'.$fault;
    }

    if ($observedFaults !== $expectedFaults) {
        throw new RuntimeException('Native Laravel fault matrix is incomplete.');
    }

    $preflight = $read($arguments[14]);

    if (($preflight['schema'] ?? null) !== 1
        || ($preflight['ok'] ?? null) !== true
        || ($preflight['provider_sentinel_checked'] ?? null) !== true
        || ($preflight['env_config_cache_checked'] ?? null) !== true
        || ($preflight['env_config_cache_cold_checked'] ?? null) !== true
        || ($preflight['config_provider_sentinel_checked'] ?? null) !== true
        || ($preflight['config_provider_cold_checked'] ?? null) !== true
        || ($preflight['cache_isolation_checked'] ?? null) !== true
        || ($preflight['env_cache_isolation_checked'] ?? null) !== true
        || ($preflight['bootstrap_cold_checked'] ?? null) !== true
        || ($preflight['phpunit_loaded'] ?? null) !== false
        || ($preflight['testbench_loaded'] ?? null) !== false
        || ! is_string($preflight['proof_hash'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $preflight['proof_hash']) !== 1) {
        throw new RuntimeException('Native Laravel provider preflight evidence is invalid.');
    }

    $summaries = [...$matrix, $faults, ...$faultCases, $preflight];
    $identities = array_map($identity, $summaries);

    if (count(array_unique($identities)) !== 1) {
        throw new RuntimeException('Native Laravel summaries mix revisions, platforms, or Drover binaries.');
    }

    $expectedRevision = getenv('DROVE_EVIDENCE_REVISION');

    if (! is_string($expectedRevision)
        || ($matrix[0]['evidence_revision'] ?? null) !== $expectedRevision) {
        throw new RuntimeException('Native Laravel summaries do not match the expected exact revision.');
    }

    $metric = static fn (array $summary): array => [
        'provider' => $summary['provider'],
        'processes' => $summary['processes'],
        'lanes_requested' => $summary['lanes_requested'],
        'lanes_observed' => $summary['lanes_observed'],
        'file_count' => $summary['file_count'],
        'test_count' => $summary['test_count'],
        'assertion_count' => $summary['assertion_count'],
        'cleanup_count' => $summary['cleanup_count'],
        'unique_test_pid_count' => $summary['unique_test_pid_count'],
        'planning_ms' => $summary['planning_ms'],
        'execution_ms' => $summary['execution_ms'],
        'wall_ms' => $summary['wall_ms'],
        'proof_wall_ms' => $summary['proof_wall_ms'],
        'parent_peak_memory_bytes' => $summary['parent_peak_memory_bytes'],
        'executor_peak_memory_bytes' => $summary['executor_peak_memory_bytes'],
    ];
    $comparison = [
        'schema' => 1,
        'ok' => true,
        'revision' => $matrix[0]['evidence_revision'],
        'platform' => $matrix[0]['runtime_platform'],
        'drover_identity' => $matrix[0]['drover_identity'],
        'fixture' => 'experiments/phase-3-laravel/native-phase-4',
        'commands' => [
            'matrix' => 'DROVE_LARAVEL_PROVIDER=P DROVE_NATIVE_PROCESSES=C php -d ffi.enable=true native-phase-4/proof.php',
            'faults' => 'php -d ffi.enable=true native-phase-4/faults.php',
            'preflight' => 'php native-phase-4/preflight.php',
        ],
        'semantic_hashes' => array_combine(
            $hashes,
            array_map(
                static fn (string $hash): string => $matrix[0][$hash],
                $hashes,
            ),
        ),
        'matrix' => array_map($metric, $matrix),
        'fault_case_count' => count($faultCases),
        'fault_case_hash' => $faults['case_hash'],
        'fault_scope' => 'Prepare-closure failures plus provider-internal enter, leave, and cleanup failure preconditions.',
        'prepare_failure_case_count' => $faults['prepare_failure_case_count'],
        'provider_internal_partial_failure_case_count' => $faults['provider_internal_partial_failure_case_count'],
        'provider_preflight_hash' => $preflight['proof_hash'],
    ];
    $output = json_encode($comparison, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

    if (file_put_contents($arguments[15], $output) === false) {
        throw new RuntimeException('Could not write the native Laravel comparison artifact.');
    }

    echo $output;
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
