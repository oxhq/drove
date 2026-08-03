<?php

declare(strict_types=1);
use Drove\Compatibility\Registry;
use Drove\Migration\ClassMigrator;
use Drove\Migration\CodemodOptions;
use Drove\Migration\Finding;
use Drove\Migration\LaravelMigrator;
use Drove\Migration\Migrator;
use Drove\Migration\Scanner;
use Drove\Native\Surface\SupportedSurface;
use DroveNativePestCorpus\PestCorpusCodemod;

$repo = dirname(__DIR__, 2);
$arguments = array_slice($_SERVER['argv'] ?? [], 1);

if (count($arguments) !== 4) {
    fwrite(
        STDERR,
        "usage: php benchmarks/corpus/generate-native-surface-inventory.php PEST_CHECKOUT INVOICESHELF_CHECKOUT LIVEWIRE_CHECKOUT FILAMENT_CHECKOUT\n",
    );
    exit(2);
}

$roots = [];

foreach (['pest', 'invoiceshelf', 'livewire', 'filament'] as $index => $id) {
    $root = realpath($arguments[$index]);

    if (! is_string($root) || ! is_dir($root)) {
        throw new RuntimeException("$id checkout is not a Git worktree");
    }

    $roots[$id] = str_replace('\\', '/', $root);
}

$shell = shellPath();

require $repo.'/vendor/autoload.php';
require $repo.'/experiments/native-corpus-1/PestCorpusCodemod.php';
require $repo.'/experiments/native-filament-corpus/profile.php';

$manifest = decode($repo.'/benchmarks/corpus/manifest.json');
$manifestById = [];

foreach ($manifest['corpora'] as $corpus) {
    $manifestById[$corpus['id']] = $corpus;
}

$inputs = [
    'pest' => [
        'root' => $roots['pest'],
        'selector' => 'pest',
        'configuration' => 'phpunit.xml',
        'support_paths' => ['tests/Pest.php'],
    ],
    'invoiceshelf' => [
        'root' => $roots['invoiceshelf'],
        'selector' => 'invoiceshelf',
        'configuration' => 'phpunit.xml',
        'support_paths' => ['tests/Pest.php', 'tests/TestCase.php'],
    ],
    'livewire' => [
        'root' => $roots['livewire'],
        'selector' => 'livewire',
        'configuration' => 'phpunit.xml.dist',
        'support_paths' => ['tests/TestCase.php'],
    ],
    'filament' => [
        'root' => $roots['filament'],
        'selector' => 'filament',
        'configuration' => 'phpunit.xml.dist',
        'support_paths' => ['tests/Pest.php', 'tests/src/TestCase.php'],
    ],
];

$surfaceCatalog = SupportedSurface::load(
    $repo.'/src/Drove/Native/Surface/supported-surface.json',
);
$supported = $surfaceCatalog->manifest();
$supportedExpectationMethods = array_fill_keys(
    array_map('strtolower', $supported['methods']['expectation']),
    true,
);
$supportedDeclarationMethods = array_fill_keys(
    array_map('strtolower', $supported['methods']['declaration']),
    true,
);
$compatibilityRegistry = Registry::load(
    $repo.'/resources/drove-bridge-compatibility.json',
);
$portableMigrator = new Migrator(
    new Scanner($compatibilityRegistry),
);
$classMigrator = new ClassMigrator;
$laravelMigrator = new LaravelMigrator($portableMigrator);
$pestCodemodConfig = require $repo.'/experiments/native-corpus-1/codemod-options.php';
$invoiceShelfCodemodConfig = require $repo.'/experiments/native-invoiceshelf-corpus/codemod-options.php';
$livewireClassProfilePath = 'experiments/phase-3-laravel/native-package/livewire-class-profile.php';
$livewireClassProfile = validatedClassMigrationProfile(require $repo.'/'.$livewireClassProfilePath);
$filamentProfilePath = 'experiments/native-filament-corpus/profile.php';

if (! is_array($pestCodemodConfig)
    || array_keys($pestCodemodConfig) !== ['matchers', 'trustedFunctions', 'imports', 'subjects']
    || ! is_array($pestCodemodConfig['matchers'])
    || ! is_array($pestCodemodConfig['trustedFunctions'])
    || ! is_array($pestCodemodConfig['imports'])
    || ! is_array($pestCodemodConfig['subjects'])) {
    throw new RuntimeException('The pinned Pest codemod configuration is invalid');
}

if (! is_array($invoiceShelfCodemodConfig)
    || array_keys($invoiceShelfCodemodConfig) !== ['trustedFunctions', 'imports', 'subjects']
    || ! is_array($invoiceShelfCodemodConfig['trustedFunctions'])
    || ! is_array($invoiceShelfCodemodConfig['imports'])
    || ! is_array($invoiceShelfCodemodConfig['subjects'])) {
    throw new RuntimeException('The pinned InvoiceShelf codemod configuration is invalid');
}

$codemodOptions = [
    'pest' => new CodemodOptions(
        matchers: $pestCodemodConfig['matchers'],
        trustedFunctions: $pestCodemodConfig['trustedFunctions'],
        imports: $pestCodemodConfig['imports'],
        subjects: $pestCodemodConfig['subjects'],
    ),
    'invoiceshelf' => new CodemodOptions(
        trustedFunctions: $invoiceShelfCodemodConfig['trustedFunctions'],
        imports: $invoiceShelfCodemodConfig['imports'],
        subjects: $invoiceShelfCodemodConfig['subjects'],
    ),
    'livewire' => new CodemodOptions,
    'filament' => nativeFilamentOptions(),
];
$inventoryCorpora = [];

