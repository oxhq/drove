<?php

declare(strict_types=1);

use Drove\Native\Command;

$droveRoot = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($droveRoot): void {
    if (! str_starts_with($class, 'Drove\\')) {
        return;
    }

    $file = $droveRoot.'/src/Drove/'.str_replace('\\', '/', substr($class, strlen('Drove\\'))).'.php';

    if (is_file($file)) {
        require $file;
    }
});

require $droveRoot.'/src/Drove/Native/functions.php';

$scenario = $argv[1] ?? '';
$scenarios = [
    'missing-only' => 'missing-only.php',
    'missing-with-pass' => 'missing-with-pass.php',
    'closure-throws' => 'closure-throws.php',
];
$fixture = $scenarios[$scenario] ?? null;

if (! is_string($fixture)) {
    fwrite(STDERR, 'Unknown native dataset diagnostic scenario.'.PHP_EOL);

    exit(2);
}

$fixtureRoot = __DIR__.'/dataset-diagnostics';

exit(Command::main(['--processes=1', $fixture], $fixtureRoot));
