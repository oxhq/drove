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
        char task_id[128];
        char failure_kind[64];
        char message[256];
    } DroverAction;

    uint32_t drover_protocol_version(void);
    size_t drover_protocol_max_frame_bytes(void);
    void *drover_scheduler_new(
        const char *run_id,
        uint32_t concurrency,
        uint32_t queue_capacity,
        uint64_t term_grace_ms
    );
    void drover_child_exit(int32_t status);
    int32_t drover_scheduler_submit(void *scheduler, const char *task_id, uint64_t timeout_ms);
    int32_t drover_scheduler_step(void *scheduler, DroverAction *action);
    size_t drover_scheduler_last_result_len(void *scheduler);
    int32_t drover_scheduler_copy_last_result(void *scheduler, char *destination, size_t capacity);
    uint32_t drover_scheduler_max_active(void *scheduler);
    void drover_scheduler_free(void *scheduler);
    int32_t drover_emit_frame(
        int32_t fd,
        const char *run_id,
        const char *task_id,
        uint32_t sequence,
        const char *event_type,
        const char *payload_json
    );
    int32_t drover_validate_frame_json(
        const char *expected_run_id,
        const char *expected_task_id,
        uint32_t expected_sequence,
        const char *frame_json
    );
C, '/usr/local/lib/libdrover.so');

$assert($ffi->drover_protocol_version() === 1, 'The native Drover protocol version drifted.');
$assert(
    $ffi->drover_protocol_max_frame_bytes() === 1_048_576,
    'The native Drover frame limit drifted.',
);

$validVectors = [
    'started.json' => 0,
    'event.json' => 1,
    'finished.json' => 2,
];

foreach ($validVectors as $filename => $sequence) {
    $json = file_get_contents(__DIR__.'/protocol/v1/'.$filename);
    $assert(is_string($json), 'A Drover protocol vector is unreadable.');
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $assert($decoded['protocol'] === 'drover.task', 'A PHP protocol vector has the wrong name.');
    $assert($decoded['version'] === 1, 'A PHP protocol vector has the wrong version.');
    $assert(
        $ffi->drover_validate_frame_json('golden-run', 'task:golden', $sequence, $json) === 0,
        'Rust rejected a PHP-consumed golden protocol vector.',
    );
}

$invalidVersion = file_get_contents(__DIR__.'/protocol/v1/invalid-version.json');
$assert(is_string($invalidVersion), 'The invalid-version vector is unreadable.');
$assert(
    $ffi->drover_validate_frame_json(
        'golden-run',
        'task:golden',
        2,
        $invalidVersion,
    ) === DROVER_ERR_VERSION,
    'Drover did not reject an incompatible protocol version.',
);

$runId = 'native-phase-1';
$concurrency = 2;
$queueCapacity = 8;
$escapedPath = '/tmp/drover-timeout-descendant';
@unlink($escapedPath);
$prepared = [
    'boot_count' => 1,
    'items' => ['root'],
];

$mutatingTask = static function (string $label) use (&$prepared): Closure {
    return static function (Closure $emit) use (&$prepared, $label): array {
        if ($prepared['boot_count'] !== 1 || $prepared['items'] !== ['root']) {
            throw new RuntimeException('A child did not inherit the prepared PHP heap.');
        }

        $before = $prepared['items'];
        $prepared['items'][] = $label;
        $emit('task.event', ['message' => $label.' inherited prepared state']);
        usleep(180_000);

        return [
            'before' => $before,
            'after' => $prepared['items'],
        ];
    };
};

$tasks = [
    [
        'id' => 'task:slow-a',
        'timeout_ms' => 1_000,
        'callback' => $mutatingTask('slow-a'),
    ],
    [
        'id' => 'task:slow-b',
        'timeout_ms' => 1_000,
        'callback' => $mutatingTask('slow-b'),
    ],
    [
        'id' => 'task:queued-fast',
        'timeout_ms' => 1_000,
        'callback' => static function (Closure $emit) use (&$prepared): array {
            if ($prepared['items'] !== ['root']) {
                throw new RuntimeException('A sibling PHP mutation escaped its fork.');
            }

            $emit('task.output', ['chunk' => 'queued']);

            return ['before' => $prepared['items']];
        },
    ],
    [
        'id' => 'task:php-exception',
        'timeout_ms' => 1_000,
        'callback' => static function (Closure $emit): never {
            throw new RuntimeException('child callback failed');
        },
    ],
    [
        'id' => 'task:timeout-tree',
        'timeout_ms' => 120,
        'callback' => static function (Closure $emit) use ($escapedPath): never {
            $script = 'trap "" TERM; sleep 1; printf escaped > '.escapeshellarg($escapedPath);
            exec('sh -c '.escapeshellarg($script).' >/dev/null 2>&1 &');
            $emit('task.event', ['message' => 'descendant started']);
            usleep(2_000_000);
            throw new RuntimeException('The timed-out callback unexpectedly resumed.');
        },
    ],
];

$scheduler = $ffi->drover_scheduler_new($runId, $concurrency, $queueCapacity, 50);
$assert(! FFI::isNull($scheduler), 'Drover could not initialize its native scheduler.');

foreach ($tasks as $ordinal => $task) {
    $submitted = $ffi->drover_scheduler_submit(
        $scheduler,
        $task['id'],
        $task['timeout_ms'],
    );
    $assert($submitted === $ordinal, 'Drover changed task submission order.');
}