foreach ($inputs as $id => $input) {
    $contract = $manifestById[$id];

    if (git($input['root'], 'rev-parse', 'HEAD') !== $contract['commit']) {
        throw new RuntimeException("$id checkout commit does not match the pinned manifest");
    }

    $files = selection($shell, $repo, $input['selector'], $input['root']);
    $expectedFiles = (int) $contract['selection']['files'];

    if (count($files) !== $expectedFiles) {
        throw new RuntimeException("$id selection drifted: expected $expectedFiles files, found ".count($files));
    }

    $surfaces = [];
    $selectionEntries = [];

    foreach ($files as $path) {
        $source = gitBlobContents($input['root'], $path);
        $selectionEntries[] = [
            'path' => $path,
            'sha256' => hash('sha256', $source),
        ];
        scanSource(
            $id,
            $path,
            $source,
            'selection',
            $surfaces,
            $supportedExpectationMethods,
            $supportedDeclarationMethods,
        );
    }

    $supportEntries = [];

    foreach ($input['support_paths'] as $path) {
        $source = gitBlobContents($input['root'], $path);
        $supportEntries[] = [
            'path' => $path,
            'sha256' => hash('sha256', $source),
        ];
        scanSource(
            $id,
            $path,
            $source,
            'support',
            $surfaces,
            $supportedExpectationMethods,
            $supportedDeclarationMethods,
        );
    }

    addRuntimeSurfaces($id, $input['root'], $files, $surfaces);
    ksort($surfaces, SORT_STRING);
    $surfaceRows = [];
    $statusCounts = ['native-supported' => 0, 'pending' => 0, 'rejected' => 0];

    foreach ($surfaces as $surfaceId => $surface) {
        $surface['selected_files'] = array_keys($surface['selected_files']);
        $surface['support_files'] = array_keys($surface['support_files']);
        sort($surface['selected_files'], SORT_STRING);
        sort($surface['support_files'], SORT_STRING);
        $surface['file_count'] = count(array_unique([
            ...$surface['selected_files'],
            ...$surface['support_files'],
        ]));
        $surface['selected_file_count'] = count($surface['selected_files']);
        $surface['support_file_count'] = count($surface['support_files']);
        $surface['examples'] = array_slice($surface['examples'], 0, 5);
        $statusCounts[$surface['status']]++;
        $surfaceRows[] = ['id' => $surfaceId, ...$surface];
    }

    $cases = $id === 'filament'
        ? (int) $contract['selection']['total_cases']
        : (int) $contract['selection']['cases'];
    $assertions = $id === 'filament'
        ? (int) $contract['selection']['total_assertions']
        : (int) $contract['baseline']['assertions'];
    $migrationReadiness = migrationReadiness(
        $id,
        $input['root'],
        $files,
        $portableMigrator,
        $classMigrator,
        $laravelMigrator,
        $codemodOptions[$id],
        $id === 'livewire' ? $livewireClassProfile : [],
    );

    $inventoryCorpora[] = [
        'id' => $id,
        'repository' => $contract['repository'],
        'commit' => $contract['commit'],
        'selector' => $contract['selection']['selector'],
        'selection' => [
            'file_count' => count($selectionEntries),
            'case_count' => $cases,
            'assertion_count' => $assertions,
            'sha256' => hash('sha256', encodeCanonical($selectionEntries)),
            'paths' => array_column($selectionEntries, 'path'),
        ],
        'runtime_inputs' => [
            'source_configuration' => [
                'path' => $input['configuration'],
                'sha256' => gitBlobHash($input['root'], $input['configuration']),
            ],
            'legacy_bridge_configuration' => [
                'path' => $contract['execution_configuration'],
                'sha256' => $contract['execution_configuration_sha256'],
                'status' => 'remove-after-native-parity',
            ],
            'support_files' => $supportEntries,
        ],
        'surface_summary' => [
            'construct_count' => count($surfaceRows),
            'by_status' => $statusCounts,
        ],
        'migration_readiness' => $migrationReadiness,
        'surfaces' => $surfaceRows,
    ];
}

$rawSourceMigrationSurfaces = rawSourceMigrationSurfaces($inventoryCorpora);

foreach ($inventoryCorpora as &$corpus) {
    foreach ($corpus['surfaces'] as &$surface) {
        unset($surface['selected_files'], $surface['support_files'], $surface['description']);
        $surface['examples'] = array_map(
            static fn (array $example): string => sprintf(
                '%s:%s:%d',
                $example['source_kind'],
                $example['path'],
                $example['line'],
            ),
            $surface['examples'],
        );

        if ($surface['native_target'] === null) {
            unset($surface['native_target']);
        }
    }
    unset($surface);
}
unset($corpus);

$inventory = [
    'schema_version' => 1,
    'scope' => 'Pinned curated selections only; this is not a whole-suite support claim.',
    'status_definitions' => [
        'native-supported' => 'A Drove-native declaration, assertion, class, or runtime equivalent exists in this revision.',
        'pending' => 'The pinned selection uses the construct and still needs native migration or corpus proof.',
        'rejected' => 'The current native surface deliberately rejects the construct; keeping the selected path creates an explicit completion conflict.',
    ],
    'migration_readiness_definition' => 'Static source readiness after the corpus-specific Drove migrator applies its deterministic codemods. A blocker-free file has no Scanner/Migrator blocker after that pass; this is not execution-parity evidence.',
    'source_contract' => [
        'manifest_schema_version' => $manifest['schema_version'],
        'manifest_path' => 'benchmarks/corpus/manifest.json',
        'manifest_sha256' => hashFile($repo.'/benchmarks/corpus/manifest.json'),
        'generator_path' => 'benchmarks/corpus/generate-native-surface-inventory.php',
        'generator_sha256' => hashFile(__FILE__),
        'verifier_path' => 'benchmarks/corpus/verify-native-surface-inventory.php',
        'verifier_sha256' => hashFile($repo.'/benchmarks/corpus/verify-native-surface-inventory.php'),
        'selector_path' => 'benchmarks/corpus/select.sh',
        'selector_sha256' => hashFile($repo.'/benchmarks/corpus/select.sh'),
        'native_surface_path' => 'src/Drove/Native/Surface/supported-surface.json',
        'native_surface_sha256' => hashFile($repo.'/src/Drove/Native/Surface/supported-surface.json'),
        'native_surface_canonical_sha256' => $surfaceCatalog->hash(),
        'livewire_class_profile_path' => $livewireClassProfilePath,
        'livewire_class_profile_sha256' => hashFile($repo.'/'.$livewireClassProfilePath),
        'filament_profile_path' => $filamentProfilePath,
        'filament_profile_sha256' => hashFile($repo.'/'.$filamentProfilePath),
        'migration_analyzers' => array_map(
            static fn (string $path): array => [
                'path' => $path,
                'sha256' => hashFile($repo.'/'.$path),
            ],
            analyzerSourcePaths($repo),
        ),
    ],
    'corpora' => $inventoryCorpora,
];

