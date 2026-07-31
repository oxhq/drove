#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/verify-design-partners.php';

const DROVE_FIXTURE_REVISION = '87c9ecd9b827f44432839e4c6e5fe79e6c86dbb1';

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

function designPartnerFixtureComposerLock(string $frontend, bool $withDrove): string
{
    [$runnerPackage, $runnerVersion] = match ($frontend) {
        'pest' => ['pestphp/pest', '5.0.1'],
        'phpunit' => ['phpunit/phpunit', '13.2.4'],
        default => throw new InvalidArgumentException("Unsupported fixture frontend: {$frontend}."),
    };
    $runnerRevision = hash('sha1', "{$runnerPackage}/{$runnerVersion}");
    $packages = $withDrove && $frontend === 'pest'
        ? []
        : [[
            'name' => $runnerPackage,
            'version' => $runnerVersion,
            'source' => ['reference' => $runnerRevision],
            'dist' => ['reference' => $runnerRevision],
        ]];

    if ($withDrove) {
        $packages[] = [
            'name' => 'oxhq/drove',
            'version' => '0.4.0-alpha.1',
            'source' => ['reference' => DROVE_FIXTURE_REVISION],
            'dist' => ['reference' => DROVE_FIXTURE_REVISION],
        ];
    }

    return json_encode(
        ['packages' => [], 'packages-dev' => $packages],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
}

/**
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
        'exit_code' => 0,
        'memory_source' => 'rss',
        'peak_memory_bytes' => 64_000_000,
    ];
    $evaluationRevision = hash('sha1', "{$projectRevision}/evaluation");
    [$baselinePackage, $baselineVersion] = match ($frontend) {
        'pest' => ['pestphp/pest', '5.0.1'],
        'phpunit' => ['phpunit/phpunit', '13.2.4'],
        default => throw new InvalidArgumentException("Unsupported fixture frontend: {$frontend}."),
    };

    return [
        'schema' => 2,
        'evaluation_id' => $id,
        'team' => $team,
        'repository' => $repository,
        'drove' => [
            'tag' => 'v0.4.0-alpha.1',
            'revision' => DROVE_FIXTURE_REVISION,
        ],
        'project_revision' => $projectRevision,
        'evaluation_revision' => $evaluationRevision,
        'dependency_state' => [
            'baseline_lock_sha256' => hash(
                'sha256',
                designPartnerFixtureComposerLock($frontend, false),
            ),
            'evaluation_lock_sha256' => hash(
                'sha256',
                designPartnerFixtureComposerLock($frontend, true),
            ),
            'baseline_runner' => [
                'package' => $baselinePackage,
                'version' => $baselineVersion,
                'revision' => hash('sha1', "{$baselinePackage}/{$baselineVersion}"),
            ],
        ],
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
                'package_revision' => DROVE_FIXTURE_REVISION,
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
                    'command_argv' => [
                        'vendor/bin/drove',
                        '--pest',
                        '--parallel',
                        '--processes=1',
                        '--replay=.drove/evaluation-work/drove-c1-replay.json',
                    ],
                    'processes' => 1,
                    'observed_lanes' => 1,
                    'wall_ms' => 1100,
                ],
                [
                    ...$semantics,
                    ...$telemetry,
                    'runner' => 'drove',
                    'command_argv' => [
                        'vendor/bin/drove',
                        '--pest',
                        '--parallel',
                        '--processes=8',
                        '--replay=.drove/evaluation-work/drove-c8-replay.json',
                    ],
                    'processes' => 8,
                    'observed_lanes' => min(8, $supported),
                    'wall_ms' => 400,
                ],
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $evidence
 * @param  array<string, string>  $artifacts
 * @param  null|array<string, mixed>  $migration
 * @return array<string, mixed>
 */
function designPartnerFixtureEntry(
    array $evidence,
    string $owner,
    string $repository,
    array &$artifacts,
    ?array $migration = null,
): array {
    $contents = json_encode(
        $evidence,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
    $evidenceRevision = hash('sha1', strtolower("{$owner}/{$repository}/evidence"));
    $url = sprintf(
        'https://raw.githubusercontent.com/%s/%s/%s/.drove/evaluation.json',
        $owner,
        $repository,
        $evidenceRevision,
    );
    $artifacts[$url] = $contents;
    $artifacts[sprintf(
        'https://raw.githubusercontent.com/%s/%s/%s/composer.lock',
        $owner,
        $repository,
        $evidence['project_revision'],
    )] = designPartnerFixtureComposerLock(
        $evidence['benchmark']['runs'][0]['frontend'],
        false,
    );
    $artifacts[sprintf(
        'https://raw.githubusercontent.com/%s/%s/%s/composer.lock',
        $owner,
        $repository,
        $evidence['evaluation_revision'],
    )] = designPartnerFixtureComposerLock(
        $evidence['benchmark']['runs'][0]['frontend'],
        true,
    );
    if (is_array($migration)) {
        foreach (['ci_started_revision', 'ci_verified_revision'] as $revisionField) {
            $artifacts[sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s/composer.lock',
                $owner,
                $repository,
                $migration[$revisionField],
            )] = designPartnerFixtureComposerLock(
                $evidence['benchmark']['runs'][0]['frontend'],
                true,
            );
        }
    }

    $entry = [
        'id' => $evidence['evaluation_id'],
        'team' => $evidence['team'],
        'repository' => $evidence['repository'],
        'drove' => $evidence['drove'],
        'project_revision' => $evidence['project_revision'],
        'evaluation_revision' => $evidence['evaluation_revision'],
        'evidence_revision' => $evidenceRevision,
        'evidence' => [
            'url' => $url,
            'sha256' => hash('sha256', $contents),
        ],
    ];

    if ($migration !== null) {
        $entry['migration'] = $migration;
    }

    return $entry;
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
 * @return list<array{filename: string, status: string}>
 */
function designPartnerFixtureEvaluationFiles(): array
{
    return [
        ['filename' => 'composer.json', 'status' => 'modified'],
        ['filename' => 'composer.lock', 'status' => 'modified'],
        ['filename' => '.drove/evaluation-config.json', 'status' => 'added'],
    ];
}

/**
 * @param  null|list<array{filename: string, status: string}>  $files
 * @return array<string, mixed>
 */
function designPartnerFixtureComparison(
    string $base,
    string $head,
    ?array $files = null,
): array {
    $comparison = [
        'status' => 'ahead',
        'ahead_by' => 2,
        'behind_by' => 0,
        'base_commit' => ['sha' => $base],
        'merge_base_commit' => ['sha' => $base],
        'head_commit' => ['sha' => $head],
    ];

    if ($files !== null) {
        $comparison['files'] = $files;
    }

    return $comparison;
}

$invoiceProjectRevision = '403a4d67225a153838ec126c484339abf60229d1';
$invoiceEvaluationRevision = hash('sha1', "{$invoiceProjectRevision}/evaluation");
$invoiceStartRevision = hash('sha1', "{$invoiceEvaluationRevision}/migration-start");
$invoiceVerifiedRevision = hash('sha1', "{$invoiceStartRevision}/migration-verified");
$livewireProjectRevision = '9c1450739d30c9b0b223ad6512be2a33f8f62f96';
$livewireEvaluationRevision = hash('sha1', "{$livewireProjectRevision}/evaluation");
$livewireStartRevision = $livewireEvaluationRevision;
$livewireVerifiedRevision = hash('sha1', "{$livewireStartRevision}/migration-verified");
$migrationInvoice = [
    'meaningful' => true,
    'drove_step_name' => 'Drove design-partner gate',
    'ci_started_at' => '2026-06-01T00:00:00Z',
    'ci_verified_at' => '2026-06-15T00:00:00Z',
    'ci_started_revision' => $invoiceStartRevision,
    'ci_verified_revision' => $invoiceVerifiedRevision,
    'ci_started_run_url' => 'https://github.com/InvoiceShelf/InvoiceShelf/actions/runs/101',
    'ci_verified_run_url' => 'https://github.com/InvoiceShelf/InvoiceShelf/actions/runs/102',
];
$migrationLivewire = [
    'meaningful' => true,
    'drove_step_name' => 'Drove design-partner gate',
    'ci_started_at' => '2026-06-01T00:00:00Z',
    'ci_verified_at' => '2026-06-15T00:00:00Z',
    'ci_started_revision' => $livewireStartRevision,
    'ci_verified_revision' => $livewireVerifiedRevision,
    'ci_started_run_url' => 'https://github.com/livewire/livewire/actions/runs/201',
    'ci_verified_run_url' => 'https://github.com/livewire/livewire/actions/runs/202',
];
$artifacts = [];
$invoiceEvidence = designPartnerFixtureEvidence(
    'fixture-invoiceshelf',
    'Fixture InvoiceShelf',
    'https://github.com/InvoiceShelf/InvoiceShelf',
    $invoiceProjectRevision,
    'pest',
    'laravel',
    202,
);
$livewireEvidence = designPartnerFixtureEvidence(
    'fixture-livewire',
    'Fixture Livewire',
    'https://github.com/livewire/livewire',
    $livewireProjectRevision,
    'phpunit',
    'testbench',
    288,
);
$filamentEvidence = designPartnerFixtureEvidence(
    'fixture-filament',
    'Fixture Filament',
    'https://github.com/filamentphp/filament',
    'e9348b2e3792088ee877068116b6c1e1559a7df8',
    'pest',
    'testbench',
    705,
);
$ledger = [
    'schema' => 2,
    'evaluations' => [
        designPartnerFixtureEntry(
            $invoiceEvidence,
            'InvoiceShelf',
            'InvoiceShelf',
            $artifacts,
            $migrationInvoice,
        ),
        designPartnerFixtureEntry(
            $livewireEvidence,
            'livewire',
            'livewire',
            $artifacts,
            $migrationLivewire,
        ),
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
          vendor/bin/drove --processes=8 --fail-on-empty-test-suite
YAML;
$workflowUrls = [
    "https://raw.githubusercontent.com/InvoiceShelf/InvoiceShelf/{$invoiceStartRevision}/.github/workflows/tests.yml",
    "https://raw.githubusercontent.com/InvoiceShelf/InvoiceShelf/{$invoiceVerifiedRevision}/.github/workflows/tests.yml",
    "https://raw.githubusercontent.com/livewire/livewire/{$livewireStartRevision}/.github/workflows/tests.yml",
    "https://raw.githubusercontent.com/livewire/livewire/{$livewireVerifiedRevision}/.github/workflows/tests.yml",
];

foreach ($workflowUrls as $workflowUrl) {
    $artifacts[$workflowUrl] = $workflow;
}

$runs = [
    'invoiceshelf/invoiceshelf/101' => designPartnerFixtureRun(
        'InvoiceShelf',
        'InvoiceShelf',
        '101',
        $invoiceStartRevision,
        '2026-06-01T00:00:00Z',
    ),
    'invoiceshelf/invoiceshelf/102' => designPartnerFixtureRun(
        'InvoiceShelf',
        'InvoiceShelf',
        '102',
        $invoiceVerifiedRevision,
        '2026-06-15T00:00:00Z',
    ),
    'livewire/livewire/201' => designPartnerFixtureRun(
        'livewire',
        'livewire',
        '201',
        $livewireStartRevision,
        '2026-06-01T00:00:00Z',
    ),
    'livewire/livewire/202' => designPartnerFixtureRun(
        'livewire',
        'livewire',
        '202',
        $livewireVerifiedRevision,
        '2026-06-15T00:00:00Z',
    ),
];
$jobs = array_fill_keys(array_keys($runs), designPartnerFixtureJobs());
$comparisons = [
    "invoiceshelf/invoiceshelf/{$invoiceEvaluationRevision}/{$invoiceStartRevision}" => designPartnerFixtureComparison(
        $invoiceEvaluationRevision,
        $invoiceStartRevision,
    ),
    "invoiceshelf/invoiceshelf/{$invoiceStartRevision}/{$invoiceVerifiedRevision}" => designPartnerFixtureComparison(
        $invoiceStartRevision,
        $invoiceVerifiedRevision,
    ),
    "livewire/livewire/{$livewireStartRevision}/{$livewireVerifiedRevision}" => designPartnerFixtureComparison(
        $livewireStartRevision,
        $livewireVerifiedRevision,
    ),
];

foreach ($ledger['evaluations'] as $evaluation) {
    $repository = designPartnerRepository(
        $evaluation['repository'],
        "{$evaluation['id']}.repository",
    );
    $key = strtolower(sprintf(
        '%s/%s/%s/%s',
        $repository['owner'],
        $repository['repository'],
        $evaluation['project_revision'],
        $evaluation['evaluation_revision'],
    ));
    $comparisons[$key] = designPartnerFixtureComparison(
        $evaluation['project_revision'],
        $evaluation['evaluation_revision'],
        designPartnerFixtureEvaluationFiles(),
    );
    $key = strtolower(sprintf(
        '%s/%s/%s/%s',
        $repository['owner'],
        $repository['repository'],
        $evaluation['evaluation_revision'],
        $evaluation['evidence_revision'],
    ));
    $comparisons[$key] = designPartnerFixtureComparison(
        $evaluation['evaluation_revision'],
        $evaluation['evidence_revision'],
    );
}
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
    || ($result['external_repository_owners'] ?? null) !== 3
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
    array $artifactOverrides = [],
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
            static fn (string $candidateUrl): string => $candidateUrl === $url
                ? $contents
                : ($artifactOverrides[$candidateUrl] ?? $artifactLoader($candidateUrl)),
            $runLoader,
            $jobLoader,
            $comparisonLoader,
        ),
        $message,
    );
    $checks++;
};
$verifyWithComparisons = static fn (array $candidateComparisons): array => $verify(
    $ledger,
    $artifactLoader,
    $runLoader,
    $jobLoader,
    static fn (
        string $owner,
        string $repository,
        string $base,
        string $head,
    ): array => $candidateComparisons[strtolower("{$owner}/{$repository}/{$base}/{$head}")],
);

$expectFailure(
    fn (): array => $verify(
        ['schema' => 2, 'evaluations' => []],
        $artifactLoader,
        $runLoader,
        $jobLoader,
        $comparisonLoader,
    ),
    'requires at least 3 completed external evaluations',
);
$duplicateOwnerLedger = $ledger;
$duplicateOwnerLedger['evaluations'][2]['repository'] =
    'https://github.com/InvoiceShelf/filament';
$expectFailure(
    fn (): array => $verify(
        $duplicateOwnerLedger,
        $artifactLoader,
        $runLoader,
        $jobLoader,
        $comparisonLoader,
    ),
    'duplicate external GitHub owner: InvoiceShelf',
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
$sameRevisionLedger = $ledger;
$sameRevisionLedger['evaluations'][0]['evidence_revision']
    = $sameRevisionLedger['evaluations'][0]['project_revision'];
$expectFailure(
    fn (): array => $verify(
        $sameRevisionLedger,
        $artifactLoader,
        $runLoader,
        $jobLoader,
        $comparisonLoader,
    ),
    'evidence_revision must be distinct from project_revision and evaluation_revision',
);
$sameEvaluationRevisionLedger = $ledger;
$sameEvaluationRevisionLedger['evaluations'][0]['evaluation_revision']
    = $sameEvaluationRevisionLedger['evaluations'][0]['project_revision'];
$expectFailure(
    fn (): array => $verify(
        $sameEvaluationRevisionLedger,
        $artifactLoader,
        $runLoader,
        $jobLoader,
        $comparisonLoader,
    ),
    'evaluation_revision must be distinct from project_revision',
);
$wrongEvidenceUrlLedger = $ledger;
$wrongEvidenceUrlLedger['evaluations'][0]['evidence']['url'] = str_replace(
    $wrongEvidenceUrlLedger['evaluations'][0]['evidence_revision'],
    $wrongEvidenceUrlLedger['evaluations'][0]['project_revision'],
    $wrongEvidenceUrlLedger['evaluations'][0]['evidence']['url'],
);
$expectFailure(
    fn (): array => $verify(
        $wrongEvidenceUrlLedger,
        $artifactLoader,
        $runLoader,
        $jobLoader,
        $comparisonLoader,
    ),
    'evidence.url must match the external repository and expected revision',
);
$badEvaluationComparisons = $comparisons;
$invoiceEvaluationComparisonKey = strtolower(sprintf(
    'invoiceshelf/invoiceshelf/%s/%s',
    $ledger['evaluations'][0]['project_revision'],
    $ledger['evaluations'][0]['evaluation_revision'],
));
$missingEvaluationFiles = $comparisons;
unset($missingEvaluationFiles[$invoiceEvaluationComparisonKey]['files']);
$expectFailure(
    fn (): array => $verifyWithComparisons($missingEvaluationFiles),
    'must contain a complete GitHub files list',
);
$truncatedEvaluationFiles = $comparisons;
$truncatedEvaluationFiles[$invoiceEvaluationComparisonKey]['files'] = array_fill(
    0,
    300,
    ['filename' => 'composer.json', 'status' => 'modified'],
);
$expectFailure(
    fn (): array => $verifyWithComparisons($truncatedEvaluationFiles),
    'must contain a complete GitHub files list',
);
$missingCoreFile = $comparisons;
array_pop($missingCoreFile[$invoiceEvaluationComparisonKey]['files']);
$missingCoreFile[$invoiceEvaluationComparisonKey]['files'][] = [
    'filename' => '.github/workflows/tests.yml',
    'status' => 'modified',
];
$expectFailure(
    fn (): array => $verifyWithComparisons($missingCoreFile),
    'must change composer.json, composer.lock, and .drove/evaluation-config.json',
);
$changedTestFile = $comparisons;
$changedTestFile[$invoiceEvaluationComparisonKey]['files'][] = [
    'filename' => 'tests/Feature/InvoiceTest.php',
    'status' => 'modified',
];
$expectFailure(
    fn (): array => $verifyWithComparisons($changedTestFile),
    'changes disallowed project path: tests/Feature/InvoiceTest.php',
);
$renamedEvaluationFile = $comparisons;
$renamedEvaluationFile[$invoiceEvaluationComparisonKey]['files'][0]['status'] = 'renamed';
$expectFailure(
    fn (): array => $verifyWithComparisons($renamedEvaluationFile),
    'cannot contain renamed paths',
);
$unknownEvaluationStatus = $comparisons;
$unknownEvaluationStatus[$invoiceEvaluationComparisonKey]['files'][0]['status'] = 'mystery';
$expectFailure(
    fn (): array => $verifyWithComparisons($unknownEvaluationStatus),
    'contains an unknown changed-file status',
);
$badEvaluationComparisons[$invoiceEvaluationComparisonKey]['status'] = 'diverged';
$expectFailure(
    fn (): array => $verify(
        $ledger,
        $artifactLoader,
        $runLoader,
        $jobLoader,
        static fn (
            string $owner,
            string $repository,
            string $base,
            string $head,
        ): array => $badEvaluationComparisons[strtolower("{$owner}/{$repository}/{$base}/{$head}")],
    ),
    'evaluation.revision_comparison must prove the start revision is a strict ancestor',
);
$badEvidenceComparisons = $comparisons;
$invoiceEvidenceComparisonKey = strtolower(sprintf(
    'invoiceshelf/invoiceshelf/%s/%s',
    $ledger['evaluations'][0]['evaluation_revision'],
    $ledger['evaluations'][0]['evidence_revision'],
));
$badEvidenceComparisons[$invoiceEvidenceComparisonKey]['status'] = 'diverged';
$expectFailure(
    fn (): array => $verify(
        $ledger,
        $artifactLoader,
        $runLoader,
        $jobLoader,
        static fn (
            string $owner,
            string $repository,
            string $base,
            string $head,
        ): array => $badEvidenceComparisons[strtolower("{$owner}/{$repository}/{$base}/{$head}")],
    ),
    'evidence.revision_comparison must prove the start revision is a strict ancestor',
);
$expectFailure(
    fn () => designPartnerVerifyEvaluationRevision($head['output'], $head['output'], 'fixture.drove revision'),
    'must be a strict ancestor of the release revision',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['project_revision'] = str_repeat('0', 40),
    'evidence identity does not match ledger field project_revision',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['evaluation_revision'] = str_repeat('0', 40),
    'evidence identity does not match ledger field evaluation_revision',
);
$expectInvoiceEvidenceFailure(
    static function (array &$evidence): void {
        unset($evidence['dependency_state']);
    },
    'dependency_state must contain exactly',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): bool => $evidence['dependency_state']['unexpected'] = true,
    'dependency_state must contain exactly',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['dependency_state']['baseline_lock_sha256'] = str_repeat('A', 64),
    'baseline_lock_sha256 must be a lowercase SHA-256 hash',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['dependency_state']['baseline_runner']['revision'] = str_repeat('0', 39),
    'baseline_runner must contain exactly package, version, and a lowercase 40-character revision',
);
$invoiceBaselineLockUrl = sprintf(
    'https://raw.githubusercontent.com/InvoiceShelf/InvoiceShelf/%s/composer.lock',
    $ledger['evaluations'][0]['project_revision'],
);
$invoiceEvaluationLockUrl = sprintf(
    'https://raw.githubusercontent.com/InvoiceShelf/InvoiceShelf/%s/composer.lock',
    $ledger['evaluations'][0]['evaluation_revision'],
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['dependency_state']['baseline_lock_sha256'] = str_repeat('0', 64),
    'hashes do not match the immutable Composer locks',
);
$invalidLock = '{"packages":';
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['dependency_state']['baseline_lock_sha256'] = hash('sha256', $invalidLock),
    'baseline is not valid JSON',
    [$invoiceBaselineLockUrl => $invalidLock],
);
$wrongRunnerLock = json_decode(
    $artifacts[$invoiceBaselineLockUrl],
    true,
    flags: JSON_THROW_ON_ERROR,
);
$wrongRunnerLock['packages-dev'][0]['version'] = '5.0.2';
$wrongRunnerContents = json_encode(
    $wrongRunnerLock,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['dependency_state']['baseline_lock_sha256'] = hash('sha256', $wrongRunnerContents),
    'baseline must contain the exact declared frontend runner and exclude oxhq/drove',
    [$invoiceBaselineLockUrl => $wrongRunnerContents],
);
$unexpectedPestLock = json_decode(
    $artifacts[$invoiceEvaluationLockUrl],
    true,
    flags: JSON_THROW_ON_ERROR,
);
$unexpectedPestLock['packages-dev'][] = [
    'name' => 'pestphp/pest',
    'version' => '5.0.1',
    'source' => ['reference' => hash('sha1', 'pestphp/pest/5.0.1')],
    'dist' => ['reference' => hash('sha1', 'pestphp/pest/5.0.1')],
];
$unexpectedPestContents = json_encode(
    $unexpectedPestLock,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['dependency_state']['evaluation_lock_sha256'] = hash('sha256', $unexpectedPestContents),
    'evaluation must replace pestphp/pest with oxhq/drove',
    [$invoiceEvaluationLockUrl => $unexpectedPestContents],
);
$livewireEvaluationLockUrl = sprintf(
    'https://raw.githubusercontent.com/livewire/livewire/%s/composer.lock',
    $ledger['evaluations'][1]['evaluation_revision'],
);
$wrongPhpunitLock = json_decode(
    $artifacts[$livewireEvaluationLockUrl],
    true,
    flags: JSON_THROW_ON_ERROR,
);
$wrongPhpunitLock['packages-dev'][0]['source']['reference'] = str_repeat('0', 40);
$wrongPhpunitLock['packages-dev'][0]['dist']['reference'] = str_repeat('0', 40);
$wrongPhpunitContents = json_encode(
    $wrongPhpunitLock,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
$wrongPhpunitState = $livewireEvidence['dependency_state'];
$wrongPhpunitState['evaluation_lock_sha256'] = hash('sha256', $wrongPhpunitContents);
$expectFailure(
    static function () use (
        $artifacts,
        $livewireEvaluationLockUrl,
        $livewireEvidence,
        $wrongPhpunitContents,
        $wrongPhpunitState,
    ): void {
        designPartnerVerifyDependencyLocks(
            static fn (string $url): string => $url === $livewireEvaluationLockUrl
                ? $wrongPhpunitContents
                : ($artifacts[$url] ?? throw new RuntimeException("Missing self-test artifact: {$url}")),
            $wrongPhpunitState,
            'livewire',
            'livewire',
            $livewireEvidence['project_revision'],
            $livewireEvidence['evaluation_revision'],
            $livewireEvidence['drove']['tag'],
            $livewireEvidence['drove']['revision'],
            'fixture-livewire.evidence.dependency_state',
        );
    },
    'evaluation must contain the exact declared PHPUnit runner',
);
$wrongDroveLock = json_decode(
    $artifacts[$invoiceEvaluationLockUrl],
    true,
    flags: JSON_THROW_ON_ERROR,
);
$wrongDroveLock['packages-dev'][0]['source']['reference'] = str_repeat('0', 40);
$wrongDroveLock['packages-dev'][0]['dist']['reference'] = str_repeat('0', 40);
$wrongDroveContents = json_encode(
    $wrongDroveLock,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['dependency_state']['evaluation_lock_sha256'] = hash('sha256', $wrongDroveContents),
    'evaluation must contain oxhq/drove matching the evaluated tag and revision',
    [$invoiceEvaluationLockUrl => $wrongDroveContents],
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): string => $evidence['dependency_state']['baseline_runner']['package'] = 'phpunit/phpunit',
    'baseline_runner must match the pest frontend and its supported version',
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
        $arguments = &$evidence['benchmark']['runs'][1]['command_argv'];
        $arguments = array_values(array_filter(
            $arguments,
            static fn (string $argument): bool => $argument !== '--pest',
        ));
    },
    'must contain one --pest bridge selector',
);
$expectInvoiceEvidenceFailure(
    static function (array &$evidence): void {
        $evidence['benchmark']['runs'][2]['command_argv'][3] = '--processes=4';
    },
    'must contain the exact Collector instrumentation suffix',
);
$expectInvoiceEvidenceFailure(
    static function (array &$evidence): void {
        $evidence['benchmark']['runs'][0]['command_argv'][] = '--parallel';
    },
    'baseline must be an uninstrumented C1 command',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): int => $evidence['benchmark']['runs'][2]['processes'] = 3,
    'must use the declared Drove concurrency matrix',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): int => $evidence['benchmark']['runs'][0]['exit_code'] = 1,
    'exit_code must be zero',
);
$expectInvoiceEvidenceFailure(
    static function (array &$evidence): void {
        $evidence['benchmark']['runs'][0]['passed']--;
        $evidence['benchmark']['runs'][0]['failed']++;
    },
    'must contain no failed tests',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): int => $evidence['benchmark']['selected'] = 1,
    'benchmark.selected must be an integer greater than or equal to 2',
);
$expectInvoiceEvidenceFailure(
    static fn (array &$evidence): null => $evidence['migration'] = null,
    'migration must be recorded later in the ledger',
);
$wrongMigrationLedger = $ledger;
$wrongMigrationLedger['evaluations'][0]['migration']['ci_verified_revision'] =
    $wrongMigrationLedger['evaluations'][0]['migration']['ci_started_revision'];
