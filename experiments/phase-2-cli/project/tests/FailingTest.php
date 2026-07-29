<?php

declare(strict_types=1);

test('fails conventionally', function (): void {
    expect('actual')->toBe('expected');
})->group('failure');
