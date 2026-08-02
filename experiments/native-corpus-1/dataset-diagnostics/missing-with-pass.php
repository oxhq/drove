<?php

declare(strict_types=1);

use function Drove\Native\test;

test('passing peer', static function (): void {
    // Planning the peer alongside the invalid dataset exercises the mixed-suite path.
});

test('missing dataset', static function (mixed $value): void {
    unset($value);
})->with('missing');
