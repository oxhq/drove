<?php

declare(strict_types=1);

use Drove\Kernel\DroverScheduler;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/support.php';

if (PHP_OS_FAMILY !== 'Linux') {
    fwrite(STDERR, 'The nested host crash proof currently requires Linux procfs.'.PHP_EOL);
    exit(2);
}

$workspace = sys_get_temp_dir().'/drove-native-phase-5-nested-host-'.bin2hex(random_bytes(8));
$statePath = $workspace.'/state.json';
$executorEscapedPath = $workspace.'/executor.escaped';
$descendantEscapedPath = $workspace.'/descendant.escaped';
$state = null;
$summary = null;
$exitCode = 1;

try {
    $identity = nativePhaseFiveIdentity();
    nativePhaseFiveAssert(
        mkdir($workspace, 0700, true),
        'Could not create the nested host crash workspace.',
    );
    $scheduler = new DroverScheduler(
        'native-phase-5-nested-host-'.bin2hex(random_bytes(6)),
        1,
        defaultTimeoutMs: 2_000,
        termGraceMs: 25,
    );
    $mapped = $scheduler->map(
        [[
            'id' => 'stateful-scope-host',
            'kind' => 'scope',
            'scope_id' => 'stateful-scope-host',
            'scopes' => ['stateful-scope-host'],
            'timeout_ms' => 0,
            'permit' => false,
        ]],
        static fn (): array => $scheduler->map(
            [[
                'id' => 'nested-test-executor',
                'kind' => 'test',
                'scope_id' => 'stateful-scope-host',
                'scopes' => ['stateful-scope-host'],
                'timeout_ms' => 0,
                'permit' => true,
            ]],
            static function () use (
                $statePath,
                $executorEscapedPath,
                $descendantEscapedPath,
            ): never {
                $scopeHostPid = posix_getppid();
                $executorPid = getmypid();
                $executorPgid = posix_getpgrp();
                $descendantPid = pcntl_fork();
                nativePhaseFiveAssert(
                    $descendantPid !== -1,
                    'Could not fork the nested executor descendant.',
                );

                if ($descendantPid === 0) {
                    pcntl_async_signals(true);
                    pcntl_signal(SIGTERM, SIG_IGN);

                    while (nativePhaseFiveProcessAlive($scopeHostPid)) {
                        usleep(1_000);
                    }

                    usleep(250_000);
                    file_put_contents($descendantEscapedPath, 'escaped', LOCK_EX);

                    while (true) {
                        usleep(10_000);
                    }
                }

                nativePhaseFiveWriteJson($statePath, [
                    'scope_host_pid' => $scopeHostPid,
                    'executor_pid' => $executorPid,
                    'executor_pgid' => $executorPgid,
                    'descendant_pid' => $descendantPid,
                ]);
                nativePhaseFiveAssert(
                    posix_kill($scopeHostPid, SIGKILL),
                    'Could not kill the stateful scope host.',
                );

                while (nativePhaseFiveProcessAlive($scopeHostPid)) {
                    usleep(1_000);
                }

                usleep(250_000);
                file_put_contents($executorEscapedPath, 'escaped', LOCK_EX);

                while (true) {
                    usleep(10_000);
                }
            },
        ),
    );
    $state = nativePhaseFiveReadJson($statePath);
    nativePhaseFiveAssert(
        is_int($state['scope_host_pid'] ?? null)
            && is_int($state['executor_pid'] ?? null)
            && is_int($state['executor_pgid'] ?? null)
            && is_int($state['descendant_pid'] ?? null)
            && $state['executor_pid'] === $state['executor_pgid'],
        'The nested host crash proof lost process identity.',
    );
    $deadline = hrtime(true) + 2_000_000_000;

    do {
        $executorEscaped = is_file($executorEscapedPath);
        $descendantEscaped = is_file($descendantEscapedPath);

        if ($executorEscaped && $descendantEscaped) {
            break;
        }

        usleep(5_000);
    } while (hrtime(true) < $deadline);

    $executorAlive = nativePhaseFiveProcessAlive($state['executor_pid']);
    $descendantAlive = nativePhaseFiveProcessAlive($state['descendant_pid']);
    $result = $mapped['results'][0] ?? null;
    $summary = [
        'schema' => 1,
        'ok' => ! $executorEscaped
            && ! $descendantEscaped
            && ! $executorAlive
            && ! $descendantAlive,
        ...$identity,
        'fixture' => 'nested-host-crash',
        'terminal_result_count' => count($mapped['results']),
        'root_result' => [
            'status' => is_array($result) ? ($result['status'] ?? null) : null,
            'failure_kind' => is_array($result)
                ? ($result['failure']['kind'] ?? null)
                : null,
            'signal' => is_array($result)
                ? ($result['telemetry']['signal'] ?? null)
                : null,
        ],
        'processes' => $state,
        'observed_before_harness_cleanup' => [
            'executor_alive' => $executorAlive,
            'descendant_alive' => $descendantAlive,
            'executor_escaped' => $executorEscaped,
            'descendant_escaped' => $descendantEscaped,
        ],
    ];
    $exitCode = $summary['ok'] ? 0 : 1;
} catch (Throwable $throwable) {
    $summary = [
        'schema' => 1,
        'ok' => false,
        'fixture' => 'nested-host-crash',
        'harness_failure' => [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
        ],
    ];
} finally {
    if (! is_array($state) && is_file($statePath)) {
        try {
            $state = nativePhaseFiveReadJson($statePath);
        } catch (Throwable) {
            $state = null;
        }
    }

    if (is_array($state) && is_int($state['executor_pgid'] ?? null)) {
        @posix_kill(-$state['executor_pgid'], SIGKILL);
    }

    if (is_array($state)) {
        nativePhaseFiveWaitForExit(array_values(array_filter([
            $state['executor_pid'] ?? null,
            $state['descendant_pid'] ?? null,
        ], is_int(...))));
    }

    try {
        nativePhaseFiveRemove($workspace);
    } catch (Throwable) {
        //
    }

    $summary['harness_cleanup'] = [
        'orphan_pids' => is_array($state)
            ? nativePhaseFiveWaitForExit(array_values(array_filter([
                $state['executor_pid'] ?? null,
                $state['descendant_pid'] ?? null,
            ], is_int(...))))
            : [],
        'artifact_residue_count' => file_exists($workspace) ? 1 : 0,
    ];
}

echo json_encode(
    $summary,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
exit($exitCode);
