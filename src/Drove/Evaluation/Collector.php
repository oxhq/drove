<?php

declare(strict_types=1);

namespace Drove\Evaluation;

use Composer\InstalledVersions;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Drove\Bridge\CompatibilityRegistry;
use Drove\Bridge\CompatibilityStatus;
use Drove\Kernel\NativeLibrary;
use Drove\Migration\Finding;
use Drove\Migration\Scanner;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * @internal Experimental evidence format; no compatibility promise is made.
 */
final readonly class Collector
{
    private const array CONCURRENCY = [2, 4, 8, 16, 30];

    /**
     * @param  null|array{tag: string, revision: string}  $packageIdentity
     */
    public function __construct(
        private ?array $packageIdentity = null,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function collect(string $root, array $configuration): array
    {
        $root = $this->root($root);
        $configuration = $this->configuration($configuration);
        $this->assertRepository($root, $configuration['repository']);
        $projectRevision = $this->gitRevision($root);
        $this->assertTrackedClean($root);
        $platform = $this->platform();
        $package = $this->package();
        $work = $root.'/.drove/evaluation-work';

        if (file_exists($work)) {
            throw new RuntimeException(
                'Drove evidence work directory already exists: .drove/evaluation-work.',
            );
        }

        if (! @mkdir($work, 0700, true) && ! is_dir($work)) {
            throw new RuntimeException('Drove could not create its evidence work directory.');
        }

        try {
            $discovery = $this->discover(
                $root,
                $configuration['baseline_command_argv'],
                $work.'/selection.xml',
                $work.'/selection.log',
            );
            $caseIds = $this->caseIds($root, $discovery);
            $baselineCommand = $configuration['baseline_command_argv'];
            $baselineMeasurement = $this->measure(
                $root,
                $baselineCommand,
                $work.'/baseline.log',
            );
            $baselineSummary = $this->summary($baselineMeasurement['output']);
            $baseline = $this->runRecord(
                $configuration,
                $baselineCommand,
                $configuration['frontend'],
                1,
                1,
                $baselineMeasurement,
                $baselineSummary,
                count($caseIds),
            );

            $droveRuns = [];

            foreach ([1, $configuration['parallel_processes']] as $processes) {
                $replay = ".drove/evaluation-work/drove-c{$processes}-replay.json";
                $command = [
                    ...$configuration['drove_command_argv'],
                    '--parallel',
                    "--processes={$processes}",
                    "--replay={$replay}",
                ];
                $measurement = $this->measure(
                    $root,
                    $command,
                    "{$work}/drove-c{$processes}.log",
                );
                $replayContents = $this->jsonFile($root.'/'.$replay);
                $replayResult = $this->replay(
                    $replayContents,
                    $processes,
                    $measurement['exit_code'],
                    $caseIds,
                );
                $summary = $this->summary($measurement['output']);

                $renderedOutcomes = $summary;
                unset($renderedOutcomes['assertions']);
                $replayOutcomes = $replayResult['summary'];
                unset($replayOutcomes['assertions']);

                if ($renderedOutcomes !== $replayOutcomes) {
                    throw new RuntimeException(
                        "Drove C{$processes} output and replay outcomes diverged.",
                    );
                }

                $droveRuns[] = $this->runRecord(
                    $configuration,
                    $command,
                    'drove',
                    $processes,
                    $replayResult['observed_lanes'],
                    $measurement,
                    $summary,
                    count($caseIds),
                );
            }

            foreach ($droveRuns as $run) {
                if ($this->semantics($run) !== $this->semantics($baseline)) {
                    throw new RuntimeException(
                        'Drove outcomes or assertions diverged from the baseline.',
                    );
                }
            }

            if ($this->gitRevision($root) !== $projectRevision) {
                throw new RuntimeException('The project revision changed during collection.');
            }

            $this->assertTrackedClean($root, allowEvidenceWork: true);

            return [
                'schema' => 1,
                'evaluation_id' => $configuration['evaluation_id'],
                'team' => $configuration['team'],
                'repository' => $configuration['repository'],
                'drove' => $package,
                'project_revision' => $projectRevision,
                'scanner' => [
                    'completed' => true,
                    'discovered' => count($caseIds),
                    'supported' => count($caseIds),
                    'bridge_only' => 0,
                    'rejected' => 0,
                ],
                'benchmark' => [
                    'completed' => true,
                    'selected' => count($caseIds),
                    'case_ids' => $caseIds,
                    'selection_sha256' => $this->caseIdsHash($caseIds),
                    'platform' => [
                        ...$platform,
                        'package_version' => substr($package['tag'], 1),
                        'package_revision' => $package['revision'],
                    ],
                    'runs' => [$baseline, ...$droveRuns],
                ],
                'migration' => $configuration['migration'],
            ];
        } finally {
            $this->removeDirectory($work);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function seal(string $root, string $artifactPath): array
    {
        $root = $this->root($root);
        $artifact = $this->relativeFile($root, $artifactPath);
        $contents = file_get_contents($root.'/'.$artifact);

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Drove evidence artifact could not be read.');
        }

        try {
            $evidence = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Drove evidence artifact is not valid JSON.', $exception->getCode(), previous: $exception);
        }

        if (! is_array($evidence)
            || ($evidence['schema'] ?? null) !== 1
            || ! is_string($evidence['evaluation_id'] ?? null)
            || ! is_string($evidence['team'] ?? null)
            || ! is_string($evidence['repository'] ?? null)
            || ! is_array($evidence['drove'] ?? null)
            || ! is_string($evidence['project_revision'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $evidence['project_revision']) !== 1) {
            throw new RuntimeException('Drove evidence artifact identity is invalid.');
        }

        $this->assertRepository($root, $evidence['repository']);
        $evidenceRevision = $this->gitRevision($root);

        if ($evidenceRevision === $evidence['project_revision']
            || $this->git(
                $root,
                ['merge-base', '--is-ancestor', $evidence['project_revision'], $evidenceRevision],
            )['exit'] !== 0) {
            throw new RuntimeException(
                'The artifact commit must descend from the distinct tested project revision.',
            );
        }

        $committed = $this->git($root, ['show', "{$evidenceRevision}:{$artifact}"], false);

        if ($committed['exit'] !== 0 || ! hash_equals($contents, $committed['output'])) {
            throw new RuntimeException(
                'The evidence artifact bytes must be committed at the current revision.',
            );
        }

        $repository = $this->repository($evidence['repository']);
        $encodedPath = implode('/', array_map(rawurlencode(...), explode('/', $artifact)));

        return [
            'id' => $evidence['evaluation_id'],
            'team' => $evidence['team'],
            'repository' => $evidence['repository'],
            'drove' => $evidence['drove'],
            'project_revision' => $evidence['project_revision'],
            'evidence_revision' => $evidenceRevision,
            'evidence' => [
                'url' => sprintf(
                    'https://raw.githubusercontent.com/%s/%s/%s/%s',
                    $repository['owner'],
                    $repository['repository'],
                    $evidenceRevision,
                    $encodedPath,
                ),
                'sha256' => hash('sha256', $contents),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{
     *     evaluation_id: string,
     *     team: string,
     *     repository: string,
     *     frontend: 'pest'|'phpunit',
     *     runtime: 'php'|'laravel'|'testbench',
     *     parallel_processes: int,
     *     baseline_command_argv: list<string>,
     *     drove_command_argv: list<string>,
     *     migration: null|array<string, mixed>
     * }
     */
    private function configuration(array $configuration): array
    {
        $allowed = [
            'baseline_command_argv',
            'drove_command_argv',
            'evaluation_id',
            'frontend',
            'migration',
            'parallel_processes',
            'repository',
            'runtime',
            'schema',
            'team',
        ];
        $keys = array_keys($configuration);
        sort($keys, SORT_STRING);

        if (array_diff($keys, $allowed) !== []
            || ($configuration['schema'] ?? null) !== 1
            || ! is_string($configuration['evaluation_id'] ?? null)
            || preg_match('/\A[a-z0-9][a-z0-9-]{2,63}\z/', $configuration['evaluation_id']) !== 1
            || ! is_string($configuration['team'] ?? null)
            || trim($configuration['team']) === ''
            || ! is_string($configuration['repository'] ?? null)
            || ! in_array($configuration['frontend'] ?? null, ['pest', 'phpunit'], true)
            || ! in_array($configuration['runtime'] ?? null, ['php', 'laravel', 'testbench'], true)
            || ! in_array($configuration['parallel_processes'] ?? null, self::CONCURRENCY, true)) {
            throw new InvalidArgumentException('Drove evidence configuration identity is invalid.');
        }

        $this->repository($configuration['repository']);
        $baseline = $this->command(
            $configuration['baseline_command_argv'] ?? null,
            $configuration['frontend'],
            'baseline_command_argv',
        );
        $drove = $this->command(
            $configuration['drove_command_argv'] ?? null,
            'drove',
            'drove_command_argv',
        );

        if (count(array_filter($drove, static fn (string $argument): bool => $argument === '--pest')) !== 1) {
            throw new InvalidArgumentException(
                'drove_command_argv must select the Pest/PHPUnit bridge with --pest.',
            );
        }

        $migration = $configuration['migration'] ?? null;

        if ($migration !== null) {
            $this->migration($migration);
        }

        /** @var array{
         *     evaluation_id: string,
         *     team: string,
         *     repository: string,
         *     frontend: 'pest'|'phpunit',
         *     runtime: 'php'|'laravel'|'testbench',
         *     parallel_processes: int,
         *     baseline_command_argv: list<string>,
         *     drove_command_argv: list<string>,
         *     migration: null|array<string, mixed>
         * } $validated
         */
        $validated = [
            'evaluation_id' => $configuration['evaluation_id'],
            'team' => trim($configuration['team']),
            'repository' => $configuration['repository'],
            'frontend' => $configuration['frontend'],
            'runtime' => $configuration['runtime'],
            'parallel_processes' => $configuration['parallel_processes'],
            'baseline_command_argv' => $baseline,
            'drove_command_argv' => $drove,
            'migration' => $migration,
        ];

        return $validated;
    }

    /**
     * @return list<string>
     */
    private function command(mixed $value, string $runner, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidArgumentException("{$field} must be a non-empty argument list.");
        }

        foreach ($value as $argument) {
            if (! is_string($argument)
                || $argument === ''
                || preg_match('/[\x00-\x1f\x7f]/', $argument) === 1) {
                throw new InvalidArgumentException(
                    "{$field} must contain non-empty strings without controls.",
                );
            }
        }

        $index = $value[0] === 'php' ? 1 : 0;
        $expected = "vendor/bin/{$runner}";

        if (($value[$index] ?? null) !== $expected
            && ($value[$index] ?? null) !== "./{$expected}") {
            throw new InvalidArgumentException("{$field} must execute {$expected}.");
        }

        foreach (array_slice($value, $index + 1) as $argument) {
            if ($argument === '--'
                || preg_match(
                    '/\A--(?:parallel|processes|replay|replay-on-failure|log-junit|list-tests-xml|no-logging)(?:=|\z)/',
                    $argument,
                ) === 1) {
                throw new InvalidArgumentException(
                    "{$field} cannot override evidence instrumentation options.",
                );
            }
        }

        /** @var non-empty-list<non-empty-string> $value */
        return $value;
    }

    private function migration(mixed $migration): void
    {
        if (! is_array($migration)) {
            throw new InvalidArgumentException('migration must be an object or null.');
        }

        $required = [
            'ci_started_at',
            'ci_started_revision',
            'ci_started_run_url',
            'ci_verified_at',
            'ci_verified_revision',
            'ci_verified_run_url',
            'drove_step_name',
            'meaningful',
        ];
        $keys = array_keys($migration);
        sort($keys, SORT_STRING);

        if ($keys !== $required
            || ($migration['meaningful'] ?? null) !== true
            || array_any(
                array_diff($required, ['meaningful']),
                static fn (string $field): bool => ! is_string($migration[$field] ?? null)
                    || $migration[$field] === '',
            )) {
            throw new InvalidArgumentException('migration evidence is incomplete.');
        }
    }

    /**
     * @param  list<string>  $command
     * @return array<string, array{id: string, source: string}>
     */
    private function discover(
        string $root,
        array $command,
        string $output,
        string $log,
    ): array {
        $relativeOutput = '.drove/evaluation-work/'.basename($output);
        $measurement = $this->measure(
            $root,
            [
                ...$command,
                '--do-not-cache-result',
                '--no-logging',
                '--list-tests-xml',
                $relativeOutput,
            ],
            $log,
        );

        if ($measurement['exit_code'] !== 0 || ! is_file($output)) {
            throw new RuntimeException('PHPUnit discovery failed; no evidence was emitted.');
        }

        return $this->discovery($root, $output);
    }

    /**
     * @return array<string, array{id: string, source: string}>
     */
    private function discovery(string $root, string $path): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->load($path, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('PHPUnit discovery XML is invalid.');
        }

        $xpath = new DOMXPath($document);
        $cases = [];

        foreach ($xpath->query('//*[local-name()="testClass"]') ?: [] as $class) {
            if (! $class instanceof DOMElement) {
                continue;
            }

            $className = $class->getAttribute('name');
            $source = $this->source($root, $class->getAttribute('file'));

            foreach ($xpath->query('./*[local-name()="testMethod"]', $class) ?: [] as $method) {
                if (! $method instanceof DOMElement) {
                    continue;
                }
                if ($method->getAttribute('id') === '') {
                    continue;
                }

                $id = $this->stableCaseId(
                    $method->getAttribute('id'),
                    $className,
                    $source,
                );

                if (isset($cases[$id])) {
                    throw new RuntimeException("PHPUnit discovery emitted duplicate case {$id}.");
                }

                $cases[$id] = ['id' => $id, 'source' => $source];
            }
        }

        if ($cases === []) {
            throw new RuntimeException('PHPUnit discovery did not find any cases.');
        }

        ksort($cases, SORT_STRING);

        return $cases;
    }

    private function source(string $root, string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        if (preg_match('/\A(.*\.php)(?:::.*)?\z/isD', $normalized, $match) === 1) {
            $normalized = $match[1];
        }

        $resolved = realpath($normalized);

        if ($resolved === false && ! str_starts_with($normalized, '/')) {
            $resolved = realpath($root.'/'.$normalized);
        }

        if ($resolved === false || ! is_file($resolved)) {
            throw new RuntimeException("Discovered source could not be resolved: {$path}.");
        }

        $resolved = str_replace('\\', '/', $resolved);
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';

        if (! str_starts_with($resolved, $prefix)) {
            throw new RuntimeException("Discovered source is outside the project: {$path}.");
        }

        return substr($resolved, strlen($prefix));
    }

    private function stableCaseId(string $id, string $class, string $source): string
    {
        $prefix = "{$class}::";

        return str_starts_with($class, 'P\\') && str_starts_with($id, $prefix)
            ? "pest:{$source}::".substr($id, strlen($prefix))
            : $id;
    }

    /**
     * @param  array<string, array{id: string, source: string}>  $cases
     * @return list<string>
     */
    private function caseIds(string $root, array $cases): array
    {
        $scanner = new Scanner(CompatibilityRegistry::load());

        foreach (array_unique(array_column($cases, 'source')) as $source) {
            $findings = $scanner->scanFile($root.'/'.$source);

            if (array_any(
                $findings,
                static fn (Finding $finding): bool => $finding->status !== CompatibilityStatus::Supported,
            )) {
                throw new RuntimeException(
                    "Selected source {$source} has a non-supported scanner finding.",
                );
            }
        }

        $caseIds = array_keys($cases);
        sort($caseIds, SORT_STRING);

        return $caseIds;
    }

    /**
     * @param  list<string>  $command
     * @return array{exit_code: int, wall_ms: int, peak_memory_bytes: int, output: string}
     */
    private function measure(string $root, array $command, string $rawPath): array
    {
        $raw = fopen($rawPath, 'xb');

        if ($raw === false) {
            throw new RuntimeException('Drove could not create an evidence run log.');
        }

        $started = hrtime(true);
        $peak = 0;

        try {
            $process = proc_open(
                $command,
                [
                    0 => ['file', $this->nullDevice(), 'r'],
                    1 => $raw,
                    2 => $raw,
                ],
                $pipes,
                $root,
                options: ['bypass_shell' => true],
            );

            if (! is_resource($process)) {
                throw new RuntimeException('Drove could not start an evidence command.');
            }

            $status = proc_get_status($process);
            $pid = $status['pid'];

            while ($status['running']) {
                if ($pid > 0) {
                    $peak = max($peak, $this->rootRssBytes($pid));
                }

                usleep(10_000);
                $status = proc_get_status($process);
            }

            $peak = max($peak, $this->rootRssBytes($pid));
            $reportedExit = $status['exitcode'];
            $closedExit = proc_close($process);
            $exit = $reportedExit >= 0 ? $reportedExit : $closedExit;
        } finally {
            fclose($raw);
        }

        $output = file_get_contents($rawPath);

        if ($exit < 0
            || $peak < 1
            || ! is_string($output)) {
            throw new RuntimeException('Drove could not meter an evidence command.');
        }

        return [
            'exit_code' => $exit,
            'wall_ms' => max(1, (int) round((hrtime(true) - $started) / 1_000_000)),
            'peak_memory_bytes' => $peak,
            'output' => $output,
        ];
    }

    private function rootRssBytes(int $pid): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $status = @file_get_contents("/proc/{$pid}/status");

            return is_string($status)
                && preg_match('/^VmRSS:\s+(\d+)\s+kB$/mi', $status, $match) === 1
                    ? (int) $match[1] * 1024
                    : 0;
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException(
                'Drove evidence collection requires supported Linux or macOS.',
            );
        }

        $process = proc_open(
            ['ps', '-o', 'rss=', '-p', (string) $pid],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            options: ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            return 0;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0
            || ! is_string($output)
            || preg_match('/^\s*(\d+)\s*$/D', $output, $match) !== 1) {
            return 0;
        }

        return (int) $match[1] * 1024;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  list<string>  $command
     * @param  array{exit_code: int, wall_ms: int, peak_memory_bytes: int, output: string}  $measurement
     * @param  array<string, int>  $summary
     * @return array<string, mixed>
     */
    private function runRecord(
        array $configuration,
        array $command,
        string $runner,
        int $processes,
        int $observedLanes,
        array $measurement,
        array $summary,
        int $selected,
    ): array {
        if (($summary['tests'] ?? null) !== $selected) {
            throw new RuntimeException(
                "{$runner} C{$processes} did not execute the exact selected case count.",
            );
        }

        if ($measurement['exit_code'] !== 0
            || $summary['failed'] !== 0
            || $summary['errors'] !== 0) {
            throw new RuntimeException(
                "{$runner} C{$processes} must complete successfully for release evidence.",
            );
        }

        $record = [
            'runner' => $runner,
            'frontend' => $configuration['frontend'],
            'runtime' => $configuration['runtime'],
            'command_argv' => $command,
            'processes' => $processes,
            'exit_code' => $measurement['exit_code'],
            'selected' => $selected,
            'passed' => $summary['passed'],
            'failed' => $summary['failed'] + $summary['errors'],
            'skipped' => $summary['skipped'],
            'incomplete' => $summary['incomplete'],
            'risky' => $summary['risky'],
            'rejected' => 0,
            'assertions' => $summary['assertions'],
            'wall_ms' => $measurement['wall_ms'],
            'peak_memory_bytes' => $measurement['peak_memory_bytes'],
            'memory_source' => 'rss',
        ];

        if ($runner === 'drove') {
            $record['observed_lanes'] = $observedLanes;
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, int>
     */
    private function semantics(array $run): array
    {
        return array_intersect_key($run, array_flip([
            'selected',
            'passed',
            'failed',
            'skipped',
            'incomplete',
            'risky',
            'rejected',
            'assertions',
        ]));
    }

    /**
     * @return array<string, int>
     */
    private function summary(string $plain): array
    {
        $summary = [
            'tests' => 0,
            'passed' => 0,
            'failed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'incomplete' => 0,
            'risky' => 0,
            'assertions' => 0,
        ];
        preg_match_all('/^\s*Tests:\s*(.+)$/mi', $plain, $matches);
        $line = trim((string) end($matches[1]));

        if ($line === ''
            && preg_match('/\bOK \((\d+) tests?,\s*(\d+) assertions?\)/i', $plain, $ok) === 1) {
            $summary['tests'] = (int) $ok[1];
            $summary['passed'] = (int) $ok[1];
            $summary['assertions'] = (int) $ok[2];
        } elseif (preg_match('/\bAssertions:\s*\d+/i', $line) === 1) {
            if (preg_match('/^(\d+)\s*,/', $line, $tests) === 1) {
                $summary['tests'] = (int) $tests[1];
            }

            foreach ([
                'assertions' => 'assertions',
                'failures' => 'failed',
                'errors' => 'errors',
                'skipped' => 'skipped',
                'incomplete' => 'incomplete',
                'risky' => 'risky',
            ] as $label => $key) {
                if (preg_match('/\b'.$label.':\s*(\d+)/i', $line, $value) === 1) {
                    $summary[$key] = (int) $value[1];
                }
            }

            $summary['passed'] = max(
                0,
                $summary['tests'] - $summary['failed'] - $summary['errors']
                    - $summary['skipped'] - $summary['incomplete'] - $summary['risky'],
            );
        } elseif ($line !== '') {
            $labels = [
                'passed' => 'passed',
                'failed' => 'failed',
                'error' => 'errors',
                'errors' => 'errors',
                'skipped' => 'skipped',
                'incomplete' => 'incomplete',
                'risky' => 'risky',
            ];
            preg_match_all('/(\d+)\s+([a-z]+)/i', $line, $counts, PREG_SET_ORDER);

            foreach ($counts as $count) {
                $label = strtolower($count[2]);

                if (isset($labels[$label])) {
                    $summary[$labels[$label]] += (int) $count[1];
                }
            }

            if (preg_match('/\((\d+)\s+assertions?\)/i', $line, $assertions) === 1) {
                $summary['assertions'] = (int) $assertions[1];
            }

            $summary['tests'] = array_sum(array_intersect_key(
                $summary,
                array_flip(['passed', 'failed', 'errors', 'skipped', 'incomplete', 'risky']),
            ));
        } else {
            throw new RuntimeException('Runner output did not contain a Tests summary.');
        }

        preg_match_all('/^\s*Assertions:\s*(\d+)\s*$/mi', $plain, $assertionMatches);
        $assertions = end($assertionMatches[1]);

        if ($assertions !== false) {
            $summary['assertions'] = (int) $assertions;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $replay
     * @param  list<string>  $expectedCaseIds
     * @return array{observed_lanes: int, summary: array<string, int>}
     */
    private function replay(
        array $replay,
        int $processes,
        int $exitCode,
        array $expectedCaseIds,
    ): array {
        $identity = $replay['plan']['case_identity'] ?? null;
        $cases = is_array($identity) ? ($identity['cases'] ?? null) : null;
        $counts = $replay['result']['counts'] ?? null;
        $observed = $replay['result']['observed_concurrency']['global'] ?? null;

        if (($replay['schema'] ?? null) !== 1
            || ($replay['kind'] ?? null) !== 'run'
            || ($replay['command']['processes'] ?? null) !== $processes
            || ($replay['result']['exit_code'] ?? null) !== $exitCode
            || ($replay['plan']['tests'] ?? null) !== count($expectedCaseIds)
            || ! is_array($cases)
            || ! array_is_list($cases)
            || ! is_array($counts)
            || ! is_int($observed)
            || $observed < 1
            || $observed > min($processes, count($expectedCaseIds))) {
            throw new RuntimeException("Drove C{$processes} replay identity is invalid.");
        }

        $byExecution = [];
        $caseIds = [];

        foreach ($cases as $case) {
            $execution = is_array($case) ? ($case['execution_id'] ?? null) : null;
            $frontend = is_array($case) ? ($case['frontend_id'] ?? null) : null;

            if (! is_string($execution)
                || $execution === ''
                || isset($byExecution[$execution])
                || ! is_string($frontend)
                || $frontend === '') {
                throw new RuntimeException("Drove C{$processes} replay case identity is invalid.");
            }

            $byExecution[$execution] = $frontend;
            $caseIds[] = $frontend;
        }

        sort($caseIds, SORT_STRING);

        if ($caseIds !== $expectedCaseIds
            || ($identity['frontend_ids_sha256'] ?? null) !== $this->caseIdsHash($caseIds)) {
            throw new RuntimeException(
                "Drove C{$processes} replay did not plan the exact selected case IDs.",
            );
        }

        $completed = [];

        foreach ($replay['result']['completion_order'] ?? [] as $execution) {
            if (is_string($execution) && isset($byExecution[$execution])) {
                $completed[] = $byExecution[$execution];
            }
        }

        sort($completed, SORT_STRING);

        if ($completed !== $expectedCaseIds) {
            throw new RuntimeException(
                "Drove C{$processes} replay did not complete every selected case.",
            );
        }

        foreach ($counts as $status => $count) {
            if (! is_string($status)
                || ! in_array(
                    $status,
                    ['passed', 'failed', 'skipped', 'incomplete', 'risky'],
                    true,
                )
                || ! is_int($count)
                || $count < 0) {
                throw new RuntimeException(
                    'Drove evidence schema cannot represent one or more replay outcomes.',
                );
            }
        }

        $summary = [
            'tests' => array_sum($counts),
            'passed' => $counts['passed'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'errors' => 0,
            'skipped' => $counts['skipped'] ?? 0,
            'incomplete' => $counts['incomplete'] ?? 0,
            'risky' => $counts['risky'] ?? 0,
            'assertions' => 0,
        ];

        return ['observed_lanes' => $observed, 'summary' => $summary];
    }

    /**
     * @param  list<string>  $caseIds
     */
    private function caseIdsHash(array $caseIds): string
    {
        return hash(
            'sha256',
            json_encode($caseIds, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFile(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("Drove could not read JSON artifact {$path}.");
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Drove could not decode JSON artifact {$path}.", $exception->getCode(), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Drove JSON artifact {$path} must be an object.");
        }

        return $decoded;
    }

    /**
     * @return array{
     *     os_family: 'Linux'|'Darwin',
     *     architecture: 'x86_64'|'aarch64'|'arm64',
     *     native_target: string,
     *     php_version: string
     * }
     */
    private function platform(): array
    {
        $target = NativeLibrary::target();
        $machine = strtolower(php_uname('m'));
        $architecture = match (PHP_OS_FAMILY) {
            'Linux' => match ($machine) {
                'amd64', 'x86_64' => 'x86_64',
                'aarch64', 'arm64' => 'aarch64',
                default => throw new RuntimeException('Unsupported Linux architecture.'),
            },
            'Darwin' => match ($machine) {
                'amd64', 'x86_64' => 'x86_64',
                'aarch64', 'arm64' => 'arm64',
                default => throw new RuntimeException('Unsupported macOS architecture.'),
            },
            default => throw new RuntimeException(
                'Drove evidence collection requires supported Linux or macOS.',
            ),
        };

        /** @var array{
         *     os_family: 'Linux'|'Darwin',
         *     architecture: 'x86_64'|'aarch64'|'arm64',
         *     native_target: string,
         *     php_version: string
         * } $platform
         */
        $platform = [
            'os_family' => PHP_OS_FAMILY,
            'architecture' => $architecture,
            'native_target' => $target,
            'php_version' => PHP_VERSION,
        ];

        return $platform;
    }

    /**
     * @return array{tag: string, revision: string}
     */
    private function package(): array
    {
        $identity = $this->packageIdentity;

        if ($identity === null) {
            if (! InstalledVersions::isInstalled('oxhq/drove')) {
                throw new RuntimeException(
                    'Drove evidence requires an installed tagged oxhq/drove package.',
                );
            }

            $version = InstalledVersions::getPrettyVersion('oxhq/drove')
                ?? InstalledVersions::getVersion('oxhq/drove');
            $revision = InstalledVersions::getReference('oxhq/drove');
            $identity = [
                'tag' => is_string($version) ? 'v'.ltrim($version, 'v') : '',
                'revision' => is_string($revision) ? $revision : '',
            ];
        }

        if (preg_match('/\Av0\.\d+\.\d+-alpha\.\d+\z/', $identity['tag']) !== 1
            || preg_match('/\A[0-9a-f]{40}\z/', $identity['revision']) !== 1) {
            throw new RuntimeException(
                'Drove evidence requires an exact experimental package tag and revision.',
            );
        }

        return $identity;
    }

    private function root(string $root): string
    {
        $resolved = realpath($root);

        if ($resolved === false || ! is_dir($resolved) || ! is_file($resolved.'/composer.json')) {
            throw new InvalidArgumentException('Drove evidence project root is invalid.');
        }

        return str_replace('\\', '/', $resolved);
    }

    private function relativeFile(string $root, string $path): string
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            $resolved = realpath($root.'/'.$path);
        }

        if ($resolved === false || ! is_file($resolved)) {
            throw new InvalidArgumentException('Drove evidence artifact does not exist.');
        }

        $resolved = str_replace('\\', '/', $resolved);
        $prefix = rtrim($root, '/').'/';

        if (! str_starts_with($resolved, $prefix)) {
            throw new InvalidArgumentException(
                'Drove evidence artifact must be inside the project root.',
            );
        }

        return substr($resolved, strlen($prefix));
    }

    private function assertTrackedClean(
        string $root,
        bool $allowEvidenceWork = false,
    ): void {
        $untracked = $this->git(
            $root,
            ['ls-files', '--others', '--exclude-standard', '-z'],
            false,
        );

        if ($untracked['exit'] !== 0) {
            throw new RuntimeException(
                'Drove evidence could not inspect untracked project files.',
            );
        }

        $untrackedPaths = array_values(array_filter(
            explode("\0", $untracked['output']),
            static fn (string $path): bool => $path !== ''
                && (! $allowEvidenceWork
                    || ! str_starts_with($path, '.drove/evaluation-work/')),
        ));

        if ($this->git($root, ['diff', '--quiet'])['exit'] !== 0
            || $this->git($root, ['diff', '--cached', '--quiet'])['exit'] !== 0
            || $untrackedPaths !== []) {
            throw new RuntimeException(
                'Drove evidence collection requires a clean checkout without untracked project files.',
            );
        }
    }

    private function gitRevision(string $root): string
    {
        $revision = $this->git($root, ['rev-parse', 'HEAD']);

        if ($revision['exit'] !== 0
            || preg_match('/\A[0-9a-f]{40}\z/', $revision['output']) !== 1) {
            throw new RuntimeException('Drove evidence requires an exact Git project revision.');
        }

        return $revision['output'];
    }

    private function assertRepository(string $root, string $repository): void
    {
        $expected = $this->repository($repository);
        $origin = $this->git($root, ['config', '--get', 'remote.origin.url']);
        $actual = $origin['exit'] === 0 ? $this->origin($origin['output']) : null;

        if ($actual === null
            || strcasecmp($actual['owner'], $expected['owner']) !== 0
            || strcasecmp($actual['repository'], $expected['repository']) !== 0) {
            throw new RuntimeException(
                'Drove evidence repository does not match the project origin.',
            );
        }
    }

    /**
     * @return array{owner: string, repository: string}
     */
    private function repository(string $url): array
    {
        if (preg_match(
            '#\Ahttps://github\.com/([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9._-]+)\z#',
            $url,
            $match,
        ) !== 1
            || strtolower($match[1]) === 'oxhq') {
            throw new InvalidArgumentException(
                'Drove evidence repository must be a canonical external GitHub URL.',
            );
        }

        return ['owner' => $match[1], 'repository' => $match[2]];
    }

    /**
     * @return null|array{owner: string, repository: string}
     */
    private function origin(string $url): ?array
    {
        if (preg_match(
            '#\A(?:https://github\.com/|git@github\.com:)([^/:\s]+)/([^/\s]+?)(?:\.git)?\z#',
            $url,
            $match,
        ) !== 1) {
            return null;
        }

        return ['owner' => $match[1], 'repository' => $match[2]];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{exit: int, output: string}
     */
    private function git(string $root, array $arguments, bool $trim = true): array
    {
        $process = proc_open(
            ['git', ...$arguments],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
            options: ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Drove could not start Git.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit' => $exit,
            'output' => $trim ? trim((string) $output) : (string) $output,
        ];
    }

    private function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    private function removeDirectory(string $directory): void
    {
        if (basename($directory) !== 'evaluation-work'
            || basename(dirname($directory)) !== '.drove') {
            return;
        }

        if (is_link($directory)) {
            if (! @unlink($directory) && file_exists($directory)) {
                throw new RuntimeException(
                    'Drove could not remove its temporary evidence symlink.',
                );
            }

            return;
        }

        if (! is_dir($directory)) {
            return;
        }

        $this->removeTree($directory);
    }

    private function removeTree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.') {
                continue;
            }
            if ($entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeTree($path);
            } elseif (! @unlink($path) && file_exists($path)) {
                throw new RuntimeException(
                    'Drove could not remove a temporary evidence artifact.',
                );
            }
        }

        if (! @rmdir($directory) && is_dir($directory)) {
            throw new RuntimeException(
                'Drove could not remove its temporary evidence directory.',
            );
        }
    }
}
