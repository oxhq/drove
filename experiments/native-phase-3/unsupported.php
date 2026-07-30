<?php

declare(strict_types=1);

use function Drove\Native\test;

test('unsupported dependency', static function (): void {
    NativePhaseThreeHeap::$unsupportedBodyExecutions++;

    throw new RuntimeException('Unsupported source reached execution.');
})->depends('another test');
