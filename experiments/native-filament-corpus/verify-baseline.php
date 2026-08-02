<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\Process\Process;

require __DIR__.'/case-parity.php';

try {
    $project = $argv[1] ?? null;
    $nonserialPath = $argv[2] ?? null;
    $serialPath = $argv[3] ?? null;
    $baselinePath = $argv[4] ?? __DIR__.'/baseline-cases.json';

    foreach ([$project, $nonserialPath, $serialPath, $baselinePath] as $argument) {
        nativeFilamentCaseParityAssert(is_string($argument), 'The independent Filament baseline arguments are invalid.');
    }

    nativeFilamentCaseParityAssert(is_dir($project), 'The independent Filament project is unavailable.');
    nativeFilamentCaseParityAssert(is_file($baselinePath), 'The committed Filament case baseline is unavailable.');
    require $project.'/vendor/autoload.php';
    $packages = InstalledVersions::getInstalledPackages();
    $forbidden = array_values(array_filter(
        $packages,
        static fn (string $package): bool => str_starts_with($package, 'oxhq/'),
    ));
    nativeFilamentCaseParityAssert($forbidden === [], 'The independent Filament baseline installed Drove packages.');
    nativeFilamentCaseParityAssert(InstalledVersions::isInstalled('pestphp/pest'), 'The independent Filament baseline did not install Pest.');
    nativeFilamentCaseParityAssert(InstalledVersions::isInstalled('phpunit/phpunit'), 'The independent Filament baseline did not install PHPUnit.');
    nativeFilamentCaseParityAssert(InstalledVersions::isInstalled('orchestra/testbench'), 'The independent Filament baseline did not install Testbench.');
    $lockSource = file_get_contents($project.'/composer.lock');

    if (! is_string($lockSource)) {
        throw new RuntimeException('The independent Filament Composer lock is unreadable.');
    }

    $canonicalLock = str_replace("\r\n", "\n", $lockSource);
    nativeFilamentCaseParityAssert(! str_contains($canonicalLock, "\r"), 'The independent Filament Composer lock contains unsupported line endings.');
    $lockBlob = new Process(['git', '-C', $project, 'rev-parse', 'HEAD:composer.lock']);
    $lockBlob->mustRun();
    $lockIdentity = [
        'git_blob_sha1' => trim($lockBlob->getOutput()),
        'worktree_canonical_sha256' => hash('sha256', $canonicalLock),
        'normalization' => 'CRLF-to-LF-only',
    ];
    nativeFilamentCaseParityAssert(
        $lockIdentity === [
            'git_blob_sha1' => '485810b09e5fea4a8609b72be78474848cc4ca13',
            'worktree_canonical_sha256' => 'f03b2f38dff3b91c2b6a09eadf00c7c516db252064304a6ad78f86b3dd9faf32',
            'normalization' => 'CRLF-to-LF-only',
        ],
        'The independent Filament Composer lock drifted.',
    );
    $baseline = json_decode((string) file_get_contents($baselinePath), true, 64, JSON_THROW_ON_ERROR);
    nativeFilamentCaseParityAssert(
        is_array($baseline)
            && ($baseline['schema'] ?? null) === 1
            && ($baseline['commit'] ?? null) === 'e9348b2e3792088ee877068116b6c1e1559a7df8'
            && ($baseline['runner']['composer_lock_git_blob_sha1'] ?? null) === $lockIdentity['git_blob_sha1']
            && ($baseline['runner']['composer_lock_sha256'] ?? null) === $lockIdentity['worktree_canonical_sha256']
            && is_array($baseline['cohorts'] ?? null),
        'The committed Filament case baseline is invalid.',
    );
    $rows = [
        'nonserial' => nativeFilamentBaselineRowsFromJunit($nonserialPath),
        'serial' => nativeFilamentBaselineRowsFromJunit($serialPath),
    ];

    foreach ($rows as $cohort => $actual) {
        $expected = $baseline['cohorts'][$cohort] ?? null;
        nativeFilamentCaseParityAssert(
            is_array($expected)
                && ($expected['rows'] ?? null) === $actual
                && ($expected['semantic_sha256'] ?? null) === nativeFilamentCaseRowsHash($actual),
            'The independent Filament '.$cohort.' case baseline diverged.',
        );
    }

    $runtimeFiles = array_values(array_filter(
        array_map(static fn (string $path): string => str_replace('\\', '/', $path), get_included_files()),
        static fn (string $path): bool => str_contains(strtolower($path), '/vendor/oxhq/'),
    ));
    nativeFilamentCaseParityAssert($runtimeFiles === [], 'The independent Filament baseline loaded Drove runtime files.');
    echo json_encode([
        'ok' => true,
        'corpus' => 'filamentphp/filament',
        'runner' => 'pestphp/pest',
        'vendor_mode' => 'independent-upstream-lock',
        'pest' => InstalledVersions::getPrettyVersion('pestphp/pest'),
        'phpunit' => InstalledVersions::getPrettyVersion('phpunit/phpunit'),
        'testbench' => InstalledVersions::getPrettyVersion('orchestra/testbench'),
        'lock_identity' => $lockIdentity,
        'cohorts' => array_map(
            static fn (array $cases): array => [
                'cases' => count($cases),
                'assertions' => array_sum(array_column($cases, 'assertions')),
                'semantic_sha256' => nativeFilamentCaseRowsHash($cases),
            ],
            $rows,
        ),
        'forbidden_packages' => [],
        'forbidden_runtime_files' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $failure) {
    fwrite(STDERR, 'DROVE_NATIVE_FILAMENT_BASELINE_FAILED '.$failure::class.': '.$failure->getMessage().PHP_EOL);
    exit(1);
}
