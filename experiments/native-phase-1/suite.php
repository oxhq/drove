<?php

declare(strict_types=1);

use function Drove\Native\afterAll;
use function Drove\Native\afterEach;
use function Drove\Native\beforeAll;
use function Drove\Native\beforeEach;
use function Drove\Native\describe;
use function Drove\Native\expect;
use function Drove\Native\it;
use function Drove\Native\test;

beforeAll(function (): void {
    expect(true)->toBe(true);
});

beforeEach(function (): void {
    expect(isset($this->leaked))->toBe(false);
    $this->leaked = true;
    $this->base = 42;
    $this->items = [];
    $this->items[] = 'fixture';

    if (getenv('DROVE_NATIVE_SCHEDULER') === 'pcntl') {
        expect(NativePhaseOneHeap::$value)->toBe(41);
        NativePhaseOneHeap::$value = 42;
    }
});

afterEach(function (): void {
    expect($this->leaked)->toBe(true);
    expect(isset($this->base))->toBe(true);
    expect($this->items)->toEqual(['fixture']);

    if (getenv('DROVE_NATIVE_SCHEDULER') === 'pcntl') {
        expect(NativePhaseOneHeap::$value)->toBe(42);
    }
});

afterAll(function (): void {
    expect(true)->toBe(true);
});

test('accepts loose equality', function (): void {
    expect($this->base)->toEqual('42')
        ->and(null)->toBeNull()
        ->and(true)->toBeTrue()
        ->and(false)->toBeFalse()
        ->and(new RuntimeException)->toBeInstanceOf(RuntimeException::class)
        ->and(['alpha', 'beta'])->toContain('alpha', 'beta')->toHaveCount(2)->toHaveKey(0, 'alpha')->toHaveKeys([0, 1])
        ->and(['nested' => ['value' => 42]])->toHaveKey('nested.value', 42)->toHaveKeys(['nested' => ['value']])
        ->and(['present' => true])->not()->toHaveKeys(['missing'])
        ->and('drove native')->toContain('native')->not()->toContain('bridge')
        ->and(static fn (): never => throw new RuntimeException('expected class message'))
        ->toThrow(RuntimeException::class, 'class message')
        ->and(static fn (): never => throw new RuntimeException('expected callback message'))
        ->toThrow(static function (RuntimeException $exception): void {
            expect($exception->getMessage())->toBe('expected callback message');
        })
        ->and(static fn (): never => throw new RuntimeException('expected object message'))
        ->toThrow(new RuntimeException('expected object message'))
        ->and(static fn (): never => throw new RuntimeException('expected message only'))
        ->toThrow('message only')
        ->and(static fn (): null => null)->not()->toThrow(RuntimeException::class);
});

test('accepts expected exception', static function (): never {
    throw new RuntimeException('native expected exception', 1);
})->throws(RuntimeException::class, 'expected exception', 1);

test('accepts expected exception dataset', static function (string $message): never {
    throw new RuntimeException($message, 1);
})->with([
    'first' => ['native expected exception first'],
    'second' => ['native expected exception second'],
])->throws(1);

test('rejects expected exception class mismatch', static function (): never {
    throw new RuntimeException('native expected exception', 1);
})->throws(LogicException::class);

test('rejects expected exception message mismatch', static function (): never {
    throw new RuntimeException('native expected exception', 1);
})->throws(RuntimeException::class, 'different message');

test('rejects expected exception code mismatch', static function (): never {
    throw new RuntimeException('native expected exception', 1);
})->throws(RuntimeException::class, code: 2);

foreach (range(1, 4) as $case) {
    test('overlaps case '.$case, function () use ($case): void {
        usleep(150_000);
        expect($this->items[0])->toBe('fixture');
        expect($case)->toBe($case);
    });
}

describe('nested scope', function (): void {
    beforeAll(function (): void {
        expect(true)->toBe(true);
    });

    beforeEach(function (): void {
        $this->answer = $this->base;
    });

    afterEach(function (): void {
        expect($this->answer)->toBe(42);
    });

    afterAll(function (): void {
        expect(true)->toBe(true);
    });

    it('passes strict identity', function (): void {
        expect($this->answer)->toBe(42);
    });

    test('reports native assertion failure', function (): void {
        expect($this->answer)->toBe('42');
    });
});
