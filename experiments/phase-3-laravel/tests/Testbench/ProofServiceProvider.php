<?php

declare(strict_types=1);

namespace Tests\Testbench;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class ProofServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $directory = getenv('DROVE_TESTBENCH_PROOF_DIRECTORY');

        if (! is_string($directory) || $directory === '') {
            throw new RuntimeException(
                'DROVE_TESTBENCH_PROOF_DIRECTORY must identify the proof directory.',
            );
        }

        if (! is_dir($directory)
            && ! mkdir($directory, 0777, true)
            && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the Testbench proof directory.');
        }

        if (file_put_contents(
            $directory.'/boot-pids.log',
            getmypid().PHP_EOL,
            FILE_APPEND | LOCK_EX,
        ) === false) {
            throw new RuntimeException('Unable to record the Testbench bootstrap PID.');
        }

        $connection = $this->app->make('db')->connection('testbench');
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('drove_testbench_proof')) {
            $schema->create(
                'drove_testbench_proof',
                static function (Blueprint $table): void {
                    $table->id();
                    $table->string('name')->unique();
                },
            );
        }

        if (! $connection->table('drove_testbench_proof')
            ->where('name', 'prepared')
            ->exists()) {
            $connection->table('drove_testbench_proof')->insert([
                'name' => 'prepared',
            ]);
        }
    }
}
