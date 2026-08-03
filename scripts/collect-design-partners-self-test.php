#!/usr/bin/env php
<?php

declare(strict_types=1);

use Drove\Evaluation\Collector;

require_once dirname(__DIR__).'/src/Drove/Compatibility/Status.php';
require_once dirname(__DIR__).'/src/Drove/Compatibility/Registry.php';
require_once dirname(__DIR__).'/src/Drove/Migration/CodemodOptions.php';
require_once dirname(__DIR__).'/src/Drove/Migration/Finding.php';
require_once dirname(__DIR__).'/src/Drove/Native/Surface/SupportedSurface.php';
require_once dirname(__DIR__).'/src/Drove/Migration/Scanner.php';
require_once dirname(__DIR__).'/src/Drove/Kernel/NativeLibrary.php';
require_once dirname(__DIR__).'/src/Drove/Evaluation/Collector.php';

$directory = sys_get_temp_dir().'/drove-evidence-'.bin2hex(random_bytes(8));
$root = $directory.'/evaluation';
$fakeBin = $directory.'/fake-bin';
$runnerRevision = str_repeat('b', 40);
$package = [
    'tag' => 'v0.4.0-alpha.3',
    'revision' => str_repeat('a', 40),
];
$originalPath = getenv('PATH');
$checks = 0;