$results = [];
$completionOrder = [];
$action = $ffi->new('DroverAction');

while (true) {
    $role = $ffi->drover_scheduler_step($scheduler, FFI::addr($action));

    if ($role === DROVER_ROLE_PROGRESS) {
        continue;
    }

    if ($role === DROVER_ROLE_CHILD) {
        $ordinal = $action->ordinal;
        $task = $tasks[$ordinal] ?? throw new RuntimeException('Drover returned an unknown task.');
        $taskId = FFI::string(FFI::addr($action->task_id[0]));
        $assert($taskId === $task['id'], 'Drover returned the wrong task to a PHP child.');
        $sequence = 0;
        $emit = static function (string $type, array $payload) use (
            $ffi,
            $action,
            $runId,
            $taskId,
            &$sequence,
        ): void {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            $result = $ffi->drover_emit_frame(
                $action->child_fd,
                $runId,
                $taskId,
                $sequence,
                $type,
                $encoded,
            );

            if ($result !== 0) {
                throw new RuntimeException(sprintf('Drover could not emit frame %d (%d).', $sequence, $result));
            }

            $sequence++;
        };

        $emit('task.started', ['worker' => 'php']);

        try {
            $value = ($task['callback'])($emit);
            $emit('task.finished', [
                'status' => 'passed',
                'value' => $value,
            ]);
            $ffi->drover_child_exit(0);
        } catch (Throwable $throwable) {
            $emit('task.finished', [
                'status' => 'failed',
                'failure_kind' => 'php_exception',
                'message' => $throwable->getMessage(),
            ]);
            $ffi->drover_child_exit(1);
        }
    }

    if ($role === DROVER_ROLE_RESULT) {
        $length = $ffi->drover_scheduler_last_result_len($scheduler);
        $assert($length > 0, 'Drover returned an empty result.');
        $buffer = $ffi->new(sprintf('char[%d]', $length + 1));
        $assert(
            $ffi->drover_scheduler_copy_last_result($scheduler, $buffer, $length + 1) === 0,
            'Drover could not copy a completed result.',
        );
        $result = json_decode(FFI::string($buffer, $length), true, flags: JSON_THROW_ON_ERROR);
        $results[$result['task_id']] = $result;
        $completionOrder[] = $result['task_id'];

        continue;
    }

    if ($role === DROVER_ROLE_DONE) {
        break;
    }

    if ($role === DROVER_ROLE_ERROR) {
        $message = FFI::string(FFI::addr($action->message[0]));
        throw new RuntimeException('Native Drover failed: '.$message);
    }

    throw new RuntimeException('Drover returned an unknown scheduler role.');
}

$maxActive = $ffi->drover_scheduler_max_active($scheduler);
$ffi->drover_scheduler_free($scheduler);

$assert(count($results) === count($tasks), 'Drover lost a queued task result.');
$assert($maxActive === $concurrency, 'Drover did not enforce permit-before-fork concurrency.');
$assert($prepared['items'] === ['root'], 'A child mutation escaped into the root PHP host.');
$assert(count(array_unique(array_column($results, 'pid'))) === count($tasks), 'Drover reused a child process.');

foreach (['task:slow-a', 'task:slow-b', 'task:queued-fast'] as $taskId) {
    $assert($results[$taskId]['status'] === 'passed', $taskId.' did not pass.');
    $assert(count($results[$taskId]['frames']) === 3, $taskId.' did not use streamed v1 frames.');
}

$terminalValue = static function (array $result): array {
    foreach (array_reverse($result['frames']) as $frame) {
        if ($frame['type'] === 'task.finished') {
            return $frame['payload']['value'] ?? [];
        }
    }

    throw new RuntimeException('A passing Drover result has no terminal value.');
};

$slowAValue = $terminalValue($results['task:slow-a']);
$assert(
    ($slowAValue['before'] ?? null) === ['root']
        && ($slowAValue['after'] ?? null) === ['root', 'slow-a'],
    'The first child observed the wrong prepared state: '.json_encode($slowAValue, JSON_THROW_ON_ERROR),
);
$slowBValue = $terminalValue($results['task:slow-b']);
$assert(
    ($slowBValue['before'] ?? null) === ['root']
        && ($slowBValue['after'] ?? null) === ['root', 'slow-b'],
    'The second child observed the wrong prepared state: '.json_encode($slowBValue, JSON_THROW_ON_ERROR),
);
$assert(
    $results['task:php-exception']['failure_kind'] === 'php_exception',
    'The PHP callback failure crossed the native boundary incorrectly.',
);
$assert(
    $results['task:timeout-tree']['failure_kind'] === 'timeout'
        && $results['task:timeout-tree']['timed_out'] === true,
    'Drover did not classify its monotonic timeout.',
);

usleep(1_200_000);
$assert(! file_exists($escapedPath), 'A timed-out descendant escaped Drover process-group termination.');

fwrite(STDOUT, json_encode([
    'status' => 'passed',
    'protocol_version' => $ffi->drover_protocol_version(),
    'golden_vectors' => count($validVectors) + 1,
    'submitted' => count($tasks),
    'completed' => count($results),
    'max_active' => $maxActive,
    'queue_capacity' => $queueCapacity,
    'completion_order' => $completionOrder,
    'failure_kinds' => array_values(array_filter(array_column($results, 'failure_kind'))),
    'prepared_root' => $prepared,
    'descendant_escaped' => file_exists($escapedPath),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
