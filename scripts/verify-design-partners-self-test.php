#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/verify-design-partners.php';

/**
 * @param  callable(): mixed  $test
 */
function expectDesignPartnerFailure(callable $test, string $message): void
{
    try {
        $test();
    } catch (DesignPartnerGateFailed $exception) {
        if (str_contains($exception->getMessage(), $message)) {
            return;
        }

        throw new RuntimeException("Expected failure containing '{$message}', got '{$exception->getMessage()}'.", $exception->getCode(), $exception);
    }

    throw new RuntimeException("Expected design-partner failure containing '{$message}'.");
}

/**
 * @param  null|array<string, mixed>  $migration
 * @return array<string, mixed>
 */
function designPartnerFixtureEvidence(
    string $id,
    string $team,
    string $repository,
    string $projectRevision,
    string $frontend,
    string $runtime,
    int $supported,
    ?array $migration,
): array {
    $caseIds = [];

    for ($case = 1; $case <= $supported; $case++) {
        $caseIds[] = sprintf('%s::case:%04d', $id, $case);
    }

    $semantics = [
        'selected' => $supported,
        'passed' => $supported,
        'failed' => 0,
        'skipped' => 0,
        'incomplete' => 0,
        'risky' => 0,
        'rejected' => 0,
        'assertions' => $supported * 2,
    ];
    $telemetry = [
        'frontend' => $frontend,
        'runtime' => $runtime,
        'memory_source' => 'rss',
        'peak_memory_bytes' => 64_000_000,
    ];
    $droveRevision = '87c9ecd9b827f44432839e4c6e5fe79e6c86dbb1';

    return [
        'schema' => 1,
        'evaluation_id' => $id,
        'team' => $team,
        'repository' => $repository,
        'drove' => [
            'tag' => 'v0.4.0-alpha.1',
            'revision' => $droveRevision,
        ],
        'project_revision' => $projectRevision,
        'scanner' => [
            'completed' => true,
            'discovered' => $supported + 2,
            'supported' => $supported,
            'bridge_only' => 1,
            'rejected' => 1,
        ],
        'benchmark' => [
            'completed' => true,
            'selected' => $supported,
            'case_ids' => $caseIds,
            'selection_sha256' => hash(
                'sha256',
                json_encode($caseIds, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
            'platform' => [
                'os_family' => 'Linux',
                'architecture' => 'x86_64',
                'native_target' => 'linux-gnu-x86_64',
                'php_version' => '8.4.10',
                'package_version' => '0.4.0-alpha.1',
                'package_revision' => $droveRevision,
            ],
            'runs' => [
                [
                    ...$semantics,
                    ...$telemetry,
                    'runner' => $frontend,
                    'command_argv' => ["vendor/bin/{$frontend}", '--colors=never'],
                    'processes' => 1,
                    'wall_ms' => 1200,
                ],
                [
                    ...$semantics,
                    ...$telemetry,
                    'runner' => 'drove',
                    'command_argv' => ['vendor/bin/drove', '--processes=1'],
                    'processes' => 1,
                    'observed_lanes' => 1,
                    'wall_ms' => 1100,
                ],
                [
                    ...$semantics,
                    ...$telemetry,
                    'runner' => 'drove',
                    'command_argv' => ['vendor/bin/drove', '--processes=8'],
                    'processes' => 8,
                    'observed_lanes' => min(8, $supported),
                    'wall_ms' => 400,
                ],
            ],
        ],
        'migration' => $migration,
    ];
}

/**
 * @param  array<string, mixed>  $evidence
 * @param  array<string, string>  $artifacts
 * @return array<string, mixed>
 */
function designPartnerFixtureEntry(
    array $evidence,
    string $owner,
    string $repository,
    array &$artifacts,
): array {
    $contents = json_encode(
        $evidence,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
    $url = sprintf(
        'https://raw.githubusercontent.com/%s/%s/%s/.drove/evaluation.json',
        $owner,
        $repository,
        $evidence['project_revision'],
    );
    $artifacts[$url] = $contents;

    return [
        'id' => $evidence['evaluation_id'],
        'team' => $evidence['team'],
        'repository' => $evidence['repository'],
        'drove' => $evidence['drove'],
        'project_revision' => $evidence['project_revision'],
        'evidence' => [
            'url' => $url,
            'sha256' => hash('sha256', $contents),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function designPartnerFixtureRun(
    string $owner,
    string $repository,
    string $runId,
    string $revision,
    string $createdAt,
): array {
    return [
        'html_url' => "https://github.com/{$owner}/{$repository}/actions/runs/{$runId}",
        'repository' => ['full_name' => "{$owner}/{$repository}"],
        'status' => 'completed',
        'conclusion' => 'success',
        'event' => 'push',
        'head_sha' => $revision,
        'created_at' => $createdAt,
        'path' => '.github/workflows/tests.yml',
    ];
}

/**
 * @return array<string, mixed>
 */
function designPartnerFixtureJobs(): array
{
    return [
        'total_count' => 1,
        'jobs' => [[
            'status' => 'completed',
            'conclusion' => 'success',
            'steps' => [[
                'name' => 'Drove design-partner gate',
                'status' => 'completed',
                'conclusion' => 'success',
            ]],
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function designPartnerFixtureComparison(string $base, string $head): array
{
    return [
        'status' => 'ahead',
        'ahead_by' => 2,
        'behind_by' => 0,
        'base_commit' => ['sha' => $base],
        'merge_base_commit' => ['sha' => $base],
        'head_commit' => ['sha' => $head],
    ];
}

$startRevision = '1111111111111111111111111111111111111111';
$migrationInvoice = [
    'meaningful' => true,
    'drove_step_name' => 'Drove design-partner gate',
    'ci_started_at' => '2026-06-01T00:00:00Z',
    'ci_verified_at' => '2026-06-15T00:00:00Z',
    'ci_started_revision' => $startRevision,
    'ci_verified_revision' => '403a4d67225a153838ec126c484339abf60229d1',
    'ci_started_run_url' => 'https://github.com/InvoiceShelf/InvoiceShelf/actions/runs/101',
    'ci_verified_run_url' => 'https://github.com/InvoiceShelf/InvoiceShelf/actions/runs/102',
];
$migrationLivewire = [
    'meaningful' => true,
    'drove_step_name' => 'Drove design-partner gate',
    'ci_started_at' => '2026-06-01T00:00:00Z',
    'ci_verified_at' => '2026-06-15T00:00:00Z',
    'ci_started_revision' => $startRevision,
    'ci_verified_revision' => '9c1450739d30c9b0b223ad6512be2a33f8f62f96',
    'ci_started_run_url' => 'https://github.com/livewire/livewire/actions/runs/201',
    'ci_verified_run_url' => 'https://github.com/livewire/livewire/actions/runs/202',
];
$artifacts = [];
$invoiceEvidence = designPartnerFixtureEvidence(
    'fixture-invoiceshelf',
    'Fixture InvoiceShelf',
    'https://github.com/InvoiceShelf/InvoiceShelf',
    '403a4d67225a153838ec126c484339abf60229d1',
    'pest',
    'laravel',
    202,
    $migrationInvoice,
);
$livewireEvidence = designPartnerFixtureEvidence(
    'fixture-livewire',
    'Fixture Livewire',
    'https://github.com/livewire/livewire',
    '9c1450739d30c9b0b223ad6512be2a33f8f62f96',
    'phpunit',
    'testbench',
    288,
    $migrationLivewire,
);
$filamentEvidence = designPartnerFixtureEvidence(
    'fixture-filament',
    'Fixture Filament',
    'https://github.com/filamentphp/filament',
    'e9348b2e3792088ee877068116b6c1e1559a7df8',
    'pest',
    'testbench',
    705,
    null,
);
$ledger = [
    'schema' => 1,
    'evaluations' => [
        designPartnerFixtureEntry($invoiceEvidence, 'InvoiceShelf', 'InvoiceShelf', $artifacts),
        designPartnerFixtureEntry($livewireEvidence, 'livewire', 'livewire', $artifacts),
        designPartnerFixtureEntry($filamentEvidence, 'filamentphp', 'filament', $artifacts),
    ],
];
$workflow = <<<'YAML'
name: Tests

jobs:
  drove:
    runs-on: ubuntu-24.04
    steps:
      - name: Drove design-partner gate
        continue-on-error: false
        shell: bash
        run: |
          vendor/bin/drove --processes=8
YAML;
$workflowUrls = [
    "https://raw.githubusercontent.com/InvoiceShelf/InvoiceShelf/{$startRevision}/.github/workflows/tests.yml",
    'https://raw.githubusercontent.com/InvoiceShelf/InvoiceShelf/403a4d67225a153838ec126c484339abf60229d1/.github/workflows/tests.yml',
    "https://raw.githubusercontent.com/livewire/livewire/{$startRevision}/.github/workflows/tests.yml",
    'https://raw.githubusercontent.com/livewire/livewire/9c1450739d30c9b0b223ad6512be2a33f8f62f96/.github/workflows/tests.yml',
];

foreach ($workflowUrls as $workflowUrl) {
    $artifacts[$workflowUrl] = $workflow;
}

$runs = [
    'invoiceshelf/invoiceshelf/101' => designPartnerFixtureRun(
        'InvoiceShelf',
        'InvoiceShelf',
        '101',
        $startRevision,
        '2026-06-01T00:00:00Z',
    ),
    'invoiceshelf/invoiceshelf/102' => designPartnerFixtureRun(
        'InvoiceShelf',
        'InvoiceShelf',
        '102',
        '403a4d67225a153838ec126c484339abf60229d1',
        '2026-06-15T00:00:00Z',
    ),
    'livewire/livewire/201' => designPartnerFixtureRun(
        'livewire',
        'livewire',
        '201',
        $startRevision,
        '2026-06-01T00:00:00Z',
    ),
    'livewire/livewire/202' => designPartnerFixtureRun(
        'livewire',
        'livewire',
        '202',
        '9c1450739d30c9b0b223ad6512be2a33f8f62f96',
        '2026-06-15T00:00:00Z',
    ),
];
$jobs = array_fill_keys(array_keys($runs), designPartnerFixtureJobs());
$comparisons = [
    "invoiceshelf/invoiceshelf/{$startRevision}/403a4d67225a153838ec126c484339abf60229d1" => designPartnerFixtureComparison(
        $startRevision,
        '403a4d67225a153838ec126c484339abf60229d1',
    ),
    "livewire/livewire/{$startRevision}/9c1450739d30c9b0b223ad6512be2a33f8f62f96" => designPartnerFixtureComparison(
        $startRevision,
        '9c1450739d30c9b0b223ad6512be2a33f8f62f96',
    ),
];
$artifactLoader = static fn (string $url): string => $artifacts[$url]
    ?? throw new RuntimeException("Missing self-test artifact: {$url}");
$runLoader = static fn (string $owner, string $repository, string $runId): array => $runs[strtolower("{$owner}/{$repository}/{$runId}")]
    ?? throw new RuntimeException("Missing self-test run: {$owner}/{$repository}/{$runId}");
$jobLoader = static fn (string $owner, string $repository, string $runId): array => $jobs[strtolower("{$owner}/{$repository}/{$runId}")]
    ?? throw new RuntimeException("Missing self-test jobs: {$owner}/{$repository}/{$runId}");
$comparisonLoader = static fn (
    string $owner,
    string $repository,
    string $base,
    string $head,
): array => $comparisons[strtolower("{$owner}/{$repository}/{$base}/{$head}")]
    ?? throw new RuntimeException("Missing self-test comparison: {$base}...{$head}");
$head = designPartnerGit(['rev-parse', 'HEAD']);

if ($head['exit'] !== 0 || preg_match('/\A[0-9a-f]{40}\z/', $head['output']) !== 1) {
    throw new RuntimeException('Self-test requires a Git checkout.');
}

$verify = static fn (
    array $candidateLedger,
    callable $candidateArtifactLoader,
    callable $candidateRunLoader,
    callable $candidateJobLoader,
    callable $candidateComparisonLoader,
): array => verifyDesignPartnerLedger(
    $candidateLedger,
    'v0.4.0-alpha.2',
    $head['output'],
    'v0.4.0-alpha.1',
    $candidateArtifactLoader,
    $candidateRunLoader,
    $candidateJobLoader,
    $candidateComparisonLoader,
    new DateTimeImmutable('2026-07-01T00:00:00Z'),
);
$result = $verify($ledger, $artifactLoader, $runLoader, $jobLoader, $comparisonLoader);

if (($result['external_evaluations'] ?? null) !== 3
    || ($result['meaningful_migrations'] ?? null) !== 2
    || ($result['verified_artifacts'] ?? null) !== 3) {
    throw new RuntimeException('Valid design-partner fixture result drifted.');
}

$checks = 1;
$expectFailure = static function (callable $test, string $message) use (&$checks): void {
    expectDesignPartnerFailure($test, $message);
    $checks++;
};
$expectInvoiceEvidenceFailure = static function (
    callable $mutate,
    string $message,
) use (
    $artifactLoader,
    $comparisonLoader,
    $invoiceEvidence,
    $jobLoader,
    $ledger,
    $runLoader,
    $verify,
    &$checks,
): void {
    $candidateLedger = $ledger;
    $candidateEvidence = $invoiceEvidence;
    $mutate($candidateEvidence);
    $url = $candidateLedger['evaluations'][0]['evidence']['url'];
    $contents = json_encode(
        $candidateEvidence,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
    $candidateLedger['evaluations'][0]['evidence']['sha256'] = hash('sha256', $contents);
    expectDesignPartnerFailure(
        static fn (): array => $verify(
            $candidateLedger,
            static fn (string $candidateUrl): string => $candidateUrl === $url ? $contents : $artifactLoader($candidateUrl),
            $runLoader,
            $jobLoader,
            $comparisonLoader,
        ),
        $message,
    );
    $checks++;
};

$expectFailure(
    fn (): array => $verify(
        ['schema' => 1, 'evaluations' => []],
        $artifactLoader,
        $runLoader,
        $jobLoader,
        $comparisonLoader,
    ),
    'requires at least 3 completed external evaluations',
);
$ssrfLedger = $ledger;
$ssrfLedger['evaluations'][0]['evidence']['url'] = 'https://127.0.0.1/evidence.json';
$expectFailure(
    fn (): array => $verify($ssrfLedger, $artifactLoader, $runLoader, $jobLoader, $comparisonLoader),
    'must be an immutable https://raw.githubusercontent.com',
);
$expectFailure(
    fn () => designPartnerRequireHttpOk(
        ['HTTP/1.1 302 Found', 'Location: https://127.0.0.1/evidence.json'],
        'https://raw.githubusercontent.com/example/project/revision/evidence.json',
    ),
    'redirects are forbidden',
);
$mismatchedEvaluationLedger = $ledger;
$mismatchedEvaluationLedger['evaluations'][0]['drove']['tag'] = 'v0.4.0-alpha.2';
$expectFailure(
    fn (): array => $verify(
        $mismatchedEvaluationLedger,
        $artifactLoader,
        $runLoader,
        $jobLoader,
        $comparisonLoader,
    ),
    'must identify the configured v0.4.0-alpha.1 evaluation tag',
);
$expectFailure(
    fn () => designPartnerVerifyEvaluationRevision($head['output'], $head['output'], 'fixture.drove revision'),
    'must be a strict ancestor of the release revision',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence) => $evidence['scanner']['bridge_only']++,
    'supported, bridge_only, or rejected',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['benchmark']['selection_sha256'] = str_repeat('0', 64),
    'sorted, unique, and match selection_sha256',
);
$expectInvoiceEvidenceFailure(
    static function (array &$evidence): void {
        $evidence['benchmark']['case_ids'][1] = $evidence['benchmark']['case_ids'][0];
    },
    'sorted, unique, and match selection_sha256',
);
$expectInvoiceEvidenceFailure(
    static function (array &$evidence): void {
        [$evidence['benchmark']['case_ids'][0], $evidence['benchmark']['case_ids'][1]] = [
            $evidence['benchmark']['case_ids'][1],
            $evidence['benchmark']['case_ids'][0],
        ];
    },
    'sorted, unique, and match selection_sha256',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence) => array_pop($evidence['benchmark']['case_ids']),
    'must bind every supported case ID',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['benchmark']['platform']['php_version'] = '8.3.19',
    'must bind a supported native target, PHP 8.4',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['benchmark']['platform']['package_revision'] = str_repeat('0', 40),
    'package version, and package revision',
);
$expectInvoiceEvidenceFailure(
    static function (array &$evidence): void {
        unset($evidence['benchmark']['runs'][0]['command_argv']);
    },
    'command_argv must be a non-empty argument list',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): array => $evidence['benchmark']['runs'][1]['command_argv'] = ['vendor/bin/pest'],
    'command_argv must execute vendor/bin/drove',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): array => $evidence['benchmark']['runs'][1]['command_argv'] = [
        'vendor/bin/drove',
        '--version',
    ],
    'command_argv must execute tests',
);
$expectInvoiceEvidenceFailure(
    static function (array &$evidence): void {
        $evidence['benchmark']['selected'] = 1;
        $evidence['scanner']['supported'] = 1;
        $evidence['scanner']['bridge_only'] += 201;
        $evidence['benchmark']['case_ids'] = [$evidence['benchmark']['case_ids'][0]];
        $evidence['benchmark']['selection_sha256'] = hash(
            'sha256',
            json_encode(
                $evidence['benchmark']['case_ids'],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
        foreach ($evidence['benchmark']['runs'] as &$run) {
            $run['selected'] = 1;
            $run['passed'] = 1;
            $run['assertions'] = 2;
        }
        unset($run);
        $evidence['benchmark']['runs'][2]['observed_lanes'] = 8;
    },
    'observed_lanes cannot exceed processes or selected cases',
);

$failedRuns = $runs;
$failedRuns['invoiceshelf/invoiceshelf/102']['conclusion'] = 'failure';
$expectFailure(
    fn (): array => $verify(
        $ledger,
        $artifactLoader,
        static fn (string $owner, string $repository, string $runId): array => $failedRuns[strtolower("{$owner}/{$repository}/{$runId}")],
        $jobLoader,
        $comparisonLoader,
    ),
    'does not match a successful GitHub Actions run',
);
$manualRuns = $runs;
$manualRuns['invoiceshelf/invoiceshelf/102']['event'] = 'workflow_dispatch';
$expectFailure(
    fn (): array => $verify(
        $ledger,
        $artifactLoader,
        static fn (string $owner, string $repository, string $runId): array => $manualRuns[strtolower("{$owner}/{$repository}/{$runId}")],
        $jobLoader,
        $comparisonLoader,
    ),
    'does not match a successful GitHub Actions run',
);
$mismatchedEventRuns = $runs;
$mismatchedEventRuns['invoiceshelf/invoiceshelf/102']['event'] = 'pull_request';
$expectFailure(
    fn (): array => $verify(
        $ledger,
        $artifactLoader,
        static fn (string $owner, string $repository, string $runId): array => $mismatchedEventRuns[strtolower("{$owner}/{$repository}/{$runId}")],
        $jobLoader,
        $comparisonLoader,
    ),
    'must use the same push or pull_request event',
);
$badComparisons = $comparisons;
$badComparisons[
    "invoiceshelf/invoiceshelf/{$startRevision}/403a4d67225a153838ec126c484339abf60229d1"
]['status'] = 'diverged';
$expectFailure(
    fn (): array => $verify(
        $ledger,
        $artifactLoader,
        $runLoader,
        $jobLoader,
        static fn (string $owner, string $repository, string $base, string $head): array => $badComparisons[strtolower("{$owner}/{$repository}/{$base}/{$head}")],
    ),
    'must prove the start revision is a strict ancestor',
);
$partialJobs = $jobs;
$partialJobs['invoiceshelf/invoiceshelf/102']['total_count'] = 2;
$expectFailure(
    fn (): array => $verify(
        $ledger,
        $artifactLoader,
        $runLoader,
        static fn (string $owner, string $repository, string $runId): array => $partialJobs[strtolower("{$owner}/{$repository}/{$runId}")],
        $comparisonLoader,
    ),
    'jobs response must be complete and unpaginated',
);
$missingStepJobs = $jobs;
$missingStepJobs['invoiceshelf/invoiceshelf/102']['jobs'][0]['steps'][0]['name'] = 'Tests';
$expectFailure(
    fn (): array => $verify(
        $ledger,
        $artifactLoader,
        $runLoader,
        static fn (string $owner, string $repository, string $runId): array => $missingStepJobs[strtolower("{$owner}/{$repository}/{$runId}")],
        $comparisonLoader,
    ),
    'must contain exactly one Drove design-partner gate step',
);
$failedStepJobs = $jobs;
$failedStepJobs['invoiceshelf/invoiceshelf/102']['jobs'][0]['steps'][0]['conclusion'] = 'failure';
$expectFailure(
    fn (): array => $verify(
        $ledger,
        $artifactLoader,
        $runLoader,
        static fn (string $owner, string $repository, string $runId): array => $failedStepJobs[strtolower("{$owner}/{$repository}/{$runId}")],
        $comparisonLoader,
    ),
    'step did not execute successfully',
);
$workflowUrl = $workflowUrls[1];
$expressionContinueWorkflow = <<<'YAML'
steps:
  - name: Drove design-partner gate
    continue-on-error: ${{ true }}
    shell: bash
    run: vendor/bin/drove
YAML;
$customShellWorkflow = <<<'YAML'
steps:
  - name: Drove design-partner gate
    shell: echo {0}
    run: vendor/bin/drove
YAML;
$maskedDefaultShellWorkflow = <<<'YAML'
defaults:
  run:
    shell: echo {0}
steps:
  - name: Drove design-partner gate
    env:
      shell: bash
    run: vendor/bin/drove
YAML;

foreach ([
    "steps:\n  # - name: Drove design-partner gate\n  #   run: vendor/bin/drove\n" => 'must define exactly one Drove design-partner gate step',
    "steps:\n  - name: Drove design-partner gate\n    run: # vendor/bin/drove\n" => 'must execute exactly one direct vendor/bin/drove test command',
    "steps:\n  - name: Drove design-partner gate\n    run: echo ok # vendor/bin/drove\n" => 'must execute exactly one direct vendor/bin/drove test command',
    "steps:\n  - name: Drove design-partner gate\n    if: false\n    run: vendor/bin/drove\n" => 'step is statically disabled',
    "steps:\n  - name: Drove design-partner gate\n    run: vendor/bin/drove || true\n" => 'must execute exactly one direct vendor/bin/drove test command',
    "steps:\n  - name: Drove design-partner gate\n    continue-on-error: true\n    run: vendor/bin/drove\n" => 'step cannot mask command failures',
    $expressionContinueWorkflow => 'step cannot mask command failures',
    $customShellWorkflow => 'must explicitly use shell: bash',
    $maskedDefaultShellWorkflow => 'must explicitly use shell: bash',
    "steps:\n  - name: Drove design-partner gate\n    run: vendor/bin/drove --version\n" => 'must execute exactly one direct vendor/bin/drove test command',
    "steps:\n  - name: Drove design-partner gate\n    run: |\n      set +e\n      vendor/bin/drove\n      true\n" => 'must execute exactly one direct vendor/bin/drove test command',
] as $badWorkflow => $message) {
    $badArtifacts = $artifacts;
    $badArtifacts[$workflowUrl] = $badWorkflow;
    $expectFailure(
        fn (): array => $verify(
            $ledger,
            static fn (string $url): string => $badArtifacts[$url],
            $runLoader,
            $jobLoader,
            $comparisonLoader,
        ),
        $message,
    );
}

echo json_encode([
    'gate' => 'design-partner-verifier-self-test',
    'status' => 'passed',
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
