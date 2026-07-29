<?php

declare(strict_types=1);

namespace Drove\Laravel\State;

use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SqliteCopyDatabaseStateAdapter extends AbstractDatabaseStateAdapter
{
    private ?int $rootPid = null;

    private ?string $workspace = null;

    private ?string $currentDatabase = null;

    private ?string $copyPrefix = null;

    /** @var array<string, string> */
    private array $pendingCopies = [];

    private ?int $dispatchPid = null;

    private ?int $descendantPid = null;

    private ?string $descendantId = null;

    private ?string $descendantDatabase = null;

    private ?string $parentDatabase = null;

    public function __construct(
        ?string $connection = null,
        private readonly ?string $configuredWorkspace = null,
        private readonly bool $preparedSchema = false,
    ) {
        parent::__construct($connection);
    }

    public function boot(Application $application): void
    {
        parent::boot($application);
        $database = $this->configuredDatabasePath();
        $workspace = $this->configuredWorkspace;

        if ($workspace === null || $workspace === '') {
            $workspace = $application->storagePath('framework/drove');
        }

        if (is_link($workspace)) {
            throw new StateAdapterException('The SQLite copy workspace may not be a symbolic link.');
        }

        if (! is_dir($workspace)
            && ! mkdir($workspace, 0777, true)
            && ! is_dir($workspace)) {
            throw new StateAdapterException(sprintf(
                'Drove could not create the SQLite copy workspace %s.',
                $workspace,
            ));
        }

        $workspace = realpath($workspace);

        if ($workspace === false || ! is_writable($workspace)) {
            throw new StateAdapterException('The SQLite copy workspace is not writable.');
        }

        $this->workspace = str_replace('\\', '/', $workspace);
        $this->currentDatabase = $database;
        $this->copyPrefix = 'drove-'.bin2hex(random_bytes(12)).'-';
        $this->rootPid = $this->pid('adapter boot');
        $this->disconnect();
    }

    public function name(): string
    {
        return 'sqlite-copy';
    }

    public function assertTestCaseSupported(TestCase $testCase): void
    {
        parent::assertTestCaseSupported($testCase);

        if (! in_array(
            RefreshDatabase::class,
            class_uses_recursive($testCase),
            true,
        )) {
            return;
        }

        if ($this->preparedSchema) {
            RefreshDatabaseState::$migrated = true;
        }
    }

    public function preflightTestCase(TestCase $testCase): void
    {
        if (in_array(
            DatabaseTruncation::class,
            class_uses_recursive($testCase),
            true,
        )) {
            throw new StateAdapterException(sprintf(
                'Drove Laravel sqlite-copy mode does not support TestCase trait %s.',
                DatabaseTruncation::class,
            ));
        }
    }

    /**
     * @return list<string>
     */
    public function limitations(): array
    {
        return [
            'Exactly one resolved, absolute, file-backed SQLite connection is supported.',
            'SQLite must use DELETE journal mode with no WAL, SHM, journal, or attached database files.',
            'Each descendant receives a verified PHP file copy; no reflink optimization is claimed.',
            'In-memory SQLite, URI databases, attached databases, and multiple connections are rejected.',
            'RefreshDatabase migrates each private copy unless prepared_schema=true declares the parent file already migrated.',
            'DatabaseTruncation is rejected before execution.',
            'Queue, cache, Redis, HTTP, and other external resources are not managed.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    public function beforeDispatch(ScopeContext $scope, array $tasks): void
    {
        $this->assertScopeApplication($scope);
        $this->assertTasks($tasks);
        $pid = $this->pid('beforeDispatch');

        if ($this->dispatchPid === $pid || $this->pendingCopies !== []) {
            throw new StateAdapterException('An SQLite copy dispatch is already active.');
        }

        $this->assertOnlySelectedConnectionIsResolved();
        $connection = $this->connection();
        $this->assertSqliteConnection($connection, $this->databasePath(), 'beforeDispatch');
        $this->disconnect();
        $this->dispatchPid = $pid;

        try {
            foreach ($tasks as $ordinal => $task) {
                $this->pendingCopies[$task['id']] = $this->copyDatabase(
                    $this->databasePath(),
                    $task['id'],
                    $ordinal,
                );
            }
        } catch (Throwable $throwable) {
            $this->cleanupCopies(array_values($this->pendingCopies));
            $this->pendingCopies = [];
            $this->dispatchPid = null;
            $this->reconnect();

            throw $throwable;
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function enterDescendant(ScopeContext $scope, array $task): void
    {
        $this->assertScopeApplication($scope);
        $this->assertTask($task);
        $pid = $this->pid('enterDescendant');

        if ($this->descendantPid === $pid) {
            throw new StateAdapterException('An SQLite copy descendant is already active.');
        }

        $copy = $this->pendingCopies[$task['id']] ?? null;

        if (! is_string($copy)) {
            throw new StateAdapterException(sprintf(
                'No SQLite copy was prepared for descendant %s.',
                $task['id'],
            ));
        }

        $parent = $this->databasePath();
        $this->pendingCopies = [];
        $this->dispatchPid = null;
        $this->descendantPid = $pid;
        $this->descendantId = $task['id'];
        $this->descendantDatabase = $copy;
        $this->parentDatabase = $parent;
        $this->configureDatabase($copy);
        $connection = $this->reconnect();
        $this->assertSqliteConnection($connection, $copy, 'enterDescendant');
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function leaveDescendant(ScopeContext $scope, array $task): void
    {
        $this->assertScopeApplication($scope);
        $this->assertTask($task);
        $pid = getmypid();

        if ($this->descendantPid !== $pid || $this->descendantId !== $task['id']) {
            throw new StateAdapterException('The SQLite copy descendant lifecycle is unbalanced.');
        }

        $this->assertOnlySelectedConnectionIsResolved();
        $copy = $this->descendantDatabase;
        $parent = $this->parentDatabase;
        $failure = null;

        try {
            $connection = $this->connection();
            $level = $connection->transactionLevel();

            if ($level !== 0) {
                $failure = new StateAdapterException(sprintf(
                    'SQLite descendant %s left transaction depth %d.',
                    $task['id'],
                    $level,
                ));

                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
        } catch (Throwable $throwable) {
            $failure ??= new StateAdapterException(
                sprintf('Drove could not close SQLite descendant %s.', $task['id']),
                previous: $throwable,
            );
        } finally {
            $this->purge();

            if (is_string($parent)) {
                $this->configureDatabase($parent);
            }

            if (is_string($copy)) {
                $this->cleanupCopies([$copy]);
            }

            $this->descendantPid = null;
            $this->descendantId = null;
            $this->descendantDatabase = null;
            $this->parentDatabase = null;
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    public function afterDispatch(ScopeContext $scope, array $tasks): void
    {
        $this->assertScopeApplication($scope);
        $pid = getmypid();

        if ($this->dispatchPid === null) {
            $copies = array_values($this->pendingCopies);
            $this->pendingCopies = [];
            $this->cleanupCopies($copies);

            return;
        }

        $this->assertTasks($tasks);

        if ($this->dispatchPid !== $pid) {
            throw new StateAdapterException('The SQLite copy dispatch lifecycle is unbalanced.');
        }

        $copies = array_values($this->pendingCopies);
        $this->pendingCopies = [];
        $this->dispatchPid = null;
        $this->cleanupCopies($copies);
        $this->configureDatabase($this->databasePath());
        $connection = $this->reconnect();
        $this->assertSqliteConnection($connection, $this->databasePath(), 'afterDispatch');

        if (getmypid() === $this->rootPid) {
            $this->cleanupAbandonedCopies();
        }
    }

    protected function validateConnection(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite') {
            throw new StateAdapterException(sprintf(
                'The SQLite copy adapter requires sqlite; %s was configured.',
                $connection->getDriverName(),
            ));
        }

        $this->assertSqliteConnection(
            $connection,
            $this->configuredDatabasePath(),
            'adapter boot',
        );
    }

    private function configuredDatabasePath(): string
    {
        $database = $this->config()
            ->get('database.connections.'.$this->connectionName().'.database');

        if (! is_string($database)
            || $database === ''
            || $database === ':memory:'
            || str_starts_with($database, 'file:')
            || ! str_starts_with($database, DIRECTORY_SEPARATOR)
            || is_link($database)) {
            throw new StateAdapterException(
                'Drove requires an absolute, ordinary file-backed SQLite database path.',
            );
        }

        $database = realpath($database);

        if ($database === false
            || ! is_file($database)
            || ! is_readable($database)
            || ! is_writable($database)) {
            throw new StateAdapterException(
                'The configured SQLite database must be an existing readable and writable file.',
            );
        }

        return str_replace('\\', '/', $database);
    }

    private function databasePath(): string
    {
        return $this->currentDatabase ?? throw new StateAdapterException(
            'The SQLite copy adapter has not resolved its current database.',
        );
    }

    private function workspace(): string
    {
        return $this->workspace ?? throw new StateAdapterException(
            'The SQLite copy adapter has not resolved its workspace.',
        );
    }

    private function copyPrefix(): string
    {
        return $this->copyPrefix ?? throw new StateAdapterException(
            'The SQLite copy adapter has not resolved its copy prefix.',
        );
    }

    private function configureDatabase(string $database): void
    {
        $this->purge();
        $this->config()
            ->set('database.connections.'.$this->connectionName().'.database', $database);
        $this->currentDatabase = $database;
    }

    private function assertSqliteConnection(
        Connection $connection,
        string $expectedDatabase,
        string $phase,
    ): void {
        $this->assertNoOpenTransaction($connection, $phase);
        $databases = array_values(array_filter(
            $connection->select('PRAGMA database_list'),
            static fn (object $database): bool => ($database->name ?? null) !== 'temp',
        ));

        if (count($databases) !== 1
            || ($databases[0]->name ?? null) !== 'main'
            || ! is_string($databases[0]->file ?? null)
            || realpath($databases[0]->file) !== realpath($expectedDatabase)) {
            throw new StateAdapterException(
                'Drove SQLite copy mode supports exactly one unattached main database.',
            );
        }

        $journal = $connection->selectOne('PRAGMA journal_mode');
        $journalMode = is_object($journal) ? ($journal->journal_mode ?? null) : null;

        if (! is_string($journalMode) || strtolower($journalMode) !== 'delete') {
            throw new StateAdapterException(sprintf(
                'Drove SQLite copy mode requires DELETE journal mode; received %s.',
                is_scalar($journalMode) ? (string) $journalMode : get_debug_type($journalMode),
            ));
        }

        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (file_exists($expectedDatabase.$suffix)) {
                throw new StateAdapterException(sprintf(
                    'Drove SQLite copy mode refuses sidecar file %s.',
                    basename($expectedDatabase.$suffix),
                ));
            }
        }
    }

    private function copyDatabase(string $source, string $taskId, int $ordinal): string
    {
        $name = $this->copyPrefix().hash(
            'sha256',
            getmypid()."\0".$ordinal."\0".$taskId."\0".bin2hex(random_bytes(8)),
        ).'.sqlite';
        $destination = $this->workspace().'/'.$name;

        if (file_exists($destination) || ! copy($source, $destination)) {
            throw new StateAdapterException(sprintf(
                'Drove could not copy SQLite state for descendant %s.',
                $taskId,
            ));
        }

        clearstatcache(true, $source);
        clearstatcache(true, $destination);
        $sourceSize = filesize($source);
        $destinationSize = filesize($destination);
        $sourceHash = hash_file('sha256', $source);
        $destinationHash = hash_file('sha256', $destination);

        if ($sourceSize === false
            || $destinationSize === false
            || $sourceSize !== $destinationSize
            || ! is_string($sourceHash)
            || ! is_string($destinationHash)
            || ! hash_equals($sourceHash, $destinationHash)) {
            @unlink($destination);

            throw new StateAdapterException(sprintf(
                'Drove could not verify the SQLite copy for descendant %s.',
                $taskId,
            ));
        }

        return $destination;
    }

    /**
     * @param  list<string>  $copies
     */
    private function cleanupCopies(array $copies): void
    {
        foreach ($copies as $copy) {
            foreach ([$copy, $copy.'-wal', $copy.'-shm', $copy.'-journal'] as $path) {
                if (file_exists($path) && ! @unlink($path)) {
                    throw new StateAdapterException(sprintf(
                        'Drove could not remove SQLite copy artifact %s.',
                        $path,
                    ));
                }
            }
        }
    }

    private function cleanupAbandonedCopies(): void
    {
        $pattern = $this->workspace().'/'.$this->copyPrefix().'*';
        $paths = glob($pattern);

        if ($paths === false) {
            throw new StateAdapterException('Drove could not scan its SQLite copy workspace.');
        }

        foreach ($paths as $path) {
            if (! is_file($path) || ! str_starts_with(basename($path), $this->copyPrefix())) {
                throw new StateAdapterException(
                    'Drove found an invalid artifact in its SQLite copy workspace.',
                );
            }

            if (! @unlink($path)) {
                throw new StateAdapterException(sprintf(
                    'Drove could not remove abandoned SQLite artifact %s.',
                    $path,
                ));
            }
        }
    }

    private function pid(string $phase): int
    {
        $pid = getmypid();

        if (! is_int($pid)) {
            throw new StateAdapterException(sprintf(
                'Drove could not resolve the SQLite copy process during %s.',
                $phase,
            ));
        }

        return $pid;
    }
}
