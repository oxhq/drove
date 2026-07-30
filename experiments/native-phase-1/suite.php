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
    expect($this->base)->toEqual('42');
});

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
