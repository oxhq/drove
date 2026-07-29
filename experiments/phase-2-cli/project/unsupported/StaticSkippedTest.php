<?php

declare(strict_types=1);

test('does not run after a generated class skip', function (): void {
    throw new RuntimeException('generated Pest class skip body ran');
});