$inventory['raw_source_migration_surfaces_definition'] = 'Raw pinned-source constructs not marked native-supported before corpus-specific codemods; non-zero counts are migration input, not failed native execution gates.';
$inventory['raw_source_migration_surfaces'] = $rawSourceMigrationSurfaces;
$encoded = json_encode(
    $inventory,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
).PHP_EOL;
$output = getenv('DROVE_NATIVE_INVENTORY_OUTPUT');
$output = is_string($output) && $output !== ''
    ? $output
    : $repo.'/benchmarks/corpus/native-surface-inventory.json';

if (file_put_contents($output, $encoded, LOCK_EX) !== strlen($encoded)) {
    throw new RuntimeException("Cannot write $output");
}

echo "generated $output", PHP_EOL;
echo command([
    PHP_BINARY,
    $repo.'/benchmarks/corpus/verify-native-surface-inventory.php',
    $output,
], $repo);

/** @return array<string, mixed> */
function decode(string $path): array
{
    $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($value)) {
        throw new RuntimeException("$path is not a JSON object");
    }

    return $value;
}

/** @return array<string, mixed> */
function validatedClassMigrationProfile(mixed $profile): array
{
    $sections = [
        'method_receivers',
        'property_replacements',
        'parent_receivers',
        'fluent_assertions',
    ];

    if (! is_array($profile) || array_keys($profile) !== $sections) {
        throw new RuntimeException('The pinned Livewire class migration profile has an invalid shape');
    }

    foreach (array_slice($sections, 0, 3) as $section) {
        $entries = $profile[$section];

        if (! is_array($entries)) {
            throw new RuntimeException("The pinned Livewire class migration profile $section is invalid");
        }

        foreach ($entries as $method => $receiver) {
            if (! is_string($method) || $method === ''
                || ! is_string($receiver) || trim($receiver) === '') {
                throw new RuntimeException("The pinned Livewire class migration profile $section is invalid");
            }
        }
    }

    $fluent = $profile['fluent_assertions'];

    if (! is_array($fluent) || ! array_is_list($fluent)
        || ! array_all($fluent, static fn (mixed $method): bool => is_string($method) && $method !== '')
        || count(array_unique(array_map('strtolower', $fluent))) !== count($fluent)) {
        throw new RuntimeException('The pinned Livewire fluent assertion profile is invalid');
    }

    return $profile;
}

function shellPath(): string
{
    $override = getenv('DROVE_SH');

    if (is_string($override) && $override !== '') {
        return $override;
    }

    $gitForWindows = 'C:/Program Files/Git/bin/sh.exe';

    return PHP_OS_FAMILY === 'Windows' && is_file($gitForWindows)
        ? $gitForWindows
        : 'sh';
}

/** @return list<string> */
function analyzerSourcePaths(string $repo): array
{
    $files = glob($repo.'/src/Drove/Migration/*.php');

    if (! is_array($files) || $files === []) {
        throw new RuntimeException('Drove migration analyzer sources are missing');
    }

    $prefix = str_replace('\\', '/', $repo).'/';
    $paths = [
        'benchmarks/corpus/generate-native-surface-inventory.php',
        'benchmarks/corpus/verify-native-surface-inventory.php',
        'experiments/native-corpus-1/codemod-options.php',
        'experiments/native-corpus-1/PestCorpusCodemod.php',
        'experiments/native-invoiceshelf-corpus/codemod-options.php',
        'experiments/native-filament-corpus/profile.php',
        'experiments/phase-3-laravel/native-package/livewire-class-profile.php',
        'resources/drove-bridge-compatibility.json',
        'src/Drove/Native/Surface/SupportedSurface.php',
    ];

    foreach ($files as $file) {
        $normalized = str_replace('\\', '/', $file);

        if (! str_starts_with($normalized, $prefix)) {
            throw new RuntimeException('Migration analyzer escaped the repository root');
        }

        $paths[] = substr($normalized, strlen($prefix));
    }

    $paths = array_values(array_unique($paths));
    sort($paths, SORT_STRING);

    return $paths;
}

/** @param list<string> $arguments */
function command(array $arguments, ?string $workingDirectory = null): string
{
    $process = proc_open(
        $arguments,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workingDirectory,
        options: ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start command: '.implode(' ', $arguments));
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0 || ! is_string($output)) {
        throw new RuntimeException(sprintf(
            "Command failed (%d): %s\n%s",
            $exit,
            implode(' ', $arguments),
            trim((string) $error),
        ));
    }

    return $output;
}

function git(string $root, string ...$arguments): string
{
    return trim(command(array_merge(['git', '-C', $root], array_values($arguments))));
}

/** @return list<string> */
function selection(string $shell, string $repo, string $selector, string $root): array
{
    $output = command([
        $shell,
        $repo.'/benchmarks/corpus/select.sh',
        $selector,
        $root,
    ], $repo);

    $paths = array_values(array_filter(preg_split('/\R/', $output) ?: []));
    $sorted = $paths;
    sort($sorted, SORT_STRING);

    if ($paths !== $sorted || count($paths) !== count(array_unique($paths))) {
        throw new RuntimeException("$selector selection is not sorted and unique");
    }

    return $paths;
}

/**
 * @param  array<string, array<string, mixed>>  $surfaces
 * @param  array<string, true>  $supportedExpectationMethods
 * @param  array<string, true>  $supportedDeclarationMethods
 */
function scanSource(
    string $corpus,
    string $path,
    string $source,
    string $kind,
    array &$surfaces,
    array $supportedExpectationMethods,
    array $supportedDeclarationMethods,
): void {
    $tokens = array_values(PhpToken::tokenize($source, TOKEN_PARSE));
    scanDslCalls(
        $corpus,
        $tokens,
        $path,
        $kind,
        $surfaces,
        $supportedExpectationMethods,
        $supportedDeclarationMethods,
    );

    if ($kind === 'selection') {
        scanPhpUnitFunctionAssertions($source, $path, $surfaces);
        scanPhpUnitInstanceApis($source, $path, $surfaces);

        if ($corpus === 'livewire') {
            scanPhpUnitClassSource($source, $path, $surfaces, scanInstanceApis: false);
        }
    }

    if ($corpus === 'invoiceshelf' && $kind === 'selection') {
        scanLaravelCalls($source, $path, $surfaces);
    }
}

/**
 * @param  list<PhpToken>  $tokens
 * @param  array<string, array<string, mixed>>  $surfaces
 * @param  array<string, true>  $supportedExpectationMethods
 * @param  array<string, true>  $supportedDeclarationMethods
 */
