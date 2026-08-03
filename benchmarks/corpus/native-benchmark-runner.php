<?php

declare(strict_types=1);

require __DIR__.'/native-benchmark-corpora.php';
require dirname(__DIR__, 2).'/experiments/native-filament-corpus/case-parity.php';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $arguments = $_SERVER['argv'] ?? [];
        nativeBenchmarkRequire(count($arguments) === 5, 'Usage: php native-benchmark-runner.php CORPUS COHORT baseline|native PROCESSES');
        $corpus = nativeBenchmarkId($arguments[1], 'corpus');
        $cohort = nativeBenchmarkId($arguments[2], 'cohort');
        $runner = $arguments[3];
        $processes = nativeBenchmarkPositiveInt($arguments[4], 'processes');
        nativeBenchmarkRequire(in_array($runner, ['baseline', 'native'], true), 'Native benchmark runner must be baseline or native.');
        nativeBenchmarkRequire($runner === 'native' || $processes === 1, 'Upstream native benchmark baselines only run at C1.');
        fwrite(STDOUT, json_encode(
            nativeBenchmarkRunnerProof('/drove', '/corpus', $corpus, $cohort, $runner, $processes),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL);
    } catch (Throwable $throwable) {
        fwrite(STDERR, $throwable->getMessage().PHP_EOL);
        exit(2);
    }
}

/** @return array<string, mixed> */
function nativeBenchmarkRunnerProof(
    string $root,
    string $checkout,
    string $corpus,
    string $cohort,
    string $runner,
    int $processes,
): array {
    $definitions = array_column(nativeBenchmarkCorpusDefinitions($root), null, 'id');
    $definition = $definitions[$corpus] ?? null;
    nativeBenchmarkRequire(is_array($definition), "Unknown native benchmark corpus $corpus.");
    $cohorts = array_column($definition['cohorts'], null, 'id');
    $cohortDefinition = $cohorts[$cohort] ?? null;
    nativeBenchmarkRequire(is_array($cohortDefinition), "Unknown native benchmark cohort $corpus/$cohort.");
    $revision = trim(nativeBenchmarkRunnerProcess(['git', '-C', $checkout, 'rev-parse', 'HEAD'])['stdout']);
    nativeBenchmarkRequire($revision === $definition['source_revision'], "$corpus checkout revision drifted.");
    $started = hrtime(true);
    $summary = $runner === 'baseline'
        ? nativeBenchmarkBaselineSummary($root, $checkout, $corpus, $cohort)
        : nativeBenchmarkNativeSummary($root, $checkout, $corpus, $cohort, $processes);
    $runnerMs = round((hrtime(true) - $started) / 1_000_000, 3);
    $expected = $cohortDefinition['expected'];
    nativeBenchmarkRequire(($summary['outcome'] ?? null) === $expected, "$corpus/$cohort $runner outcome diverged from its committed baseline.");
    $phases = $summary['phases_ms'] ?? null;
    nativeBenchmarkRequire(is_array($phases), "$corpus/$cohort $runner did not expose phase telemetry after $runnerMs ms.");

    return [
        'schema_version' => 1,
        'outcome' => $expected,
        'timing' => ['phases_ms' => $phases],
        'topology' => $summary['topology'],
    ];
}

