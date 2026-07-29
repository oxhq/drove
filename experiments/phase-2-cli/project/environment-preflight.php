<?php

declare(strict_types=1);

use Drove\Pest\Runner;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/proof/FakeLaravelRuntime.php';

exit(Runner::main($_SERVER['argv'], __DIR__));