function scanDslCalls(
    string $corpus,
    array $tokens,
    string $path,
    string $kind,
    array &$surfaces,
    array $supportedExpectationMethods,
    array $supportedDeclarationMethods,
): void {
    $declarations = ['test' => true, 'it' => true, 'describe' => true];
    $hooks = ['beforeall' => true, 'beforeeach' => true, 'aftereach' => true, 'afterall' => true];
    $known = [
        ...array_keys($declarations),
        ...array_keys($hooks),
        'dataset',
        'expect',
        'uses',
        'pest',
    ];
    $known = array_fill_keys($known, true);

    foreach ($tokens as $index => $token) {
        if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $parts = explode('\\', strtolower(ltrim($token->text, '\\')));
        $name = (string) end($parts);

        if (! isset($known[$name])) {
            continue;
        }

        $previous = previousSignificant($tokens, $index);

        if ($previous !== null
            && ($tokens[$previous]->is([
                T_FUNCTION,
                T_FN,
                T_NEW,
                T_OBJECT_OPERATOR,
                T_NULLSAFE_OBJECT_OPERATOR,
                T_DOUBLE_COLON,
            ]) || in_array($tokens[$previous]->text, ['->', '?->', '::'], true))) {
            continue;
        }

        $open = nextSignificant($tokens, $index);

        if ($open === null || $tokens[$open]->text !== '(') {
            continue;
        }

        $close = closingParenthesis($tokens, $open);
        $empty = nextSignificant($tokens, $open) === $close;
        $argumentCount = argumentCount($tokens, $open, $close);
        $root = $name;

        if (isset($declarations[$name])) {
            if ($name === 'test' && $empty) {
                $root = 'test-context';
                addSurface(
                    $surfaces,
                    'pest.context.current-test',
                    'pending',
                    $kind,
                    $path,
                    $token->line,
                    'Zero-argument test() context accessor.',
                    null,
                );
            } elseif ($argumentCount < 2) {
                addSurface(
                    $surfaces,
                    'pest.declaration.higher-order',
                    'pending',
                    $kind,
                    $path,
                    $token->line,
                    'Closure-less Pest declaration.',
                    null,
                );
            } else {
                addSurface(
                    $surfaces,
                    'pest.declaration.'.$name,
                    'native-supported',
                    $kind,
                    $path,
                    $token->line,
                    'Pest '.$name.'() declaration.',
                    'Drove\\Native\\'.$name,
                );
            }
        } elseif (isset($hooks[$name])) {
            if ($empty) {
                addSurface(
                    $surfaces,
                    'pest.hook.current-hook',
                    'pending',
                    $kind,
                    $path,
                    $token->line,
                    'Zero-argument Pest hook proxy.',
                    null,
                );
            } else {
                addSurface(
                    $surfaces,
                    'pest.hook.'.$name,
                    'native-supported',
                    $kind,
                    $path,
                    $token->line,
                    'Pest '.$name.'() hook.',
                    'Drove\\Native\\'.$name,
                );
            }
        } elseif ($name === 'dataset') {
            addSurface(
                $surfaces,
                'pest.dataset.definition',
                'native-supported',
                $kind,
                $path,
                $token->line,
                'Named Pest dataset definition.',
                'Drove\\Native\\dataset',
            );
        } elseif ($name === 'expect') {
            if ($empty) {
                foreach (expectationChain($tokens, $close) as $member) {
                    if ($member['called'] && strtolower($member['name']) === 'extend') {
                        addSurface(
                            $surfaces,
                            'pest.expectation.extension-definition',
                            'pending',
                            $kind,
                            $path,
                            $member['line'],
                            'Runtime expectation extension registration.',
                            null,
                        );
                    }
                }
            } else {
                addSurface(
                    $surfaces,
                    'pest.expectation.expect',
                    'native-supported',
                    $kind,
                    $path,
                    $token->line,
                    'Pest expect() assertion entrypoint.',
                    'Drove\\Native\\expect',
                );

                foreach (expectationChain($tokens, $close) as $member) {
                    if (! $member['called'] || $member['nullsafe']) {
                        addSurface(
                            $surfaces,
                            'pest.expectation.higher-order',
                            'pending',
                            $kind,
                            $path,
                            $member['line'],
                            'Property-style or uncalled higher-order expectation chain.',
                            null,
                        );

                        continue;
                    }

                    $normalized = strtolower($member['name']);
                    $status = $normalized === 'tomatchsnapshot'
                        ? ($corpus === 'filament' ? 'native-supported' : 'rejected')
                        : (isset($supportedExpectationMethods[$normalized])
                            ? 'native-supported'
                            : 'pending');
                    addSurface(
                        $surfaces,
                        'pest.expectation.method.'.$member['name'],
                        $status,
                        $kind,
                        $path,
                        $member['line'],
                        $normalized === 'tomatchsnapshot' && $corpus === 'filament'
                            ? 'Snapshot assertion is lowered to the pinned Filament typed matcher extension.'
                            : ($status === 'rejected'
                            ? 'Snapshot assertion is explicitly rejected by the current native surface.'
                            : 'Pest expectation method '.$member['name'].'().'),
                        $normalized === 'tomatchsnapshot' && $corpus === 'filament'
                            ? 'corpus/filament::snapshot via typed-matcher-map-v1'
                            : ($status === 'native-supported'
                                ? 'Drove\\Native\\Expectation::'.$member['name']
                                : null
                            ),
                    );
                }
            }
        } elseif ($name === 'uses') {
            addSurface(
                $surfaces,
                'pest.environment.uses',
                'pending',
                $kind,
                $path,
                $token->line,
                'Pest TestCase or trait binding.',
                'Drove native environment declaration',
            );
        } elseif ($name === 'pest') {
            addSurface(
                $surfaces,
                'pest.bootstrap.configuration',
                'pending',
                $kind,
                $path,
                $token->line,
                'Pest project/bootstrap configuration.',
                null,
            );
        }

        foreach (callChain($tokens, $close) as $method) {
            $normalized = strtolower($method['name']);

            if (isset($declarations[$root])) {
                $status = isset($supportedDeclarationMethods[$normalized])
                    ? 'native-supported'
                    : 'pending';
                addSurface(
                    $surfaces,
                    'pest.declaration.modifier.'.$method['name'],
                    $status,
                    $kind,
                    $path,
                    $method['line'],
                    'Pest declaration modifier '.$method['name'].'().',
                    $status === 'native-supported'
                        ? 'Drove\\Native\\TestDefinition::'.$method['name']
                        : null,
                );
            } elseif ($root === 'test-context') {
                addSurface(
                    $surfaces,
                    'pest.context.method.'.$method['name'],
                    'pending',
                    $kind,
                    $path,
                    $method['line'],
                    'Method on the current Pest test context.',
                    null,
                );
            } elseif (isset($hooks[$root])) {
                addSurface(
                    $surfaces,
                    'pest.hook.modifier.'.$method['name'],
                    'pending',
                    $kind,
                    $path,
                    $method['line'],
                    'Expectation or modifier proxied from a Pest hook.',
                    null,
                );
            } elseif ($root === 'uses') {
                addSurface(
                    $surfaces,
                    'pest.environment.uses.'.$method['name'],
                    'pending',
                    $kind,
                    $path,
                    $method['line'],
                    'Pest uses() scope modifier '.$method['name'].'().',
                    null,
                );
            } elseif ($root === 'pest') {
                $status = $normalized === 'browser' ? 'rejected' : 'pending';
                addSurface(
                    $surfaces,
                    'pest.bootstrap.modifier.'.$method['name'],
                    $status,
                    $kind,
                    $path,
                    $method['line'],
                    'Pest bootstrap modifier '.$method['name'].'().',
                    null,
                );
            }
        }
    }
}

