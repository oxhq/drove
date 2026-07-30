<?php

declare(strict_types=1);

use function Drove\Native\afterAll;
use function Drove\Native\afterEach;
use function Drove\Native\beforeAll;
use function Drove\Native\beforeEach;
use function Drove\Native\dataset;
use function Drove\Native\describe;
use function Drove\Native\expect;
use function Drove\Native\test;

$fixture = getenv('DROVE_NATIVE_FIXTURE') ?: 'conformance';

if ($fixture === 'stress') {
    foreach (range(1, 30) as $shard) {
        $firstCase = intdiv(($shard - 1) * 10_000, 30) + 1;
        $lastCase = intdiv($shard * 10_000, 30);

        describe(sprintf('stress shard %02d', $shard), static function () use ($firstCase, $lastCase): void {
            foreach (range($firstCase, $lastCase) as $case) {
                test(sprintf('stress case %05d', $case), function () use ($case): void {
                    if (NativePhaseThreeHeap::$value !== 41) {
                        throw new RuntimeException('A stress case observed mutated sibling state.');
                    }

                    NativePhaseThreeHeap::$value = 42;
                    $this->defer(static function (): void {
                        if (NativePhaseThreeHeap::$value !== 42) {
                            throw new RuntimeException('A stress cleanup observed unexpected state.');
                        }

                        NativePhaseThreeHeap::$value = 41;
                    });

                    if ($case <= 60) {
                        usleep(100_000);
                    }

                    expect($case)->toBe($case);
                })->group('stress');
            }
        });
    }

    return;
}

if ($fixture !== 'conformance') {
    throw new RuntimeException('Unknown native Phase 3 fixture '.$fixture.'.');
}

$recordScopeHook = static function (string $hook): void {
    $path = getenv('DROVE_NATIVE_HOOK_SENTINEL');

    if (! is_string($path) || $path === '') {
        return;
    }

    if (file_put_contents($path, $hook.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Could not record native scope-hook execution.');
    }
};

beforeAll(static function () use ($recordScopeHook): void {
    $recordScopeHook('root.beforeAll');
});

beforeEach(function (): void {
    if (isset($this->leaked)) {
        throw new RuntimeException('A conformance case observed leaked context state.');
    }

    $this->leaked = true;
    $this->fixture = 42;
});

afterEach(function (): void {
    if ($this->leaked !== true || $this->fixture !== 42) {
        throw new RuntimeException('A conformance case lost its context during teardown.');
    }
});

afterAll(static function () use ($recordScopeHook): void {
    $recordScopeHook('root.afterAll');
});

foreach (range(1, 32) as $case) {
    test('parallel case '.$case, function () use ($case): void {
        if (NativePhaseThreeHeap::$value !== 41) {
            throw new RuntimeException('A parallel case observed mutated sibling state.');
        }

        NativePhaseThreeHeap::$value = 42;
        $this->defer(static function (): void {
            if (NativePhaseThreeHeap::$value !== 42) {
                throw new RuntimeException('A conformance cleanup observed unexpected state.');
            }

            NativePhaseThreeHeap::$value = 41;
        });
        usleep(75_000);
        expect($case)->toBe($case);
    })->group('parallel', 'fast');
}

test('inline dataset', function (int $actual, int $expected): void {
    expect($actual)->toEqual($expected);
})->with([
    [0, '0'],
    'one' => [1, '1'],
    'two' => [2, '2'],
    'three' => [3, '3'],
])->group('dataset', 'fast');

dataset('named sums', [
    'small' => [1, 2, 3],
    'medium' => [2, 3, 5],
    'large' => [10, 20, 30],
    'negative' => [-2, 1, -1],
]);

test('named dataset', function (int $left, int $right, int $sum): void {
    expect($left + $right)->toBe($sum);
})->with('named sums')->group('dataset');

dataset('lazy lengths', static fn (): array => [
    'short' => ['drove', 5],
    'long' => ['prepared-state', 14],
]);

test('lazy named dataset', function (string $value, int $length): void {
    expect(strlen($value))->toBe($length);
})->with('lazy lengths')->group('dataset');

test('captures structured stdout', function (): void {
    echo "native-phase-3:stdout\n";
    expect(true)->toBe(true);
})->group('output');

test('skips before its body', static function (): never {
    throw new RuntimeException('A skipped native body executed.');
})->skip('conformance skip')->group('status');

test('records todo before its body', static function (): never {
    throw new RuntimeException('A todo native body executed.');
})->todo('conformance todo')->group('status');

test('preserves body and cleanup failures', function (): void {
    $this->defer(static function (): never {
        throw new RuntimeException('cleanup failure sentinel');
    });

    expect(false)->toBe(true);
})->group('failure');

test('enforces a per-test timeout', static function (): void {
    usleep(250_000);
})->timeout(25)->group('timeout');

describe('nested lifecycle', function () use ($recordScopeHook): void {
    beforeAll(static function () use ($recordScopeHook): void {
        $recordScopeHook('nested.beforeAll');
    });

    beforeEach(function (): void {
        $this->nested = $this->fixture;
    });

    afterEach(function (): void {
        if ($this->nested !== 42) {
            throw new RuntimeException('Nested teardown observed unexpected state.');
        }
    });

    afterAll(static function () use ($recordScopeHook): void {
        $recordScopeHook('nested.afterAll');
    });

    test('uses strict identity', function (): void {
        $this->cleanupOrder = [];
        $this->defer(function (): void {
            if ($this->cleanupOrder !== ['second']) {
                throw new RuntimeException('Native deferred cleanup did not run in LIFO order.');
            }

            $this->cleanupOrder[] = 'first';
        });
        $this->defer(function (): void {
            if ($this->cleanupOrder !== []) {
                throw new RuntimeException('Native deferred cleanup started from dirty state.');
            }

            $this->cleanupOrder[] = 'second';
        });
        expect($this->nested)->toBe(42);
    })->group('nested');

    test('uses loose equality', function (): void {
        expect($this->nested)->toEqual('42');
    })->group('nested');
});
