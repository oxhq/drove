#!/usr/bin/env php
<?php

declare(strict_types=1);

const DROVE_MINIMUM_EVALUATIONS = 3;
const DROVE_MINIMUM_MIGRATIONS = 2;
const DROVE_MINIMUM_CI_SECONDS = 14 * 24 * 60 * 60;
const DROVE_MAXIMUM_EVIDENCE_BYTES = 1_048_576;
const DROVE_MAXIMUM_GITHUB_BYTES = 8_388_608;

final class DesignPartnerGateFailed extends RuntimeException {}

function designPartnerFail(string $message, int $exitCode = 1): never
{
    throw new DesignPartnerGateFailed($message, $exitCode);
}

/**
 * @param  list<string>  $arguments
 * @return array{exit: int, output: string}
 */
function designPartnerGit(array $arguments): array
{
    $process = proc_open(
        ['git', ...$arguments],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    if (! is_resource($process)) {
        designPartnerFail('could not start Git while validating release ancestry.');
    }

    fclose($pipes[0]);
    $output = trim(stream_get_contents($pipes[1]));
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit' => proc_close($process),
        'output' => $output,
    ];
}

/**
 * @param  list<string>  $headers
 */
function designPartnerRequireHttpOk(array $headers, string $url): void
{
    $status = null;

    foreach ($headers as $header) {
        if (preg_match('/\AHTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }

    if ($status !== 200) {
        designPartnerFail("{$url} returned HTTP status ".($status ?? 'unknown').'; redirects are forbidden.');
    }
}

/**
 * @param  list<string>  $headers
 */
function designPartnerDownload(
    string $url,
    array $headers = [],
    int $maximumBytes = DROVE_MAXIMUM_GITHUB_BYTES,
): string {
    $context = stream_context_create([
        'http' => [
            'follow_location' => 0,
            'ignore_errors' => true,
            'max_redirects' => 0,
            'timeout' => 30,
            'user_agent' => 'Drove design-partner release gate',
            'header' => implode("\r\n", $headers),
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $stream = @fopen($url, 'rb', false, $context);

    if ($stream === false) {
        designPartnerFail("{$url} could not be downloaded.");
    }

    $metadata = stream_get_meta_data($stream);
    $responseHeaders = $metadata['wrapper_data'] ?? [];

    if (! is_array($responseHeaders)) {
        fclose($stream);
        designPartnerFail("{$url} did not return valid HTTP metadata.");
    }

    designPartnerRequireHttpOk(array_values($responseHeaders), $url);
    $contents = stream_get_contents($stream, $maximumBytes + 1);
    fclose($stream);

    if ($contents === false || $contents === '') {
        designPartnerFail("{$url} returned an empty artifact.");
    }

    if (strlen($contents) > $maximumBytes) {
        designPartnerFail("{$url} exceeds its download size limit.");
    }

    return $contents;
}

/**
 * @return array<string, mixed>
 */
function designPartnerFetchGitHubJson(string $url, string $label): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $token = getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN');

    if (is_string($token) && $token !== '') {
        $headers[] = "Authorization: Bearer {$token}";
    }

    try {
        $value = json_decode(
            designPartnerDownload($url, $headers, DROVE_MAXIMUM_GITHUB_BYTES),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } catch (JsonException $exception) {
        designPartnerFail("{$label} is not valid JSON: {$exception->getMessage()}");
    }

    if (! is_array($value)) {
        designPartnerFail("{$label} must be a JSON object.");
    }

    return $value;
}

/**
 * @return array<string, mixed>
 */
function designPartnerFetchGitHubRun(string $owner, string $repository, string $runId): array
{
    $url = "https://api.github.com/repos/{$owner}/{$repository}/actions/runs/{$runId}";

    return designPartnerFetchGitHubJson($url, "GitHub Actions run {$runId}");
}

/**
 * @return array<string, mixed>
 */
function designPartnerFetchGitHubJobs(string $owner, string $repository, string $runId): array
{
    $url = "https://api.github.com/repos/{$owner}/{$repository}/actions/runs/{$runId}/jobs"
        .'?filter=all&per_page=100&page=1';

    return designPartnerFetchGitHubJson($url, "GitHub Actions jobs for run {$runId}");
}

/**
 * @return array<string, mixed>
 */
function designPartnerFetchGitHubComparison(
    string $owner,
    string $repository,
    string $base,
    string $head,
): array {
    $url = "https://api.github.com/repos/{$owner}/{$repository}/compare/{$base}...{$head}"
        .'?per_page=1&page=1';

    return designPartnerFetchGitHubJson($url, "GitHub comparison {$base}...{$head}");
}

/**
 * @return array{owner: string, repository: string}
 */
function designPartnerRepository(string $url, string $field): array
{
    if (preg_match(
        '#\Ahttps://github\.com/([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9._-]+)\z#',
        $url,
        $matches,
    ) !== 1) {
        designPartnerFail("{$field} must be a canonical https://github.com/<owner>/<repository> URL.");
    }

    if (strtolower($matches[1]) === 'oxhq') {
        designPartnerFail("{$field} must belong to a non-OxHQ repository.");
    }

    return [
        'owner' => $matches[1],
        'repository' => $matches[2],
    ];
}

/**
 * @return array{owner: string, repository: string, revision: string}
 */
function designPartnerEvidenceUrl(
    string $url,
    string $expectedOwner,
    string $expectedRepository,
    string $expectedRevision,
    string $field,
): array {
    if (preg_match(
        '#\Ahttps://raw\.githubusercontent\.com/([^/]+)/([^/]+)/([0-9a-f]{40})/([^?\#]+)\z#',
        $url,
        $matches,
    ) !== 1) {
        designPartnerFail(
            "{$field} must be an immutable https://raw.githubusercontent.com/<owner>/<repository>/<40-sha>/<path> URL.",
        );
    }

    $pathSegments = explode('/', rawurldecode($matches[4]));

    if (strcasecmp($matches[1], $expectedOwner) !== 0
        || strcasecmp($matches[2], $expectedRepository) !== 0
        || $matches[3] !== $expectedRevision
        || in_array('.', $pathSegments, true)
        || in_array('..', $pathSegments, true)
        || str_contains($matches[4], '\\')) {
        designPartnerFail("{$field} must match the external repository and expected revision.");
    }

    return [
        'owner' => $matches[1],
        'repository' => $matches[2],
        'revision' => $matches[3],
    ];
}

/**
 * @return array{owner: string, repository: string, run_id: string}
 */
function designPartnerCiUrl(
    mixed $url,
    string $expectedOwner,
    string $expectedRepository,
    string $field,
): array {
    if (! is_string($url)
        || preg_match(
            '#\Ahttps://github\.com/([^/]+)/([^/]+)/actions/runs/([1-9]\d*)\z#',
            $url,
            $matches,
        ) !== 1
        || strcasecmp($matches[1], $expectedOwner) !== 0
        || strcasecmp($matches[2], $expectedRepository) !== 0) {
        designPartnerFail(
            "{$field} must be a GitHub Actions run URL for the evaluated repository.",
        );
    }

    return [
        'owner' => $matches[1],
        'repository' => $matches[2],
        'run_id' => $matches[3],
    ];
}

function designPartnerTimestamp(mixed $value, string $field): DateTimeImmutable
{
    if (! is_string($value)) {
        designPartnerFail("{$field} must be an RFC 3339 UTC timestamp.");
    }

    $timestamp = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s\Z',
        $value,
        new DateTimeZone('UTC'),
    );
    $errors = DateTimeImmutable::getLastErrors();

    if ($timestamp === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        designPartnerFail("{$field} must be an RFC 3339 UTC timestamp.");
    }

    return $timestamp;
}

/**
 * @param  array<string, mixed>  $record
 */
function designPartnerInteger(
    array $record,
    string $field,
    string $label,
    int $minimum = 0,
): int {
    $value = $record[$field] ?? null;

    if (! is_int($value) || $value < $minimum) {
        designPartnerFail("{$label}.{$field} must be an integer greater than or equal to {$minimum}.");
    }

    return $value;
}

/**
 * @param  array<string, mixed>  $run
 * @return array<string, int>
 */
function designPartnerRunSemantics(array $run, string $label, int $selected): array
{
    $runSelected = designPartnerInteger($run, 'selected', $label, 1);
    $semantics = [];

    foreach (['passed', 'failed', 'skipped', 'incomplete', 'risky', 'rejected'] as $outcome) {
        $semantics[$outcome] = designPartnerInteger($run, $outcome, $label);
    }

    $semantics['assertions'] = designPartnerInteger($run, 'assertions', $label);

    if ($runSelected !== $selected
        || array_sum(array_diff_key($semantics, ['assertions' => true])) !== $selected
        || $semantics['rejected'] !== 0) {
        designPartnerFail("{$label} must account for the supported selection exactly once.");
    }

    return $semantics;
}

/**
 * @param  array<string, mixed>  $expected
 * @param  array<string, mixed>  $actual
 */
function designPartnerEvidenceIdentity(
    array $expected,
    array $actual,
    string $evaluationId,
): void {
    $fields = [
        'evaluation_id' => 'id',
        'team' => 'team',
        'repository' => 'repository',
        'drove' => 'drove',
        'project_revision' => 'project_revision',
        'evaluation_revision' => 'evaluation_revision',
    ];

    foreach ($fields as $evidenceField => $ledgerField) {
        if (($actual[$evidenceField] ?? null) !== ($expected[$ledgerField] ?? null)) {
            designPartnerFail(
                "{$evaluationId}.evidence identity does not match ledger field {$ledgerField}.",
            );
        }
    }
}

/**
 * @param  array<string, mixed>  $run
 * @return array{path: string, event: string}
 */
function designPartnerVerifyCiRun(
    array $run,
    string $url,
    string $owner,
    string $repository,
    string $revision,
    string $timestamp,
    string $label,
): array {
    if (($run['html_url'] ?? null) !== $url
        || strcasecmp((string) ($run['repository']['full_name'] ?? ''), "{$owner}/{$repository}") !== 0
        || ($run['status'] ?? null) !== 'completed'
        || ($run['conclusion'] ?? null) !== 'success'
        || ($run['head_sha'] ?? null) !== $revision
        || ($run['created_at'] ?? null) !== $timestamp
        || ! in_array($run['event'] ?? null, ['push', 'pull_request'], true)
        || ! is_string($run['path'] ?? null)
        || $run['path'] === '') {
        designPartnerFail("{$label} does not match a successful GitHub Actions run.");
    }

    return [
        'path' => $run['path'],
        'event' => $run['event'],
    ];
}

/**
 * @param  array<string, mixed>  $response
 */
function designPartnerVerifyDroveJob(array $response, string $stepName, string $label): void
{
    $jobs = $response['jobs'] ?? null;
    $total = $response['total_count'] ?? null;

    if (! is_int($total)
        || ! is_array($jobs)
        || ! array_is_list($jobs)
        || $total !== count($jobs)) {
        designPartnerFail("{$label} jobs response must be complete and unpaginated.");
    }

    $matches = [];

    foreach ($jobs as $job) {
        if (! is_array($job) || ! is_array($job['steps'] ?? null)) {
            designPartnerFail("{$label} jobs response contains an invalid job.");
        }

        foreach ($job['steps'] as $step) {
            if (is_array($step) && ($step['name'] ?? null) === $stepName) {
                $matches[] = [$job, $step];
            }
        }
    }

    if (count($matches) !== 1) {
        designPartnerFail("{$label} must contain exactly one {$stepName} step.");
    }

    [$job, $step] = $matches[0];

    if (($job['status'] ?? null) !== 'completed'
        || ($job['conclusion'] ?? null) !== 'success'
        || ($step['status'] ?? null) !== 'completed'
        || ($step['conclusion'] ?? null) !== 'success') {
        designPartnerFail("{$label} {$stepName} step did not execute successfully.");
    }
}

function designPartnerIsDroveCommand(string $line): bool
{
    if (preg_match('/[$#;&|`\\\\<>*?\[\]{}~]/', $line) === 1
        || preg_match(
            '/(?:\A|\s)(?:-h|-V|--help|--version|--compatibility|--list-(?:groups|suites|test-files|tests|tests-json|tests-xml))(?:=|\s|\z)/',
            $line,
        ) === 1) {
        return false;
    }

    if (preg_match(
        '#\A\s*(?:(?:php)\s+)?(?:\./)?vendor/bin/drove(?:\s|$)#',
        $line,
    ) !== 1) {
        return false;
    }

    $arguments = preg_split('/\s+/', trim($line));

    return is_array($arguments)
        && ! in_array('--do-not-fail-on-empty-test-suite', $arguments, true)
        && count(array_filter(
            $arguments,
            static fn (string $argument): bool => $argument === '--fail-on-empty-test-suite',
        )) === 1;
}

/**
 * @param  callable(string): string  $loader
 */
function designPartnerVerifyWorkflow(
    callable $loader,
    string $owner,
    string $repository,
    string $revision,
    string $path,
    string $stepName,
    string $label,
): void {
    if (preg_match('#\A\.github/workflows/[A-Za-z0-9._/-]+\.ya?ml\z#', $path) !== 1
        || in_array('..', explode('/', $path), true)) {
        designPartnerFail("{$label} has an invalid workflow path.");
    }

    $url = "https://raw.githubusercontent.com/{$owner}/{$repository}/{$revision}/{$path}";
    designPartnerEvidenceUrl(
        $url,
        $owner,
        $repository,
        $revision,
        "{$label}.workflow",
    );
    $workflow = $loader($url);

    if ($workflow === '' || strlen($workflow) > DROVE_MAXIMUM_EVIDENCE_BYTES) {
        designPartnerFail("{$label} workflow is empty or too large.");
    }

    $lines = preg_split('/\R/', $workflow);

    if (! is_array($lines)) {
        designPartnerFail("{$label} workflow could not be parsed.");
    }

    $matchingSteps = 0;

    foreach ($lines as $index => $line) {
        if (preg_match('/\A(\s*)-\s+name:\s*(.+?)\s*\z/', $line, $matches) !== 1) {
            continue;
        }

        $name = trim($matches[2]);

        if ((str_starts_with($name, '"') && str_ends_with($name, '"'))
            || (str_starts_with($name, "'") && str_ends_with($name, "'"))) {
            $name = substr($name, 1, -1);
        }

        if ($name !== $stepName) {
            continue;
        }

        $matchingSteps++;
        $indent = strlen($matches[1]);
        $commands = [];
        $shells = [];
        $propertyIndent = null;

        for ($cursor = $index + 1; $cursor < count($lines); $cursor++) {
            $candidate = $lines[$cursor];
            if (trim($candidate) === '') {
                continue;
            }
            if (preg_match('/\A\s*#/', $candidate) === 1) {
                continue;
            }

            preg_match('/\A(\s*)/', $candidate, $indentMatch);
            $candidateIndent = strlen($indentMatch[1] ?? '');

            if ($candidateIndent <= $indent) {
                break;
            }

            $propertyIndent ??= $candidateIndent;

            if ($candidateIndent !== $propertyIndent) {
                continue;
            }

            if (preg_match('/\A\s*if:\s*(?:false|\$\{\{\s*false\s*\}\})\s*\z/i', $candidate) === 1) {
                designPartnerFail("{$label} {$stepName} step is statically disabled.");
            }

            if (preg_match('/\A\s*continue-on-error:\s*(.*?)\s*\z/i', $candidate, $continueMatch) === 1
                && preg_match('/\A(?:false|\$\{\{\s*false\s*\}\})\z/i', trim($continueMatch[1])) !== 1) {
                designPartnerFail(
                    "{$label} {$stepName} step cannot mask command failures with continue-on-error.",
                );
            }

            if (preg_match('/\A\s*shell:\s*(.*?)\s*\z/', $candidate, $shellMatch) === 1) {
                $shells[] = trim($shellMatch[1]);
            }

            if (preg_match('/\A\s*run:\s*(.*?)\s*\z/', $candidate, $runMatch) === 1) {
                $command = trim($runMatch[1]);

                if ($command !== '' && ! in_array($command, ['|', '|-', '|+', '>', '>-', '>+'], true)) {
                    $commands[] = $command;

                    continue;
                }

                $blockCommands = [];

                for ($commandIndex = $cursor + 1; $commandIndex < count($lines); $commandIndex++) {
                    $commandLine = $lines[$commandIndex];
                    preg_match('/\A(\s*)/', $commandLine, $commandIndentMatch);

                    if (trim($commandLine) !== ''
                        && strlen($commandIndentMatch[1] ?? '') <= $candidateIndent) {
                        break;
                    }

                    if (trim($commandLine) !== '' && preg_match('/\A\s*#/', $commandLine) !== 1) {
                        $blockCommands[] = trim($commandLine);
                    }
                }

                $commands[] = count($blockCommands) === 1 ? $blockCommands[0] : '';
            }
        }

        if (count($commands) !== 1 || ! designPartnerIsDroveCommand($commands[0])) {
            designPartnerFail(
                "{$label} {$stepName} step must execute exactly one direct vendor/bin/drove test command.",
            );
        }

        if ($shells !== ['bash']) {
            designPartnerFail("{$label} {$stepName} step must explicitly use shell: bash.");
        }
    }

    if ($matchingSteps !== 1) {
        designPartnerFail("{$label} workflow must define exactly one {$stepName} step.");
    }
}

/**
 * @param  array<string, mixed>  $comparison
 */
function designPartnerVerifyComparison(
    array $comparison,
    string $base,
    string $head,
    string $label,
): void {
    if ($base === $head
        || ($comparison['status'] ?? null) !== 'ahead'
        || ($comparison['base_commit']['sha'] ?? null) !== $base
        || ($comparison['merge_base_commit']['sha'] ?? null) !== $base
        || ($comparison['head_commit']['sha'] ?? null) !== $head
        || ! is_int($comparison['ahead_by'] ?? null)
        || $comparison['ahead_by'] < 1
        || ($comparison['behind_by'] ?? null) !== 0) {
        designPartnerFail("{$label} must prove the start revision is a strict ancestor.");
    }
}

/**
 * @param  array<string, mixed>  $comparison
 */
function designPartnerVerifyEvaluationDelta(array $comparison, string $label): void
{
    $files = $comparison['files'] ?? null;

    if (! is_array($files)
        || ! array_is_list($files)
        || count($files) < 3
        || count($files) >= 300) {
        designPartnerFail("{$label} must contain a complete GitHub files list with fewer than 300 entries.");
    }

    $required = array_fill_keys(
        ['composer.json', 'composer.lock', '.drove/evaluation-config.json'],
        false,
    );
    $seen = [];

    foreach ($files as $file) {
        $path = is_array($file) ? ($file['filename'] ?? null) : null;
        $status = is_array($file) ? ($file['status'] ?? null) : null;

        if (! is_string($path) || $path === '' || isset($seen[$path])) {
            designPartnerFail("{$label} must contain unique changed paths.");
        }
        $seen[$path] = true;

        if ($status === 'renamed') {
            designPartnerFail("{$label} cannot contain renamed paths.");
        }

        if (! in_array($status, ['added', 'modified', 'removed'], true)) {
            designPartnerFail("{$label} contains an unknown changed-file status.");
        }

        if (array_key_exists($path, $required)) {
            if ($status === 'removed') {
                designPartnerFail("{$label} cannot remove a required evaluation file.");
            }
            $required[$path] = true;

            continue;
        }

        if (preg_match('#\A\.github/workflows/[^/]+\.ya?ml\z#', $path) !== 1) {
            designPartnerFail("{$label} changes disallowed project path: {$path}.");
        }
    }

    if (in_array(false, $required, true)) {
        designPartnerFail(
            "{$label} must change composer.json, composer.lock, and .drove/evaluation-config.json.",
        );
    }
}

function designPartnerVerifyEvaluationRevision(
    string $evaluatedRevision,
    string $releaseRevision,
    string $label,
): void {
    if ($evaluatedRevision === $releaseRevision) {
        designPartnerFail("{$label} must be a strict ancestor of the release revision.");
    }

    $ancestor = designPartnerGit(['merge-base', '--is-ancestor', $evaluatedRevision, $releaseRevision]);

    if ($ancestor['exit'] !== 0) {
        designPartnerFail("{$label} must be a strict ancestor of the release revision.");
    }
}

/**
 * @param  array<string, mixed>  $benchmark
 */
function designPartnerVerifyPlatform(
    array $benchmark,
    string $tag,
    string $revision,
    string $label,
): void {
    $platform = $benchmark['platform'] ?? null;
    $targets = [
        'linux-gnu-x86_64' => ['Linux', 'x86_64'],
        'linux-gnu-aarch64' => ['Linux', 'aarch64'],
        'macos-x86_64' => ['Darwin', 'x86_64'],
        'macos-aarch64' => ['Darwin', 'arm64'],
    ];

    if (! is_array($platform)
        || ! isset($targets[$platform['native_target'] ?? null])
        || [$platform['os_family'] ?? null, $platform['architecture'] ?? null]
            !== $targets[$platform['native_target']]
        || ! is_string($platform['php_version'] ?? null)
        || preg_match('/\A8\.4\.\d+\z/', $platform['php_version']) !== 1
        || ($platform['package_version'] ?? null) !== substr($tag, 1)
        || ($platform['package_revision'] ?? null) !== $revision) {
        designPartnerFail(
            "{$label}.platform must bind a supported native target, PHP 8.4, package version, and package revision.",
        );
    }
}

/**
 * @param  array<string, mixed>  $evidence
 * @return array{
 *     baseline_lock_sha256: string,
 *     evaluation_lock_sha256: string,
 *     baseline_runner: array{package: string, version: string, revision: string}
 * }
 */
function designPartnerVerifyDependencyState(
    array $evidence,
    string $frontend,
    string $label,
): array {
    $state = $evidence['dependency_state'] ?? null;

    if (! is_array($state)
        || count($state) !== 3
        || ! array_key_exists('baseline_lock_sha256', $state)
        || ! array_key_exists('evaluation_lock_sha256', $state)
        || ! array_key_exists('baseline_runner', $state)) {
        designPartnerFail(
            "{$label}.dependency_state must contain exactly baseline_lock_sha256, evaluation_lock_sha256, and baseline_runner.",
        );
    }

    foreach (['baseline_lock_sha256', 'evaluation_lock_sha256'] as $field) {
        if (! is_string($state[$field])
            || preg_match('/\A[0-9a-f]{64}\z/', $state[$field]) !== 1) {
            designPartnerFail("{$label}.dependency_state.{$field} must be a lowercase SHA-256 hash.");
        }
    }

    $runner = $state['baseline_runner'];

    if (! is_array($runner)
        || count($runner) !== 3
        || ! array_key_exists('package', $runner)
        || ! array_key_exists('version', $runner)
        || ! array_key_exists('revision', $runner)
        || ! is_string($runner['revision'])
        || preg_match('/\A[0-9a-f]{40}\z/', $runner['revision']) !== 1) {
        designPartnerFail(
            "{$label}.dependency_state.baseline_runner must contain exactly package, version, and a lowercase 40-character revision.",
        );
    }

    $expected = match ($frontend) {
        'pest' => ['pestphp/pest', '5.0.1'],
        'phpunit' => ['phpunit/phpunit', '13.2.4'],
        default => designPartnerFail("{$label} has an unsupported frontend."),
    };

    if ([$runner['package'] ?? null, $runner['version'] ?? null] !== $expected) {
        designPartnerFail(
            "{$label}.dependency_state.baseline_runner must match the {$frontend} frontend and its supported version.",
        );
    }

    return [
        'baseline_lock_sha256' => $state['baseline_lock_sha256'],
        'evaluation_lock_sha256' => $state['evaluation_lock_sha256'],
        'baseline_runner' => [
            'package' => $runner['package'],
            'version' => $runner['version'],
            'revision' => $runner['revision'],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function designPartnerComposerLock(string $contents, string $label): array
{
    try {
        $lock = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        designPartnerFail("{$label} is not valid JSON: {$exception->getMessage()}");
    }

    if (! is_array($lock)
        || array_is_list($lock)
        || ! is_array($lock['packages'] ?? null)
        || ! array_is_list($lock['packages'])
        || ! is_array($lock['packages-dev'] ?? null)
        || ! array_is_list($lock['packages-dev'])) {
        designPartnerFail("{$label} must be a Composer lock object with package lists.");
    }

    return $lock;
}

/**
 * @param  array<string, mixed>  $lock
 * @return null|array<string, mixed>
 */
function designPartnerComposerPackage(array $lock, string $name, string $label): ?array
{
    $match = null;

    foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
        if (! is_array($package)
            || ! is_string($package['name'] ?? null)
            || strcasecmp($package['name'], $name) !== 0) {
            continue;
        }

        if ($match !== null) {
            designPartnerFail("{$label} contains duplicate {$name} packages.");
        }
        $match = $package;
    }

    return $match;
}

/**
 * @param  array<string, mixed>  $package
 */
function designPartnerComposerVersion(array $package): ?string
{
    $version = $package['pretty_version'] ?? $package['version'] ?? null;

    if (! is_string($version)) {
        return null;
    }

    return str_starts_with($version, 'v') ? substr($version, 1) : $version;
}

/**
 * @param  array<string, mixed>  $package
 */
function designPartnerComposerRevision(array $package, string $label): string
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
    $references = array_values(array_unique($references));

    if (count($references) !== 1
        || preg_match('/\A[0-9a-f]{40}\z/', $references[0]) !== 1) {
        designPartnerFail("{$label} must bind one exact lowercase 40-character package revision.");
    }

    return $references[0];
}

/**
 * @param  array<string, mixed>  $lock
 */
function designPartnerVerifyDrovePackage(
    array $lock,
    string $tag,
    string $revision,
    string $label,
): void {
    $drove = designPartnerComposerPackage($lock, 'oxhq/drove', $label);

    if ($drove === null
        || designPartnerComposerVersion($drove) !== ltrim($tag, 'v')
        || designPartnerComposerRevision($drove, $label) !== $revision) {
        designPartnerFail(
            "{$label} must contain oxhq/drove matching the evaluated tag and revision.",
        );
    }
}

/**
 * @param  callable(string): string  $artifactLoader
 * @param  array{
 *     baseline_lock_sha256: string,
 *     evaluation_lock_sha256: string,
 *     baseline_runner: array{package: string, version: string, revision: string}
 * }  $state
 */
function designPartnerVerifyDependencyLocks(
    callable $artifactLoader,
    array $state,
    string $owner,
    string $repository,
    string $projectRevision,
    string $evaluationRevision,
    string $evaluatedTag,
    string $evaluatedRevision,
    string $label,
): void {
    $baselineUrl = "https://raw.githubusercontent.com/{$owner}/{$repository}/{$projectRevision}/composer.lock";
    $evaluationUrl = "https://raw.githubusercontent.com/{$owner}/{$repository}/{$evaluationRevision}/composer.lock";
    $baselineContents = $artifactLoader($baselineUrl);
    $evaluationContents = $artifactLoader($evaluationUrl);

    if (! hash_equals($state['baseline_lock_sha256'], hash('sha256', $baselineContents))
        || ! hash_equals($state['evaluation_lock_sha256'], hash('sha256', $evaluationContents))) {
        designPartnerFail("{$label} hashes do not match the immutable Composer locks.");
    }

    $baselineLock = designPartnerComposerLock($baselineContents, "{$label}.baseline");
    $evaluationLock = designPartnerComposerLock($evaluationContents, "{$label}.evaluation");
    $runnerState = $state['baseline_runner'];
    $baselineRunner = designPartnerComposerPackage(
        $baselineLock,
        $runnerState['package'],
        "{$label}.baseline",
    );

    if ($baselineRunner === null
        || designPartnerComposerVersion($baselineRunner) !== $runnerState['version']
        || designPartnerComposerRevision($baselineRunner, "{$label}.baseline_runner")
            !== $runnerState['revision']
        || designPartnerComposerPackage($baselineLock, 'oxhq/drove', "{$label}.baseline") !== null) {
        designPartnerFail(
            "{$label}.baseline must contain the exact declared frontend runner and exclude oxhq/drove.",
        );
    }

    $evaluationRunner = designPartnerComposerPackage(
        $evaluationLock,
        $runnerState['package'],
        "{$label}.evaluation",
    );

    if ($runnerState['package'] === 'phpunit/phpunit') {
        if ($evaluationRunner === null
            || designPartnerComposerVersion($evaluationRunner) !== $runnerState['version']
            || designPartnerComposerRevision($evaluationRunner, "{$label}.evaluation_runner")
                !== $runnerState['revision']) {
            designPartnerFail(
                "{$label}.evaluation must contain the exact declared PHPUnit runner.",
            );
        }
    } elseif ($evaluationRunner !== null) {
        designPartnerFail(
            "{$label}.evaluation must replace pestphp/pest with oxhq/drove.",
        );
    }

    designPartnerVerifyDrovePackage(
        $evaluationLock,
        $evaluatedTag,
        $evaluatedRevision,
        "{$label}.evaluation",
    );
}

/**
 * @param  array<string, mixed>  $run
 */
function designPartnerVerifyCommand(
    array $run,
    string $runner,
    int $processes,
    string $label,
): void {
    $arguments = $run['command_argv'] ?? null;

    if (! is_array($arguments) || ! array_is_list($arguments) || $arguments === []) {
        designPartnerFail("{$label}.command_argv must be a non-empty argument list.");
    }

    foreach ($arguments as $argument) {
        if (! is_string($argument)
            || $argument === ''
            || preg_match('/[\x00-\x1f\x7f]/', $argument) === 1) {
            designPartnerFail("{$label}.command_argv must contain non-empty strings without controls.");
        }
    }

    $expected = "vendor/bin/{$runner}";
    $executableIndex = $arguments[0] === 'php' ? 1 : 0;
    $executable = $arguments[$executableIndex] ?? null;

    if ($executable !== $expected && $executable !== "./{$expected}") {
        designPartnerFail("{$label}.command_argv must execute {$expected}.");
    }

    $runnerArguments = array_slice($arguments, $executableIndex + 1);

    foreach ($runnerArguments as $argument) {
        if (preg_match(
            '/\A(?:-h|-V|--help|--version|--compatibility|--list-(?:groups|suites|test-files|tests|tests-json|tests-xml))(?:=|\z)/',
            $argument,
        ) === 1) {
            designPartnerFail("{$label}.command_argv must execute tests, not an informational or listing mode.");
        }
    }

    $instrumentation = static fn (string $argument): bool => $argument === '--'
        || preg_match(
            '/\A--(?:parallel|processes|replay|replay-on-failure|log-junit|list-tests-xml|no-logging)(?:=|\z)/',
            $argument,
        ) === 1;

    if ($runner !== 'drove') {
        if ($processes !== 1 || array_any($runnerArguments, $instrumentation)) {
            designPartnerFail(
                "{$label}.command_argv baseline must be an uninstrumented C1 command.",
            );
        }

        return;
    }

    if (! in_array($processes, [1, 2, 4, 8, 16, 30], true)) {
        designPartnerFail("{$label}.processes must use the declared Drove concurrency matrix.");
    }

    $suffix = [
        '--parallel',
        "--processes={$processes}",
        "--replay=.drove/evaluation-work/drove-c{$processes}-replay.json",
    ];

    if (array_slice($arguments, -count($suffix)) !== $suffix) {
        designPartnerFail(
            "{$label}.command_argv must contain the exact Collector instrumentation suffix.",
        );
    }

    $baseArguments = array_slice(
        $arguments,
        $executableIndex + 1,
        count($arguments) - $executableIndex - count($suffix) - 1,
    );

    if (count(array_filter(
        $baseArguments,
        static fn (string $argument): bool => $argument === '--pest',
    )) !== 1
        || array_any($baseArguments, $instrumentation)) {
        designPartnerFail(
            "{$label}.command_argv must contain one --pest bridge selector before Collector instrumentation.",
        );
    }
}

/**
 * @param  array<string, mixed>  $ledger
 * @param  callable(string): string  $artifactLoader
 * @param  callable(string, string, string): array<string, mixed>  $runLoader
 * @param  callable(string, string, string): array<string, mixed>  $jobLoader
 * @param  callable(string, string, string, string): array<string, mixed>  $comparisonLoader
 * @return array<string, int|string>
 */
function verifyDesignPartnerLedger(
    array $ledger,
    string $releaseTag,
    string $releaseRevision,
    string $evaluationTag,
    callable $artifactLoader,
    callable $runLoader,
    callable $jobLoader,
    callable $comparisonLoader,
    ?DateTimeImmutable $now = null,
): array {
    if (preg_match('/\Av0\.\d+\.\d+-alpha\.\d+\z/', $releaseTag) !== 1) {
        designPartnerFail('release tag must match v0.x.y-alpha.n.', 2);
    }

    if (preg_match('/\A[0-9a-f]{40}\z/', $releaseRevision) !== 1) {
        designPartnerFail('release revision must be a lowercase 40-character Git SHA.', 2);
    }

    if (preg_match('/\Av0\.\d+\.\d+-alpha\.\d+\z/', $evaluationTag) !== 1
        || version_compare(substr($releaseTag, 1), substr($evaluationTag, 1), '<=')) {
        designPartnerFail('evaluation tag must identify an earlier experimental release.', 2);
    }

    $head = designPartnerGit(['rev-parse', 'HEAD']);

    if ($head['exit'] !== 0 || $head['output'] !== $releaseRevision) {
        designPartnerFail('release revision must match the current checkout.', 2);
    }

    if (($ledger['schema'] ?? null) !== 2
        || ! is_array($ledger['evaluations'] ?? null)
        || ! array_is_list($ledger['evaluations'])) {
        designPartnerFail('ledger must use schema 2 with an evaluations list.');
    }

    $evaluations = $ledger['evaluations'];

    if (count($evaluations) < DROVE_MINIMUM_EVALUATIONS) {
        designPartnerFail(sprintf(
            'requires at least %d completed external evaluations; found %d.',
            DROVE_MINIMUM_EVALUATIONS,
            count($evaluations),
        ));
    }

    $ids = [];
    $teams = [];
    $repositories = [];
    $repositoryOwners = [];
    $artifactOwners = [];
    $verifiedArtifacts = [];
    $migrations = 0;
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

    foreach ($evaluations as $index => $evaluation) {
        if (! is_array($evaluation)) {
            designPartnerFail("evaluations[{$index}] must be an object.");
        }

        $id = $evaluation['id'] ?? null;
        $team = $evaluation['team'] ?? null;
        $repositoryUrl = $evaluation['repository'] ?? null;
        $drove = $evaluation['drove'] ?? null;
        $projectRevision = $evaluation['project_revision'] ?? null;
        $evaluationRevision = $evaluation['evaluation_revision'] ?? null;
        $evidenceRevision = $evaluation['evidence_revision'] ?? null;

        if (! is_string($id) || preg_match('/\A[a-z0-9][a-z0-9-]{2,63}\z/', $id) !== 1) {
            designPartnerFail("evaluations[{$index}].id must be a stable lowercase slug.");
        }

        if (isset($ids[$id])) {
            designPartnerFail("duplicate evaluation id: {$id}.");
        }
        $ids[$id] = true;

        if (! is_string($team) || trim($team) === '') {
            designPartnerFail("{$id}.team must identify the external team.");
        }
        $teamKey = strtolower(trim($team));

        if (isset($teams[$teamKey])) {
            designPartnerFail("duplicate external team: {$team}.");
        }
        $teams[$teamKey] = true;

        if (! is_string($repositoryUrl)) {
            designPartnerFail("{$id}.repository must be a string.");
        }
        $repository = designPartnerRepository($repositoryUrl, "{$id}.repository");
        $repositoryKey = strtolower("{$repository['owner']}/{$repository['repository']}");
        $repositoryOwner = strtolower($repository['owner']);

        if (isset($repositories[$repositoryKey])) {
            designPartnerFail("duplicate external repository: {$repositoryUrl}.");
        }
        $repositories[$repositoryKey] = true;

        if (isset($repositoryOwners[$repositoryOwner])) {
            designPartnerFail(
                "duplicate external GitHub owner: {$repository['owner']}.",
            );
        }
        $repositoryOwners[$repositoryOwner] = true;

        $evaluatedTag = is_array($drove) ? ($drove['tag'] ?? null) : null;
        $evaluatedRevision = is_array($drove) ? ($drove['revision'] ?? null) : null;

        if (! is_string($evaluatedTag)
            || preg_match('/\Av0\.\d+\.\d+-alpha\.\d+\z/', $evaluatedTag) !== 1
            || $evaluatedTag !== $evaluationTag
            || ! is_string($evaluatedRevision)
            || preg_match('/\A[0-9a-f]{40}\z/', $evaluatedRevision) !== 1) {
            designPartnerFail(
                "{$id}.drove must identify the configured {$evaluationTag} evaluation tag and revision.",
            );
        }

        $tagReference = "refs/tags/{$evaluatedTag}";
        $tagType = designPartnerGit(['cat-file', '-t', $tagReference]);
        $tagRevision = designPartnerGit(['rev-parse', "{$tagReference}^{commit}"]);

        if ($tagType['exit'] !== 0
            || $tagType['output'] !== 'tag'
            || $tagRevision['exit'] !== 0
            || $tagRevision['output'] !== $evaluatedRevision) {
            designPartnerFail("{$id}.drove does not match an annotated repository tag.");
        }

        designPartnerVerifyEvaluationRevision(
            $evaluatedRevision,
            $releaseRevision,
            "{$id}.drove revision",
        );

        if (! is_string($projectRevision)
            || preg_match('/\A[0-9a-f]{40}\z/', $projectRevision) !== 1) {
            designPartnerFail("{$id}.project_revision must be a lowercase 40-character Git SHA.");
        }

        if (! is_string($evaluationRevision)
            || preg_match('/\A[0-9a-f]{40}\z/', $evaluationRevision) !== 1
            || $evaluationRevision === $projectRevision) {
            designPartnerFail(
                "{$id}.evaluation_revision must be distinct from project_revision and use a lowercase 40-character Git SHA.",
            );
        }

        if (! is_string($evidenceRevision)
            || preg_match('/\A[0-9a-f]{40}\z/', $evidenceRevision) !== 1
            || $evidenceRevision === $projectRevision
            || $evidenceRevision === $evaluationRevision) {
            designPartnerFail(
                "{$id}.evidence_revision must be distinct from project_revision and evaluation_revision and use a lowercase 40-character Git SHA.",
            );
        }

        $evaluationComparison = $comparisonLoader(
            $repository['owner'],
            $repository['repository'],
            $projectRevision,
            $evaluationRevision,
        );
        designPartnerVerifyComparison(
            $evaluationComparison,
            $projectRevision,
            $evaluationRevision,
            "{$id}.evaluation.revision_comparison",
        );
        designPartnerVerifyEvaluationDelta(
            $evaluationComparison,
            "{$id}.evaluation.changed_files",
        );
        designPartnerVerifyComparison(
            $comparisonLoader(
                $repository['owner'],
                $repository['repository'],
                $evaluationRevision,
                $evidenceRevision,
            ),
            $evaluationRevision,
            $evidenceRevision,
            "{$id}.evidence.revision_comparison",
        );

        $evidenceDescriptor = $evaluation['evidence'] ?? null;
        $evidenceUrl = is_array($evidenceDescriptor) ? ($evidenceDescriptor['url'] ?? null) : null;
        $evidenceHash = is_array($evidenceDescriptor) ? ($evidenceDescriptor['sha256'] ?? null) : null;

        if (! is_string($evidenceUrl)) {
            designPartnerFail("{$id}.evidence.url must be a string.");
        }

        designPartnerEvidenceUrl(
            $evidenceUrl,
            $repository['owner'],
            $repository['repository'],
            $evidenceRevision,
            "{$id}.evidence.url",
        );

        if (! is_string($evidenceHash)
            || preg_match('/\A[0-9a-f]{64}\z/', $evidenceHash) !== 1) {
            designPartnerFail("{$id}.evidence.sha256 must be a lowercase SHA-256 hash.");
        }

        $artifactKey = "{$evidenceUrl}\0{$evidenceHash}";
        $artifactOwner = $artifactOwners[$artifactKey] ?? null;

        if ($artifactOwner !== null && $artifactOwner !== $id) {
            designPartnerFail("{$id}.evidence reuses evidence owned by {$artifactOwner}.");
        }
        $artifactOwners[$artifactKey] = $id;

        $evidenceContents = $verifiedArtifacts[$artifactKey] ?? null;

        if (! is_string($evidenceContents)) {
            $evidenceContents = $artifactLoader($evidenceUrl);

            if ($evidenceContents === ''
                || strlen($evidenceContents) > DROVE_MAXIMUM_EVIDENCE_BYTES
                || ! hash_equals($evidenceHash, hash('sha256', $evidenceContents))) {
                designPartnerFail("{$id}.evidence does not match its SHA-256 or size contract.");
            }
            $verifiedArtifacts[$artifactKey] = $evidenceContents;
        }

        try {
            $evidence = json_decode(
                $evidenceContents,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            designPartnerFail("{$id}.evidence is not valid JSON: {$exception->getMessage()}");
        }

        if (! is_array($evidence) || ($evidence['schema'] ?? null) !== 2) {
            designPartnerFail("{$id}.evidence must be a schema 2 JSON object.");
        }

        if (array_key_exists('migration', $evidence)) {
            designPartnerFail(
                "{$id}.evidence migration must be recorded later in the ledger, not in the measured artifact.",
            );
        }

        designPartnerEvidenceIdentity($evaluation, $evidence, $id);
        $scanner = $evidence['scanner'] ?? null;

        if (! is_array($scanner) || ($scanner['completed'] ?? null) !== true) {
            designPartnerFail("{$id}.evidence.scanner.completed must be true.");
        }

        $discovered = designPartnerInteger($scanner, 'discovered', "{$id}.evidence.scanner", 1);
        $supported = designPartnerInteger($scanner, 'supported', "{$id}.evidence.scanner", 2);
        $bridgeOnly = designPartnerInteger($scanner, 'bridge_only', "{$id}.evidence.scanner");
        $rejected = designPartnerInteger($scanner, 'rejected', "{$id}.evidence.scanner");

        if ($supported + $bridgeOnly + $rejected !== $discovered) {
            designPartnerFail(
                "{$id}.evidence.scanner must classify every discovered case as supported, bridge_only, or rejected.",
            );
        }

        $benchmark = $evidence['benchmark'] ?? null;
        $runs = is_array($benchmark) ? ($benchmark['runs'] ?? null) : null;

        if (! is_array($benchmark)
            || ($benchmark['completed'] ?? null) !== true
            || ! is_array($runs)
            || ! array_is_list($runs)
            || count($runs) < 3) {
            designPartnerFail("{$id}.evidence.benchmark must contain at least three completed runs.");
        }

        $selected = designPartnerInteger($benchmark, 'selected', "{$id}.evidence.benchmark", 2);
        $selectionHash = $benchmark['selection_sha256'] ?? null;
        $caseIds = $benchmark['case_ids'] ?? null;

        if ($selected !== $supported
            || ! is_array($caseIds)
            || ! array_is_list($caseIds)
            || count($caseIds) !== $selected
            || ! is_string($selectionHash)
            || preg_match('/\A[0-9a-f]{64}\z/', $selectionHash) !== 1) {
            designPartnerFail(
                "{$id}.evidence.benchmark must bind every supported case ID and its canonical SHA-256.",
            );
        }

        foreach ($caseIds as $caseId) {
            if (! is_string($caseId)
                || $caseId === ''
                || preg_match('/[\x00-\x1f\x7f]/', $caseId) === 1) {
                designPartnerFail(
                    "{$id}.evidence.benchmark.case_ids must contain non-empty strings without controls.",
                );
            }
        }

        $sortedCaseIds = $caseIds;
        sort($sortedCaseIds, SORT_STRING);

        if ($caseIds !== $sortedCaseIds
            || count(array_unique($caseIds, SORT_STRING)) !== count($caseIds)
            || ! hash_equals(
                $selectionHash,
                hash(
                    'sha256',
                    json_encode(
                        $caseIds,
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    ),
                ),
            )) {
            designPartnerFail(
                "{$id}.evidence.benchmark.case_ids must be sorted, unique, and match selection_sha256.",
            );
        }

        designPartnerVerifyPlatform(
            $benchmark,
            $evaluatedTag,
            $evaluatedRevision,
            "{$id}.evidence.benchmark",
        );

        $runKeys = [];
        $baselineC1 = false;
        $droveC1 = false;
        $droveParallel = false;
        $expectedSemantics = null;
        $expectedFrontend = null;
        $expectedRuntime = null;

        foreach ($runs as $runIndex => $run) {
            if (! is_array($run)) {
                designPartnerFail("{$id}.evidence.benchmark.runs[{$runIndex}] must be an object.");
            }

            $label = "{$id}.evidence.benchmark.runs[{$runIndex}]";
            $runner = $run['runner'] ?? null;
            $frontend = $run['frontend'] ?? null;
            $runtime = $run['runtime'] ?? null;
            $memorySource = $run['memory_source'] ?? null;
            $processes = designPartnerInteger($run, 'processes', $label, 1);
            $exitCode = designPartnerInteger($run, 'exit_code', $label);

            if (! in_array($runner, ['pest', 'phpunit', 'drove'], true)
                || ! in_array($frontend, ['pest', 'phpunit'], true)
                || ! in_array($runtime, ['php', 'laravel', 'testbench'], true)
                || ! in_array($memorySource, ['rss', 'pss', 'cgroup'], true)
                || $processes > 30) {
                designPartnerFail("{$label} has an unsupported runner, frontend, runtime, memory source, or process count.");
            }

            if ($exitCode !== 0) {
                designPartnerFail("{$label}.exit_code must be zero.");
            }

            designPartnerVerifyCommand($run, $runner, $processes, $label);

            if ($expectedFrontend === null) {
                $expectedFrontend = $frontend;
                $expectedRuntime = $runtime;
            } elseif ($frontend !== $expectedFrontend || $runtime !== $expectedRuntime) {
                designPartnerFail("{$label} is not comparable to the other frontend/runtime runs.");
            }

            $runKey = "{$runner}:{$processes}";

            if (isset($runKeys[$runKey])) {
                designPartnerFail("{$label} duplicates benchmark run {$runKey}.");
            }
            $runKeys[$runKey] = true;

            designPartnerInteger($run, 'wall_ms', $label, 1);
            designPartnerInteger($run, 'peak_memory_bytes', $label, 1);
            $semantics = designPartnerRunSemantics($run, $label, $selected);

            if ($semantics['failed'] !== 0) {
                designPartnerFail("{$label} must contain no failed tests.");
            }

            if ($expectedSemantics === null) {
                $expectedSemantics = $semantics;
            } elseif ($semantics !== $expectedSemantics) {
                designPartnerFail("{$label} outcomes or assertions diverge from the supported baseline.");
            }

            if ($runner !== 'drove') {
                if ($runner !== $frontend) {
                    designPartnerFail("{$label} baseline runner must match its frontend.");
                }
                $baselineC1 = $baselineC1 || $processes === 1;

                continue;
            }

            $observedLanes = designPartnerInteger($run, 'observed_lanes', $label, 1);

            if ($observedLanes > min($processes, $selected)) {
                designPartnerFail("{$label}.observed_lanes cannot exceed processes or selected cases.");
            }

            if ($processes === 1) {
                $droveC1 = $observedLanes === 1;
            } elseif ($observedLanes >= 2) {
                $droveParallel = true;
            }
        }

        if (! $baselineC1 || ! $droveC1 || ! $droveParallel) {
            designPartnerFail(
                "{$id}.evidence.benchmark requires baseline C1 plus Drove C1 and an observed parallel Drove run.",
            );
        }

        $dependencyState = designPartnerVerifyDependencyState(
            $evidence,
            $expectedFrontend,
            "{$id}.evidence",
        );
        designPartnerVerifyDependencyLocks(
            $artifactLoader,
            $dependencyState,
            $repository['owner'],
            $repository['repository'],
            $projectRevision,
            $evaluationRevision,
            $evaluatedTag,
            $evaluatedRevision,
            "{$id}.evidence.dependency_state",
        );

        $migration = $evaluation['migration'] ?? null;

        if ($migration === null) {
            continue;
        }

        if (! is_array($migration) || ($migration['meaningful'] ?? null) !== true) {
            designPartnerFail("{$id}.migration.meaningful must be true when migration evidence is present.");
        }

        $startedAtValue = $migration['ci_started_at'] ?? null;
        $verifiedAtValue = $migration['ci_verified_at'] ?? null;
        $startedRevision = $migration['ci_started_revision'] ?? null;
        $verifiedRevision = $migration['ci_verified_revision'] ?? null;
        $droveStepName = $migration['drove_step_name'] ?? null;
        $startedAt = designPartnerTimestamp($startedAtValue, "{$id}.migration.ci_started_at");
        $verifiedAt = designPartnerTimestamp($verifiedAtValue, "{$id}.migration.ci_verified_at");

        if (! is_string($startedRevision)
            || preg_match('/\A[0-9a-f]{40}\z/', $startedRevision) !== 1
            || ! is_string($verifiedRevision)
            || preg_match('/\A[0-9a-f]{40}\z/', $verifiedRevision) !== 1
            || $verifiedRevision === $startedRevision) {
            designPartnerFail("{$id}.migration must bind distinct start and verification revisions.");
        }

        if (! is_string($droveStepName)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._\/:-]{2,79}\z/', $droveStepName) !== 1) {
            designPartnerFail("{$id}.migration.drove_step_name must be a stable explicit step name.");
        }

        if ($verifiedAt->getTimestamp() - $startedAt->getTimestamp() < DROVE_MINIMUM_CI_SECONDS) {
            designPartnerFail("{$id}.migration must remain in CI for at least 14 complete days.");
        }

        if ($verifiedAt > $now) {
            designPartnerFail("{$id}.migration.ci_verified_at cannot be in the future.");
        }

        $startedUrl = designPartnerCiUrl(
            $migration['ci_started_run_url'] ?? null,
            $repository['owner'],
            $repository['repository'],
            "{$id}.migration.ci_started_run_url",
        );
        $verifiedUrl = designPartnerCiUrl(
            $migration['ci_verified_run_url'] ?? null,
            $repository['owner'],
            $repository['repository'],
            "{$id}.migration.ci_verified_run_url",
        );

        if ($startedUrl['run_id'] === $verifiedUrl['run_id']) {
            designPartnerFail("{$id}.migration must identify distinct start and verification CI runs.");
        }

        $startedRunUrl = $migration['ci_started_run_url'];
        $verifiedRunUrl = $migration['ci_verified_run_url'];

        if (! is_string($startedRunUrl)
            || ! is_string($verifiedRunUrl)
            || ! is_string($startedAtValue)
            || ! is_string($verifiedAtValue)) {
            designPartnerFail("{$id}.migration CI evidence must use string values.");
        }

        $startedRun = designPartnerVerifyCiRun(
            $runLoader($repository['owner'], $repository['repository'], $startedUrl['run_id']),
            $startedRunUrl,
            $repository['owner'],
            $repository['repository'],
            $startedRevision,
            $startedAtValue,
            "{$id}.migration.ci_started_run",
        );
        $verifiedRun = designPartnerVerifyCiRun(
            $runLoader($repository['owner'], $repository['repository'], $verifiedUrl['run_id']),
            $verifiedRunUrl,
            $repository['owner'],
            $repository['repository'],
            $verifiedRevision,
            $verifiedAtValue,
            "{$id}.migration.ci_verified_run",
        );

        if ($startedRun['path'] !== $verifiedRun['path']) {
            designPartnerFail("{$id}.migration CI runs must execute the same workflow path.");
        }

        if ($startedRun['event'] !== $verifiedRun['event']) {
            designPartnerFail("{$id}.migration CI runs must use the same push or pull_request event.");
        }

        if ($startedRevision !== $evaluationRevision) {
            designPartnerVerifyComparison(
                $comparisonLoader(
                    $repository['owner'],
                    $repository['repository'],
                    $evaluationRevision,
                    $startedRevision,
                ),
                $evaluationRevision,
                $startedRevision,
                "{$id}.migration.evaluation_revision_comparison",
            );
        }
        designPartnerVerifyComparison(
            $comparisonLoader(
                $repository['owner'],
                $repository['repository'],
                $startedRevision,
                $verifiedRevision,
            ),
            $startedRevision,
            $verifiedRevision,
            "{$id}.migration.revision_comparison",
        );
        $startedLockUrl = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/composer.lock',
            $repository['owner'],
            $repository['repository'],
            $startedRevision,
        );
        designPartnerVerifyDrovePackage(
            designPartnerComposerLock(
                $artifactLoader($startedLockUrl),
                "{$id}.migration.started_dependency_state",
            ),
            $evaluatedTag,
            $evaluatedRevision,
            "{$id}.migration.started_dependency_state",
        );
        $verifiedLockUrl = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/composer.lock',
            $repository['owner'],
            $repository['repository'],
            $verifiedRevision,
        );
        designPartnerVerifyDrovePackage(
            designPartnerComposerLock(
                $artifactLoader($verifiedLockUrl),
                "{$id}.migration.verified_dependency_state",
            ),
            $evaluatedTag,
            $evaluatedRevision,
            "{$id}.migration.verified_dependency_state",
        );
        designPartnerVerifyDroveJob(
            $jobLoader($repository['owner'], $repository['repository'], $startedUrl['run_id']),
            $droveStepName,
            "{$id}.migration.ci_started_run",
        );
        designPartnerVerifyDroveJob(
            $jobLoader($repository['owner'], $repository['repository'], $verifiedUrl['run_id']),
            $droveStepName,
            "{$id}.migration.ci_verified_run",
        );

        designPartnerVerifyWorkflow(
            $artifactLoader,
            $repository['owner'],
            $repository['repository'],
            $startedRevision,
            $startedRun['path'],
            $droveStepName,
            "{$id}.migration.ci_started_run",
        );
        designPartnerVerifyWorkflow(
            $artifactLoader,
            $repository['owner'],
            $repository['repository'],
            $verifiedRevision,
            $verifiedRun['path'],
            $droveStepName,
            "{$id}.migration.ci_verified_run",
        );

        $migrations++;
    }

    if ($migrations < DROVE_MINIMUM_MIGRATIONS) {
        designPartnerFail(sprintf(
            'requires at least %d meaningful migrations retained in CI for 14 days; found %d.',
            DROVE_MINIMUM_MIGRATIONS,
            $migrations,
        ));
    }

    return [
        'gate' => 'design-partner-evidence',
        'status' => 'passed',
        'release_tag' => $releaseTag,
        'release_revision' => $releaseRevision,
        'external_evaluations' => count($evaluations),
        'external_repository_owners' => count($repositoryOwners),
        'meaningful_migrations' => $migrations,
        'verified_artifacts' => count($verifiedArtifacts),
    ];
}

/**
 * @param  list<string>  $arguments
 */
function designPartnerMain(array $arguments): int
{
    if (count($arguments) !== 4) {
        designPartnerFail(
            'usage: verify-design-partners.php <ledger.json> <release-tag> <release-revision>.',
            2,
        );
    }

    $ledgerPath = $arguments[1];
    $releaseTag = $arguments[2];
    $releaseRevision = $arguments[3];
    $contents = @file_get_contents($ledgerPath);

    if ($contents === false) {
        designPartnerFail('ledger could not be read.');
    }

    try {
        $ledger = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        designPartnerFail("ledger is not valid JSON: {$exception->getMessage()}");
    }

    if (! is_array($ledger)) {
        designPartnerFail('ledger must be a JSON object.');
    }

    $releaseContents = @file_get_contents(dirname(__DIR__).'/resources/release.json');

    try {
        $release = is_string($releaseContents)
            ? json_decode($releaseContents, true, flags: JSON_THROW_ON_ERROR)
            : null;
    } catch (JsonException) {
        $release = null;
    }

    $candidateVersion = is_array($release) ? ($release['candidate_version'] ?? null) : null;
    $evaluationVersion = is_array($release) ? ($release['evaluation_version'] ?? null) : null;

    if (($release['schema'] ?? null) !== 1
        || ! is_string($candidateVersion)
        || ! is_string($evaluationVersion)
        || $releaseTag !== 'v'.$candidateVersion) {
        designPartnerFail('release tag does not match resources/release.json.', 2);
    }

    $result = verifyDesignPartnerLedger(
        $ledger,
        $releaseTag,
        $releaseRevision,
        'v'.$evaluationVersion,
        designPartnerDownload(...),
        designPartnerFetchGitHubRun(...),
        designPartnerFetchGitHubJobs(...),
        designPartnerFetchGitHubComparison(...),
    );
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

    return 0;
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;

if (is_string($scriptFilename) && realpath($scriptFilename) === __FILE__) {
    $arguments = $_SERVER['argv'] ?? [];

    try {
        exit(designPartnerMain(is_array($arguments) ? array_values($arguments) : []));
    } catch (DesignPartnerGateFailed $exception) {
        fwrite(STDERR, "Design-partner gate failed: {$exception->getMessage()}\n");

        exit($exception->getCode() > 0 ? $exception->getCode() : 1);
    }
}
