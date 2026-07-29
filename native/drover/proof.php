<?php

declare(strict_types=1);

const DROVER_ROLE_ERROR = -1;
const DROVER_ROLE_PROGRESS = 0;
const DROVER_ROLE_CHILD = 1;
const DROVER_ROLE_RESULT = 2;
const DROVER_ROLE_DONE = 3;
const DROVER_ERR_VERSION = -4;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$library = $argv[1]
    ?? (getenv('DROVER_LIBRARY') ?: null)
    ?? '/usr/local/lib/'.(PHP_OS_FAMILY === 'Darwin' ? 'libdrover.dylib' : 'libdrover.so');
$ffi = FFI::cdef(<<<'C'
    typedef struct {
        int32_t role;
        uint32_t ordinal;
        int32_t pid;
        int32_t child_fd;
        int32_t status;
        int32_t timed_out;
        int32_t exit_code;
        int32_t term_signal;
        uint32_t protocol_version;
        uint32_t active_count;
        uint32_t max_active;
        uint32_t frame_count;
        char task_id[512];
        char failure_kind[64];
        char message[256];
    } DroverAction;

    uint32_t drover_protocol_version(void);
    size_t drover_protocol_max_frame_bytes(void);
    void *drover_engine_new(
        const char *run_id,
        uint32_t concurrency,
        const char *scope_limits_json,
        uint64_t term_grace_ms
    );
    int32_t drover_engine_acquire(void *engine, const char *scopes_json);
    int32_t drover_engine_release(void *engine, const char *scopes_json);
    void drover_engine_free(void *engine);
    void *drover_map_new(void *engine, uint32_t queue_capacity);
    int32_t drover_map_submit(
        void *map,
        const char *task_id,
        const char *task_kind,
        const char *scope_id,
        const char *scopes_json,
        uint64_t timeout_ms,
        int32_t permit
    );
    int32_t drover_map_step(void *map, DroverAction *action);
    int32_t drover_map_interrupt(void *map, int32_t signal);
    size_t drover_map_last_result_len(void *map);
    int32_t drover_map_copy_last_result(void *map, char *destination, size_t capacity);
    uint32_t drover_map_max_active(void *map);
    void drover_map_free(void *map);
    void drover_child_exit(int32_t status);
    int32_t drover_emit_frame(
        int32_t fd,
        const char *run_id,
        const char *task_id,
        const char *task_kind,
        const char *scope_id,
        uint32_t ordinal,
        uint32_t sequence,
        const char *event_type,
        const char *payload_json
    );
    int32_t drover_validate_frame_json(
        const char *expected_run_id,
        const char *expected_task_id,
        const char *expected_task_kind,
        const char *expected_scope_id,
        uint32_t expected_ordinal,
        uint32_t expected_sequence,
        const char *frame_json
    );
C, $library);

$assert($ffi->drover_protocol_version() === 1, 'The native protocol version drifted.');
$assert($ffi->drover_protocol_max_frame_bytes() === 1_048_576, 'The native frame limit drifted.');

$validVectors = [
    'started.json' => 0,
    'event.json' => 1,
    'value.json' => 2,
    'finished.json' => 3,
];

foreach ($validVectors as $filename => $sequence) {
    $json = file_get_contents(__DIR__.'/protocol/v1/'.$filename);
    $assert(is_string($json), 'A ChildProtocol vector is unreadable.');
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $assert($decoded['protocol_version'] === 1, 'A PHP-consumed vector has the wrong version.');
    $assert(
        $ffi->drover_validate_frame_json(
            'golden-run',
            'task:golden',
            'test',
            'scope:golden',
            7,
            $sequence,
            $json,
        ) === 0,
        'Rust rejected a PHP-consumed ChildProtocol vector.',
    );
}

$invalidVersion = file_get_contents(__DIR__.'/protocol/v1/invalid-version.json');
$assert(is_string($invalidVersion), 'The invalid-version vector is unreadable.');
$assert(
    $ffi->drover_validate_frame_json(
        'golden-run',
        'task:golden',
        'test',
        'scope:golden',
        7,
        3,
        $invalidVersion,
    ) === DROVER_ERR_VERSION,
    'Drover did not reject an incompatible ChildProtocol version.',
);

