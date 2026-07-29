<?php

declare(strict_types=1);

namespace Drove\Laravel\State;

use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class InMemorySqliteDatabaseStateAdapter extends AbstractDatabaseStateAdapter
{
    /** @var list<class-string> */
    private const array UNSUPPORTED_DATABASE_TRAITS = [
        LazilyRefreshDatabase::class,
        DatabaseMigrations::class,
        DatabaseTruncation::class,
    ];

    private ?int $pdoId = null;

    private ?int $dispatchPid = null;

    private ?int $descendantPid = null;

    private ?string $descendantId = null;

    private ?string $descendantKind = null;

    public function __construct(
        ?string $configuredConnection = null,
        private readonly bool $preparedSchema = false,
    ) {
        parent::__construct($configuredConnection);
    }

    public function boot(Application $application): void
    {
        parent::boot($application);
        $this->connection()->getPdo();
        $pdo = $this->connection()->getRawPdo();

        if (! $pdo instanceof PDO) {
            throw new StateAdapterException(
                'The SQLite in-memory adapter could not open its parent PDO connection.',
            );
        }

        $this->pdoId = spl_object_id($pdo);
    }

    public function name(): string
    {
        return 'sqlite-memory';
    }

    public function assertTestCaseSupported(TestCase $testCase): void
    {
        parent::assertTestCaseSupported($testCase);

        if (in_array(
            RefreshDatabase::class,
            class_uses_recursive($testCase),
            true,
        )) {
            RefreshDatabaseState::$migrated = true;
            RefreshDatabaseState::$inMemoryConnections[$this->connectionName()]
                = $this->inheritedPdo('TestCase binding');
        }
    }

    public function preflightTestCase(TestCase $testCase): void
    {
        $traits = class_uses_recursive($testCase);

        foreach (self::UNSUPPORTED_DATABASE_TRAITS as $trait) {
            if (in_array($trait, $traits, true)) {
                throw new StateAdapterException(sprintf(
                    'Drove Laravel sqlite-memory mode does not support TestCase trait %s.',
                    $trait,
                ));
            }
        }

        if (in_array(RefreshDatabase::class, $traits, true)) {
            if (! $this->preparedSchema) {
                throw new StateAdapterException(
                    'Drove Laravel sqlite-memory mode requires prepared_schema=true before using RefreshDatabase; Drove will not skip migrations implicitly.',
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    public function limitations(): array
    {
        return [
            'Exactly one resolved SQLite connection configured as literal :memory: is supported.',
            'Isolation relies on Linux fork copy-on-write with the prepared parent PDO kept open.',
            'Reconnects, disconnects before descendant setup, URI databases, attached databases, and multiple connections are unsupported.',
            'RefreshDatabase requires prepared_schema=true and reuses that explicitly prepared inherited PDO; migration and truncation traits are rejected.',
            'Queue, cache, Redis, HTTP, filesystem writes, and other external resources are not managed.',
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

        if ($this->dispatchPid === $pid) {
            throw new StateAdapterException(
                'An SQLite in-memory Drove dispatch is already active.',
            );
        }

        $this->assertOnlySelectedConnectionIsResolved();
        $connection = $this->connection();
        $this->assertNoOpenTransaction($connection, 'beforeDispatch');
        $this->inheritedPdo('beforeDispatch');
        $this->dispatchPid = $pid;
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
            throw new StateAdapterException(
                'An SQLite in-memory descendant is already active.',
            );
        }

        $this->dispatchPid = null;
        $this->descendantPid = $pid;
        $this->descendantId = $task['id'];
        $this->descendantKind = $task['kind'];
        $this->assertOnlySelectedConnectionIsResolved();
        $connection = $this->connection();
        $this->assertNoOpenTransaction($connection, 'enterDescendant');
        $this->inheritedPdo('enterDescendant');
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function leaveDescendant(ScopeContext $scope, array $task): void
    {
        $this->assertScopeApplication($scope);
        $this->assertTask($task);
        $pid = getmypid();

        if ($this->descendantPid !== $pid
            || $this->descendantId !== $task['id']
            || $this->descendantKind !== $task['kind']) {
            throw new StateAdapterException(
                'The SQLite in-memory descendant lifecycle is unbalanced.',
            );
        }

        $failure = null;

        try {
            $this->assertOnlySelectedConnectionIsResolved();
            $connection = $this->connection();
            $level = $connection->transactionLevel();

            if ($level !== 0) {
                $failure = new StateAdapterException(sprintf(
                    'SQLite in-memory descendant %s left transaction depth %d.',
                    $task['id'],
                    $level,
                ));

                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
        } catch (Throwable $throwable) {
            $failure ??= new StateAdapterException(
                sprintf(
                    'Drove could not close SQLite in-memory descendant %s.',
                    $task['id'],
                ),
                previous: $throwable,
            );
        } finally {
            $this->descendantPid = null;
            $this->descendantId = null;
            $this->descendantKind = null;
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

        if ($this->dispatchPid === null) {
            return;
        }

        $this->assertTasks($tasks);

        if ($this->dispatchPid !== getmypid()) {
            throw new StateAdapterException(
                'The SQLite in-memory dispatch lifecycle is unbalanced.',
            );
        }

        $this->dispatchPid = null;
        $this->assertOnlySelectedConnectionIsResolved();
        $connection = $this->connection();
        $this->assertNoOpenTransaction($connection, 'afterDispatch');
        $this->inheritedPdo('afterDispatch');
    }

    protected function validateConnection(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite') {
            throw new StateAdapterException(sprintf(
                'The SQLite in-memory adapter requires sqlite; %s was configured.',
                $connection->getDriverName(),
            ));
        }

        $database = $this->config()
            ->get('database.connections.'.$this->connectionName().'.database');

        if ($database !== ':memory:') {
            throw new StateAdapterException(
                'The SQLite in-memory adapter requires database=:memory:.',
            );
        }

        $this->assertNoOpenTransaction($connection, 'adapter boot');
        $connection->getPdo();
        $pdo = $connection->getRawPdo();

        if (! $pdo instanceof PDO) {
            throw new StateAdapterException(
                'The SQLite in-memory adapter could not open its parent PDO connection.',
            );
        }

        $databases = array_values(array_filter(
            $connection->select('PRAGMA database_list'),
            static fn (object $database): bool => ($database->name ?? null) !== 'temp',
        ));

        if (count($databases) !== 1
            || ($databases[0]->name ?? null) !== 'main'
            || ($databases[0]->file ?? null) !== '') {
            throw new StateAdapterException(
                'Drove SQLite in-memory mode supports exactly one unattached main database.',
            );
        }
    }

    private function inheritedPdo(string $phase): PDO
    {
        $pdo = $this->connection()->getRawPdo();

        if (! $pdo instanceof PDO
            || $this->pdoId === null
            || spl_object_id($pdo) !== $this->pdoId) {
            throw new StateAdapterException(sprintf(
                'The SQLite in-memory PDO was replaced or disconnected during %s.',
                $phase,
            ));
        }

        return $pdo;
    }

    private function pid(string $phase): int
    {
        $pid = getmypid();

        if (! is_int($pid)) {
            throw new StateAdapterException(sprintf(
                'Drove could not resolve the SQLite in-memory process during %s.',
                $phase,
            ));
        }

        return $pid;
    }
}
