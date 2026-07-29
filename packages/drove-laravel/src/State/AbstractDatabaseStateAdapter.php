<?php

declare(strict_types=1);

namespace Drove\Laravel\State;

use Drove\Kernel\ScopeContext;
use Drove\Kernel\StateAdapterException;
use Drove\Laravel\Contracts\DatabaseStateAdapter;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

abstract class AbstractDatabaseStateAdapter implements DatabaseStateAdapter
{
    protected ?Application $application = null;

    protected ?DatabaseManager $database = null;

    protected ?ConfigRepository $config = null;

    protected ?string $resolvedConnection = null;

    public function __construct(
        protected readonly ?string $configuredConnection = null,
    ) {
        //
    }

    public function boot(Application $application): void
    {
        if ($this->application instanceof Application) {
            throw new StateAdapterException(sprintf(
                'The %s adapter was already booted.',
                $this->name(),
            ));
        }

        $database = $application->make('db');
        $config = $application->make('config');

        if (! $database instanceof DatabaseManager) {
            throw new StateAdapterException('Laravel did not resolve its database manager.');
        }

        if (! $config instanceof ConfigRepository) {
            throw new StateAdapterException('Laravel did not resolve its configuration repository.');
        }

        $connection = $this->configuredConnection ?? $database->getDefaultConnection();

        if (! is_string($connection) || $connection === '') {
            throw new StateAdapterException('Drove requires one named Laravel database connection.');
        }

        $this->application = $application;
        $this->database = $database;
        $this->config = $config;
        $this->resolvedConnection = $connection;
        $this->assertOnlySelectedConnectionIsResolved();
        $this->validateConnection($this->connection());
    }

    public function assertTestCaseSupported(TestCase $testCase): void
    {
        if (! method_exists($testCase, 'connectionsToTransact')) {
            return;
        }

        try {
            $method = new ReflectionMethod($testCase, 'connectionsToTransact');
            $method->setAccessible(true);
            $connections = $method->invoke($testCase);
        } catch (Throwable $throwable) {
            throw new StateAdapterException(
                'Drove could not inspect the Laravel TestCase database connections.',
                previous: $throwable,
            );
        }

        if (! is_array($connections)) {
            throw new StateAdapterException(
                'Laravel TestCase::connectionsToTransact() must return an array for Drove.',
            );
        }

        $selected = $this->connectionName();
        $connections = array_values(array_unique(array_map(
            static fn (mixed $connection): string => $connection === null
                ? $selected
                : (is_string($connection) ? $connection : ''),
            $connections,
        )));

        if ($connections !== [] && $connections !== [$selected]) {
            throw new StateAdapterException(sprintf(
                'Drove Laravel supports one TestCase database connection (%s); received [%s].',
                $selected,
                implode(', ', $connections),
            ));
        }
    }

    abstract public function name(): string;

    protected function application(): Application
    {
        return $this->application ?? throw new StateAdapterException(
            sprintf('The %s adapter has not been booted.', $this->name()),
        );
    }

    protected function database(): DatabaseManager
    {
        return $this->database ?? throw new StateAdapterException(
            sprintf('The %s adapter has not been booted.', $this->name()),
        );
    }

    protected function config(): ConfigRepository
    {
        return $this->config ?? throw new StateAdapterException(
            sprintf('The %s adapter has not been booted.', $this->name()),
        );
    }

    protected function connectionName(): string
    {
        return $this->resolvedConnection ?? throw new StateAdapterException(
            sprintf('The %s adapter has not been booted.', $this->name()),
        );
    }

    protected function connection(): Connection
    {
        return $this->database()->connection($this->connectionName());
    }

    protected function reconnect(): Connection
    {
        $connection = $this->database()->reconnect($this->connectionName());

        if (! $connection instanceof Connection) {
            throw new StateAdapterException(sprintf(
                'Laravel could not reconnect database connection %s.',
                $this->connectionName(),
            ));
        }

        return $connection;
    }

    protected function disconnect(): void
    {
        $this->database()->disconnect($this->connectionName());
    }

    protected function purge(): void
    {
        $this->database()->purge($this->connectionName());
    }

    protected function assertOnlySelectedConnectionIsResolved(): void
    {
        $connections = array_keys($this->database()->getConnections());
        $secondary = array_values(array_filter(
            $connections,
            fn (string $connection): bool => $connection !== $this->connectionName(),
        ));

        if ($secondary !== []) {
            throw new StateAdapterException(sprintf(
                'Drove Laravel does not support multiple resolved database connections; %s is active alongside %s.',
                implode(', ', $secondary),
                $this->connectionName(),
            ));
        }
    }

    protected function assertNoOpenTransaction(Connection $connection, string $phase): void
    {
        if ($connection->transactionLevel() !== 0) {
            throw new StateAdapterException(sprintf(
                'Database connection %s has an open transaction during %s.',
                $this->connectionName(),
                $phase,
            ));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    protected function assertTasks(array $tasks): void
    {
        if (! array_is_list($tasks)) {
            throw new StateAdapterException('Drove Laravel requires a list of dispatch tasks.');
        }

        $ids = [];

        foreach ($tasks as $task) {
            $this->assertTask($task);
            $id = $task['id'];

            if (isset($ids[$id])) {
                throw new StateAdapterException(sprintf(
                    'Drove Laravel received duplicate dispatch task %s.',
                    $id,
                ));
            }

            $ids[$id] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    protected function assertTask(array $task): void
    {
        if (! is_string($task['id'] ?? null)
            || $task['id'] === ''
            || ! in_array($task['kind'] ?? null, ['scope', 'test'], true)) {
            throw new StateAdapterException('Drove Laravel received an invalid descendant task.');
        }
    }

    protected function assertScopeApplication(ScopeContext $scope): void
    {
        if ($scope->app() !== $this->application()) {
            throw new StateAdapterException(
                'Drove Laravel received a scope from a different application.',
            );
        }
    }

    abstract protected function validateConnection(Connection $connection): void;
}
