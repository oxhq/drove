<?php

declare(strict_types=1);

namespace Drove\PhaseSixFixtures;

use PHPUnit\Framework\TestCase;

require \dirname(__DIR__, 2).'/vendor/autoload.php';

final class LaravelApplication
{
    //
}

abstract class OrchestraTestCase extends TestCase
{
    //
}

if (! \class_exists('Illuminate\\Foundation\\Application')) {
    \class_alias(LaravelApplication::class, 'Illuminate\\Foundation\\Application');
}

if (! \class_exists('Orchestra\\Testbench\\TestCase')) {
    \class_alias(OrchestraTestCase::class, 'Orchestra\\Testbench\\TestCase');
}

require_once \dirname(__DIR__, 2).'/packages/drove-laravel/src/TestbenchBridge.php';

$callback = ['Drove\\Laravel\\TestbenchBridge', 'available'];

exit(\is_callable($callback) && $callback() ? 0 : 1);
