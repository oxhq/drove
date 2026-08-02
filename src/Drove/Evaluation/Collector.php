<?php

declare(strict_types=1);

namespace Drove\Evaluation;

use Composer\InstalledVersions;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Drove\Compatibility\Registry;
use Drove\Compatibility\Status;
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
        $projectRevision = $configuration['baseline_revision'];
        $evaluationRevision = $this->gitRevision($root);
        $this->assertTrackedClean($root);
        $this->assertTrackedFile($root, 'composer.lock');
        $this->assertEvaluationDelta($root, $projectRevision, $evaluationRevision);
        $this->assertNoTrackedVendor($root, $projectRevision);
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

        $baselineContainer = null;
        $baselineRoot = null;
        $evaluationContainer = null;
        $evaluationRoot = null;

        try {
            $baselineContainer = $this->worktreeContainer('baseline');
            $baselineRoot = $baselineContainer.'/project';
            $this->createDetachedWorktree(
                $root,
                $baselineRoot,
                $projectRevision,
                'baseline',
            );
            $baselineRoot = $this->root($baselineRoot);
            $this->assertRepository($baselineRoot, $configuration['repository']);

            if ($this->gitRevision($baselineRoot) !== $projectRevision) {
                throw new RuntimeException(
                    'Drove created the baseline worktree at an unexpected revision.',
                );
            }

            $evaluationContainer = $this->worktreeContainer('evaluation');
            $evaluationRoot = $evaluationContainer.'/project';
            $this->createDetachedWorktree(
                $root,
                $evaluationRoot,
                $evaluationRevision,
                'evaluation',
            );
            $evaluationRoot = $this->root($evaluationRoot);
            $this->assertRepository($evaluationRoot, $configuration['repository']);

            if ($this->gitRevision($evaluationRoot) !== $evaluationRevision) {
                throw new RuntimeException(
                    'Drove created the evaluation worktree at an unexpected revision.',
                );
            }

            $this->assertTrackedClean($baselineRoot);
            $this->assertTrackedClean($evaluationRoot);
            $this->assertTrackedFile($baselineRoot, 'composer.lock');
            $this->assertTrackedFile($evaluationRoot, 'composer.lock');
            $composerInstall = [
                'composer',
                'install',
                '--no-interaction',
                '--no-progress',
                '--prefer-dist',
            ];
            $baselineInstall = $this->measure(
                $baselineRoot,
                $composerInstall,
                $work.'/composer-baseline-install.log',
            );

            if ($baselineInstall['exit_code'] !== 0) {
                throw new RuntimeException(
                    'Composer could not install the committed baseline lock.',
                );
            }

            $evaluationInstall = $this->measure(
                $evaluationRoot,
                $composerInstall,
                $work.'/composer-evaluation-install.log',
            );

            if ($evaluationInstall['exit_code'] !== 0) {
                throw new RuntimeException(
                    'Composer could not install the committed evaluation lock.',
                );
            }

            $this->assertTrackedClean($baselineRoot, allowVendor: true);
            $this->assertTrackedClean($evaluationRoot, allowVendor: true);
            $dependencyState = $this->dependencyState(
                $baselineRoot,
                $evaluationRoot,
                $configuration['frontend'],
                $package,
            );
            $discovery = $this->discover(
                $baselineRoot,
                $configuration['baseline_command_argv'],
                $work.'/selection.xml',
                $work.'/selection.log',
            );
            $caseIds = $this->caseIds($evaluationRoot, $discovery);

            if (count($caseIds) < 2) {
                throw new RuntimeException(
                    'Drove evidence collection requires at least two selected cases.',
                );
            }

            $baselineCommand = $configuration['baseline_command_argv'];
            $baselineMeasurement = $this->measure(
                $baselineRoot,
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
            $evaluationWork = $evaluationRoot.'/.drove/evaluation-work';

            if (! @mkdir($evaluationWork, 0700, true) && ! is_dir($evaluationWork)) {
                throw new RuntimeException(
                    'Drove could not create its clean evaluation work directory.',
                );
            }

            foreach ([1, $configuration['parallel_processes']] as $processes) {
                $replay = ".drove/evaluation-work/drove-c{$processes}-replay.json";
                $command = [
                    ...$configuration['drove_command_argv'],
                    '--parallel',
                    "--processes={$processes}",
                    "--replay={$replay}",
                ];
                $measurement = $this->measure(
                    $evaluationRoot,
                    $command,
                    "{$work}/drove-c{$processes}.log",
                );
                $replayContents = $this->jsonFile($evaluationRoot.'/'.$replay);
                $replayResult = $this->replay(
                    $replayContents,
                    $processes,
                    $measurement['exit_code'],
                    $caseIds,
                );

                if ($processes === $configuration['parallel_processes']
                    && $replayResult['observed_lanes'] < 2) {
                    throw new RuntimeException(
                        'Drove parallel replay must observe at least two lanes.',
                    );
                }

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

            if ($this->gitRevision($baselineRoot) !== $projectRevision
                || $this->gitRevision($evaluationRoot) !== $evaluationRevision
                || $this->gitRevision($root) !== $evaluationRevision) {
                throw new RuntimeException(
                    'A project revision changed during evidence collection.',
                );
            }

            $this->assertTrackedClean($baselineRoot, allowVendor: true);
            $this->assertTrackedClean(
                $evaluationRoot,
                allowEvidenceWork: true,
                allowVendor: true,
            );
            $this->assertTrackedClean($root, allowEvidenceWork: true);

            return [
                'schema' => 2,
                'evaluation_id' => $configuration['evaluation_id'],
                'team' => $configuration['team'],
                'repository' => $configuration['repository'],
                'drove' => $package,
                'project_revision' => $projectRevision,
                'evaluation_revision' => $evaluationRevision,
                'dependency_state' => $dependencyState,
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
            ];
        } finally {
            try {
                $this->removeDirectory($work);
            } finally {
                try {
                    if (is_string($evaluationRoot) && is_string($evaluationContainer)) {
                        $this->removeDetachedWorktree(
                            $root,
                            $evaluationRoot,
                            $evaluationContainer,
                            'evaluation',
                        );
                    }
                } finally {
                    if (is_string($baselineRoot) && is_string($baselineContainer)) {
                        $this->removeDetachedWorktree(
                            $root,
                            $baselineRoot,
                            $baselineContainer,
                            'baseline',
                        );
                    }
                }
            }
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
            || ($evidence['schema'] ?? null) !== 2
            || ! is_string($evidence['evaluation_id'] ?? null)
            || ! is_string($evidence['team'] ?? null)
            || ! is_string($evidence['repository'] ?? null)
            || ! is_array($evidence['drove'] ?? null)
            || ! is_string($evidence['project_revision'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $evidence['project_revision']) !== 1
            || ! is_string($evidence['evaluation_revision'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $evidence['evaluation_revision']) !== 1
            || ! is_array($evidence['dependency_state'] ?? null)) {
            throw new RuntimeException('Drove evidence artifact identity is invalid.');
        }

        $this->assertRepository($root, $evidence['repository']);
        $evidenceRevision = $this->gitRevision($root);

        if ($evidence['evaluation_revision'] === $evidence['project_revision']
            || $this->git(
                $root,
                [
                    'merge-base',
                    '--is-ancestor',
                    $evidence['project_revision'],
                    $evidence['evaluation_revision'],
                ],
            )['exit'] !== 0
            || $evidenceRevision === $evidence['evaluation_revision']
            || $this->git(
                $root,
                [
                    'merge-base',
                    '--is-ancestor',
                    $evidence['evaluation_revision'],
                    $evidenceRevision,
                ],
            )['exit'] !== 0) {
            throw new RuntimeException(
                'Evidence revisions must form a strict project < evaluation < evidence chain.',
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
            'evaluation_revision' => $evidence['evaluation_revision'],
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
     *     baseline_revision: string,
     *     baseline_command_argv: list<string>,
     *     drove_command_argv: list<string>
     * }
     */
    private function configuration(array $configuration): array
    {
        $allowed = [
            'baseline_command_argv',
            'baseline_revision',
            'drove_command_argv',
            'evaluation_id',
            'frontend',
            'parallel_processes',
            'repository',
            'runtime',
            'schema',
            'team',
        ];
        $keys = array_keys($configuration);
        sort($keys, SORT_STRING);

        if (array_diff($keys, $allowed) !== []
            || ($configuration['schema'] ?? null) !== 2
            || ! is_string($configuration['evaluation_id'] ?? null)
            || preg_match('/\A[a-z0-9][a-z0-9-]{2,63}\z/', $configuration['evaluation_id']) !== 1
            || ! is_string($configuration['team'] ?? null)
            || trim($configuration['team']) === ''
            || ! is_string($configuration['repository'] ?? null)
            || ! is_string($configuration['baseline_revision'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $configuration['baseline_revision']) !== 1
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

        /** @var array{
         *     evaluation_id: string,
         *     team: string,
         *     repository: string,
         *     frontend: 'pest'|'phpunit',
         *     runtime: 'php'|'laravel'|'testbench',
         *     parallel_processes: int,
         *     baseline_revision: string,
         *     baseline_command_argv: list<string>,
         *     drove_command_argv: list<string>
         * } $validated
         */
        $validated = [
            'evaluation_id' => $configuration['evaluation_id'],
            'team' => trim($configuration['team']),
            'repository' => $configuration['repository'],
            'frontend' => $configuration['frontend'],
            'runtime' => $configuration['runtime'],
            'parallel_processes' => $configuration['parallel_processes'],
            'baseline_revision' => $configuration['baseline_revision'],
            'baseline_command_argv' => $baseline,
            'drove_command_argv' => $drove,
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
        $measurement = $this->measure(
            $root,
            [
                ...$command,
                '--do-not-cache-result',
                '--no-logging',
                '--list-tests-xml',
                $output,
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
        $scanner = new Scanner(Registry::load());

        foreach (array_unique(array_column($cases, 'source')) as $source) {
            $findings = $scanner->scanFile($root.'/'.$source);

            if (array_any(
                $findings,
                static fn (Finding $finding): bool => $finding->status !== Status::Supported,
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
     * @return array<array-key, mixed>
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

    private function worktreeContainer(string $purpose): string
    {
        $container = str_replace(
            '\\',
            '/',
            sys_get_temp_dir()."/drove-evaluation-{$purpose}-".bin2hex(random_bytes(16)),
        );

        if (! @mkdir($container, 0700)) {
            throw new RuntimeException(
                "Drove could not create a private {$purpose} directory.",
            );
        }

        if (! @chmod($container, 0700)) {
            @rmdir($container);

            throw new RuntimeException(
                "Drove could not secure its private {$purpose} directory.",
            );
        }

        return $container;
    }

    private function createDetachedWorktree(
        string $root,
        string $worktreeRoot,
        string $revision,
        string $purpose,
    ): void {
        if ($this->git(
            $root,
            ['worktree', 'add', '--detach', $worktreeRoot, $revision],
        )['exit'] !== 0
            || ! is_dir($worktreeRoot)
            || ! @chmod($worktreeRoot, 0700)) {
            throw new RuntimeException(
                "Drove could not create the detached {$purpose} worktree.",
            );
        }
    }

    private function removeDetachedWorktree(
        string $root,
        string $worktreeRoot,
        string $container,
        string $purpose,
    ): void {
        $removeFailed = is_dir($worktreeRoot)
            && $this->git(
                $root,
                ['worktree', 'remove', '--force', $worktreeRoot],
            )['exit'] !== 0;
        $directoryFailure = null;

        try {
            if (is_dir($container)) {
                $this->removeWorktreeDirectory($container);
            }
        } catch (RuntimeException $exception) {
            $directoryFailure = $exception;
        }

        $pruneFailed = $this->git($root, ['worktree', 'prune'])['exit'] !== 0;

        if ($removeFailed || $pruneFailed || $directoryFailure instanceof RuntimeException) {
            throw new RuntimeException(
                "Drove could not remove and prune its {$purpose} worktree.",
                previous: $directoryFailure,
            );
        }
    }

    private function removeWorktreeDirectory(string $directory): void
    {
        $temporary = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/');

        if (dirname($directory) !== $temporary
            || preg_match(
                '/\Adrove-evaluation-(?:baseline|evaluation)-[0-9a-f]{32}\z/D',
                basename($directory),
            ) !== 1) {
            throw new RuntimeException('Drove refused to remove an unsafe worktree path.');
        }

        $this->removeTree($directory);
    }

    private function assertNoTrackedVendor(string $root, string $revision): void
    {
        $tracked = $this->git(
            $root,
            ['ls-tree', '-r', '--name-only', '-z', $revision, '--', 'vendor'],
            false,
        );

        if ($tracked['exit'] !== 0) {
            throw new RuntimeException(
                'Drove evidence could not inspect the baseline Composer tree.',
            );
        }

        if ($tracked['output'] !== '') {
            throw new RuntimeException(
                'The baseline revision must not contain tracked vendor files.',
            );
        }
    }

    private function assertEvaluationDelta(
        string $root,
        string $projectRevision,
        string $evaluationRevision,
    ): void {
        if ($projectRevision === $evaluationRevision
            || $this->git(
                $root,
                ['merge-base', '--is-ancestor', $projectRevision, $evaluationRevision],
            )['exit'] !== 0) {
            throw new RuntimeException(
                'The project revision must be a strict ancestor of the evaluation revision.',
            );
        }

        $renames = $this->git(
            $root,
            [
                'diff',
                '--diff-filter=R',
                '--name-only',
                '-z',
                "{$projectRevision}..{$evaluationRevision}",
            ],
            false,
        );

        if ($renames['exit'] !== 0 || $renames['output'] !== '') {
            throw new RuntimeException(
                'Evaluation revision must not contain renamed project paths.',
            );
        }

        $diff = $this->git(
            $root,
            [
                'diff',
                '--no-renames',
                '--name-only',
                '-z',
                "{$projectRevision}..{$evaluationRevision}",
            ],
            false,
        );

        if ($diff['exit'] !== 0) {
            throw new RuntimeException('Drove evidence could not inspect the evaluation delta.');
        }

        $changed = [];

        foreach (array_filter(
            explode("\0", $diff['output']),
            static fn (string $path): bool => $path !== '',
        ) as $path) {
            $changed[] = $path;

            if (in_array(
                $path,
                ['composer.json', 'composer.lock', '.drove/evaluation-config.json'],
                true,
            )) {
                continue;
            }

            if (preg_match('#\A\.github/workflows/[^/]+\.ya?ml\z#', $path) === 1) {
                continue;
            }

            throw new RuntimeException(
                "Evaluation revision changes unsupported project path: {$path}.",
            );
        }

        foreach (['composer.json', 'composer.lock', '.drove/evaluation-config.json'] as $required) {
            if (! in_array($required, $changed, true)) {
                throw new RuntimeException(
                    "Evaluation revision must change required project path: {$required}.",
                );
            }
        }
    }

    /**
     * @param  'pest'|'phpunit'  $frontend
     * @param  array{tag: string, revision: string}  $drove
     * @return array{
     *     baseline_lock_sha256: string,
     *     evaluation_lock_sha256: string,
     *     baseline_runner: array{package: string, version: string, revision: string}
     * }
     */
    private function dependencyState(
        string $baselineRoot,
        string $evaluationRoot,
        string $frontend,
        array $drove,
    ): array {
        $baselineLockPath = $baselineRoot.'/composer.lock';
        $evaluationLockPath = $evaluationRoot.'/composer.lock';
        $baselineInstalledPath = $baselineRoot.'/vendor/composer/installed.json';
        $evaluationInstalledPath = $evaluationRoot.'/vendor/composer/installed.json';

        foreach ([
            $baselineLockPath,
            $evaluationLockPath,
            $baselineInstalledPath,
            $evaluationInstalledPath,
        ] as $path) {
            if (! is_file($path)) {
                throw new RuntimeException(
                    'Independent baseline evidence requires both Composer locks and installed.json files.',
                );
            }
        }

        $this->assertTrackedFile($baselineRoot, 'composer.lock');
        $this->assertTrackedFile($evaluationRoot, 'composer.lock');

        $baselineLock = $this->jsonFile($baselineLockPath);
        $evaluationLock = $this->jsonFile($evaluationLockPath);
        $baselineInstalled = $this->jsonFile($baselineInstalledPath);
        $evaluationInstalled = $this->jsonFile($evaluationInstalledPath);
        [$runnerPackage, $runnerVersion] = $frontend === 'pest'
            ? ['pestphp/pest', '5.0.1']
            : ['phpunit/phpunit', '13.2.4'];
        $lockedRunner = $this->composerPackage($baselineLock, $runnerPackage);
        $installedRunner = $this->composerPackage($baselineInstalled, $runnerPackage);

        if ($lockedRunner === null
            || $installedRunner === null
            || $this->composerVersion($lockedRunner) !== $runnerVersion
            || $this->composerVersion($installedRunner) !== $runnerVersion
            || $this->composerPackage($baselineLock, 'oxhq/drove') !== null
            || $this->composerPackage($baselineInstalled, 'oxhq/drove') !== null) {
            throw new RuntimeException(
                "Baseline Composer state must contain {$runnerPackage} {$runnerVersion} and exclude oxhq/drove.",
            );
        }

        $runnerReferences = $this->composerReferences($lockedRunner);
        $runnerRevision = count($runnerReferences) === 1 ? $runnerReferences[0] : null;

        if (! is_string($runnerRevision)
            || preg_match('/\A[0-9a-f]{40}\z/', $runnerRevision) !== 1
            || $this->composerReferences($installedRunner) !== [$runnerRevision]) {
            throw new RuntimeException(
                'Installed baseline runner must match the exact locked revision.',
            );
        }

        $evaluationLockedRunner = $this->composerPackage($evaluationLock, $runnerPackage);
        $evaluationInstalledRunner = $this->composerPackage(
            $evaluationInstalled,
            $runnerPackage,
        );
        $evaluationDrove = $this->composerPackage($evaluationLock, 'oxhq/drove');
        $evaluationInstalledDrove = $this->composerPackage(
            $evaluationInstalled,
            'oxhq/drove',
        );
        $expectedVersion = ltrim($drove['tag'], 'v');

        if ($frontend === 'phpunit') {
            if ($evaluationLockedRunner === null
                || $evaluationInstalledRunner === null
                || $this->composerVersion($evaluationLockedRunner) !== $runnerVersion
                || $this->composerVersion($evaluationInstalledRunner) !== $runnerVersion
                || $this->composerReferences($evaluationLockedRunner) !== [$runnerRevision]
                || $this->composerReferences($evaluationInstalledRunner) !== [$runnerRevision]) {
                throw new RuntimeException(
                    'Evaluation Composer state must use the exact baseline PHPUnit runner.',
                );
            }
        } elseif ($evaluationLockedRunner !== null || $evaluationInstalledRunner !== null) {
            throw new RuntimeException(
                'Evaluation Composer state must replace pestphp/pest with oxhq/drove.',
            );
        }

        if ($evaluationDrove === null
            || $evaluationInstalledDrove === null
            || $this->composerVersion($evaluationDrove) !== $expectedVersion
            || $this->composerVersion($evaluationInstalledDrove) !== $expectedVersion
            || $this->composerReferences($evaluationDrove) !== [$drove['revision']]
            || $this->composerReferences($evaluationInstalledDrove) !== [$drove['revision']]) {
            throw new RuntimeException(
                'Evaluation Composer state must contain oxhq/drove matching the evaluated tag and revision.',
            );
        }

        $baselineHash = hash_file('sha256', $baselineLockPath);
        $evaluationHash = hash_file('sha256', $evaluationLockPath);

        if (! is_string($baselineHash) || ! is_string($evaluationHash)) {
            throw new RuntimeException('Drove evidence could not hash Composer lock state.');
        }

        return [
            'baseline_lock_sha256' => $baselineHash,
            'evaluation_lock_sha256' => $evaluationHash,
            'baseline_runner' => [
                'package' => $runnerPackage,
                'version' => $runnerVersion,
                'revision' => $runnerRevision,
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $document
     * @return null|array<string, mixed>
     */
    private function composerPackage(array $document, string $name): ?array
    {
        $packages = array_is_list($document)
            ? $document
            : [
                ...(is_array($document['packages'] ?? null) ? $document['packages'] : []),
                ...(is_array($document['packages-dev'] ?? null) ? $document['packages-dev'] : []),
            ];

        foreach ($packages as $package) {
            if (is_array($package)
                && is_string($package['name'] ?? null)
                && strcasecmp($package['name'], $name) === 0) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function composerVersion(array $package): ?string
    {
        $version = $package['pretty_version'] ?? $package['version'] ?? null;

        if (! is_string($version)) {
            return null;
        }

        return str_starts_with($version, 'v')
            ? substr($version, 1)
            : $version;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private function composerReferences(array $package): array
    {
        $references = [];

        foreach (['source', 'dist'] as $kind) {
            $reference = is_array($package[$kind] ?? null)
                ? ($package[$kind]['reference'] ?? null)
                : null;

            if (is_string($reference) && $reference !== '') {
                $references[] = $reference;
            }
        }

        return array_values(array_unique($references));
    }

    private function root(string $root): string
    {
        $resolved = realpath($root);

        if ($resolved === false || ! is_dir($resolved) || ! is_file($resolved.'/composer.json')) {
            throw new InvalidArgumentException('Drove evidence project root is invalid.');
        }

        return str_replace('\\', '/', $resolved);
    }

    private function assertTrackedFile(string $root, string $path): void
    {
        if ($this->git($root, ['ls-files', '--error-unmatch', '--', $path])['exit'] !== 0) {
            throw new RuntimeException("Drove evidence requires tracked {$path}.");
        }
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
        bool $allowVendor = false,
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
                    || ! str_starts_with($path, '.drove/evaluation-work/'))
                && (! $allowVendor
                    || ($path !== 'vendor' && ! str_starts_with($path, 'vendor/'))),
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