/** @param array<string, array<string, mixed>> $surfaces */
function scanPhpUnitClassSource(
    string $source,
    string $path,
    array &$surfaces,
    bool $scanInstanceApis = true,
): void {
    if (preg_match('/\bclass\s+[A-Za-z_][A-Za-z0-9_]*\s+extends\s+(?:\\\\Tests\\\\)?TestCase\b/', $source, $match, PREG_OFFSET_CAPTURE) === 1) {
        addAtOffset(
            $surfaces,
            'phpunit.class.test-case-inheritance',
            'pending',
            $path,
            $source,
            $match[0][1],
            'PHPUnit/Testbench TestCase inheritance.',
            'Drove native class frontend plus an explicit environment',
        );
    }

    if (preg_match_all('/\bfunction\s+(test[A-Za-z0-9_]*)\s*\(/i', $source, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[1] as [, $offset]) {
            addAtOffset(
                $surfaces,
                'phpunit.class.test-method',
                'native-supported',
                $path,
                $source,
                $offset,
                'Public test*() class method.',
                'Drove\\Native\\ClassFrontend',
            );
        }
    }

    foreach (['setUp', 'tearDown', 'setUpBeforeClass', 'tearDownAfterClass'] as $method) {
        if (preg_match_all('/\bfunction\s+'.preg_quote($method, '/').'\s*\(/i', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [, $offset]) {
                addAtOffset(
                    $surfaces,
                    'phpunit.lifecycle.'.strtolower($method),
                    'native-supported',
                    $path,
                    $source,
                    $offset,
                    'PHPUnit-style '.$method.'() lifecycle method.',
                    'Drove\\Native\\ClassFrontend',
                );
            }
        }
    }

    if (preg_match_all('/#\[\s*DataProvider\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [, $offset]) {
            addAtOffset(
                $surfaces,
                'phpunit.attribute.data-provider',
                'pending',
                $path,
                $source,
                $offset,
                'PHPUnit DataProvider attribute requires namespace migration.',
                'Drove\\Native\\Attributes\\DataProvider',
            );
        }
    }

    if ($scanInstanceApis) {
        scanPhpUnitInstanceApis($source, $path, $surfaces);
    }
}

/** @param array<string, array<string, mixed>> $surfaces */
function scanPhpUnitFunctionAssertions(string $source, string $path, array &$surfaces): void
{
    if (! preg_match_all(
        '/^use\s+function\s+PHPUnit\\\\Framework\\\\([A-Za-z_][A-Za-z0-9_]*)\s*;/m',
        $source,
        $imports,
    )) {
        return;
    }

    foreach (array_unique($imports[1]) as $function) {
        if (! preg_match_all(
            '/(?<!function\s)\b'.preg_quote($function, '/').'\s*\(/',
            $source,
            $calls,
            PREG_OFFSET_CAPTURE,
        )) {
            continue;
        }

        foreach ($calls[0] as [, $offset]) {
            addAtOffset(
                $surfaces,
                'phpunit.function.'.$function,
                'pending',
                $path,
                $source,
                $offset,
                'Imported PHPUnit assertion function '.$function.'().',
                null,
            );
        }
    }
}

/** @param array<string, array<string, mixed>> $surfaces */
function scanPhpUnitInstanceApis(string $source, string $path, array &$surfaces): void
{
    $frameworkMethods = [
        'assertEquals',
        'assertFalse',
        'assertFileExists',
        'assertInstanceOf',
        'assertLessThan',
        'assertMatchesRegularExpression',
        'assertNotEquals',
        'assertNotNull',
        'assertNotSame',
        'assertNull',
        'assertSame',
        'assertStringContainsString',
        'assertStringNotContainsString',
        'assertTrue',
        'expectException',
        'expectNotToPerformAssertions',
        'fail',
        'markTestIncomplete',
        'markTestSkipped',
    ];

    foreach ($frameworkMethods as $method) {
        $pattern = '/(?:\$this\s*->|\b(?:self|static)\s*::)\s*'.preg_quote($method, '/').'\s*\(/i';

        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [, $offset]) {
                $category = str_starts_with($method, 'assert')
                    ? 'assertion'
                    : ($method === 'expectException' ? 'expected-exception' : 'outcome');
                addAtOffset(
                    $surfaces,
                    'phpunit.'.$category.'.'.$method,
                    'pending',
                    $path,
                    $source,
                    $offset,
                    'PHPUnit instance API '.$method.'().',
                    null,
                );
            }
        }
    }
}

