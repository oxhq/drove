<?php

declare(strict_types=1);

namespace Drove\Laravel {
    final class LaravelMigrationProof
    {
        /** @var list<array{string, mixed}> */
        public static array $calls = [];
    }

    final class LaravelMigrationProofResponse
    {
        public function assertOk(): self
        {
            LaravelMigrationProof::$calls[] = ['assertOk', 200];

            return $this;
        }
    }

    final class LaravelMigrationProofContext
    {
        /** @param array<string, mixed> $data */
        public function assertDatabaseHas(string $table, array $data): self
        {
            LaravelMigrationProof::$calls[] = ['assertDatabaseHas', [$table, $data]];

            return $this;
        }

        public function application(): self
        {
            LaravelMigrationProof::$calls[] = ['application', null];

            return $this;
        }

        public function mark(string $value): void
        {
            LaravelMigrationProof::$calls[] = ['mark', $value];
        }
    }

    function getJson(string $uri): LaravelMigrationProofResponse
    {
        LaravelMigrationProof::$calls[] = ['getJson', $uri];

        return new LaravelMigrationProofResponse;
    }

    function laravelContext(): LaravelMigrationProofContext
    {
        LaravelMigrationProof::$calls[] = ['laravelContext', null];

        return new LaravelMigrationProofContext;
    }
}

namespace Drove\Native {
    use Drove\Laravel\LaravelMigrationProof;

    function test(string $description, \Closure $body): void
    {
        LaravelMigrationProof::$calls[] = ['test', $description];
        $body();
    }
}
