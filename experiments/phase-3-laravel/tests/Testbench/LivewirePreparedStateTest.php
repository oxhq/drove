<?php

declare(strict_types=1);

use Drove\Laravel\LaravelRuntime;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Testbench\ProofCounter;

$proofDirectory = static function (): string {
    $directory = getenv('DROVE_TESTBENCH_PROOF_DIRECTORY');

    if (! is_string($directory) || $directory === '') {
        throw new RuntimeException(
            'DROVE_TESTBENCH_PROOF_DIRECTORY must identify the proof directory.',
        );
    }

    return $directory;
};

$record = static function (string $name) use ($proofDirectory): void {
    $runtime = app(LaravelRuntime::class);
    $rows = DB::connection('testbench')
        ->table('drove_testbench_proof')
        ->orderBy('name')
        ->pluck('name')
        ->all();
    $payload = [
        'pid' => getmypid(),
        'root_pid' => app(LaravelRuntime::ROOT_PID_BINDING),
        'application_id' => spl_object_id(app()),
        'runtime_application_id' => spl_object_id($runtime->application()),
        'adapter' => $runtime->stateAdapter()->name(),
        'rows' => $rows,
    ];

    file_put_contents(
        $proofDirectory().'/'.$name.'.json',
        json_encode($payload, JSON_THROW_ON_ERROR),
        LOCK_EX,
    );
};

beforeAll(static function () use ($record): void {
    $record('scope');
});

afterAll(static function () use ($record): void {
    $record('after');
});

it('isolates the alpha Livewire case', function () use ($record): void {
    Livewire::test(ProofCounter::class)
        ->call('increment')
        ->assertSet('count', 1);

    DB::connection('testbench')
        ->table('drove_testbench_proof')
        ->insert(['name' => 'alpha']);

    $record('alpha');
});

it('isolates the beta Livewire case', function () use ($record): void {
    Livewire::test(ProofCounter::class)
        ->call('increment')
        ->call('increment')
        ->assertSet('count', 2);

    DB::connection('testbench')
        ->table('drove_testbench_proof')
        ->insert(['name' => 'beta']);

    $record('beta');
});
