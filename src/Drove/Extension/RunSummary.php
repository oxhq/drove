<?php

declare(strict_types=1);

namespace Drove\Extension;

use InvalidArgumentException;

final readonly class RunSummary
{
    /** @var array<string, int> */
    public array $testCounts;

    /** @param array<array-key, mixed> $testCounts */
    public function __construct(
        public string $status,
        public int $exitCode,
        array $testCounts,
    ) {
        if (trim($status) === '') {
            throw new InvalidArgumentException('A Drove extension run summary requires a status.');
        }

        if ($exitCode < 0 || $exitCode > 255) {
            throw new InvalidArgumentException('A Drove extension run exit code must be between 0 and 255.');
        }

        foreach ($testCounts as $name => $count) {
            if (! is_string($name) || trim($name) === '' || ! is_int($count) || $count < 0) {
                throw new InvalidArgumentException(
                    'Drove extension test counts must map non-empty status names to non-negative integers.',
                );
            }
        }

        ksort($testCounts, SORT_STRING);
        $this->testCounts = $testCounts;
    }
}
