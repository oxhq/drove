<?php

declare(strict_types=1);

if ($argc !== 6) {
    fwrite(
        STDERR,
        "usage: source-identity.php CORPUS SOURCE_ROOT SELECTION_FILE CONFIGURATION OUTPUT\n",
    );
    exit(2);
}

[, $corpusId, $sourceRootArgument, $selectionFile, $configurationArgument, $output] = $argv;
$sourceRoot = realpath($sourceRootArgument);
$configuration = realpath($configurationArgument);

if (! is_string($sourceRoot)
    || (! is_dir("$sourceRoot/.git") && ! is_file("$sourceRoot/.git"))) {
    throw new RuntimeException('CORPUS_SOURCE_ROOT must name a Git checkout.');
}

if (! is_string($configuration) || ! is_file($configuration)) {
    throw new RuntimeException('CORPUS_CONFIGURATION must name a readable file.');
}

$configurationPath = relativePath($configuration, $sourceRoot);
$manifestPath = getenv('CORPUS_MANIFEST') ?: __DIR__.'/manifest.json';
$manifest = decodeFile($manifestPath);
$corpus = null;

foreach ($manifest['corpora'] ?? [] as $candidate) {
    if (($candidate['id'] ?? null) === $corpusId) {
        $corpus = $candidate;

        break;
    }
}

if (! is_array($corpus)) {
    throw new RuntimeException("Unknown corpus $corpusId.");
}

if ($configurationPath !== ($corpus['execution_configuration'] ?? null)) {
    throw new RuntimeException(
        "$corpusId configuration mismatch: expected "
        .json_encode($corpus['execution_configuration'] ?? null)
        .", found $configurationPath.",
    );
}

$configurationHash = fileHash($configuration);

if ($configurationHash !== ($corpus['execution_configuration_sha256'] ?? null)) {
    throw new RuntimeException("$corpusId configuration identity drifted.");
}

$commit = git($sourceRoot, ['rev-parse', 'HEAD']);
$tree = git($sourceRoot, ['rev-parse', 'HEAD^{tree}']);

if ($commit !== ($corpus['commit'] ?? null)
    || preg_match('/^[0-9a-f]{40}$/D', $tree) !== 1) {
    throw new RuntimeException("$corpusId source checkout is not the pinned commit.");
}

$trackedChanges = git(
    $sourceRoot,
    ['status', '--porcelain', '--untracked-files=no'],
    allowEmpty: true,
);
$overlays = [];

foreach (preg_split('/\R/', $trackedChanges) ?: [] as $line) {
    if ($line === '') {
        continue;
    }

    $path = normalizeGitStatusPath(substr($line, 3));

    if (! in_array($path, ['composer.json', 'composer.lock'], true)) {
        throw new RuntimeException(
            "$corpusId source checkout has an undeclared tracked change: $path.",
        );
    }

    $absolute = "$sourceRoot/$path";

    if (! is_file($absolute)) {
        throw new RuntimeException("$corpusId dependency overlay removed $path.");
    }

    $overlays[] = [
        'path' => $path,
        'sha256' => fileHash($absolute),
    ];
}

usort(
    $overlays,
    static fn (array $left, array $right): int => $left['path'] <=> $right['path'],
);
$overlayContract = $corpus['dependency_overlay'] ?? null;
$composerJsonHash = fileHash("$sourceRoot/composer.json");

if (! is_array($overlayContract)
    || $composerJsonHash !== ($overlayContract['composer_json_sha256'] ?? null)
    || array_column($overlays, 'path') !== ($overlayContract['tracked_paths'] ?? null)) {
    throw new RuntimeException("$corpusId dependency overlay identity drifted.");
}

$selectionContents = file_get_contents($selectionFile);

if (! is_string($selectionContents)) {
    throw new RuntimeException('CORPUS_SELECTION_FILE is unreadable.');
}

$paths = preg_split('/\R/', $selectionContents) ?: [];

while ($paths !== [] && end($paths) === '') {
    array_pop($paths);
}

if ($paths === []) {
    throw new RuntimeException('Corpus selection is empty.');
}

$sorted = $paths;
sort($sorted, SORT_STRING);

if ($paths !== $sorted || count(array_unique($paths)) !== count($paths)) {
    throw new RuntimeException('Corpus selection must be sorted and unique.');
}

$selectionEntries = [];

