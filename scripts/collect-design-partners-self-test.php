#!/usr/bin/env php
<?php

declare(strict_types=1);

use Drove\Evaluation\Collector;

require_once dirname(__DIR__).'/src/Drove/Bridge/CompatibilityStatus.php';
require_once dirname(__DIR__).'/src/Drove/Bridge/CompatibilityRegistry.php';
require_once dirname(__DIR__).'/src/Drove/Migration/Finding.php';
require_once dirname(__DIR__).'/src/Drove/Migration/Scanner.php';
require_once dirname(__DIR__).'/src/Drove/Kernel/NativeLibrary.php';
require_once dirname(__DIR__).'/src/Drove/Evaluation/Collector.php';

$directory = sys_get_temp_dir().'/drove-evidence-'.bin2hex(random_bytes(8));
$package = [
    'tag' => 'v0.4.0-alpha.2',
    'revision' => str_repeat('a', 40),
];

try {
    mkdir($directory, 0700, true);
    mkdir($directory.'/.drove', 0700, true);
    mkdir($directory.'/tests', 0700, true);
    mkdir($directory.'/vendor/bin', 0700, true);
    file_put_contents($directory.'/composer.json', "{}\n");
    file_put_contents(
        $directory.'/tests/ExampleTest.php',
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
    file_put_contents(
        $directory.'/vendor/bin/phpunit',
        <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = getcwd();
$source = $root.'/tests/ExampleTest.php';
$methods = ['testOne', 'testTwo'];
$discovery = null;

foreach ($argv as $index => $argument) {
    if ($argument === '--list-tests-xml') {
        $discovery = $argv[$index + 1] ?? null;
    }
}

if (is_string($discovery)) {
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
echo "Tests: 2, Assertions: 2.\n";
PHP,
    );
    file_put_contents(
        $directory.'/vendor/bin/drove',
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
        'observed_concurrency' => ['global' => min(2, $processes)],
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
    chmod($directory.'/vendor/bin/phpunit', 0755);
    chmod($directory.'/vendor/bin/drove', 0755);
    $configuration = [
        'schema' => 1,
        'evaluation_id' => 'fixture-evaluation',
        'team' => 'Fixture Partner',
        'repository' => 'https://github.com/example-partner/evaluation-fixture',
        'frontend' => 'phpunit',
        'runtime' => 'php',
        'parallel_processes' => 2,
        'baseline_command_argv' => ['vendor/bin/phpunit'],
        'drove_command_argv' => ['vendor/bin/drove', '--pest'],
        'migration' => null,
    ];
    file_put_contents(
        $directory.'/.drove/evaluation-config.json',
        json_encode(
            $configuration,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n",
    );
    evidenceGit($directory, ['init', '--initial-branch=main']);
    evidenceGit($directory, ['remote', 'add', 'origin', 'https://github.com/example-partner/evaluation-fixture.git']);
    evidenceGit($directory, ['add', '.']);
    evidenceGit(
        $directory,
        ['-c', 'user.name=Drove Fixture', '-c', 'user.email=drove@example.test', 'commit', '-m', 'fixture'],
    );
    $projectRevision = evidenceGit($directory, ['rev-parse', 'HEAD']);
    $collector = new Collector($package);
    $evidence = $collector->collect($directory, $configuration);

    if (($evidence['project_revision'] ?? null) !== $projectRevision
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
        || ($evidence['benchmark']['runs'][2]['observed_lanes'] ?? null) !== 2) {
        throw new RuntimeException('Collector fixture output drifted.');
    }

    $encoded = json_encode(
        $evidence,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n";
    file_put_contents($directory.'/.drove/evaluation.json', $encoded);
    evidenceGit($directory, ['add', '.drove/evaluation.json']);
    evidenceGit(
        $directory,
        ['-c', 'user.name=Drove Fixture', '-c', 'user.email=drove@example.test', 'commit', '-m', 'evidence'],
    );
    $evidenceRevision = evidenceGit($directory, ['rev-parse', 'HEAD']);
    $entry = $collector->seal($directory, '.drove/evaluation.json');

    if (($entry['project_revision'] ?? null) !== $projectRevision
        || ($entry['evidence_revision'] ?? null) !== $evidenceRevision
        || ($entry['evidence']['sha256'] ?? null) !== hash('sha256', $encoded)
        || ($entry['evidence']['url'] ?? null) !== sprintf(
            'https://raw.githubusercontent.com/example-partner/evaluation-fixture/%s/.drove/evaluation.json',
            $evidenceRevision,
        )) {
        throw new RuntimeException('Collector seal output drifted.');
    }

    file_put_contents($directory.'/.drove/evaluation.json', $encoded.' ');

    try {
        $collector->seal($directory, '.drove/evaluation.json');
        throw new RuntimeException('Seal accepted changed artifact bytes.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'bytes must be committed')) {
            throw $exception;
        }
    }

    evidenceGit($directory, ['checkout', '--', '.drove/evaluation.json']);
    file_put_contents(
        $directory.'/tests/ExampleTest.php',
        <<<'PHP'
<?php

declare(strict_types=1);

expect()->extend('toBeFixture', fn (): object => $this);
PHP,
    );
    evidenceGit($directory, ['add', 'tests/ExampleTest.php']);
    evidenceGit(
        $directory,
        ['-c', 'user.name=Drove Fixture', '-c', 'user.email=drove@example.test', 'commit', '-m', 'bridge-only'],
    );

    try {
        $collector->collect($directory, $configuration);
        throw new RuntimeException('Collector relabeled a bridge-only source as supported.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'non-supported scanner finding')) {
            throw $exception;
        }
    }

    file_put_contents($directory.'/untracked-bootstrap.php', "<?php\n");

    try {
        $collector->collect($directory, $configuration);
        throw new RuntimeException('Collector accepted an untracked project file.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'without untracked project files')) {
            throw $exception;
        }
    } finally {
        unlink($directory.'/untracked-bootstrap.php');
    }

    file_put_contents(
        $directory.'/tests/ExampleTest.php',
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
    $baselineRunner = file_get_contents($directory.'/vendor/bin/phpunit');

    if (! is_string($baselineRunner)) {
        throw new RuntimeException('Could not read baseline fixture runner.');
    }

    $failingBaseline = str_replace(
        'echo "Tests: 2, Assertions: 2.\n";',
        'echo "Tests: 2, Assertions: 2.\n"; exit(1);',
        $baselineRunner,
    );

    if ($failingBaseline === $baselineRunner) {
        throw new RuntimeException('Could not make baseline fixture runner fail.');
    }

    file_put_contents($directory.'/vendor/bin/phpunit', $failingBaseline);
    evidenceGit($directory, ['add', 'tests/ExampleTest.php', 'vendor/bin/phpunit']);
    evidenceGit(
        $directory,
        ['-c', 'user.name=Drove Fixture', '-c', 'user.email=drove@example.test', 'commit', '-m', 'failing-run'],
    );

    try {
        $collector->collect($directory, $configuration);
        throw new RuntimeException('Collector accepted a non-zero benchmark exit.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'must complete successfully')) {
            throw $exception;
        }
    }

    echo json_encode([
        'gate' => 'design-partner-collector-self-test',
        'status' => 'passed',
        'checks' => 6,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    evidenceRemove($directory);
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