/** @param array<string, array<string, mixed>> $surfaces */
function scanLaravelCalls(string $source, string $path, array &$surfaces): void
{
    foreach (['get', 'getJson', 'postJson'] as $method) {
        if (preg_match_all('/(?<!->)(?<!::)\b'.preg_quote($method, '/').'\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [, $offset]) {
                addAtOffset(
                    $surfaces,
                    'laravel.http.helper.'.$method,
                    'pending',
                    $path,
                    $source,
                    $offset,
                    'Pest Laravel HTTP helper '.$method.'().',
                    'Drove\\Laravel\\LaravelTestContext::'.$method,
                );
            }
        }
    }

    foreach (['followingRedirects', 'withHeaders', 'assertAuthenticatedAs', 'assertDatabaseHas'] as $method) {
        if (preg_match_all('/\$this\s*->\s*'.preg_quote($method, '/').'\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [, $offset]) {
                addAtOffset(
                    $surfaces,
                    'laravel.context.method.'.$method,
                    'pending',
                    $path,
                    $source,
                    $offset,
                    'Laravel TestCase-bound method '.$method.'().',
                    'Drove\\Laravel\\LaravelTestContext::'.$method,
                );
            }
        }
    }

    foreach (['assertOk', 'assertSee', 'assertJson', 'assertStatus', 'assertJsonValidationErrors', 'assertNotFound', 'assertForbidden'] as $method) {
        if (preg_match_all('/->\s*'.preg_quote($method, '/').'\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [, $offset]) {
                addAtOffset(
                    $surfaces,
                    'laravel.response.method.'.$method,
                    'pending',
                    $path,
                    $source,
                    $offset,
                    'Laravel HTTP response assertion '.$method.'().',
                    'Drove\\Laravel\\LaravelResponse::'.$method,
                );
            }
        }
    }
}

/**
 * @param  list<string>  $selection
 * @param  array<string, array<string, mixed>>  $surfaces
 */
function addRuntimeSurfaces(string $id, string $root, array $selection, array &$surfaces): void
{
    if ($id === 'invoiceshelf') {
        addSynthetic($surfaces, 'laravel.application.test-case', 'pending', 'tests/TestCase.php', 10, 'Laravel application TestCase bootstrap.', 'Drove\\Laravel\\ApplicationRuntime');
        addSynthetic($surfaces, 'laravel.database.refresh-database', 'pending', 'tests/Pest.php', 6, 'RefreshDatabase is bound to both selected roots.', 'Prepared sqlite-copy environment');
        addSynthetic($surfaces, 'laravel.application.without-vite', 'pending', 'tests/TestCase.php', 20, 'Custom TestCase disables Vite during setup.', 'Drove\\Laravel\\LaravelTestContext::withoutVite');
    } elseif ($id === 'livewire') {
        addSynthetic($surfaces, 'testbench.dusk.test-case', 'pending', 'tests/TestCase.php', 13, 'Selected classes inherit an Orchestra Testbench Dusk application.', 'Drove Laravel native application environment');
        addSynthetic($surfaces, 'testbench.application.after-created', 'pending', 'tests/TestCase.php', 18, 'Application-created callback runs the shared clean-slate routine.', null);
        addSynthetic($surfaces, 'testbench.application.before-destroyed', 'pending', 'tests/TestCase.php', 26, 'Application-destroyed callback runs the shared clean-slate routine.', null);
        addSynthetic($surfaces, 'testbench.profile.package-providers', 'pending', 'tests/TestCase.php', 49, 'Testbench package provider profile.', null);
        addSynthetic($surfaces, 'testbench.profile.environment', 'pending', 'tests/TestCase.php', 56, 'Testbench environment profile.', null);
        addSynthetic($surfaces, 'testbench.profile.resolve-application', 'pending', 'tests/TestCase.php', 104, 'Testbench application resolution override.', null);
        addSynthetic($surfaces, 'laravel.database.sqlite-memory', 'pending', 'tests/TestCase.php', 71, 'Inherited in-memory PDO must be preserved without Testbench lifecycle bridging.', 'Drove sqlite-memory prepared state');
        addSynthetic($surfaces, 'filesystem.shared-clean-slate', 'pending', 'tests/TestCase.php', 33, 'Per-test cleanup mutates shared Livewire paths.', 'Native resource/environment lifecycle');
    } elseif ($id === 'filament') {
        addSynthetic($surfaces, 'testbench.application.test-case', 'pending', 'tests/src/TestCase.php', 58, 'Filament TestCase extends Orchestra Testbench.', 'Drove Laravel native application environment');
        addSynthetic($surfaces, 'testbench.profile.with-workbench', 'pending', 'tests/src/TestCase.php', 61, 'WithWorkbench configures the Testbench application.', null);
        addSynthetic($surfaces, 'testbench.profile.package-providers', 'pending', 'tests/src/TestCase.php', 63, 'Testbench package provider profile.', null);
        addSynthetic($surfaces, 'testbench.profile.environment', 'pending', 'tests/src/TestCase.php', 106, 'Testbench environment profile.', null);
        addSynthetic($surfaces, 'laravel.database.refresh-database', 'pending', 'tests/src/TestCase.php', 60, 'RefreshDatabase executes over prepared sqlite-copy state.', 'Prepared sqlite-copy environment');
        addSynthetic($surfaces, 'filesystem.serial-cohort', 'pending', 'tests/src/Support/Components/ViewComponentTest.php', 13, 'One selected file is serialized because it mutates published views.', 'Native resource coordination');
    }
}

/**
 * @param  list<PhpToken>  $tokens
 * @return list<array{name: string, line: int}>
 */
function callChain(array $tokens, int $close): array
{
    $methods = [];
    $cursor = $close;

    while (true) {
        $operator = nextSignificant($tokens, $cursor);

        if ($operator === null || ! $tokens[$operator]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            break;
        }

        $member = nextSignificant($tokens, $operator);

        if ($member === null || ! $tokens[$member]->is(T_STRING)) {
            break;
        }

        $open = nextSignificant($tokens, $member);

        if ($open === null || $tokens[$open]->text !== '(') {
            break;
        }

        $methods[] = ['name' => $tokens[$member]->text, 'line' => $tokens[$member]->line];
        $cursor = closingParenthesis($tokens, $open);
    }

    return $methods;
}

/**
 * @param  list<PhpToken>  $tokens
 * @return list<array{name: string, line: int, called: bool, nullsafe: bool}>
 */
