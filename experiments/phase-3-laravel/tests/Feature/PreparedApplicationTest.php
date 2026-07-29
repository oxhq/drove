<?php

declare(strict_types=1);

use Drove\Laravel\LaravelRuntime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function droveProofDirectory(): string
{
    return storage_path('framework/drove-proof');
}

/**
 * @param  array<string, mixed>  $payload
 */
function recordDroveProof(string $name, array $payload): void
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

    if (file_put_contents(droveProofDirectory().'/'.$name.'.json', $encoded, LOCK_EX) === false) {
        throw new RuntimeException(sprintf('Unable to record Drove proof %s.', $name));
    }
}

beforeAll(function (): void {
    Schema::dropIfExists('prepared_fixtures');
    Schema::create('prepared_fixtures', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    DB::table('prepared_fixtures')->insert(['name' => 'prepared']);

    recordDroveProof('scope', [
        'pid' => getmypid(),
        'root_pid' => app(LaravelRuntime::ROOT_PID_BINDING),
        'application_id' => spl_object_id(app()),
        'rows' => DB::table('prepared_fixtures')->pluck('name')->all(),
    ]);
});

afterAll(function (): void {
    $rows = DB::table('prepared_fixtures')->orderBy('id')->pluck('name')->all();

    expect($rows)->toBe(['prepared']);

    recordDroveProof('after', [
        'pid' => getmypid(),
        'root_pid' => app(LaravelRuntime::ROOT_PID_BINDING),
        'application_id' => spl_object_id(app()),
        'rows' => $rows,
    ]);
});

it('isolates the alpha descendant', function (): void {
    DB::table('prepared_fixtures')->insert(['name' => 'alpha']);
    $rows = DB::table('prepared_fixtures')->orderBy('id')->pluck('name')->all();

    expect($this->app)->toBe(app())
        ->and(getmypid())->not->toBe(app(LaravelRuntime::ROOT_PID_BINDING))
        ->and($rows)->toBe(['prepared', 'alpha']);

    recordDroveProof('alpha', [
        'pid' => getmypid(),
        'root_pid' => app(LaravelRuntime::ROOT_PID_BINDING),
        'application_id' => spl_object_id($this->app),
        'rows' => $rows,
    ]);
});

it('isolates the beta descendant', function (): void {
    DB::table('prepared_fixtures')->insert(['name' => 'beta']);
    $rows = DB::table('prepared_fixtures')->orderBy('id')->pluck('name')->all();

    expect($this->app)->toBe(app())
        ->and(getmypid())->not->toBe(app(LaravelRuntime::ROOT_PID_BINDING))
        ->and($rows)->toBe(['prepared', 'beta']);

    recordDroveProof('beta', [
        'pid' => getmypid(),
        'root_pid' => app(LaravelRuntime::ROOT_PID_BINDING),
        'application_id' => spl_object_id($this->app),
        'rows' => $rows,
    ]);
});
