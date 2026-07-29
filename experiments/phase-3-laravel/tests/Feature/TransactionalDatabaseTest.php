<?php

declare(strict_types=1);

use Drove\Laravel\LaravelRuntime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function droveMysqlProofDirectory(): string
{
    return storage_path('framework/drove-proof-mysql');
}

/**
 * @param  array<string, mixed>  $payload
 */
function recordDroveMysqlProof(string $name, array $payload): void
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

    if (file_put_contents(
        droveMysqlProofDirectory().'/'.$name.'.json',
        $encoded,
        LOCK_EX,
    ) === false) {
        throw new RuntimeException(sprintf('Unable to record MySQL proof %s.', $name));
    }
}

beforeAll(function (): void {
    Schema::dropIfExists('drove_transaction_proof');
    Schema::create('drove_transaction_proof', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    DB::table('drove_transaction_proof')->insert(['name' => 'prepared']);

    recordDroveMysqlProof('scope', [
        'pid' => getmypid(),
        'root_pid' => app(LaravelRuntime::ROOT_PID_BINDING),
        'application_id' => spl_object_id(app()),
        'transaction_level' => DB::connection()->transactionLevel(),
        'rows' => DB::table('drove_transaction_proof')->pluck('name')->all(),
    ]);
});

afterAll(function (): void {
    try {
        $rows = DB::table('drove_transaction_proof')->orderBy('id')->pluck('name')->all();

        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and($rows)->toBe(['prepared']);

        recordDroveMysqlProof('after', [
            'pid' => getmypid(),
            'root_pid' => app(LaravelRuntime::ROOT_PID_BINDING),
            'application_id' => spl_object_id(app()),
            'transaction_level' => DB::connection()->transactionLevel(),
            'rows' => $rows,
        ]);
    } finally {
        Schema::dropIfExists('drove_transaction_proof');
    }
});

it('rolls back the alpha transaction', function (): void {
    expect(DB::connection()->transactionLevel())->toBe(1);

    DB::table('drove_transaction_proof')->insert(['name' => 'alpha']);
    $rows = DB::table('drove_transaction_proof')->orderBy('id')->pluck('name')->all();

    expect($this->app)->toBe(app())
        ->and(getmypid())->not->toBe(app(LaravelRuntime::ROOT_PID_BINDING))
        ->and($rows)->toBe(['prepared', 'alpha']);

    recordDroveMysqlProof('alpha', [
        'pid' => getmypid(),
        'root_pid' => app(LaravelRuntime::ROOT_PID_BINDING),
        'application_id' => spl_object_id($this->app),
        'transaction_level' => DB::connection()->transactionLevel(),
        'rows' => $rows,
    ]);
});

it('rolls back the beta transaction', function (): void {
    expect(DB::connection()->transactionLevel())->toBe(1);

    DB::table('drove_transaction_proof')->insert(['name' => 'beta']);
    $rows = DB::table('drove_transaction_proof')->orderBy('id')->pluck('name')->all();

    expect($this->app)->toBe(app())
        ->and(getmypid())->not->toBe(app(LaravelRuntime::ROOT_PID_BINDING))
        ->and($rows)->toBe(['prepared', 'beta']);

    recordDroveMysqlProof('beta', [
        'pid' => getmypid(),
        'root_pid' => app(LaravelRuntime::ROOT_PID_BINDING),
        'application_id' => spl_object_id($this->app),
        'transaction_level' => DB::connection()->transactionLevel(),
        'rows' => $rows,
    ]);
});