function expectationChain(array $tokens, int $close): array
{
    $members = [];
    $cursor = $close;

    while (true) {
        $operator = nextSignificant($tokens, $cursor);

        if ($operator === null || ! $tokens[$operator]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            break;
        }

        $member = nextSignificant($tokens, $operator);

        if ($member === null || ! $tokens[$member]->is(T_STRING)) {
            break;
        }

        $open = nextSignificant($tokens, $member);
        $called = $open !== null && $tokens[$open]->text === '(';
        $members[] = [
            'name' => $tokens[$member]->text,
            'line' => $tokens[$member]->line,
            'called' => $called,
            'nullsafe' => $tokens[$operator]->is(T_NULLSAFE_OBJECT_OPERATOR),
        ];
        $cursor = $called ? closingParenthesis($tokens, $open) : $member;
    }

    return $members;
}

/** @param list<PhpToken> $tokens */
function closingParenthesis(array $tokens, int $open): int
{
    $depth = 0;

    for ($index = $open, $count = count($tokens); $index < $count; $index++) {
        if ($tokens[$index]->text === '(') {
            $depth++;
        } elseif ($tokens[$index]->text === ')' && --$depth === 0) {
            return $index;
        }
    }

    throw new RuntimeException('Unclosed function call');
}

/** @param list<PhpToken> $tokens */
function argumentCount(array $tokens, int $open, int $close): int
{
    $first = nextSignificant($tokens, $open);

    if ($first === $close) {
        return 0;
    }

    $round = $square = $curly = 0;
    $arguments = 1;

    for ($index = $open + 1; $index < $close; $index++) {
        $text = $tokens[$index]->text;

        if ($text === '(') {
            $round++;
        } elseif ($text === ')') {
            $round--;
        } elseif ($text === '[') {
            $square++;
        } elseif ($text === ']') {
            $square--;
        } elseif ($text === '{') {
            $curly++;
        } elseif ($text === '}') {
            $curly--;
        } elseif ($text === ',' && $round === 0 && $square === 0 && $curly === 0) {
            $arguments++;
        }
    }

    return $arguments;
}

/** @param list<PhpToken> $tokens */
function nextSignificant(array $tokens, int $index): ?int
{
    for ($index++, $count = count($tokens); $index < $count; $index++) {
        if (! $tokens[$index]->isIgnorable()) {
            return $index;
        }
    }

    return null;
}

/** @param list<PhpToken> $tokens */
function previousSignificant(array $tokens, int $index): ?int
{
    for ($index--; $index >= 0; $index--) {
        if (! $tokens[$index]->isIgnorable()) {
            return $index;
        }
    }

    return null;
}

/** @param array<string, array<string, mixed>> $surfaces */
function addAtOffset(
    array &$surfaces,
    string $id,
    string $status,
    string $path,
    string $source,
    int $offset,
    string $description,
    ?string $target,
): void {
    addSurface(
        $surfaces,
        $id,
        $status,
        'selection',
        $path,
        substr_count(substr($source, 0, $offset), "\n") + 1,
        $description,
        $target,
    );
}

/** @param array<string, array<string, mixed>> $surfaces */
function addSynthetic(
    array &$surfaces,
    string $id,
    string $status,
    string $path,
    int $line,
    string $description,
    ?string $target,
): void {
    addSurface($surfaces, $id, $status, 'support', $path, $line, $description, $target);
}

/** @param array<string, array<string, mixed>> $surfaces */
function addSurface(
    array &$surfaces,
    string $id,
    string $status,
    string $kind,
    string $path,
    int $line,
    string $description,
    ?string $target,
): void {
    if (isset($surfaces[$id])
        && ($surfaces[$id]['status'] !== $status
            || $surfaces[$id]['description'] !== $description
            || $surfaces[$id]['native_target'] !== $target)) {
        throw new RuntimeException("Inconsistent surface definition for $id");
    }

    $surfaces[$id] ??= [
        'status' => $status,
        'description' => $description,
        'native_target' => $target,
        'occurrences' => 0,
        'selected_files' => [],
        'support_files' => [],
        'examples' => [],
    ];
    $surfaces[$id]['occurrences']++;
    $fileKey = $kind === 'selection' ? 'selected_files' : 'support_files';
    $surfaces[$id][$fileKey][$path] = true;

    if (count($surfaces[$id]['examples']) < 5) {
        $surfaces[$id]['examples'][] = [
            'path' => $path,
            'line' => $line,
            'source_kind' => $kind,
        ];
    }
}

/**
 * @param  list<array<string, mixed>>  $corpora
 * @return list<array<string, mixed>>
 */
function rawSourceMigrationSurfaces(array $corpora): array
{
    $lookup = [];

    foreach ($corpora as $corpus) {
        foreach ($corpus['surfaces'] as $surface) {
            $lookup[$corpus['id']][$surface['id']] = $surface;
        }
    }

    $sum = static function (string $corpus, array $prefixes) use ($lookup): array {
        $occurrences = 0;
        $files = [];
        $constructs = 0;

        foreach ($lookup[$corpus] ?? [] as $id => $surface) {
            if ($surface['status'] === 'native-supported') {
                continue;
            }

            foreach ($prefixes as $prefix) {
                if ($id === $prefix || str_starts_with($id, $prefix)) {
                    $constructs++;
                    $occurrences += $surface['occurrences'];
                    $files = [...$files, ...$surface['selected_files'], ...$surface['support_files']];
                    break;
                }
            }
        }

        return [
            'constructs' => $constructs,
            'occurrences' => $occurrences,
            'files' => count(array_unique($files)),
        ];
    };

    return [
        [
            'order' => 1,
            'id' => 'pest-expectation-and-higher-order-parity',
            'corpus' => 'pest',
            'evidence' => $sum('pest', ['pest.expectation.']),
            'proof_target' => 'Every selected expectation construct is native-supported or has an explicitly accepted rejection.',
        ],
        [
            'order' => 2,
            'id' => 'phpunit-instance-api-and-testbench-removal',
            'corpus' => 'livewire',
            'evidence' => $sum('livewire', ['phpunit.', 'testbench.', 'laravel.database.', 'filesystem.']),
            'proof_target' => 'All 288 cases run through the native class frontend without PHPUnit runBare or Testbench lifecycle bridging.',
        ],
        [
            'order' => 3,
            'id' => 'laravel-context-and-refresh-database-migration',
            'corpus' => 'invoiceshelf',
            'evidence' => $sum('invoiceshelf', ['laravel.', 'pest.environment.']),
            'proof_target' => 'All 202 cases use native Laravel context and prepared state without Pest Laravel or Laravel TestCase binding.',
        ],
        [
            'order' => 4,
            'id' => 'filament-testbench-uses-and-remaining-expectations',
            'corpus' => 'filament',
            'evidence' => $sum('filament', ['pest.expectation.', 'pest.environment.', 'testbench.', 'laravel.', 'filesystem.']),
            'proof_target' => 'All 705 cases preserve the serial split and run without Pest/Testbench bridges.',
        ],
        [
            'order' => 5,
            'id' => 'selected-rejection-conflicts',
            'corpus' => 'all',
            'evidence' => [
                'constructs' => array_sum(array_map(
                    static fn (array $corpus): int => count(array_filter(
                        $corpus['surfaces'],
                        static fn (array $surface): bool => $surface['status'] === 'rejected'
                            && $surface['selected_files'] !== [],
                    )),
                    $corpora,
                )),
            ],
            'proof_target' => 'Either implement each rejected construct used by a selected path or explicitly revise the pinned cohort contract.',
        ],
    ];
}

