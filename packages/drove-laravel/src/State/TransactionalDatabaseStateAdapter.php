<?php

declare(strict_types=1);

namespace Drove\Laravel\State;

use Drove\Environment\ResourceCapability;
use Drove\Environment\ResourceKind;
use Drove\Environment\ResourcePlan;
use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;

final class TransactionalDatabaseStateAdapter extends AbstractDatabaseStateAdapter
{
    /** @var list<class-string> */
    private const array UNSUPPORTED_DATABASE_TRAITS = [
        LazilyRefreshDatabase::class,
        RefreshDatabase::class,
        DatabaseMigrations::class,
        DatabaseTransactions::class,
        DatabaseTruncation::class,
    ];

    private ?int $dispatchPid = null;

    private ?int $descendantPid = null;

    private ?string $descendantId = null;

    private ?string $descendantKind = null;

    private bool $transactionStarted = false;

    public function name(): string
    {
        return 'transaction';
    }

    public function resourcePlan(): ResourcePlan
    {
        return new ResourcePlan(
            ResourceKind::Database,
            $this->name(),
            [
                ResourceCapability::LeafIsolated,
            ],
            $this->limitations(),
        );
    }

    public function preflightTestCase(TestCase $testCase): void
    {
        $traits = class_uses_recursive($testCase);

        foreach (self::UNSUPPORTED_DATABASE_TRAITS as $trait) {
            if (in_array($trait, $traits, true)) {
                throw new StateAdapterException(sprintf(
                    'Drove Laravel transaction mode does not support TestCase trait %s.',
                    $trait,
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    public function limitations(): array
    {
        return [
            'Exactly one resolved MySQL connection in a disposable test database with InnoDB tables is supported.',
            'Only test descendants are isolated by rollback; scope hooks are not transaction-isolated.',
            'A dispatch containing a scope must contain only that one scope.',
            'Laravel-managed migration, transaction, and truncation test traits are rejected.',
            'DDL, explicit commits, implicit commits, reconnects, raw PDO transactions, and writes outside the selected connection are unsupported.',
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
        $pid = getmypid();

        if ($this->dispatchPid === $pid) {
            throw new StateAdapterException('A transactional Drove dispatch is already active.');
        }

        $this->assertOnlySelectedConnectionIsResolved();
        $connection = $this->connection();
        $this->assertNoOpenTransaction($connection, 'beforeDispatch');
        $this->assertTransactionalTables($connection);
        $this->disconnect();
        $this->dispatchPid = $pid;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function enterDescendant(ScopeContext $scope, array $task): void
    {
        $this->assertScopeApplication($scope);
        $this->assertTask($task);
        $pid = getmypid();

        if ($this->descendantPid === $pid) {
            throw new StateAdapterException('A transactional descendant is already active.');
        }

        $this->dispatchPid = null;
        $this->descendantPid = $pid;
        $this->descendantId = $task['id'];
        $this->descendantKind = $task['kind'];
        $this->transactionStarted = false;
        $this->assertOnlySelectedConnectionIsResolved();
        $connection = $this->reconnect();
        $this->assertNoOpenTransaction($connection, 'enterDescendant');

        if ($task['kind'] === 'test') {
            $connection->beginTransaction();
            $this->transactionStarted = true;
        }
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
            throw new StateAdapterException('The transactional descendant lifecycle is unbalanced.');
        }

        $this->assertOnlySelectedConnectionIsResolved();
        $connection = $this->connection();
        $level = $connection->transactionLevel();
        $failure = null;

        try {
            if ($this->transactionStarted && $level !== 1) {
                $failure = new StateAdapterException(sprintf(
                    'Test %s left transaction depth %d; Drove requires exactly one adapter transaction.',
                    $task['id'],
                    $level,
                ));
            } elseif (! $this->transactionStarted && $level !== 0) {
                $failure = new StateAdapterException(sprintf(
                    'Scope %s left an open database transaction.',
                    $task['id'],
                ));
            }

            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable $throwable) {
            $failure ??= new StateAdapterException(
                sprintf('Drove could not roll back descendant %s.', $task['id']),
                previous: $throwable,
            );
        } finally {
            $this->disconnect();
            $this->descendantPid = null;
            $this->descendantId = null;
            $this->descendantKind = null;
            $this->transactionStarted = false;
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
            return;
        }

        $this->assertTasks($tasks);

        if ($this->dispatchPid !== $pid) {
            throw new StateAdapterException('The transactional dispatch lifecycle is unbalanced.');
        }

        $this->dispatchPid = null;
        $this->assertOnlySelectedConnectionIsResolved();
        $connection = $this->reconnect();
        $this->assertNoOpenTransaction($connection, 'afterDispatch');
    }

    protected function validateConnection(Connection $connection): void
    {
        $driver = $connection->getDriverName();

        if ($driver !== 'mysql') {
            throw new StateAdapterException(sprintf(
                'The transactional adapter currently supports only MySQL; %s was configured.',
                $driver,
            ));
        }

        $this->assertNoOpenTransaction($connection, 'adapter boot');
    }

    private function assertTransactionalTables(Connection $connection): void
    {
        $tables = $connection->select(
            <<<'SQL'
SELECT TABLE_NAME AS table_name, ENGINE AS engine
FROM information_schema.tables
WHERE TABLE_SCHEMA = ?
  AND TABLE_TYPE = 'BASE TABLE'
  AND (ENGINE IS NULL OR UPPER(ENGINE) <> 'INNODB')
ORDER BY TABLE_NAME
SQL,
            [$connection->getDatabaseName()],
        );

        if ($tables === []) {
            return;
        }

        $unsupported = array_map(
            static fn (object $table): string => sprintf(
                '%s (%s)',
                (string) ($table->table_name ?? 'unknown'),
                (string) ($table->engine ?? 'unknown'),
            ),
            $tables,
        );

        throw new StateAdapterException(sprintf(
            'Drove Laravel transaction mode requires InnoDB tables; received %s.',
            implode(', ', $unsupported),
        ));
    }
}
