<?php

declare(strict_types=1);

require __DIR__.'/native-benchmark-corpora.php';

try {
    $arguments = $_SERVER['argv'] ?? [];
    nativeBenchmarkRequire(
        count($arguments) === 6,
        'Usage: php native-benchmark-evidence.php PEST_ARTIFACTS INVOICESHELF_ARTIFACTS LIVEWIRE_ARTIFACTS FILAMENT_ARTIFACTS OUTPUT_JSON',
    );
    $root = dirname(__DIR__, 2);
    $directories = array_combine(DROVE_NATIVE_BENCHMARK_CORPORA, array_slice($arguments, 1, 4));

    foreach ($directories as $corpus => $directory) {
        nativeBenchmarkRequire(is_string($directory) && is_dir($directory), "$corpus N1-N5 artifact directory is unavailable.");
        $resolved = realpath($directory);
        nativeBenchmarkRequire(is_string($resolved), "$corpus N1-N5 artifact directory cannot be resolved.");
        $directories[$corpus] = $resolved;
    }

    $definitions = nativeBenchmarkCorpusDefinitions($root);
    $byId = array_column($definitions, null, 'id');
    $artifacts = [];
    $droveRevision = null;
    $record = static function (string $corpus, string $path) use (&$artifacts): array {
        $payload = nativeBenchmarkReadJson($path);
        $artifacts[] = [
            'corpus' => $corpus,
            'path' => nativeBenchmarkAbsolutePath($path, getcwd() ?: '.'),
            'sha256' => nativeBenchmarkHash($path),
        ];

        return $payload;
    };
    $expected = static function (string $corpus, string $cohort) use ($byId): array {
        $definition = $byId[$corpus] ?? null;
        nativeBenchmarkRequire(is_array($definition), "$corpus benchmark definition is missing.");

        foreach ($definition['cohorts'] as $candidate) {
            if ($candidate['id'] === $cohort) {
                return $candidate['expected'];
            }
        }

        throw new RuntimeException("$corpus/$cohort benchmark definition is missing.");
    };
    $bindDroveRevision = static function (array $artifact, string $subject) use (&$droveRevision): void {
        $revision = $artifact['drove_revision'] ?? null;
        nativeBenchmarkRequire(
            is_string($revision) && preg_match('/^[0-9a-f]{40}$/D', $revision) === 1,
            "$subject is not bound to an exact Drove revision.",
        );
        $droveRevision ??= $revision;
        nativeBenchmarkRequire($revision === $droveRevision, "$subject was produced by a different Drove revision.");
    };

    $pestBaseline = $record('pest', $directories['pest'].'/pest-baseline.json');
    nativeBenchmarkRequire(
        $pestBaseline === nativeBenchmarkReadJson($root.'/experiments/native-corpus-1/baseline.json'),
        'The final independent Pest baseline artifact diverged from the committed baseline.',
    );
    $pestExpected = $expected('pest', 'selected');

    foreach (DROVE_NATIVE_BENCHMARK_PROCESSES as $processes) {
        $proof = $record('pest', $directories['pest'].'/pest-native-c'.$processes.'.json');
        $bindDroveRevision($proof, 'The final native Pest C'.$processes.' artifact');
        $nativeCases = $proof['native']['cases'] ?? null;
        $nativeCaseRows = is_array($nativeCases) ? array_map(static fn (array $case): array => [
            'id' => $case['id'] ?? null,
            'status' => $case['status'] ?? null,
            'assertions' => $case['assertions'] ?? null,
            'stdout' => $case['stdout'] ?? null,
            'stderr' => $case['stderr'] ?? null,
        ], $nativeCases) : null;
        is_array($nativeCaseRows) && usort($nativeCaseRows, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $runnable = $pestExpected['cases'] - ($pestExpected['statuses']['skipped'] ?? 0);
        nativeBenchmarkRequire(
            ($proof['schema'] ?? null) === 1
                && ($proof['corpus'] ?? null) === 'pest'
                && ($proof['commit'] ?? null) === $byId['pest']['source_revision']
                && ($proof['baseline_sha256'] ?? null) === nativeBenchmarkHash($root.'/experiments/native-corpus-1/baseline.json')
                && ($proof['native']['scheduler'] ?? null) === 'drover'
                && ($proof['native']['requested_processes'] ?? null) === $processes
                && ($proof['native']['tests'] ?? null) === $pestExpected['cases']
                && ($proof['native']['passed'] ?? null) === ($pestExpected['statuses']['passed'] ?? null)
                && ($proof['native']['skipped'] ?? null) === ($pestExpected['statuses']['skipped'] ?? 0)
                && ($proof['native']['assertions'] ?? null) === $pestExpected['assertions']
                && ($proof['native']['exit_code'] ?? null) === 0
                && $nativeCaseRows === $pestBaseline['cases']
                && ($proof['native']['concurrency']['runnable_cases'] ?? null) === $runnable
                && ($proof['native']['concurrency']['telemetry_cases'] ?? null) === $runnable
                && ($proof['native']['concurrency']['unique_executor_pids'] ?? null) === $runnable
                && ($proof['native']['concurrency']['one_child_pid_per_case'] ?? null) === true
                && ($proof['native']['concurrency']['observed_peak_lanes'] ?? null) === $processes
                && ($proof['forbidden_runtime']['classes'] ?? null) === []
                && ($proof['forbidden_runtime']['files'] ?? null) === []
                && ($proof['dependency_loader']['status'] ?? null) === 'n5',
            'The final native Pest C'.$processes.' artifact does not prove N1-N5.',
        );
    }

    $invoice = $record('invoiceshelf', $directories['invoiceshelf'].'/invoiceshelf-native-gate.json');
    $bindDroveRevision($invoice, 'The final InvoiceShelf artifact');
    $invoiceExpected = $expected('invoiceshelf', 'selected');
    nativeBenchmarkRequire(
        ($invoice['ok'] ?? null) === true
            && ($invoice['commit'] ?? null) === $byId['invoiceshelf']['source_revision']
            && ($invoice['selection']['cases'] ?? null) === $invoiceExpected['cases']
            && ($invoice['selection']['assertions'] ?? null) === $invoiceExpected['assertions']
            && ($invoice['independent_baseline']['contains_drove'] ?? null) === false
            && ($invoice['independent_baseline']['case_semantics_sha256'] ?? null) === $invoiceExpected['semantic_hash']
            && ($invoice['independent_baseline']['exact_native_match'] ?? null) === true
            && ($invoice['fault_cleanup'] ?? null) === true
            && array_all([
                'baseline_drift', 'baseline_path_reference_exceptions', 'forbidden_packages',
                'forbidden_virtual_packages', 'forbidden_vendor_paths', 'forbidden_autoload_prefixes',
                'forbidden_autoload_files', 'forbidden_binaries', 'forbidden_runtime_symbols',
                'forbidden_runtime_files',
            ], static fn (string $field): bool => ($invoice['environment'][$field] ?? null) === [])
            && array_column($invoice['matrix'] ?? [], 'requested_processes') === DROVE_NATIVE_BENCHMARK_PROCESSES
            && array_all($invoice['matrix'] ?? [], static fn (mixed $row): bool => is_array($row)
                && ($row['one_child_pid_per_case'] ?? null) === true
                && ($row['semantic_match'] ?? null) === true
                && ($row['observed_peak_lanes'] ?? null) === ($row['requested_processes'] ?? null)
                && ($row['runnable_cases'] ?? null) === $invoiceExpected['cases']
                && ($row['telemetry_cases'] ?? null) === $invoiceExpected['cases']
                && ($row['unique_executor_pids'] ?? null) === $invoiceExpected['cases']
                && ($row['distinct_descendant_pids'] ?? null) === $invoiceExpected['cases']),
        'The final InvoiceShelf artifact does not prove N1-N5.',
    );

    $livewire = $record('livewire', $directories['livewire'].'/livewire-native-gate.json');
    $bindDroveRevision($livewire, 'The final Livewire artifact');
    $livewireFull = $expected('livewire', 'full');
    $livewireParallel = $expected('livewire', 'parallel');
    nativeBenchmarkRequire(
        ($livewire['ok'] ?? null) === true
            && ($livewire['case_rows_parity'] ?? null) === true
            && ($livewire['dependencies']['separate_vendors'] ?? null) === true
            && ($livewire['dependencies']['shared_version_source_dist_drift'] ?? null) === []
            && ($livewire['independent_baseline']['forbidden_packages'] ?? null) === []
            && ($livewire['independent_baseline']['forbidden_runtime_files'] ?? null) === []
            && ($livewire['independent_baseline']['case_rows_sha256'] ?? null) === $livewireFull['semantic_hash']
            && ($livewire['full']['commit'] ?? null) === $byId['livewire']['source_revision']
            && ($livewire['full']['cases'] ?? null) === $livewireFull['cases']
            && ($livewire['full']['passed'] ?? null) === ($livewireFull['statuses']['passed'] ?? null)
            && ($livewire['full']['incomplete'] ?? null) === ($livewireFull['statuses']['incomplete'] ?? 0)
            && ($livewire['full']['assertions'] ?? null) === $livewireFull['assertions']
            && ($livewire['full']['case_rows_sha256'] ?? null) === $livewireFull['semantic_hash']
            && ($livewire['full']['child_pids'] ?? null) === $livewireFull['cases']
            && ($livewire['full']['forks'] ?? null) === $livewireFull['cases']
            && ($livewire['full']['runtime_evidence']['forbidden_classes'] ?? null) === 0
            && ($livewire['full']['runtime_evidence']['forbidden_files'] ?? null) === 0
            && ($livewire['faults']['ok'] ?? null) === true
            && ($livewire['faults']['checkout']['clean_after_faults'] ?? null) === true
            && ($livewire['parallel_safe_matrix']['semantic_hash'] ?? null) === $livewireParallel['semantic_hash']
            && array_column($livewire['parallel_safe_matrix']['matrix'] ?? [], 'processes') === DROVE_NATIVE_BENCHMARK_PROCESSES
            && array_all($livewire['parallel_safe_matrix']['matrix'] ?? [], static fn (mixed $row): bool => is_array($row)
                && ($row['cases'] ?? null) === $livewireParallel['cases']
                && ($row['assertions'] ?? null) === $livewireParallel['assertions']
                && ($row['child_pids'] ?? null) === $livewireParallel['cases']
                && ($row['forks'] ?? null) === $livewireParallel['cases']
                && ($row['observed_lanes'] ?? null) === ($row['processes'] ?? null)),
        'The final Livewire artifact does not prove N1-N5.',
    );

    $filament = $record('filament', $directories['filament'].'/filament-native-gate.json');
    $bindDroveRevision($filament, 'The final Filament artifact');
    $filamentNonserial = $expected('filament', 'nonserial');
    $filamentSerial = $expected('filament', 'serial');
    $filamentProcesses = array_map(
        static fn (array $row): string => $row['cohort'].'@'.$row['processes'],
        $filament['matrix'] ?? [],
    );
    nativeBenchmarkRequire(
        ($filament['ok'] ?? null) === true
            && ($filament['commit'] ?? null) === $byId['filament']['source_revision']
            && ($filament['selection']['cases'] ?? null) === $filamentNonserial['cases'] + $filamentSerial['cases']
            && ($filament['selection']['assertions'] ?? null) === $filamentNonserial['assertions'] + $filamentSerial['assertions']
            && ($filament['environment']['pest_phpunit_testbench_packages'] ?? null) === 0
            && ($filament['one_fork_per_case'] ?? null) === true
            && ($filament['runtime_guard_fault_detected'] ?? null) === true
            && ($filament['prepared_database_unchanged'] ?? null) === true
            && $filamentProcesses === [
                'nonserial@1', 'nonserial@2', 'nonserial@4', 'nonserial@8',
                'nonserial@16', 'nonserial@30', 'serial@1',
            ]
            && array_all($filament['matrix'] ?? [], static fn (mixed $row): bool => is_array($row)
                && ($row['one_fork_per_case'] ?? null) === true
                && ($row['observed_concurrency'] ?? null) === ($row['processes'] ?? null)
                && ($row['cases'] ?? null) === ($row['cohort'] === 'nonserial' ? $filamentNonserial['cases'] : $filamentSerial['cases'])
                && ($row['assertions'] ?? null) === ($row['cohort'] === 'nonserial' ? $filamentNonserial['assertions'] : $filamentSerial['assertions'])
                && ($row['case_semantic_sha256'] ?? null) === ($row['cohort'] === 'nonserial' ? $filamentNonserial['semantic_hash'] : $filamentSerial['semantic_hash'])),
        'The final Filament artifact does not prove N1-N5.',
    );

    $output = nativeBenchmarkOutputPath($arguments[5], getcwd() ?: '.');
    nativeBenchmarkRequire(
        ! in_array($output, array_column($artifacts, 'path'), true),
        'Native N1-N5 evidence cannot overwrite an input gate artifact.',
    );
    nativeBenchmarkWriteJson($output, [
        'schema_version' => 1,
        'verification' => 'passed',
        'phases' => ['N1', 'N2', 'N3', 'N4', 'N5'],
        'corpora' => DROVE_NATIVE_BENCHMARK_CORPORA,
        'filament_final' => true,
        'drove_revision' => $droveRevision,
        'outcomes' => array_map(static fn (array $corpus): array => [
            'id' => $corpus['id'],
            'source_revision' => $corpus['source_revision'],
            'cohorts' => array_map(static fn (array $cohort): array => [
                'id' => $cohort['id'],
                'mode' => $cohort['mode'],
                'c1_only_reason' => $cohort['c1_only_reason'],
                'expected' => $cohort['expected'],
            ], $corpus['cohorts']),
        ], $definitions),
        'artifacts' => $artifacts,
    ]);
    fwrite(STDOUT, "Native N1-N5 evidence written to $output.\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage().PHP_EOL);
    exit(2);
}
