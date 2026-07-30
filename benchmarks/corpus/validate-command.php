<?php

declare(strict_types=1);

if ($argc < 4 || $argv[2] !== '--') {
    fwrite(STDERR, "usage: validate-command.php RUNNER -- COMMAND...\n");
    exit(2);
}

$runner = $argv[1];
$command = array_slice($argv, 3);

if (! in_array($runner, ['baseline', 'drove'], true)) {
    fwrite(STDERR, "Corpus runner must be baseline or drove.\n");
    exit(2);
}

$bridgeSelectors = count(array_filter(
    $command,
    static fn (string $argument): bool => $argument === '--pest',
));
$nativeSelectors = count(array_filter(
    $command,
    static fn (string $argument): bool => $argument === '--native',
));
$droveExecutables = count(array_filter(
    $command,
    static function (string $argument): bool {
        $normalized = str_replace('\\', '/', $argument);

        return in_array($normalized, ['bin/drove', 'vendor/bin/drove'], true);
    },
));

if ($runner === 'drove'
    && ($droveExecutables !== 1
        || $bridgeSelectors !== 1
        || $nativeSelectors !== 0)) {
    fwrite(
        STDERR,
        'Drove corpus records require one Drove executable, exactly one explicit '
            ."--pest bridge selector, and no --native selector.\n",
    );
    exit(2);
}

if ($runner === 'baseline'
    && ($droveExecutables !== 0
        || $bridgeSelectors !== 0
        || $nativeSelectors !== 0)) {
    fwrite(
        STDERR,
        "Baseline corpus records cannot select a Drove frontend.\n",
    );
    exit(2);
}