/** @return array<string, mixed> */
function nativeBenchmarkBaselineSummary(string $root, string $checkout, string $corpus, string $cohort): array
{
    $junit = tempnam(sys_get_temp_dir(), 'drove-n6-junit-');
    nativeBenchmarkRequire(is_string($junit), 'Cannot create the baseline JUnit path.');

    try {
        if ($corpus === 'pest' && $cohort === 'selected') {
            $paths = require $root.'/experiments/native-corpus-1/cohort.php';
            nativeBenchmarkRequire(is_array($paths) && array_is_list($paths), 'The Pest benchmark cohort is invalid.');
            $pathCount = count($paths);
            $paths = array_values(array_filter($paths, 'is_string'));
            nativeBenchmarkRequire(count($paths) === $pathCount, 'The Pest benchmark cohort contains a non-string path.');
            $execution = nativeBenchmarkRunnerProcess([
                PHP_BINARY, 'bin/pest', '--configuration=phpunit.xml', '--colors=never', '--log-junit='.$junit, ...$paths,
            ], $checkout);
            $actual = tempnam(sys_get_temp_dir(), 'drove-n6-pest-');
            nativeBenchmarkRequire(is_string($actual), 'Cannot create the Pest baseline normalization path.');

            try {
                $verification = nativeBenchmarkRunnerProcess([
                    PHP_BINARY, $root.'/experiments/native-corpus-1/generate-baseline.php', $checkout, $junit, $actual,
                ]);
                $baseline = nativeBenchmarkReadJson($root.'/experiments/native-corpus-1/baseline.json');
                nativeBenchmarkRequire(nativeBenchmarkReadJson($actual) === $baseline, 'The timed Pest baseline case rows diverged.');

                return nativeBenchmarkUpstreamSummary(nativeBenchmarkOutcomeFromCases($baseline['cases']), [
                    'execution' => $execution['wall_ms'],
                    'verification' => $verification['wall_ms'],
                ]);
            } finally {
                @unlink($actual);
            }
        }

        if ($corpus === 'invoiceshelf' && $cohort === 'selected') {
            $baseline = nativeBenchmarkReadJson($root.'/experiments/native-invoiceshelf-corpus/detailed-baseline.json');
            $paths = $baseline['cases'] ?? null;
            $fileBaseline = nativeBenchmarkReadJson($root.'/experiments/native-invoiceshelf-corpus/baseline.json');
            $files = $fileBaseline['files'] ?? null;
            nativeBenchmarkRequire(is_array($paths) && is_array($files) && array_is_list($files), 'The InvoiceShelf benchmark baseline is invalid.');
            $fileCount = count($files);
            $files = array_values(array_filter($files, 'is_string'));
            nativeBenchmarkRequire(count($files) === $fileCount, 'The InvoiceShelf benchmark cohort contains a non-string path.');
            $execution = nativeBenchmarkRunnerProcess([
                PHP_BINARY, 'vendor/bin/pest', '--configuration=phpunit.xml', '--colors=never', '--log-junit='.$junit, ...$files,
            ], $checkout, nativeBenchmarkInvoiceEnvironment());
            $actual = tempnam(sys_get_temp_dir(), 'drove-n6-invoice-');
            nativeBenchmarkRequire(is_string($actual), 'Cannot create the InvoiceShelf normalization path.');

            try {
                $verification = nativeBenchmarkRunnerProcess([
                    PHP_BINARY, $root.'/experiments/native-invoiceshelf-corpus/normalize-baseline.php', $checkout, $junit, $actual,
                ]);
                nativeBenchmarkRequire(nativeBenchmarkReadJson($actual) === $baseline, 'The timed InvoiceShelf baseline case rows diverged.');

                return nativeBenchmarkUpstreamSummary(nativeBenchmarkOutcomeFromCases($paths, $baseline['case_semantics_sha256']), [
                    'execution' => $execution['wall_ms'],
                    'verification' => $verification['wall_ms'],
                ]);
            } finally {
                @unlink($actual);
            }
        }

        if ($corpus === 'livewire' && in_array($cohort, ['parallel', 'full'], true)) {
            $baselinePath = $root.'/experiments/phase-3-laravel/native-package/livewire-'.($cohort === 'full' ? 'full' : 'parallel').'-baseline.json';
            $baseline = nativeBenchmarkReadJson($baselinePath);
            $files = array_values(array_filter(array_keys($baseline['files'] ?? []), 'is_string'));
            nativeBenchmarkRequire($files !== [], 'The Livewire benchmark baseline has no selected files.');
            $execution = nativeBenchmarkRunnerProcess([
                PHP_BINARY, 'vendor/bin/phpunit', '--configuration=phpunit.xml.dist', '--do-not-cache-result',
                '--colors=never', '--log-junit='.$junit, ...$files,
            ], $checkout, ['DUSK_DRIVER_URL' => 'http://127.0.0.1:9515']);
            $verificationStarted = hrtime(true);
            $expected = $baseline['cases'] ?? null;
            nativeBenchmarkRequire(is_array($expected), 'The committed Livewire benchmark case rows are invalid.');
            usort($expected, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
            $actual = nativeBenchmarkLivewireRows(
                $junit,
                array_key_exists('stdout', $expected[0] ?? []),
            );
            nativeBenchmarkRequire($actual === $expected, 'The timed Livewire baseline case rows diverged.');

            return nativeBenchmarkUpstreamSummary(nativeBenchmarkOutcomeFromCases($actual), [
                'execution' => $execution['wall_ms'],
                'verification' => round((hrtime(true) - $verificationStarted) / 1_000_000, 3),
            ]);
        }

        if ($corpus === 'filament' && in_array($cohort, ['nonserial', 'serial'], true)) {
            $selection = nativeBenchmarkRunnerProcess(['sh', $root.'/benchmarks/corpus/select.sh', 'filament', $checkout])['stdout'];
            $files = preg_split('/\R/', trim($selection)) ?: [];
            $files = array_values(array_filter($files, static fn (string $path): bool => $path !== ''));
            $group = $cohort === 'serial' ? '--group=serial' : '--exclude-group=serial';
            $execution = nativeBenchmarkRunnerProcess([
                PHP_BINARY, '-d', 'auto_prepend_file=', 'vendor/bin/pest', '--configuration=phpunit.xml.dist',
                $group, '--colors=never', '--log-junit='.$junit, ...$files,
            ], $checkout, nativeBenchmarkFilamentEnvironment());
            $verificationStarted = hrtime(true);
            $actual = nativeFilamentBaselineRowsFromJunit($junit);
            $baseline = nativeBenchmarkReadJson($root.'/experiments/native-filament-corpus/baseline-cases.json');
            $expected = $baseline['cohorts'][$cohort] ?? null;
            nativeBenchmarkRequire(is_array($expected) && ($expected['rows'] ?? null) === $actual, 'The timed Filament baseline case rows diverged.');

            return nativeBenchmarkUpstreamSummary(nativeBenchmarkOutcomeFromCases($actual, $expected['semantic_sha256']), [
                'execution' => $execution['wall_ms'],
                'verification' => round((hrtime(true) - $verificationStarted) / 1_000_000, 3),
            ]);
        }

        throw new RuntimeException("No upstream runner for $corpus/$cohort.");
    } finally {
        @unlink($junit);
    }
}

/** @return array<string, mixed> */
function nativeBenchmarkNativeSummary(string $root, string $checkout, string $corpus, string $cohort, int $processes): array
{
    if ($corpus === 'pest' && $cohort === 'selected') {
        $process = nativeBenchmarkRunnerProcess([
            PHP_BINARY, $root.'/experiments/native-corpus-1/proof.php', $checkout,
        ], $root, [
            'DROVE_NATIVE_CORPUS_VENDOR' => '/native-deps/vendor',
            'DROVE_NATIVE_CORPUS_PROCESSES' => (string) $processes,
            'DROVE_NATIVE_CORPUS_SCHEDULER' => 'drover',
        ]);
        $proof = nativeBenchmarkRunnerJson($process['stdout'], 'native Pest');
        $baseline = nativeBenchmarkReadJson($root.'/experiments/native-corpus-1/baseline.json');
        $native = $proof['native'] ?? null;
        nativeBenchmarkRequire(is_array($native) && ($proof['baseline_sha256'] ?? null) === nativeBenchmarkHash($root.'/experiments/native-corpus-1/baseline.json'), 'The timed native Pest proof diverged.');
        $timing = $proof['timing_ms'] ?? null;
        nativeBenchmarkRequire(is_array($timing), 'The timed native Pest phase telemetry is missing.');
        $preparation = nativeBenchmarkRunnerPhase($timing['discovery'] ?? null, 'native Pest discovery')
            + nativeBenchmarkRunnerPhase($timing['codemod'] ?? null, 'native Pest codemod');
        $planning = nativeBenchmarkRunnerPhase($timing['planning'] ?? null, 'native Pest planning');
        $execution = nativeBenchmarkRunnerPhase($timing['execution'] ?? null, 'native Pest execution');
        $known = $preparation + $planning + $execution;

        return [
            'outcome' => nativeBenchmarkOutcomeFromCases($baseline['cases']),
            'phases_ms' => [
                'preparation' => $preparation,
                'planning' => $planning,
                'execution' => $execution,
                'verification' => max(0, round($process['wall_ms'] - $known, 3)),
            ],
            'topology' => nativeBenchmarkIsolatedTopology(
                $native['concurrency']['observed_peak_lanes'] ?? null,
                $native['concurrency']['runnable_cases'] ?? null,
                $native['concurrency']['unique_executor_pids'] ?? null,
            ),
        ];
    }

    if ($corpus === 'invoiceshelf' && $cohort === 'selected') {
        $process = nativeBenchmarkRunnerProcess([
            PHP_BINARY, $root.'/experiments/native-invoiceshelf-corpus/proof.php', $checkout, $root,
        ], $root, ['DROVE_NATIVE_INVOICESHELF_PROCESSES' => (string) $processes, ...nativeBenchmarkInvoiceEnvironment()]);
        $proof = nativeBenchmarkRunnerJson($process['stdout'], 'native InvoiceShelf');
        $baseline = nativeBenchmarkReadJson($root.'/experiments/native-invoiceshelf-corpus/detailed-baseline.json');
        nativeBenchmarkRequire(($proof['case_semantics']['sha256'] ?? null) === $baseline['case_semantics_sha256'], 'The timed native InvoiceShelf semantics diverged.');

        return [
            'outcome' => nativeBenchmarkOutcomeFromCases($baseline['cases'], $baseline['case_semantics_sha256']),
            'phases_ms' => $proof['phases_ms'] ?? null,
            'topology' => nativeBenchmarkIsolatedTopology(
                $proof['scheduler']['observed_peak_lanes'] ?? null,
                $proof['scheduler']['runnable_cases'] ?? null,
                $proof['scheduler']['unique_executor_pids'] ?? null,
            ),
        ];
    }

    if ($corpus === 'livewire' && in_array($cohort, ['parallel', 'full'], true)) {
        $script = $cohort === 'full' ? 'proof-livewire-full.php' : 'proof-livewire-cohort.php';
        $storage = sys_get_temp_dir().'/drove-n6-livewire-'.bin2hex(random_bytes(8));
        nativeBenchmarkRequire(mkdir($storage, 0700), 'Cannot create the Livewire benchmark storage path.');

        try {
            $process = nativeBenchmarkRunnerProcess([
                PHP_BINARY, $root.'/experiments/phase-3-laravel/native-package/'.$script,
            ], $root, [
                'DROVE_NATIVE_VENDOR' => '/native-vendor',
                'DROVE_NATIVE_LIVEWIRE_CHECKOUT' => $checkout,
                'DROVE_NATIVE_PACKAGE_STORAGE' => $storage,
                'DROVE_NATIVE_LIVEWIRE_PROCESSES' => (string) $processes,
            ]);
        } catch (Throwable $failure) {
            nativeBenchmarkRunnerRethrowAfterCleanup($failure, $storage);
        }

        nativeBenchmarkRunnerRemoveTree($storage);
        $proof = nativeBenchmarkRunnerJson($process['stdout'], 'native Livewire');
        nativeBenchmarkRequireNaturalCorpusProof($proof, 'Livewire');
        $baselinePath = $root.'/experiments/phase-3-laravel/native-package/livewire-'.($cohort === 'full' ? 'full' : 'parallel').'-baseline.json';
        $baseline = nativeBenchmarkReadJson($baselinePath);
        $cases = $baseline['cases'] ?? null;
        nativeBenchmarkRequire(is_array($cases), 'The committed Livewire benchmark case rows are invalid.');
        usort($cases, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $hash = hash('sha256', json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $actualHash = $cohort === 'full' ? ($proof['case_rows_sha256'] ?? null) : ($proof['semantic_hash'] ?? null);
        nativeBenchmarkRequire($actualHash === $hash, 'The timed native Livewire semantics diverged.');

        return [
            'outcome' => nativeBenchmarkOutcomeFromCases($cases),
            'phases_ms' => $proof['phases_ms'] ?? null,
            'topology' => nativeBenchmarkIsolatedTopology(
                $cohort === 'full' ? 1 : ($proof['observed_lanes'] ?? null),
                $proof['cases'] ?? null,
                $proof['forks'] ?? null,
            ),
        ];
    }

    if ($corpus === 'filament' && in_array($cohort, ['nonserial', 'serial'], true)) {
        $process = nativeBenchmarkRunnerProcess([
            PHP_BINARY, '-d', 'auto_prepend_file=', $root.'/experiments/native-filament-corpus/proof.php', $checkout, $root,
        ], $root, [
            'DROVE_NATIVE_FILAMENT_COHORT' => $cohort,
            'DROVE_NATIVE_FILAMENT_PROCESSES' => (string) $processes,
            ...nativeBenchmarkFilamentEnvironment(),
        ]);
        $proof = nativeBenchmarkRunnerJson($process['stdout'], 'native Filament');
        nativeBenchmarkRequireNaturalCorpusProof($proof, 'Filament');
        $baseline = nativeBenchmarkReadJson($root.'/experiments/native-filament-corpus/baseline-cases.json');
        $expected = $baseline['cohorts'][$cohort] ?? null;
        nativeBenchmarkRequire(is_array($expected) && ($proof['case_parity']['semantic_sha256'] ?? null) === $expected['semantic_sha256'], 'The timed native Filament semantics diverged.');

        return [
            'outcome' => nativeBenchmarkOutcomeFromCases($expected['rows'], $expected['semantic_sha256']),
            'phases_ms' => $proof['phases_ms'] ?? null,
            'topology' => nativeBenchmarkIsolatedTopology(
                $proof['observed_concurrency'] ?? null,
                $proof['native']['cases'] ?? null,
                $proof['scheduler']['executor_workers'] ?? null,
            ),
        ];
    }

    throw new RuntimeException("No native runner for $corpus/$cohort.");
}

/** @param array<string, mixed> $proof */
function nativeBenchmarkRequireNaturalCorpusProof(array $proof, string $corpus): void
{
    nativeBenchmarkRequire(
        ($proof['capacity_proof']['enabled'] ?? null) === false,
        "The timed native $corpus proof must observe the natural corpus without a capacity barrier.",
    );
}

/** @param list<array<string, mixed>> $cases
 * @return array{cases: int, assertions: int, statuses: array<string, int>, semantic_hash: string}
 */
function nativeBenchmarkOutcomeFromCases(array $cases, ?string $hash = null): array
{
    usort($cases, static fn (array $left, array $right): int => ($left['id'] ?? '') <=> ($right['id'] ?? ''));
    $statuses = array_count_values(array_column($cases, 'status'));
    ksort($statuses, SORT_STRING);

    return nativeBenchmarkExpectedOutcome([
        'cases' => count($cases),
        'assertions' => array_sum(array_column($cases, 'assertions')),
        'statuses' => $statuses,
        'semantic_hash' => $hash ?? hash('sha256', json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
    ], 'timed corpus outcome');
}

/**
 * @param  array{cases: int, assertions: int, statuses: array<string, int>, semantic_hash: string}  $outcome
 * @param  array<string, int|float>  $phases
 * @return array<string, mixed>
 */
function nativeBenchmarkUpstreamSummary(array $outcome, array $phases): array
{
    return [
        'outcome' => $outcome,
        'phases_ms' => $phases,
        'topology' => [
            'observed_lanes' => 1,
            'forks' => 0,
            'strategy' => 'upstream',
            'fork_semantics' => 'runner-native',
        ],
    ];
}

/** @return array<string, mixed> */
function nativeBenchmarkIsolatedTopology(mixed $lanes, mixed $runnableCases, mixed $forks): array
{
    nativeBenchmarkRequire(is_int($lanes) && is_int($runnableCases) && is_int($forks), 'Native runner topology is incomplete.');

    return [
        'observed_lanes' => $lanes,
        'runnable_cases' => $runnableCases,
        'forks' => $forks,
        'strategy' => 'isolated-per-test',
        'fork_semantics' => 'one-fork-per-test',
    ];
}

function nativeBenchmarkRunnerPhase(mixed $value, string $subject): float
{
    nativeBenchmarkRequire(nativeBenchmarkNonNegativeNumber($value), "The $subject phase is invalid.");

    return (float) $value;
}

/** @return array<string, string> */
function nativeBenchmarkInvoiceEnvironment(): array
{
    return [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:x5m/qWTRn07o2rXmBGZap8zkqzrPybJGqGbi2f8I7Pc=',
        'NIGHTWATCH_ENABLED' => 'false',
        'PULSE_ENABLED' => 'false',
        'TELESCOPE_ENABLED' => 'false',
    ];
}

/** @return array<string, string> */
function nativeBenchmarkFilamentEnvironment(): array
{
    return [
        'APP_ENV' => 'self-testing',
        'APP_KEY' => 'base64:yk+bUVuZa1p86Dqjk9OjVK2R1pm6XHxC6xEKFq8utH0=',
        'DB_CONNECTION' => 'testing',
    ];
}

/** @return list<array{id: string, status: string, assertions: int, stdout?: string, stderr?: string}> */
function nativeBenchmarkLivewireRows(string $junit, bool $includeEmptyStreams = true): array
{
    $document = new DOMDocument;
    nativeBenchmarkRequire($document->load($junit, LIBXML_NONET), 'The timed Livewire JUnit artifact is invalid.');
    $rows = [];

    foreach ($document->getElementsByTagName('testcase') as $case) {
        $path = str_replace('\\', '/', $case->getAttribute('file'));
        $offset = strpos($path, '/src/');
        nativeBenchmarkRequire($offset !== false, 'A timed Livewire case has no source path.');
        $name = $case->getAttribute('name');
        $dataset = '';

        if (preg_match('/^(.*) with data set #(\d+)$/sD', $name, $match) === 1) {
            $name = $match[1];
            $dataset = '::dataset:index:'.$match[2];
        } elseif (preg_match('/^(.*) with data set "(.*)"$/sD', $name, $match) === 1) {
            $name = $match[1];
            $dataset = '::dataset:name:'.rawurlencode($match[2]);
        }
        $id = 'test:'.substr($path, $offset + 1).'::'.rawurlencode($name).$dataset;
        nativeBenchmarkRequire(! isset($rows[$id]), 'The timed Livewire baseline emitted a duplicate case ID.');
        $failed = $case->getElementsByTagName('failure')->length > 0 || $case->getElementsByTagName('error')->length > 0;
        $incomplete = $case->getElementsByTagName('skipped')->length > 0;
        $stdout = nativeBenchmarkJunitStream($case, 'system-out');
        $stderr = nativeBenchmarkJunitStream($case, 'system-err');
        nativeBenchmarkRequire(
            $includeEmptyStreams || ($stdout === '' && $stderr === ''),
            'The timed Livewire baseline emitted output absent from its committed case rows.',
        );
        $status = $failed ? 'failed' : ($incomplete ? 'incomplete' : 'passed');
        $assertions = (int) $case->getAttribute('assertions');
        $rows[$id] = $includeEmptyStreams
            ? compact('id', 'status', 'assertions', 'stdout', 'stderr')
            : compact('id', 'status', 'assertions');
    }

    ksort($rows, SORT_STRING);

    return array_values($rows);
}

function nativeBenchmarkJunitStream(DOMElement $case, string $element): string
{
    $nodes = $case->getElementsByTagName($element);
    nativeBenchmarkRequire($nodes->length <= 1, "The timed JUnit case emitted duplicate $element nodes.");
    $node = $nodes->item(0);

    return $node instanceof DOMElement ? $node->textContent : '';
}

/** @param list<string> $command
 * @param  array<string, string>  $environment
 * @return array{stdout: string, stderr: string, wall_ms: float}
 */
function nativeBenchmarkRunnerProcess(array $command, ?string $cwd = null, array $environment = []): array
{
    $base = getenv();
    $stdoutPath = tempnam(sys_get_temp_dir(), 'drove-n6-stdout-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'drove-n6-stderr-');
    nativeBenchmarkRequire(is_string($stdoutPath) && is_string($stderrPath), 'Cannot create native benchmark command logs.');

    try {
        $started = hrtime(true);
        $process = proc_open(
            $command,
            [0 => ['file', nativeBenchmarkNullDevice(), 'r'], 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']],
            $pipes,
            $cwd,
            [...$base, ...$environment],
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('A native benchmark runner command could not start.');
        }

        $exit = proc_close($process);
        $stdout = file_get_contents($stdoutPath);
        $stderr = file_get_contents($stderrPath);

        if (! is_string($stdout) || ! is_string($stderr)) {
            throw new RuntimeException('A native benchmark runner command output is unreadable.');
        }

        nativeBenchmarkRequire($exit === 0, sprintf(
            'Native benchmark command failed with exit %d: %s',
            $exit,
            substr(trim($stderr."\n".$stdout), 0, 4_000),
        ));

        return ['stdout' => $stdout, 'stderr' => $stderr, 'wall_ms' => round((hrtime(true) - $started) / 1_000_000, 3)];
    } finally {
        @unlink($stdoutPath);
        @unlink($stderrPath);
    }
}

/** @return array<string, mixed> */
function nativeBenchmarkRunnerJson(string $contents, string $subject): array
{
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    nativeBenchmarkRequire(is_array($decoded) && ! array_is_list($decoded), "The $subject summary is invalid.");

    return $decoded;
}

function nativeBenchmarkRunnerRemoveTree(string $directory): void
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        clearstatcache(true, $directory);

        if (! file_exists($directory) && ! is_link($directory)) {
            return;
        }

        if (is_link($directory) || ! is_dir($directory)) {
            throw new RuntimeException("Native benchmark cleanup target is not a directory: $directory");
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $entry) {
                $path = $entry->getPathname();

                if ($entry->isLink()) {
                    @unlink($path);

                    continue;
                }

                if ($entry->isDir()) {
                    @chmod($path, 0700);
                    @rmdir($path);

                    continue;
                }

                @chmod($path, 0600);
                @unlink($path);
            }
        } catch (UnexpectedValueException) {
            // A transient Windows handle can also make directory enumeration fail.
        }

        unset($entry, $iterator);
        @chmod($directory, 0700);
        @rmdir($directory);
        clearstatcache(true, $directory);

        if (! file_exists($directory) && ! is_link($directory)) {
            return;
        }

        if ($attempt < 19) {
            usleep(100_000);
        }
    }

    $remaining = @scandir($directory);
    $remainingCount = is_array($remaining) ? count(array_diff($remaining, ['.', '..'])) : -1;

    throw new RuntimeException(sprintf(
        'Could not remove native benchmark workspace %s; %d entries remain.',
        $directory,
        $remainingCount,
    ));
}

function nativeBenchmarkRunnerRethrowAfterCleanup(Throwable $failure, string ...$directories): never
{
    $cleanupFailures = [];

    foreach ($directories as $directory) {
        try {
            nativeBenchmarkRunnerRemoveTree($directory);
        } catch (Throwable $cleanupFailure) {
            $cleanupFailures[] = $cleanupFailure->getMessage();
        }
    }

    if ($cleanupFailures !== []) {
        throw new RuntimeException(
            $failure->getMessage().' Cleanup also failed: '.implode(' | ', $cleanupFailures),
            0,
            $failure,
        );
    }

    throw $failure;
}
