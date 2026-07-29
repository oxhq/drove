<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

beforeAll(function (): void {
    $app = TestCase::$preparedApplication;

    if (! $app instanceof Application) {
        throw new RuntimeException('The root application was not prepared.');
    }

    $proof = $GLOBALS['drove_before_all_proof'];
    $proof->count++;
    $proof->pid = getmypid();

    Schema::dropIfExists('prepared_fixtures');
    Schema::create('prepared_fixtures', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    $scope = $GLOBALS['drove_scope_state'];
    $scope->fixture_id = DB::table('prepared_fixtures')->insertGetId(['name' => 'prepared once']);
    $scope->mutations[] = 'prepared';
    $app->instance('drove.fixture_id', $scope->fixture_id);
});

afterAll(function (): void {
    $proof = $GLOBALS['drove_after_all_proof'];
    $proof->count++;
    $proof->pid = getmypid();
});

it('reads root-prepared state in a forked child', function (): void {
    $scope = $GLOBALS['drove_scope_state'];
    $scope->mutations[] = 'child';

    $GLOBALS['drove_pest_execution'] = [
        'pid' => getmypid(),
        'class' => $this::class,
        'method' => $this->name(),
        'mutations' => $scope->mutations,
    ];

    $rootPid = $this->app->make('drove.root_pid');

    expect(getmypid())->not->toBe($rootPid)
        ->and($GLOBALS['drove_laravel_boot_pids'])->toBe([$rootPid])
        ->and(spl_object_id($this->app))->toBe($this->app->make('drove.application_object_id'));

    $this->assertDatabaseHas('prepared_fixtures', [
        'id' => $this->app->make('drove.fixture_id'),
        'name' => 'prepared once',
    ]);
});
