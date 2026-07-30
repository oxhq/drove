<?php

declare(strict_types=1);

use Drove\Environment\ResourcePlan;
use Drove\Kernel\ChildProtocol;
use Drove\Kernel\DroverScheduler;
use Drove\Kernel\NativeLibrary;
use Drove\Kernel\ScopeContext;
use Drove\Laravel\Contracts\DatabaseStateProvider;
use Illuminate\Foundation\Application;

final class NativePhaseFourStateProvider implements DatabaseStateProvider
{
    public int $bootCount = 0;

    private ?string $copyPrefix = null;

    public function __construct(
        private readonly DatabaseStateProvider $inner,
        private readonly ?string $fault = null,
        private readonly ?string $faultLog = null,
        private readonly ?string $copyWorkspace = null,
        private readonly ?int $rootPid = null,
    ) {}

    public function boot(Application $application): void
    {
        $this->bootCount++;
        $this->inner->boot($application);
    }

    /** @param list<array<string, mixed>> $tasks */
    public function beforeDispatch(ScopeContext $scope, array $tasks): void
    {
        $this->inner->beforeDispatch($scope, $tasks);
    }

    /** @param array<string, mixed> $task */
    public function enterDescendant(ScopeContext $scope, array $task): void
    {
        if ($this->fault === 'enter' && $this->inner->name() === 'sqlite-memory') {
            $scope->app()->make('db')->disconnect('sqlite');
            $this->record('enter');
        }

        $this->inner->enterDescendant($scope, $task);

        if ($this->inner->name() !== 'sqlite-copy'
            || ! in_array($this->fault, ['enter', 'cleanup'], true)) {
            return;
        }

        $copies = $this->copyArtifacts();

        if (count($copies) !== 1
            || preg_match(
                '/\A(drove-[a-f0-9]{24}-)/D',
                basename($copies[0]),
                $match,
            ) !== 1) {
            throw new RuntimeException('Could not identify the lazy SQLite copy.');
        }

        $this->copyPrefix = $match[1];

        if ($this->fault === 'enter') {
            if (file_put_contents(
                $copies[0],
                'native-phase-four-corrupt-copy',
                LOCK_EX,
            ) === false) {
                throw new RuntimeException('Could not corrupt the lazy SQLite enter fault copy.');
            }

            $scope->app()->make('db')->disconnect('sqlite');
            $this->record('enter');
        }
    }

    /** @param array<string, mixed> $task */
    public function leaveDescendant(ScopeContext $scope, array $task): void
    {
        $this->inner->leaveDescendant($scope, $task);

        if ($this->fault === 'cleanup' && $this->inner->name() === 'sqlite-copy') {
            $prefix = $this->copyPrefix ?? throw new RuntimeException(
                'The lazy SQLite cleanup fault prefix was not captured.',
            );
            $artifact = $this->workspace().'/'.$prefix.'fault-artifact';

            if (! mkdir($artifact)) {
                throw new RuntimeException('Could not create the SQLite cleanup fault artifact.');
            }

            $this->record('cleanup');
        }
    }

    /** @param list<array<string, mixed>> $tasks */
    public function afterDispatch(ScopeContext $scope, array $tasks): void
    {
        if ($this->fault === 'cleanup'
            && $this->inner->name() === 'sqlite-memory'
            && getmypid() === $this->rootPid) {
            $scope->app()->make('db')->disconnect('sqlite');
            $this->record('cleanup');
        }

        $this->inner->afterDispatch($scope, $tasks);
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function limitations(): array
    {
        return $this->inner->limitations();
    }

    public function resourcePlan(): ResourcePlan
    {
        return $this->inner->resourcePlan();
    }

    private function record(string $phase): void
    {
        if (is_string($this->faultLog)
            && file_put_contents(
                $this->faultLog,
                $phase.' '.getmypid().PHP_EOL,
                FILE_APPEND | LOCK_EX,
            ) === false) {
            throw new RuntimeException('Could not record the native Laravel fault sentinel.');
        }
    }

    /** @return list<string> */
    private function copyArtifacts(): array
    {
        $paths = glob($this->workspace().'/drove-*.sqlite');

        if ($paths === false) {
            throw new RuntimeException('Could not scan SQLite fault copies.');
        }

        return array_values(array_filter($paths, is_file(...)));
    }

    private function workspace(): string
    {
        if (! is_string($this->copyWorkspace)
            || $this->copyWorkspace === '') {
            throw new RuntimeException('The SQLite fault workspace is missing.');
        }

        return $this->copyWorkspace;
    }
}

/**
 * @return array{
 *     evidence_revision: string,
 *     runtime_platform: array{os_family: string, os: string, architecture: string, php: string},
 *     drover_identity: array{
 *         scheduler_class: class-string<DroverScheduler>,
 *         target: string,
 *         library_sha256: string,
 *         protocol_version: int,
 *         protocol_max_frame_bytes: int
 *     }
 * }
 */
function nativePhaseFourIdentity(): array
{
    $revision = getenv('DROVE_EVIDENCE_REVISION');

    if (! is_string($revision)
        || preg_match('/\A[a-f0-9]{40}\z/D', $revision) !== 1) {
        throw new RuntimeException('DROVE_EVIDENCE_REVISION must be the exact 40-character Git SHA.');
    }

    $library = NativeLibrary::resolve();
    $hash = hash_file('sha256', $library);

    if (! is_string($hash)) {
        throw new RuntimeException('Could not hash the resolved Drover library.');
    }

    return [
        'evidence_revision' => $revision,
        'runtime_platform' => [
            'os_family' => PHP_OS_FAMILY,
            'os' => PHP_OS,
            'architecture' => php_uname('m'),
            'php' => PHP_VERSION,
        ],
        'drover_identity' => [
            'scheduler_class' => DroverScheduler::class,
            'target' => NativeLibrary::target(),
            'library_sha256' => $hash,
            'protocol_version' => ChildProtocol::VERSION,
            'protocol_max_frame_bytes' => 1_048_576,
        ],
    ];
}

/** @param array<string, string> $values */
function nativePhaseFourEnvironment(array $values): void
{
    foreach ($values as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function nativePhaseFourRemove(string $directory): void
{
    if (! file_exists($directory)) {
        return;
    }

    if (is_link($directory) || ! is_dir($directory)) {
        throw new RuntimeException('Refusing to recursively remove a non-directory proof path.');
    }

    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.') {
            continue;
        }
        if ($name === '..') {
            continue;
        }
        $path = $directory.'/'.$name;

        if (is_link($path)) {
            throw new RuntimeException('Refusing to follow a proof symlink.');
        }

        if (is_dir($path)) {
            nativePhaseFourRemove($path);
        } elseif (! unlink($path)) {
            throw new RuntimeException('Could not remove native Laravel proof artifact '.$path.'.');
        }
    }

    if (! rmdir($directory)) {
        throw new RuntimeException('Could not remove native Laravel proof directory '.$directory.'.');
    }
}

function nativePhaseFourWrite(string $path, string $contents): void
{
    $directory = dirname($path);

    if (! is_dir($directory)
        && ! mkdir($directory, 0777, true)
        && ! is_dir($directory)) {
        throw new RuntimeException('Could not create native Laravel proof directory '.$directory.'.');
    }

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not write native Laravel proof file '.$path.'.');
    }
}

/** @return list<string> */
function nativePhaseFourFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS,
        ),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}
