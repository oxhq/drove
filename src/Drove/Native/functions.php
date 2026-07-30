<?php

declare(strict_types=1);

namespace Drove\Native;

use Closure;
use LogicException;

function test(string $description, Closure $body): void
{
    $location = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
    $path = $location['file'] ?? throw new LogicException('Drove could not locate the native test source path.');
    $line = $location['line'] ?? throw new LogicException('Drove could not locate the native test source line.');
    Declarations::current()->declareTest($description, $body, $path, $line);
}

function it(string $description, Closure $body): void
{
    $location = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
    $path = $location['file'] ?? throw new LogicException('Drove could not locate the native test source path.');
    $line = $location['line'] ?? throw new LogicException('Drove could not locate the native test source line.');
    Declarations::current()->declareTest('it '.$description, $body, $path, $line);
}

function describe(string $description, Closure $declarations): void
{
    $location = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
    $path = $location['file'] ?? throw new LogicException('Drove could not locate the native describe source path.');
    $line = $location['line'] ?? throw new LogicException('Drove could not locate the native describe source line.');
    Declarations::current()->declareDescribe($description, $declarations, $path, $line);
}

function beforeAll(Closure $hook): void
{
    $location = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
    $path = $location['file'] ?? throw new LogicException('Drove could not locate the native hook source path.');
    $line = $location['line'] ?? throw new LogicException('Drove could not locate the native hook source line.');
    Declarations::current()->declareHook('before_all', $hook, $path, $line);
}

function beforeEach(Closure $hook): void
{
    $location = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
    $path = $location['file'] ?? throw new LogicException('Drove could not locate the native hook source path.');
    $line = $location['line'] ?? throw new LogicException('Drove could not locate the native hook source line.');
    Declarations::current()->declareHook('before_each', $hook, $path, $line);
}

function afterEach(Closure $hook): void
{
    $location = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
    $path = $location['file'] ?? throw new LogicException('Drove could not locate the native hook source path.');
    $line = $location['line'] ?? throw new LogicException('Drove could not locate the native hook source line.');
    Declarations::current()->declareHook('after_each', $hook, $path, $line);
}

function afterAll(Closure $hook): void
{
    $location = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
    $path = $location['file'] ?? throw new LogicException('Drove could not locate the native hook source path.');
    $line = $location['line'] ?? throw new LogicException('Drove could not locate the native hook source line.');
    Declarations::current()->declareHook('after_all', $hook, $path, $line);
}

function expect(mixed $value): Expectation
{
    return new Expectation($value);
}
