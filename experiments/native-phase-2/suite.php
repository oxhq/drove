<?php

declare(strict_types=1);

use function Drove\Native\test;

foreach (range(1, 32) as $case) {
    test('extension case '.$case, function () use ($case): void {
        if (isset($this->leaked)) {
            throw new RuntimeException('Native test context leaked between cases.');
        }

        $this->leaked = $case;

        if ($this->extensionValue('proof/alpha', 'label') !== 'blue') {
            throw new RuntimeException('The typed extension context value diverged.');
        }

        $this->assertWith('proof/alpha', 'even', $case * 2);
        usleep(100_000);
    });
}