$runId = 'native-abi-smoke';
$timeoutProbe = '/tmp/drover-timeout-descendant-'.getmypid();
$readyPath = $timeoutProbe.'.ready';
$escapedPath = $timeoutProbe.'.escaped';
@unlink($readyPath);
@unlink($escapedPath);
$prepared = ['items' => ['root']];
$tasks = [
    [
        'id' => 'task:slow-a',
        'kind' => 'test',
        'scope_id' => 'scope:parallel',
        'scopes' => ['scope:root', 'scope:parallel'],
        'timeout_ms' => 1_000,
        'callback' => static function () use (&$prepared): array {
            $before = $prepared['items'];
            $prepared['items'][] = 'slow-a';
            usleep(150_000);

            return ['before' => $before, 'after' => $prepared['items']];
        },
    ],
    [
        'id' => 'task:slow-b',
        'kind' => 'test',
        'scope_id' => 'scope:parallel',
        'scopes' => ['scope:root', 'scope:parallel'],
        'timeout_ms' => 1_000,
        'callback' => static function () use (&$prepared): array {
            $before = $prepared['items'];
            $prepared['items'][] = 'slow-b';
            usleep(150_000);

            return ['before' => $before, 'after' => $prepared['items']];
        },
    ],
    [
        'id' => 'task:php-exception',
        'kind' => 'test',
        'scope_id' => 'scope:serial',
        'scopes' => ['scope:root', 'scope:serial'],
        'timeout_ms' => 1_000,
        'callback' => static function (): never {
            throw new RuntimeException('child callback failed');
        },
    ],
    [
        'id' => 'task:timeout-tree',
        'kind' => 'test',
        'scope_id' => 'scope:serial',
        'scopes' => ['scope:root', 'scope:serial'],
        'timeout_ms' => 500,
        'callback' => static function () use ($escapedPath, $readyPath): never {
            $descendant = pcntl_fork();

            if ($descendant === -1) {
                throw new RuntimeException('The timeout descendant could not fork.');
            }

            if ($descendant === 0) {
                if (! pcntl_signal(SIGTERM, SIG_IGN)) {
                    exit(70);
                }

                $descendantState = sprintf(
                    'pid=%d ppid=%d pgid=%d started_ns=%d',
                    getmypid(),
                    posix_getppid(),
                    posix_getpgrp(),
                    hrtime(true),
                );
                file_put_contents($readyPath, $descendantState);
                $escapeAt = hrtime(true) + 1_000_000_000;

                while (($remaining = $escapeAt - hrtime(true)) > 0) {
                    usleep(max(1, min(100_000, intdiv($remaining, 1_000))));
                }

                file_put_contents($escapedPath, $descendantState.' escaped_ns='.hrtime(true));
                exit(0);
            }

            usleep(2_000_000);
            throw new RuntimeException('The timed-out callback resumed.');
        },
    ],
];

$engine = $ffi->drover_engine_new($runId, 2, '{"scope:serial":1}', 50);
$assert(! FFI::isNull($engine), 'Drover could not initialize its shared permit engine.');
$map = $ffi->drover_map_new($engine, count($tasks));
$assert(! FFI::isNull($map), 'Drover could not initialize its ABI smoke map.');

foreach ($tasks as $ordinal => $task) {
    $submitted = $ffi->drover_map_submit(
        $map,
        $task['id'],
        $task['kind'],
        $task['scope_id'],
        json_encode($task['scopes'], JSON_THROW_ON_ERROR),
        $task['timeout_ms'],
        1,
    );
    $assert($submitted === $ordinal, 'Drover changed task submission order.');
}

$results = [];
$action = $ffi->new('DroverAction');