/**
 * @param  list<string>  $files
 * @param  array<string, mixed>  $classProfile
 * @return array<string, mixed>
 */
function migrationReadiness(
    string $corpus,
    string $root,
    array $files,
    Migrator $portableMigrator,
    ClassMigrator $classMigrator,
    LaravelMigrator $laravelMigrator,
    CodemodOptions $options,
    array $classProfile,
): array {
    $analyzer = match ($corpus) {
        'pest', 'filament' => Migrator::class,
        'livewire' => ClassMigrator::class,
        'invoiceshelf' => LaravelMigrator::class,
        default => throw new RuntimeException("No migration analyzer for $corpus"),
    };
    $blockerFree = [];
    $blocked = [];
    $changedFiles = 0;
    $groups = [];

    foreach ($files as $path) {
        $source = gitBlobContents($root, $path);
        $normalized = [];
        $changed = false;

        if ($corpus === 'livewire') {
            $result = $classMigrator->migrate($source, $path, 'Tests\\TestCase', $classProfile);
            $changed = $result->changed();

            foreach ($result->blockers as $finding) {
                $normalized[] = findingBlocker('class', $finding);
            }
        } elseif ($corpus === 'invoiceshelf') {
            $result = $laravelMigrator->migrate($source, $path, $options);
            $changed = $result->changed();

            foreach ($result->portableBlockers as $finding) {
                $normalized[] = findingBlocker('portable', $finding);
            }

            foreach ($result->blockers as $blocker) {
                $normalized[] = [
                    'stage' => 'laravel',
                    'code' => $blocker['kind'],
                    'diagnostic' => $blocker['diagnostic'],
                    'construct' => $blocker['construct'],
                    'line' => $blocker['line'],
                ];
            }
        } elseif ($corpus === 'filament') {
            $profiled = nativeFilamentProfileSource($source, $path);
            $result = $portableMigrator->migrate($profiled, $path, $options);
            $changed = $profiled !== $source || $result->changed();

            foreach ($result->blockers as $finding) {
                $normalized[] = findingBlocker('portable', $finding);
            }

            $second = $portableMigrator->migrate(
                nativeFilamentProfileSource($result->source, $path),
                $path,
                $options,
            );

            if ($second->blockers !== []
                || $second->changed()
                || $second->source !== $result->source
                || $second->resultHash !== $result->resultHash) {
                throw new RuntimeException("Filament migration profile is not idempotent for $path");
            }
        } else {
            $source = PestCorpusCodemod::migrate($source, $path);

            $result = $portableMigrator->migrate($source, $path, $options);
            $changed = $result->changed();

            foreach ($result->blockers as $finding) {
                $normalized[] = findingBlocker('portable', $finding);
            }
        }

        $changedFiles += $changed ? 1 : 0;

        if ($normalized === []) {
            $blockerFree[] = $path;

            continue;
        }

        $blocked[] = $path;

        foreach ($normalized as $blocker) {
            $key = $blocker['stage'].'|'.$blocker['code'];
            $groups[$key] ??= [
                'stage' => $blocker['stage'],
                'code' => $blocker['code'],
                'diagnostic' => $blocker['diagnostic'],
                'occurrence_count' => 0,
                'files' => [],
                'examples' => [],
            ];
            $groups[$key]['occurrence_count']++;
            $groups[$key]['files'][$path] = true;

            if (count($groups[$key]['examples']) < 5) {
                $groups[$key]['examples'][] = $path.':'.$blocker['line'].':'.$blocker['construct'];
            }
        }
    }

    sort($blockerFree, SORT_STRING);
    sort($blocked, SORT_STRING);
    ksort($groups, SORT_STRING);
    $blockerGroups = [];
    $occurrences = 0;

    foreach ($groups as $group) {
        $occurrences += $group['occurrence_count'];
        $group['file_count'] = count($group['files']);
        unset($group['files']);
        $blockerGroups[] = $group;
    }

    return [
        'analyzer' => $analyzer,
        'scope' => 'selected-source-files-after-codemod',
        'selected_file_counts' => [
            'total' => count($files),
            'blocker_free' => count($blockerFree),
            'blocked' => count($blocked),
            'changed_by_codemod' => $changedFiles,
            'unchanged_by_codemod' => count($files) - $changedFiles,
        ],
        'blocker_occurrence_count' => $occurrences,
        'blocker_groups' => $blockerGroups,
        'blocker_free_paths' => $blockerFree,
    ];
}

/** @return array{stage: string, code: string, diagnostic: string, construct: string, line: int} */
function findingBlocker(string $stage, Finding $finding): array
{
    return [
        'stage' => $stage,
        'code' => $finding->surface,
        'diagnostic' => $finding->diagnostic,
        'construct' => $finding->construct,
        'line' => $finding->line,
    ];
}

function hashFile(string $path): string
{
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException("Cannot hash $path");
    }

    return hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents));
}

function gitBlobHash(string $root, string $path): string
{
    return hash('sha256', gitBlobContents($root, $path));
}

function gitBlobContents(string $root, string $path): string
{
    return command(['git', '-C', $root, 'show', 'HEAD:'.$path]);
}

function encodeCanonical(mixed $value): string
{
    return json_encode(canonical($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function canonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('canonical', $value);
    }

    ksort($value, SORT_STRING);

    return array_map('canonical', $value);
}
