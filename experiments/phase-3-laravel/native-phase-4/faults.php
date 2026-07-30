<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/support.php';

try {
    $identity = nativePhaseFourIdentity();
    $cases = [];

    foreach (['sqlite-memory', 'sqlite-copy'] as $provider) {
        foreach ([
            'prepare',
            'enter',
            'leave',
            'cleanup',
        ] as $fault) {
            putenv('DROVE_LARAVEL_PROVIDER='.$provider);
            putenv('DROVE_LARAVEL_FAULT='.$fault);
            $pipes = [];
            $process = proc_open(
                [
                    PHP_BINARY,
                    '-d',
                    'ffi.enable=true',
                    __DIR__.'/fault-case.php',
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__),
            );

            if (! is_resource($process)) {
                throw new RuntimeException('Could not start a native Laravel fault case.');
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0
                || ! is_string($stdout)
                || ! is_string($stderr)
                || $stderr !== '') {
                throw new RuntimeException(sprintf(
                    'Native Laravel fault case %s/%s failed: %s',
                    $provider,
                    $fault,
                    is_string($stderr) ? trim($stderr) : 'missing stderr',
                ));
            }

            $case = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);

            if (! is_array($case)
                || ($case['ok'] ?? null) !== true
                || ($case['provider'] ?? null) !== $provider
                || ($case['fault'] ?? null) !== $fault
                || ($case['evidence_revision'] ?? null) !== $identity['evidence_revision']
                || ($case['runtime_platform'] ?? null) !== $identity['runtime_platform']
                || ($case['drover_identity'] ?? null) !== $identity['drover_identity']) {
                throw new RuntimeException('Native Laravel fault case identity diverged.');
            }

            $cases[] = $case;
        }
    }

    echo json_encode([
        'schema' => 1,
        'ok' => true,
        ...$identity,
        'case_count' => count($cases),
        'fault_scope' => 'mixed',
        'prepare_failure_case_count' => count(array_filter(
            $cases,
            static fn (array $case): bool => ($case['fault_scope'] ?? null)
                === 'prepare-closure-before-provider-boot',
        )),
        'provider_internal_partial_failure_case_count' => count(array_filter(
            $cases,
            static fn (array $case): bool => ($case['internal_partial_provider_failure_checked'] ?? null)
                === true,
        )),
        'cases' => $cases,
        'case_hash' => hash('sha256', json_encode($cases, JSON_THROW_ON_ERROR)),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