while (true) {
    $role = $ffi->drover_map_step($map, FFI::addr($action));

    if ($role === DROVER_ROLE_PROGRESS) {
        continue;
    }

    if ($role === DROVER_ROLE_CHILD) {
        $ordinal = $action->ordinal;
        $task = $tasks[$ordinal] ?? throw new RuntimeException('Drover returned an unknown task.');
        $sequence = 0;
        $emit = static function (string $type, array $payload) use (
            $action,
            $ffi,
            $ordinal,
            $runId,
            &$sequence,
            $task,
        ): void {
            $result = $ffi->drover_emit_frame(
                $action->child_fd,
                $runId,
                $task['id'],
                $task['kind'],
                $task['scope_id'],
                $ordinal,
                $sequence,
                $type,
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
            $assertion = $result === 0;

            if (! $assertion) {
                throw new RuntimeException(sprintf('Drover could not emit frame %d (%d).', $sequence, $result));
            }

            $sequence++;
        };
        $emit('task.started', ['started_ns' => hrtime(true)]);

        try {
            $value = ($task['callback'])();
            $emit('task.stdout', ['encoding' => 'base64', 'data' => base64_encode($task['id'])]);
            $emit('task.value', [
                'encoding' => 'base64',
                'data' => base64_encode(json_encode($value, JSON_THROW_ON_ERROR)),
            ]);
            $emit('task.finished', [
                'status' => 'passed',
                'failure' => null,
                'finished_ns' => hrtime(true),
                'memory_peak_bytes' => memory_get_peak_usage(true),
            ]);
            $ffi->drover_child_exit(0);
        } catch (Throwable $throwable) {
            $emit('task.value', ['encoding' => 'base64', 'data' => base64_encode('null')]);
            $emit('task.finished', [
                'status' => 'failed',
                'failure' => [
                    'kind' => 'php_exception',
                    'message' => $throwable->getMessage(),
                    'class' => $throwable::class,
                    'file' => $throwable->getFile(),
                    'line' => $throwable->getLine(),
                    'phase' => 'executor',
                    'hook_id' => null,
                ],
                'finished_ns' => hrtime(true),
                'memory_peak_bytes' => memory_get_peak_usage(true),
            ]);
            $ffi->drover_child_exit(1);
        }
    }

    if ($role === DROVER_ROLE_RESULT) {
        $length = $ffi->drover_map_last_result_len($map);
        $buffer = $ffi->new(sprintf('char[%d]', $length + 1));
        $assert(
            $ffi->drover_map_copy_last_result($map, $buffer, $length + 1) === 0,
            'Drover could not copy a result.',
        );
        $result = json_decode(FFI::string($buffer, $length), true, flags: JSON_THROW_ON_ERROR);
        $results[$result['id']] = $result;

        continue;
    }

    if ($role === DROVER_ROLE_DONE) {
        break;
    }

    if ($role === DROVER_ROLE_ERROR) {
        throw new RuntimeException('Native Drover failed: '.FFI::string(FFI::addr($action->message[0])));
    }

    throw new RuntimeException('Drover returned an unknown role.');
}

$maxActive = $ffi->drover_map_max_active($map);
$ffi->drover_map_free($map);
$ffi->drover_engine_free($engine);

$assert(count($results) === count($tasks), 'Drover lost an ABI smoke result.');
$assert($maxActive === 2, 'Drover did not reserve global permits before fork.');
$assert($prepared['items'] === ['root'], 'A child mutation escaped into the prepared PHP host.');
$assert($results['task:slow-a']['value']['after'] === ['root', 'slow-a'], 'Prepared state was not inherited.');
$assert($results['task:php-exception']['failure']['kind'] === 'php_exception', 'PHP failure drifted.');
$assert($results['task:timeout-tree']['failure']['kind'] === 'timeout', 'Timeout failure drifted.');
$assert(file_exists($readyPath), 'The timeout descendant did not start before cleanup.');
usleep(1_200_000);
$escapedState = @file_get_contents($escapedPath);
$assert(
    $escapedState === false,
    sprintf(
        'A timed-out descendant escaped its process group (task_pid=%s; %s).',
        $results['task:timeout-tree']['telemetry']['pid'] ?? 'unknown',
        $escapedState ?: 'descendant state unavailable',
    ),
);
@unlink($readyPath);

$interruptionReady = '/tmp/drover-active-interruption-'.getmypid().'.ready';
$interruptionDescendantReady = '/tmp/drover-active-interruption-'.getmypid().'.descendant-ready';
$interruptionEscaped = '/tmp/drover-active-interruption-'.getmypid().'.escaped';
@unlink($interruptionReady);
@unlink($interruptionDescendantReady);
@unlink($interruptionEscaped);
$interruptionEngine = $ffi->drover_engine_new('native-active-interruption', 1, '{}', 50);
$assert(! FFI::isNull($interruptionEngine), 'Drover could not initialize its interruption engine.');
$interruptionMap = $ffi->drover_map_new($interruptionEngine, 1);
$assert(! FFI::isNull($interruptionMap), 'Drover could not initialize its interruption map.');
$assert(
    $ffi->drover_map_submit(
        $interruptionMap,
        'task:active-interruption',
        'test',
        'scope:root',
        '["scope:root"]',
        0,
        1,
    ) === 0,
    'Drover rejected its active interruption task.',
);
$interruptionAction = $ffi->new('DroverAction');
$interruptionSent = false;
$interruptionResult = null;
$interruptionSignal = defined('SIGINT') ? constant('SIGINT') : 2;
$interruptionDeadline = hrtime(true) + 5_000_000_000;

try {
    while (hrtime(true) < $interruptionDeadline) {
        if (! $interruptionSent && file_exists($interruptionReady)) {
            $assert(
                $ffi->drover_map_interrupt($interruptionMap, $interruptionSignal) === 0,
                'Drover rejected an active interruption.',
            );
            $interruptionSent = true;
        }

        $role = $ffi->drover_map_step($interruptionMap, FFI::addr($interruptionAction));

        if ($role === DROVER_ROLE_PROGRESS) {
            continue;
        }

        if ($role === DROVER_ROLE_CHILD) {
            $script = 'trap "" TERM; printf ready > '
                .escapeshellarg($interruptionDescendantReady)
                .'; sleep 0.8; printf escaped > '
                .escapeshellarg($interruptionEscaped)
                .'; while :; do sleep 1; done';
            exec('sh -c '.escapeshellarg($script).' >/dev/null 2>&1 &');
            $readyDeadline = hrtime(true) + 1_000_000_000;

            while (! file_exists($interruptionDescendantReady) && hrtime(true) < $readyDeadline) {
                usleep(1_000);
            }

            file_put_contents($interruptionReady, 'ready');

            while (true) {
                usleep(10_000);
            }
        }

        if ($role === DROVER_ROLE_RESULT) {
            $length = $ffi->drover_map_last_result_len($interruptionMap);
            $buffer = $ffi->new(sprintf('char[%d]', $length + 1));
            $assert(
                $ffi->drover_map_copy_last_result($interruptionMap, $buffer, $length + 1) === 0,
                'Drover could not copy its interruption result.',
            );
            $interruptionResult = json_decode(
                FFI::string($buffer, $length),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            continue;
        }

        if ($role === DROVER_ROLE_DONE) {
            break;
        }

        throw new RuntimeException('Drover failed its active interruption proof.');
    }
} finally {
    $ffi->drover_map_free($interruptionMap);
}

$assert($interruptionSent, 'Drover never activated its interruption task.');
$assert(is_array($interruptionResult), 'Drover did not finish its active interruption.');
$assert(
    ($interruptionResult['failure']['kind'] ?? null) === 'user_interruption'
        && ($interruptionResult['telemetry']['interrupted_signal'] ?? null) === $interruptionSignal,
    'Drover did not preserve active interruption identity.',
);
$assert(
    $ffi->drover_engine_acquire($interruptionEngine, '[]') === 0
        && $ffi->drover_engine_release($interruptionEngine, '[]') === 0,
    'An active interruption leaked its concurrency permit.',
);
$ffi->drover_engine_free($interruptionEngine);
usleep(1_000_000);
$assert(! file_exists($interruptionEscaped), 'An actively interrupted descendant escaped its process group.');
@unlink($interruptionReady);
@unlink($interruptionDescendantReady);

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'gate' => 'native-abi-smoke',
    'protocol_version' => $ffi->drover_protocol_version(),
    'golden_vectors' => count($validVectors) + 1,
    'completed' => count($results),
    'max_active' => $maxActive,
    'failure_kinds' => array_values(array_filter(array_map(
        static fn (array $result): ?string => $result['failure']['kind'] ?? null,
        $results,
    ))),
    'descendant_escaped' => file_exists($escapedPath),
    'active_interruption' => $interruptionResult['failure']['kind'] ?? null,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
