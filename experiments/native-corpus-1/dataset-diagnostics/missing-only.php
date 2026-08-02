<?php

declare(strict_types=1);

use function Drove\Native\test;

test('missing dataset', static function (mixed $value): void {
    unset($value);
})->with('missing');
