<?php

declare(strict_types=1);

use Drove\Kernel\DroverScheduler;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/support.php';

if (PHP_OS_FAMILY !== 'Linux') {
    fwrite(STDERR, 'The cancellation proof currently requires Linux procfs.'.PHP_EOL);
    exit(2);
}

/**
 * @return array<string, mixed>
 */
function nativePhaseFiveCancellationCase(string $kind, string $workspace): array
{
    $statePath = $workspace.'/state.json';
    $executorEscapedPath = $workspace.'/executor.escaped';
    $descendantEscapedPath = $workspace.'/descendant.escaped';
    $rootPid = getmypid();

    if (! is_int($rootPid)) {
        throw new RuntimeException('Could not identify the cancellation proof root.');
    }

    $state = null;
    $caught = null;
    $handlerCalls = 0;
    $injectedFailures = 0;
    $observed = [
        'executor_alive' => null,
        'descendant_alive' => null,
        'executor_escaped' => null,
        'descendant_escaped' => null,
    ];
    $case = [
        'kind' => $kind,
        'ok' => false,
    ];

    try {
        nativePhaseFiveAssert(
            mkdir($workspace, 0700, true),
            'Could not create the cancellation proof workspace.',
        );
        $scheduler = new DroverScheduler(
            'native-phase-5-cancel-'.$kind.'-'.bin2hex(random_bytes(6)),
            1,
            defaultTimeoutMs: 2_000,
            termGraceMs: 25,
        );
        $previousAsyncSignals = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGUSR1);
        pcntl_signal(SIGUSR1, static function () use (
            &$handlerCalls,
            &$injectedFailures,
            $kind,
            $scheduler,
            $statePath,
        ): void {
            $handlerCalls++;

            if ($kind === 'injected-failure') {
                $state = nativePhaseFiveReadJson($statePath);
                $scopeHostPid = $state['scope_host_pid'] ?? null;
                nativePhaseFiveAssert(
                    is_int($scopeHostPid)
                        && posix_kill($scopeHostPid, SIGKILL),
                    'Could not inject a lost waitable scope host.',
                );
                $status = 0;
                nativePhaseFiveAssert(
                    pcntl_waitpid($scopeHostPid, $status) === $scopeHostPid,
                    'Could not reap the injected scope host.',
                );
                $injectedFailures++;
            }

            $scheduler->requestCancellation();
        });

        try {
            $scheduler->map(
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
                        'timeout_ms' => 2_000,
                        'permit' => true,
                    ]],
                    static function () use (

                        $rootPid,
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
                            'Could not fork the cancellation proof descendant.',
                        );

                        if ($descendantPid === 0) {
                            pcntl_async_signals(true);
                            pcntl_signal(SIGTERM, SIG_IGN);
                            usleep(750_000);
                            file_put_contents($descendantEscapedPath, 'escaped', LOCK_EX);
                            $lingerDeadline = hrtime(true) + 5_000_000_000;

                            do {
                                usleep(10_000);
                            } while (hrtime(true) < $lingerDeadline);

                            exit(99);
                        }

                        usleep(100_000);
                        nativePhaseFiveWriteJson($statePath, [
                            'root_pid' => $rootPid,
                            'scope_host_pid' => $scopeHostPid,
                            'executor_pid' => $executorPid,
                            'executor_pgid' => $executorPgid,
                            'descendant_pid' => $descendantPid,
                        ]);
                        nativePhaseFiveAssert(
                            posix_kill($rootPid, SIGUSR1),
                            'Could not request explicit cancellation.',
                        );

                        usleep(750_000);
                        file_put_contents($executorEscapedPath, 'escaped', LOCK_EX);
                        $lingerDeadline = hrtime(true) + 5_000_000_000;

                        do {
                            usleep(10_000);
                        } while (hrtime(true) < $lingerDeadline);

                        exit(99);
                    },
                ),
            );
        } catch (Throwable $throwable) {
            $caught = $throwable;
        } finally {
            pcntl_signal(SIGUSR1, $previousHandler);
            pcntl_async_signals($previousAsyncSignals);
        }

        nativePhaseFiveAssert(
            is_file($statePath),
            'The cancellation proof did not publish process identity.',
        );
        $state = nativePhaseFiveReadJson($statePath);
        nativePhaseFiveAssert(
            is_int($state['root_pid'] ?? null)
                && $state['root_pid'] === $rootPid
                && is_int($state['scope_host_pid'] ?? null)
                && is_int($state['executor_pid'] ?? null)
                && is_int($state['executor_pgid'] ?? null)
                && $state['executor_pid'] === $state['executor_pgid']
                && is_int($state['descendant_pid'] ?? null),
            'The cancellation proof lost process identity.',
        );
        usleep(900_000);
        $observed = [
            'executor_alive' => nativePhaseFiveProcessAlive($state['executor_pid']),
            'descendant_alive' => nativePhaseFiveProcessAlive($state['descendant_pid']),
            'executor_escaped' => is_file($executorEscapedPath),
            'descendant_escaped' => is_file($descendantEscapedPath),
        ];
        $message = $caught?->getMessage();
        $expectedFailure = $kind === 'explicit'
            ? $message === 'Drove explicit cancellation requested.'
            : is_string($message)
                && str_starts_with($message, 'Native Drover cancellation failed:')
                && str_contains($message, 'Drover cancellation failed:')
                && str_contains(
                    $message,
                    'executor observation for task stateful-scope-host: '
                        .'waitid() lost the Drove child: No child processes (os error 10).',
                )
                && str_contains(
                    $message,
                    'Original map failure: Drove explicit cancellation requested.',
                );
        $case = [
            'kind' => $kind,
            'ok' => $handlerCalls === 1
                && $caught instanceof RuntimeException
                && $expectedFailure
                && $observed === [
                    'executor_alive' => false,
                    'descendant_alive' => false,
                    'executor_escaped' => false,
                    'descendant_escaped' => false,
                ],
            'terminal_outcome_count' => $caught instanceof Throwable ? 1 : 0,
            'terminal_outcome' => [
                'class' => $caught instanceof Throwable ? $caught::class : null,
                'kind' => $kind === 'explicit'
                    ? 'explicit_cancellation'
                    : 'native_cancellation_failure',
                'message' => $message,
            ],
            'handler_call_count' => $handlerCalls,
            'injected_failure_count' => $injectedFailures,
            'cancellation_error_observed' => $kind === 'injected-failure' && $expectedFailure,
            'processes' => $state,
            'observed_before_harness_cleanup' => $observed,
        ];
    } catch (Throwable $throwable) {
        $case['harness_failure'] = [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
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

        $tracked = is_array($state)
            ? array_values(array_filter([
                $state['scope_host_pid'] ?? null,
                $state['executor_pid'] ?? null,
                $state['descendant_pid'] ?? null,
            ], is_int(...)))
            : [];
        nativePhaseFiveWaitForExit($tracked);

        try {
            nativePhaseFiveRemove($workspace);
        } catch (Throwable) {
            //
        }

        $case['harness_cleanup'] = [
            'orphan_pids' => nativePhaseFiveWaitForExit($tracked),
            'artifact_residue_count' => file_exists($workspace) ? 1 : 0,
        ];
    }

    return $case;
}