$expectFailure(
    fn (): array => $verify(
        $wrongMigrationLedger,
        $artifactLoader,
        $runLoader,
        $jobLoader,
        $comparisonLoader,
    ),
    'migration must bind distinct start and verification revisions',
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
    "invoiceshelf/invoiceshelf/{$invoiceStartRevision}/{$invoiceVerifiedRevision}"
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
$startBeforeEvaluationComparisons = $comparisons;
$startBeforeEvaluationComparisons[
    "invoiceshelf/invoiceshelf/{$invoiceEvaluationRevision}/{$invoiceStartRevision}"
]['status'] = 'behind';
$expectFailure(
    fn (): array => $verify(
        $ledger,
        $artifactLoader,
        $runLoader,
        $jobLoader,
        static fn (string $owner, string $repository, string $base, string $head): array => $startBeforeEvaluationComparisons[strtolower("{$owner}/{$repository}/{$base}/{$head}")],
    ),
    'must prove the start revision is a strict ancestor',
);
$invoiceStartedLockUrl = sprintf(
    'https://raw.githubusercontent.com/InvoiceShelf/InvoiceShelf/%s/composer.lock',
    $invoiceStartRevision,
);
$wrongStartedLock = json_decode(
    $artifacts[$invoiceStartedLockUrl],
    true,
    flags: JSON_THROW_ON_ERROR,
);
$wrongStartedLock['packages-dev'][0]['source']['reference'] = str_repeat('0', 40);
$wrongStartedLock['packages-dev'][0]['dist']['reference'] = str_repeat('0', 40);
$wrongStartedLockContents = json_encode(
    $wrongStartedLock,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
$expectFailure(
    fn (): array => $verify(
        $ledger,
        static fn (string $url): string => $url === $invoiceStartedLockUrl
            ? $wrongStartedLockContents
            : $artifactLoader($url),
        $runLoader,
        $jobLoader,
        $comparisonLoader,
    ),
    'migration.started_dependency_state must contain oxhq/drove matching the evaluated tag and revision',
);
$invoiceVerifiedLockUrl = sprintf(
    'https://raw.githubusercontent.com/InvoiceShelf/InvoiceShelf/%s/composer.lock',
    $invoiceVerifiedRevision,
);
$wrongVerifiedLock = json_decode(
    $artifacts[$invoiceVerifiedLockUrl],
    true,
    flags: JSON_THROW_ON_ERROR,
);
$wrongVerifiedLock['packages-dev'][0]['source']['reference'] = str_repeat('0', 40);
$wrongVerifiedLock['packages-dev'][0]['dist']['reference'] = str_repeat('0', 40);
$wrongVerifiedLockContents = json_encode(
    $wrongVerifiedLock,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
$expectFailure(
    fn (): array => $verify(
        $ledger,
        static fn (string $url): string => $url === $invoiceVerifiedLockUrl
            ? $wrongVerifiedLockContents
            : $artifactLoader($url),
        $runLoader,
        $jobLoader,
        $comparisonLoader,
    ),
    'migration.verified_dependency_state must contain oxhq/drove matching the evaluated tag and revision',
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
    run: vendor/bin/drove --fail-on-empty-test-suite
YAML;
$maskedDefaultShellWorkflow = <<<'YAML'
defaults:
  run:
    shell: echo {0}
steps:
  - name: Drove design-partner gate
    env:
      shell: bash
    run: vendor/bin/drove --fail-on-empty-test-suite
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
    "steps:\n  - name: Drove design-partner gate\n    run: vendor/bin/drove --fail-on-empty-test-suite \$DROVE_ARGS\n" => 'must execute exactly one direct vendor/bin/drove test command',
    "steps:\n  - name: Drove design-partner gate\n    run: vendor/bin/drove --fail-on-empty-test-suite --do-not-fail-on-empty-test-suite\n" => 'must execute exactly one direct vendor/bin/drove test command',
    "steps:\n  - name: Drove design-partner gate\n    run: vendor/bin/drove --processes=8\n" => 'must execute exactly one direct vendor/bin/drove test command',
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
