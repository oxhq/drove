<?php

declare(strict_types=1);

namespace Drove\Laravel\Contracts;

use PHPUnit\Framework\TestCase;

interface DatabaseStateAdapter extends DatabaseStateProvider
{
    public function assertTestCaseSupported(TestCase $testCase): void;
}