foreach ($paths as $path) {
    if ($path === ''
        || str_contains($path, '\\')
        || str_starts_with($path, '/')
        || in_array('..', explode('/', $path), true)) {
        throw new RuntimeException("Corpus selection contains an invalid path: $path.");
    }

    $absolute = realpath("$sourceRoot/$path");

    if (! is_string($absolute)
        || ! is_file($absolute)
        || relativePath($absolute, $sourceRoot) !== $path) {
        throw new RuntimeException("Corpus selection path is not a source file: $path.");
    }

    git($sourceRoot, ['ls-files', '--error-unmatch', '--', $path]);
    $selectionEntries[] = [
        'path' => $path,
        'sha256' => fileHash($absolute),
    ];
}

$dependencyLock = __DIR__.'/'.($corpus['dependency_lock']['path'] ?? '');
$dependencyLockHash = fileHash($dependencyLock);
$sourceLockHash = fileHash("$sourceRoot/composer.lock");

if ($dependencyLockHash !== ($corpus['dependency_lock']['sha256'] ?? null)
    || $sourceLockHash !== $dependencyLockHash) {
    throw new RuntimeException("$corpusId dependency lock identity drifted.");
}

$selectionHash = hash(
    'sha256',
    encodeCanonical($selectionEntries),
);
$identity = [
    'schema_version' => 1,
    'corpus' => $corpusId,
    'source_commit' => $commit,
    'source_tree' => $tree,
    'tracked_source_clean' => true,
    'tracked_dependency_overlays' => $overlays,
    'composer_json_sha256' => $composerJsonHash,
    'selection' => [
        'files' => count($selectionEntries),
        'sha256' => $selectionHash,
    ],
    'configuration' => [
        'path' => $configurationPath,
        'sha256' => $configurationHash,
    ],
    'dependency_lock_sha256' => $dependencyLockHash,
];
$identity['source_sha256'] = hash('sha256', encodeCanonical($identity));
$encoded = json_encode(
    $identity,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;

if (file_put_contents($output, $encoded, LOCK_EX) !== strlen($encoded)) {
    throw new RuntimeException("Cannot write source identity to $output.");
}

/**
 * @param  list<string>  $arguments
 */
function git(string $root, array $arguments, bool $allowEmpty = false): string
{
    $command = ['git', '-C', $root, ...$arguments];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        options: ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Cannot inspect corpus Git identity.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0 || ! is_string($stdout) || ! is_string($stderr)) {
        throw new RuntimeException(
            'Corpus Git identity check failed: '.trim((string) $stderr),
        );
    }

    $value = $allowEmpty
        ? rtrim($stdout, "\r\n")
        : trim($stdout);

    if (! $allowEmpty && $value === '') {
        throw new RuntimeException('Corpus Git identity check returned no value.');
    }

    return $value;
}

function normalizeGitStatusPath(string $path): string
{
    if (str_contains($path, ' -> ')) {
        [, $path] = explode(' -> ', $path, 2);
    }

    if (str_starts_with($path, '"')) {
        $decoded = json_decode($path, true);

        if (! is_string($decoded)) {
            throw new RuntimeException('Corpus Git status contains an invalid path.');
        }

        $path = $decoded;
    }

    return str_replace('\\', '/', $path);
}

function relativePath(string $path, string $root): string
{
    $normalizedPath = str_replace('\\', '/', $path);
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    $prefix = "$normalizedRoot/";

    if (! str_starts_with($normalizedPath, $prefix)) {
        throw new RuntimeException('Corpus identity path escapes its source root.');
    }

    return substr($normalizedPath, strlen($prefix));
}

function fileHash(string $path): string
{
    $hash = is_file($path) ? hash_file('sha256', $path) : false;

    if (! is_string($hash)) {
        throw new RuntimeException("Cannot hash corpus identity input $path.");
    }

    return $hash;
}

/**
 * @return array<string, mixed>
 */
function decodeFile(string $path): array
{
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException("Cannot read $path.");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("$path does not contain a JSON object.");
    }

    return $decoded;
}

function encodeCanonical(mixed $value): string
{
    return json_encode(
        canonical($value),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
}

function canonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(canonical(...), $value);
    }

    ksort($value, SORT_STRING);

    foreach ($value as &$item) {
        $item = canonical($item);
    }

    return $value;
}