$root = sys_get_temp_dir().'/drove-native-phase-5-cancel-'.bin2hex(random_bytes(8));
$summary = null;
$exitCode = 1;

try {
    $identity = nativePhaseFiveIdentity();
    nativePhaseFiveAssert(
        mkdir($root, 0700, true),
        'Could not create the cancellation proof root.',
    );
    $cases = [
        nativePhaseFiveCancellationCase('explicit', $root.'/explicit'),
        nativePhaseFiveCancellationCase('injected-failure', $root.'/injected-failure'),
    ];
    $summary = [
        'schema' => 1,
        'ok' => array_all(
            $cases,
            static fn (array $case): bool => ($case['ok'] ?? null) === true
                && ($case['harness_cleanup']['orphan_pids'] ?? null) === []
                && ($case['harness_cleanup']['artifact_residue_count'] ?? null) === 0,
        ),
        ...$identity,
        'fixture' => 'explicit-cancellation',
        'terminal_outcome_count' => array_sum(array_column($cases, 'terminal_outcome_count')),
        'cases' => $cases,
    ];
    $exitCode = $summary['ok'] ? 0 : 1;
} catch (Throwable $throwable) {
    $summary = [
        'schema' => 1,
        'ok' => false,
        'fixture' => 'explicit-cancellation',
        'harness_failure' => [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
        ],
    ];
} finally {
    try {
        nativePhaseFiveRemove($root);
    } catch (Throwable) {
        //
    }

    $summary['harness_cleanup'] = [
        'artifact_residue_count' => file_exists($root) ? 1 : 0,
    ];
}

echo json_encode(
    $summary,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
exit($exitCode);
