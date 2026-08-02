<?php

declare(strict_types=1);

use function Drove\Native\dataset;
use function Drove\Native\test;

dataset('explodes', static fn (): never => throw new RuntimeException('boom from dataset'));

test('dataset closure error', static function (mixed $value): void {
    unset($value);
})->with('explodes');
