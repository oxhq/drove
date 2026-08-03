<?php

declare(strict_types=1);

use Drove\Compatibility\Registry;
use Drove\Migration\Migrator;
use Drove\Migration\Scanner;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/profile.php';

$checkout = $argv[1] ?? null;

if (! is_string($checkout) || ! is_dir($checkout.'/.git')) {
    fwrite(STDERR, "Usage: diagnose.php /pinned/filament\n");
    exit(2);
}

$paths = preg_split('/\R/', trim((string) shell_exec(sprintf(
    'git -C %s ls-tree -r --name-only %s -- tests/src/Support',
    escapeshellarg($checkout),
    escapeshellarg('e9348b2e3792088ee877068116b6c1e1559a7df8'),
)))) ?: [];
$paths = array_values(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '.php')));
sort($paths, SORT_STRING);
$migrator = new Migrator(new Scanner(Registry::load(
    dirname(__DIR__, 2).'/resources/drove-bridge-compatibility.json',
)));
$options = nativeFilamentOptions();
$blocked = [];
$codemods = [];

foreach ($paths as $path) {
    $source = shell_exec(sprintf(
        'git -C %s show %s',
        escapeshellarg($checkout),
        escapeshellarg('e9348b2e3792088ee877068116b6c1e1559a7df8:'.$path),
    ));

    if (! is_string($source)) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_SOURCE_READ:'.$path);
    }

    $result = $migrator->migrate(nativeFilamentProfileSource($source, $path), $path, $options);
    $second = $migrator->migrate(nativeFilamentProfileSource($result->source, $path), $path, $options);

    if ($second->blockers !== [] || $second->changed() || $second->source !== $result->source) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_MIGRATION_NOT_IDEMPOTENT:'.$path);
    }

    $codemods[$path] = $result->applied;

    if ($result->blockers !== []) {
        $blocked[$path] = array_map(
            static fn ($finding): array => [
                'diagnostic' => $finding->diagnostic,
                'surface' => $finding->surface,
                'construct' => $finding->construct,
                'line' => $finding->line,
            ],
            $result->blockers,
        );
    }
}

echo json_encode([
    'files' => count($paths),
    'ready' => count($paths) - count($blocked),
    'blocked' => $blocked,
    'codemods' => $codemods,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