try {
    mkdir($root, 0700, true);
    mkdir($root.'/tests', 0700, true);
    mkdir($fakeBin, 0700, true);
    evidenceFakeComposer($fakeBin);
    putenv('PATH='.$fakeBin.PATH_SEPARATOR.(is_string($originalPath) ? $originalPath : ''));
    file_put_contents(
        $root.'/.gitignore',
        "/vendor/\n/.drove/evaluation-work/\n",
    );
    evidenceJson($root.'/composer.json', [
        'require-dev' => ['phpunit/phpunit' => '13.2.4'],
    ]);
    evidenceJson(
        $root.'/composer.lock',
        evidenceLock([
            evidencePackage('phpunit/phpunit', '13.2.4', $runnerRevision),
        ]),
    );
    file_put_contents(
        $root.'/tests/ExampleTest.php',
        <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests;

final class ExampleTest
{
    public function testOne(): void {}

    public function testTwo(): void {}
}
PHP,
    );
    evidenceGit($root, ['init', '--initial-branch=main']);
    evidenceGit(
        $root,
        ['remote', 'add', 'origin', 'https://github.com/example-partner/evaluation-fixture.git'],
    );
    evidenceGit($root, ['add', '.']);
    evidenceCommit($root, 'baseline');
    $projectRevision = evidenceGit($root, ['rev-parse', 'HEAD']);
    $baselineLockHash = hash_file('sha256', $root.'/composer.lock');

    mkdir($root.'/.drove', 0700, true);
    mkdir($root.'/.github/workflows', 0700, true);
    evidenceJson($root.'/composer.json', [
        'require-dev' => [
            'oxhq/drove' => '0.4.0-alpha.3',
            'phpunit/phpunit' => '13.2.4',
        ],
    ]);
    evidenceJson(
        $root.'/composer.lock',
        evidenceLock([
            evidencePackage('oxhq/drove', '0.4.0-alpha.3', $package['revision']),
            evidencePackage('phpunit/phpunit', '13.2.4', $runnerRevision),
        ]),
    );
    evidenceGit($root, ['add', 'composer.json', 'composer.lock']);
    evidenceCommit($root, 'dependencies');
    $incompleteRevision = evidenceGit($root, ['rev-parse', 'HEAD']);
    $configuration = [
        'schema' => 2,
        'evaluation_id' => 'fixture-evaluation',
        'team' => 'Fixture Partner',
        'repository' => 'https://github.com/example-partner/evaluation-fixture',
        'frontend' => 'phpunit',
        'runtime' => 'php',
        'parallel_processes' => 2,
        'baseline_revision' => $projectRevision,
        'baseline_command_argv' => ['vendor/bin/phpunit'],
        'drove_command_argv' => ['vendor/bin/drove', '--pest'],
    ];
    evidenceJson($root.'/.drove/evaluation-config.json', $configuration);
    file_put_contents(
        $root.'/.github/workflows/drove.yaml',
        "name: Drove\non: push\njobs: {}\n",
    );
    evidenceGit(
        $root,
        ['add', '.drove/evaluation-config.json', '.github/workflows/drove.yaml'],
    );
    evidenceCommit($root, 'evaluation');
    $evaluationRevision = evidenceGit($root, ['rev-parse', 'HEAD']);
    evidenceEvaluationVendor($root, $package, $runnerRevision);
    $worktrees = evidenceGit($root, ['worktree', 'list', '--porcelain']);

    $collector = new Collector($package);
    $evidence = $collector->collect($root, $configuration);

    if (($evidence['schema'] ?? null) !== 2
        || ($evidence['project_revision'] ?? null) !== $projectRevision
        || ($evidence['evaluation_revision'] ?? null) !== $evaluationRevision
        || ($evidence['dependency_state'] ?? null) !== [
            'baseline_lock_sha256' => $baselineLockHash,
            'evaluation_lock_sha256' => hash_file('sha256', $root.'/composer.lock'),
            'baseline_runner' => [
                'package' => 'phpunit/phpunit',
                'version' => '13.2.4',
                'revision' => $runnerRevision,
            ],
        ]
        || ($evidence['scanner'] ?? null) !== [
            'completed' => true,
            'discovered' => 2,
            'supported' => 2,
            'bridge_only' => 0,
            'rejected' => 0,
        ]
        || ($evidence['benchmark']['selected'] ?? null) !== 2
        || ($evidence['benchmark']['case_ids'] ?? null) !== [
            'Tests\\ExampleTest::testOne',
            'Tests\\ExampleTest::testTwo',
        ]
        || array_column($evidence['benchmark']['runs'] ?? [], 'exit_code') !== [0, 0, 0]
        || ($evidence['benchmark']['runs'][2]['observed_lanes'] ?? null) !== 2
        || is_file($root.'/.drove/current-vendor-used')
        || evidenceGit($root, ['worktree', 'list', '--porcelain']) !== $worktrees
        || evidenceGit($root, ['status', '--porcelain']) !== '') {
        throw new RuntimeException('Collector fixture output drifted.');
    }
    $checks++;

    evidenceFails(
        static fn () => $collector->collect(
            $root,
            [...$configuration, 'migration' => null],
        ),
        'configuration identity is invalid',
        'Collector accepted migration evidence in the reproducible evaluation config.',
    );
    $checks++;

    $incompleteConfiguration = [
        ...$configuration,
        'baseline_revision' => $incompleteRevision,
    ];
    evidenceFails(
        static fn () => $collector->collect($root, $incompleteConfiguration),
        'must change required project path: composer.json',
        'Collector accepted an incomplete evaluation delta.',
    );
    $checks++;

    file_put_contents($fakeBin.'/fail', "1\n");
    evidenceFails(
        static fn () => $collector->collect($root, $configuration),
        'Composer could not install',
        'Collector accepted a failed baseline Composer install.',
    );
    unlink($fakeBin.'/fail');

    if (evidenceGit($root, ['worktree', 'list', '--porcelain']) !== $worktrees) {
        throw new RuntimeException('Collector leaked a failed baseline worktree.');
    }
    $checks++;

    file_put_contents($fakeBin.'/evaluation-fail', "1\n");
    evidenceFails(
        static fn () => $collector->collect($root, $configuration),
        'Composer could not install the committed evaluation lock',
        'Collector accepted a failed evaluation Composer install.',
    );
    unlink($fakeBin.'/evaluation-fail');

    if (evidenceGit($root, ['worktree', 'list', '--porcelain']) !== $worktrees) {
        throw new RuntimeException('Collector leaked a failed evaluation worktree.');
    }
    $checks++;

    file_put_contents($fakeBin.'/installed-revision', str_repeat('c', 40)."\n");
    evidenceFails(
        static fn () => $collector->collect($root, $configuration),
        'match the exact locked revision',
        'Collector accepted a different installed baseline runner revision.',
    );
    unlink($fakeBin.'/installed-revision');
    $checks++;

    file_put_contents(
        $fakeBin.'/evaluation-runner-revision',
        str_repeat('c', 40)."\n",
    );
    evidenceFails(
        static fn () => $collector->collect($root, $configuration),
        'exact baseline PHPUnit runner',
        'Collector accepted a different evaluation runner revision.',
    );
    unlink($fakeBin.'/evaluation-runner-revision');
    $checks++;

    file_put_contents(
        $fakeBin.'/evaluation-drove-revision',
        str_repeat('c', 40)."\n",
    );
    evidenceFails(
        static fn () => $collector->collect($root, $configuration),
        'oxhq/drove matching',
        'Collector accepted a different installed Drove revision.',
    );
    unlink($fakeBin.'/evaluation-drove-revision');
    $checks++;

    $pestBaselineRoot = $directory.'/pest-baseline-state';
    $pestEvaluationRoot = $directory.'/pest-evaluation-state';
    $pestRunnerRevision = str_repeat('d', 40);
    $pestPackage = evidencePackage('pestphp/pest', '5.0.1', $pestRunnerRevision);
    $drovePackage = evidencePackage(
        'oxhq/drove',
        ltrim($package['tag'], 'v'),
        $package['revision'],
    );

    foreach ([$pestBaselineRoot, $pestEvaluationRoot] as $stateRoot) {
        mkdir($stateRoot.'/vendor/composer', 0700, true);
        evidenceGit($stateRoot, ['init', '--initial-branch=main']);
    }

    evidenceJson($pestBaselineRoot.'/composer.lock', evidenceLock([$pestPackage]));
    evidenceJson(
        $pestBaselineRoot.'/vendor/composer/installed.json',
        ['packages' => [$pestPackage]],
    );
    evidenceJson($pestEvaluationRoot.'/composer.lock', evidenceLock([$drovePackage]));
    evidenceJson(
        $pestEvaluationRoot.'/vendor/composer/installed.json',
        ['packages' => [$drovePackage]],
    );

    foreach ([$pestBaselineRoot, $pestEvaluationRoot] as $stateRoot) {
        evidenceGit($stateRoot, ['add', 'composer.lock']);
        evidenceCommit($stateRoot, 'state');
    }

    $dependencyState = new ReflectionMethod(Collector::class, 'dependencyState');
    $pestState = $dependencyState->invoke(
        $collector,
        $pestBaselineRoot,
        $pestEvaluationRoot,
        'pest',
        $package,
    );

    if (! is_array($pestState)
        || ($pestState['baseline_runner'] ?? null) !== [
            'package' => 'pestphp/pest',
            'version' => '5.0.1',
            'revision' => $pestRunnerRevision,
        ]) {
        throw new RuntimeException('Collector rejected a valid Pest replacement state.');
    }
    $checks++;

    evidenceJson(
        $pestEvaluationRoot.'/composer.lock',
        evidenceLock([$pestPackage, $drovePackage]),
    );
    evidenceJson(
        $pestEvaluationRoot.'/vendor/composer/installed.json',
        ['packages' => [$pestPackage, $drovePackage]],
    );
    evidenceFails(
        static fn () => $dependencyState->invoke(
            $collector,
            $pestBaselineRoot,
            $pestEvaluationRoot,
            'pest',
            $package,
        ),
        'replace pestphp/pest',
        'Collector accepted pestphp/pest beside its Drove replacement.',
    );
    $checks++;

    file_put_contents($fakeBin.'/case-count', "1\n");
    evidenceFails(
        static fn () => $collector->collect($root, $configuration),
        'at least two selected cases',
        'Collector accepted a single selected case.',
    );
    unlink($fakeBin.'/case-count');
    $checks++;

    file_put_contents($fakeBin.'/observed-lanes', "1\n");
    evidenceFails(
        static fn () => $collector->collect($root, $configuration),
        'at least two lanes',
        'Collector accepted a parallel replay that used one lane.',
    );
    unlink($fakeBin.'/observed-lanes');
    $checks++;

    file_put_contents($fakeBin.'/baseline-exit', "1\n");
    evidenceFails(
        static fn () => $collector->collect($root, $configuration),
        'must complete successfully',
        'Collector accepted a non-zero baseline exit.',
    );
    unlink($fakeBin.'/baseline-exit');
    $checks++;

    file_put_contents($root.'/untracked-bootstrap.php', "<?php\n");
    evidenceFails(
        static fn () => $collector->collect($root, $configuration),
        'without untracked project files',
        'Collector accepted an untracked project file.',
    );
    unlink($root.'/untracked-bootstrap.php');
    $checks++;

    $encoded = json_encode(
        $evidence,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n";
    file_put_contents($root.'/.drove/evaluation.json', $encoded);
    evidenceGit($root, ['add', '.drove/evaluation.json']);
    evidenceCommit($root, 'evidence');
    $evidenceRevision = evidenceGit($root, ['rev-parse', 'HEAD']);
    $entry = $collector->seal($root, '.drove/evaluation.json');

    if (($entry['project_revision'] ?? null) !== $projectRevision
        || ($entry['evaluation_revision'] ?? null) !== $evaluationRevision
        || ($entry['evidence_revision'] ?? null) !== $evidenceRevision
        || ($entry['evidence']['sha256'] ?? null) !== hash('sha256', $encoded)
        || ($entry['evidence']['url'] ?? null) !== sprintf(
            'https://raw.githubusercontent.com/example-partner/evaluation-fixture/%s/.drove/evaluation.json',
            $evidenceRevision,
        )) {
        throw new RuntimeException('Collector seal output drifted.');
    }
    $checks++;

    file_put_contents($root.'/.drove/evaluation.json', $encoded.' ');
    evidenceFails(
        static fn () => $collector->seal($root, '.drove/evaluation.json'),
        'bytes must be committed',
        'Seal accepted changed artifact bytes.',
    );
    evidenceGit($root, ['checkout', '--', '.drove/evaluation.json']);
    $checks++;

    echo json_encode([
        'gate' => 'design-partner-collector-self-test',
        'status' => 'passed',
        'checks' => $checks,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    is_string($originalPath) ? putenv('PATH='.$originalPath) : putenv('PATH');
    evidenceRemove($directory);
}

/**
 * @param  list<array<string, mixed>>  $packages
 * @return array<string, mixed>
 */
function evidenceLock(array $packages): array
{
    return [
        '_readme' => ['fixture'],
        'content-hash' => hash(
            'sha256',
            json_encode($packages, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ),
        'packages' => [],
        'packages-dev' => $packages,
    ];
}

/**
 * @return array<string, mixed>
 */
function evidencePackage(string $package, string $version, string $revision): array
{
    return [
        'name' => $package,
        'version' => $version,
        'source' => [
            'type' => 'git',
            'url' => "https://github.com/{$package}.git",
            'reference' => $revision,
        ],
        'dist' => [
            'type' => 'zip',
            'url' => "https://api.github.com/repos/{$package}/zipball/{$revision}",
            'reference' => $revision,
        ],
    ];
}

/**
 * @param  array{tag: string, revision: string}  $drove
 */
function evidenceEvaluationVendor(
    string $root,
    array $drove,
    string $runnerRevision,
): void {
    if (! is_dir($root.'/vendor/bin')) {
        mkdir($root.'/vendor/bin', 0700, true);
    }
    if (! is_dir($root.'/vendor/composer')) {
        mkdir($root.'/vendor/composer', 0700, true);
    }
    file_put_contents($root.'/vendor/fixture-observed-lanes', "2\n");
    file_put_contents(
        $root.'/vendor/bin/phpunit',
        <<<'PHP'
#!/usr/bin/env php
<?php

file_put_contents(getcwd().'/.drove/current-vendor-used', "1\n");
exit(99);
PHP,
    );
    file_put_contents(
        $root.'/vendor/bin/drove',
        <<<'PHP'
#!/usr/bin/env php
<?php

file_put_contents(getcwd().'/.drove/current-vendor-used', "1\n");
exit(99);
PHP,
    );
    chmod($root.'/vendor/bin/phpunit', 0755);
    chmod($root.'/vendor/bin/drove', 0755);
    evidenceJson($root.'/vendor/composer/installed.json', [
        'packages' => [
            evidencePackage('phpunit/phpunit', '13.2.4', $runnerRevision),
            evidencePackage('oxhq/drove', ltrim($drove['tag'], 'v'), $drove['revision']),
        ],
    ]);
}

function evidenceFakeComposer(string $bin): void
{
    file_put_contents(
        $bin.'/composer',
        <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

$expected = ['install', '--no-interaction', '--no-progress', '--prefer-dist'];

if (array_slice($argv, 1) !== $expected) {
    fwrite(STDERR, "unexpected Composer argv\n");
    usleep(50_000);
    exit(64);
}

if (is_file(__DIR__.'/fail')) {
    fwrite(STDERR, "fixture Composer failure\n");
    usleep(50_000);
    exit(42);
}

$lock = json_decode(
    (string) file_get_contents(getcwd().'/composer.lock'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$packages = [
    ...(is_array($lock['packages'] ?? null) ? $lock['packages'] : []),
    ...(is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : []),
];
$runner = null;
$drove = null;

foreach ($packages as $package) {
    if (is_array($package) && ($package['name'] ?? null) === 'phpunit/phpunit') {
        $runner = $package;
    }
    if (is_array($package) && ($package['name'] ?? null) === 'oxhq/drove') {
        $drove = $package;
    }
}

if (is_array($drove) && is_file(__DIR__.'/evaluation-fail')) {
    fwrite(STDERR, "fixture evaluation Composer failure\n");
    usleep(50_000);
    exit(43);
}

if (! is_array($runner)) {
    fwrite(STDERR, "missing fixture runner lock\n");
    usleep(50_000);
    exit(65);
}

if (is_file(__DIR__.'/installed-revision')) {
    $revision = trim((string) file_get_contents(__DIR__.'/installed-revision'));
    $runner['source']['reference'] = $revision;
    $runner['dist']['reference'] = $revision;
}

if (is_array($drove) && is_file(__DIR__.'/evaluation-runner-revision')) {
    $revision = trim((string) file_get_contents(__DIR__.'/evaluation-runner-revision'));
    $runner['source']['reference'] = $revision;
    $runner['dist']['reference'] = $revision;
}

if (is_array($drove) && is_file(__DIR__.'/evaluation-drove-revision')) {
    $revision = trim((string) file_get_contents(__DIR__.'/evaluation-drove-revision'));
    $drove['source']['reference'] = $revision;
    $drove['dist']['reference'] = $revision;
}

mkdir(getcwd().'/vendor/bin', 0700, true);
mkdir(getcwd().'/vendor/composer', 0700, true);
file_put_contents(
    getcwd().'/vendor/composer/installed.json',
    json_encode(
        ['packages' => is_array($drove) ? [$runner, $drove] : [$runner]],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n",
);
copy(__DIR__.'/phpunit-fixture', getcwd().'/vendor/bin/phpunit');
chmod(getcwd().'/vendor/bin/phpunit', 0755);
file_put_contents(
    getcwd().'/vendor/fixture-case-count',
    is_file(__DIR__.'/case-count')
        ? file_get_contents(__DIR__.'/case-count')
        : "2\n",
);
file_put_contents(
    getcwd().'/vendor/fixture-baseline-exit',
    is_file(__DIR__.'/baseline-exit')
        ? file_get_contents(__DIR__.'/baseline-exit')
        : "0\n",
);
if (is_array($drove)) {
    copy(__DIR__.'/drove-fixture', getcwd().'/vendor/bin/drove');
    chmod(getcwd().'/vendor/bin/drove', 0755);
    file_put_contents(
        getcwd().'/vendor/fixture-observed-lanes',
        is_file(__DIR__.'/observed-lanes')
            ? file_get_contents(__DIR__.'/observed-lanes')
            : "2\n",
    );
}
usleep(50_000);
echo "fixture Composer install\n";
PHP,
    );
    file_put_contents(
        $bin.'/phpunit-fixture',
        <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

$count = (int) trim((string) file_get_contents(dirname(__DIR__).'/fixture-case-count'));
$exit = (int) trim((string) file_get_contents(dirname(__DIR__).'/fixture-baseline-exit'));
$methods = array_slice(['testOne', 'testTwo'], 0, $count);
$discovery = null;

foreach ($argv as $index => $argument) {
    if ($argument === '--list-tests-xml') {
        $discovery = $argv[$index + 1] ?? null;
    }
}

if (is_string($discovery)) {
    $source = getcwd().'/tests/ExampleTest.php';
    $xml = '<tests><testSuite name="fixture"><testClass name="Tests\\ExampleTest" file="'
        .htmlspecialchars($source, ENT_XML1).'">';

    foreach ($methods as $method) {
        $id = 'Tests\\ExampleTest::'.$method;
        $xml .= '<testMethod id="'.htmlspecialchars($id, ENT_XML1).'" name="'.$method.'"/>';
    }

    file_put_contents($discovery, $xml.'</testClass></testSuite></tests>');
    usleep(50_000);
    exit(0);
}

usleep(50_000);
echo "Tests: {$count}, Assertions: {$count}.\n";
exit($exit);
PHP,
    );
    file_put_contents(
        $bin.'/drove-fixture',
        <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

$processes = 1;
$replay = null;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--processes=')) {
        $processes = (int) substr($argument, strlen('--processes='));
    } elseif (str_starts_with($argument, '--replay=')) {
        $replay = substr($argument, strlen('--replay='));
    }
}

$caseIds = ['Tests\\ExampleTest::testOne', 'Tests\\ExampleTest::testTwo'];
$cases = [
    ['execution_id' => 'test:1', 'frontend_id' => $caseIds[0]],
    ['execution_id' => 'test:2', 'frontend_id' => $caseIds[1]],
];
$configuredLanes = (int) trim(
    (string) file_get_contents(dirname(__DIR__).'/fixture-observed-lanes'),
);
$artifact = [
    'schema' => 1,
    'kind' => 'run',
    'command' => ['processes' => $processes],
    'plan' => [
        'tests' => 2,
        'case_identity' => [
            'schema' => 1,
            'cases' => $cases,
            'frontend_ids_sha256' => hash(
                'sha256',
                json_encode($caseIds, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
        ],
    ],
    'result' => [
        'exit_code' => 0,
        'counts' => ['passed' => 2],
        'completion_order' => ['test:1', 'test:2'],
        'observed_concurrency' => [
            'global' => min($configuredLanes, $processes),
        ],
    ],
];

if (! is_string($replay)) {
    fwrite(STDERR, "missing replay\n");
    exit(2);
}

file_put_contents(
    $replay,
    json_encode(
        $artifact,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n",
);
usleep(50_000);
echo "Tests: 2 passed (2)\nAssertions: 2\n";
PHP,
    );
    chmod($bin.'/composer', 0755);
    chmod($bin.'/phpunit-fixture', 0755);
    chmod($bin.'/drove-fixture', 0755);
}

/**
 * @param  array<string, mixed>  $value
 */
function evidenceJson(string $path, array $value): void
{
    $parent = dirname($path);

    if (! is_dir($parent)) {
        mkdir($parent, 0700, true);
    }

    file_put_contents(
        $path,
        json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n",
    );
}

function evidenceFails(Closure $operation, string $message, string $failure): void
{
    try {
        $operation();
        throw new RuntimeException($failure);
    } catch (Throwable $exception) {
        if (! str_contains($exception->getMessage(), $message)) {
            throw $exception;
        }
    }
}

function evidenceCommit(string $root, string $message): void
{
    evidenceGit(
        $root,
        [
            '-c',
            'user.name=Drove Fixture',
            '-c',
            'user.email=drove@example.test',
            'commit',
            '-m',
            $message,
        ],
    );
}

/**
 * @param  list<string>  $arguments
 */
function evidenceGit(string $root, array $arguments): string
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
        throw new RuntimeException('Could not start fixture Git.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        throw new RuntimeException('Fixture Git failed: '.trim((string) $error));
    }

    return trim((string) $output);
}

function evidenceRemove(string $directory): void
{
    if (! is_dir($directory) || ! str_starts_with(basename($directory), 'drove-evidence-')) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir() && ! $entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }

    rmdir($directory);
}
