<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public static ?Application $preparedApplication = null;

    /** @var list<int> */
    public static array $applicationRequestPids = [];

    public function createApplication(): Application
    {
        if (! self::$preparedApplication instanceof Application) {
            throw new RuntimeException('The prepared Laravel application is unavailable.');
        }

        self::$applicationRequestPids[] = getmypid();

        return self::$preparedApplication;
    }
}
