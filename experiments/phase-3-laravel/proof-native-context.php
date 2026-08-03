<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Drove\Kernel\DroverScheduler;
use Drove\Laravel\DroveLaravelServiceProvider;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Native\Declarations;
use Drove\Native\Runner;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use function Drove\Laravel\deleteJson;
use function Drove\Laravel\getJson;
use function Drove\Laravel\laravel;
use function Drove\Laravel\laravelContext;
use function Drove\Laravel\patchJson;
use function Drove\Laravel\postJson;
use function Drove\Laravel\putJson;
use function Drove\Native\test;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/native-phase-4/support.php';

function nativeLaravelAssertExternalRuntimeAbsent(string $evidenceFile): void
{
    $classes = array_values(array_filter(
        get_declared_classes(),
        static fn (string $class): bool => in_array($class, [
            'PHPUnit\\Framework\\TestCase',
            'PHPUnit\\Framework\\TestSuite',
            'PHPUnit\\Runner\\TestSuiteLoader',
            'PHPUnit\\TextUI\\Configuration\\TestSuiteBuilder',
            'Orchestra\\Testbench\\TestCase',
        ], true),
    ));
    $files = array_values(array_filter(
        array_map(static fn (string $file): string => str_replace('\\', '/', $file), get_included_files()),
        static fn (string $file): bool => preg_match(
            '~/(?:phpunit/phpunit/src/(?:Framework/(?:TestCase|TestSuite)|Runner/TestSuiteLoader|TextUI/Configuration/TestSuiteBuilder)|orchestra/testbench(?:-core)?/src/(?:TestCase|Concerns)/)~',
            $file,
        ) === 1,
    ));

    $record = json_encode([
        'pid' => getmypid(),
        'classes' => $classes,
        'files' => $files,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

    if (file_put_contents($evidenceFile, $record, FILE_APPEND | LOCK_EX) !== strlen($record)) {
        throw new RuntimeException('Native Laravel could not record descendant runtime evidence.');
    }

    if ($classes !== [] || $files !== []) {
        throw new RuntimeException('DROVE_NATIVE_LARAVEL_EXECUTION_RUNTIME_LOADED');
    }
}

$token = bin2hex(random_bytes(6));
$proofDirectoryName = 'drove-native-context-'.$token;
$proofDirectory = __DIR__.'/storage/framework/'.$proofDirectoryName;
$runtimeEvidenceFile = sys_get_temp_dir().'/drove-native-context-runtime-'.$token.'.jsonl';

try {
    nativePhaseFourEnvironment([
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DROVE_LARAVEL' => '1',
        'DROVE_LARAVEL_PROOF_DIRECTORY' => $proofDirectoryName,
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ]);

    $phpunitLoadedBefore = class_exists('PHPUnit\\Framework\\TestCase', false);
    $testbenchLoadedBefore = class_exists('Orchestra\\Testbench\\TestCase', false);
    $registry = Declarations::capture(
        static function () use ($runtimeEvidenceFile): void {
            laravel(
                __DIR__,
                new InMemorySqliteDatabaseStateAdapter('sqlite', true),
                [
                    AppServiceProvider::class,
                    DroveLaravelServiceProvider::class,
                ],
                static function (Application $application): void {
                    $database = $application->make('db')->connection('sqlite');
                    $database->statement(
                        'CREATE TABLE native_context_rows ('
                        .'id INTEGER PRIMARY KEY, value TEXT NOT NULL)',
                    );
                    $database->table('native_context_rows')->insert([
                        'id' => 1,
                        'value' => 'prepared',
                    ]);

                    $router = $application->make('router');
                    $router->get('/native-context/json', static fn (Request $request) => response()->json([
                        'ok' => true,
                        'meta' => [
                            'header' => $request->header('X-Drove-Proof'),
                            'pid' => getmypid(),
                        ],
                    ]));
                    $router->match(
                        ['POST', 'PUT', 'PATCH', 'DELETE'],
                        '/native-context/method',
                        static fn (Request $request) => response()->json([
                            'method' => $request->method(),
                            'payload' => $request->json()->all(),
                        ], $request->isMethod('POST') ? 201 : 200),
                    );
                    $router->get(
                        '/native-context/html',
                        static fn () => new Response('window.InvoiceShelf.start()'),
                    );
                    $router->redirect('/native-context/redirect', '/native-context/html');
                    $router->get(
                        '/native-context/validation',
                        static fn () => response()->json([
                            'message' => 'Invalid input.',
                            'errors' => ['email' => ['The email is invalid.']],
                        ], 422),
                    );
                    $router->get(
                        '/native-context/forbidden',
                        static fn () => response()->json([], 403),
                    );
                    $router->get(
                        '/native-context/not-found',
                        static fn () => response()->json([], 404),
                    );
                    $router->get(
                        '/native-context/no-content',
                        static fn () => response('', 204),
                    );
                },
            );

            test('native InvoiceShelf-shaped HTTP and database helpers', function () use ($runtimeEvidenceFile): void {
                $context = laravelContext()->withHeaders([
                    'X-Drove-Proof' => 'native',
                ]);
                $context->withoutVite();

                if ((string) $context->application()->make(Vite::class)(['resources/js/app.js']) !== '') {
                    throw new RuntimeException('Native Laravel withoutVite did not replace Vite.');
                }

                getJson('/native-context/json')
                    ->assertOk()
                    ->assertJson(['ok' => true, 'meta' => ['header' => 'native']])
                    ->assertJsonPath('meta.header', 'native');
                postJson('/native-context/method', ['name' => 'Drove'])
                    ->assertCreated()
                    ->assertJson(['method' => 'POST', 'payload' => ['name' => 'Drove']]);
                putJson('/native-context/method', ['verb' => 'put'])
                    ->assertJsonPath('method', 'PUT');
                patchJson('/native-context/method', ['verb' => 'patch'])
                    ->assertJsonPath('method', 'PATCH');
                deleteJson('/native-context/method', ['verb' => 'delete'])
                    ->assertJsonPath('method', 'DELETE');

                $context->application()->make('db')->connection('sqlite')
                    ->table('native_context_rows')
                    ->insert(['id' => 2, 'value' => 'child']);
                $context
                    ->assertDatabaseHas('native_context_rows', ['id' => 2, 'value' => 'child'])
                    ->assertDatabaseMissing('native_context_rows', ['id' => 3])
                    ->assertDatabaseCount('native_context_rows', 2);
                nativeLaravelAssertExternalRuntimeAbsent($runtimeEvidenceFile);
            });

            test('native Laravel response assertions and state isolation', function () use ($runtimeEvidenceFile): void {
                $context = laravelContext();
                $context
                    ->assertDatabaseMissing('native_context_rows', ['id' => 2])
                    ->assertDatabaseCount('native_context_rows', 1);
                $context->followingRedirects()
                    ->get('/native-context/redirect')
                    ->assertOk()
                    ->assertSee('window.InvoiceShelf.start()', false);
                getJson('/native-context/validation')
                    ->assertStatus(422)
                    ->assertJsonValidationErrors(['email']);
                getJson('/native-context/forbidden')->assertForbidden();
                getJson('/native-context/not-found')->assertNotFound();
                getJson('/native-context/no-content')->assertNoContent();
                nativeLaravelAssertExternalRuntimeAbsent($runtimeEvidenceFile);
            });
        },
        __DIR__,
        'Drove native Laravel context proof',
    );
    $run = new Runner(new DroverScheduler(
        'native-laravel-context-'.$token,
        2,
    ))->run($registry);
    $tests = $run['tests'] ?? null;
    $phpunitLoadedAfter = class_exists('PHPUnit\\Framework\\TestCase', false);
    $testbenchLoadedAfter = class_exists('Orchestra\\Testbench\\TestCase', false);
    $runtimeEvidence = is_file($runtimeEvidenceFile)
        ? file($runtimeEvidenceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        : false;

    if (! is_array($runtimeEvidence)) {
        throw new RuntimeException('Native Laravel descendants produced no runtime evidence.');
    }

    $runtimeChecks = array_map(
        static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR),
        $runtimeEvidence,
    );
    $rootRows = laravelContext()->application()->make('db')->connection('sqlite')
        ->table('native_context_rows')
        ->orderBy('id')
        ->get(['id', 'value'])
        ->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'value' => $row->value,
        ])
        ->all();

    if (! is_array($tests)
        || ($run['status'] ?? null) !== 'passed'
        || ($run['exit_code'] ?? null) !== 0
        || count($tests) !== 2
        || array_column($tests, 'status') !== ['passed', 'passed']
        || array_column($tests, 'assertions') !== [11, 10]
        || $rootRows !== [['id' => 1, 'value' => 'prepared']]
        || $phpunitLoadedBefore
        || $phpunitLoadedAfter
        || $testbenchLoadedBefore
        || $testbenchLoadedAfter
        || count($runtimeChecks) !== 2
        || ! array_all($runtimeChecks, static fn (array $check): bool => is_int($check['pid'] ?? null)
            && $check['pid'] > 0
            && ($check['classes'] ?? null) === []
            && ($check['files'] ?? null) === [])) {
        throw new RuntimeException('Native Laravel context proof diverged: '.json_encode([
            'run_status' => $run['status'] ?? null,
            'exit_code' => $run['exit_code'] ?? null,
            'tests' => $tests,
            'root_rows' => $rootRows,
            'phpunit_loaded_before' => $phpunitLoadedBefore,
            'phpunit_loaded_after' => $phpunitLoadedAfter,
            'testbench_loaded_before' => $testbenchLoadedBefore,
            'testbench_loaded_after' => $testbenchLoadedAfter,
            'runtime_checks' => $runtimeChecks,
        ], JSON_THROW_ON_ERROR));
    }

    echo json_encode([
        'ok' => true,
        'tests' => count($tests),
        'assertions' => array_sum(array_column($tests, 'assertions')),
        'statuses' => array_column($tests, 'status'),
        'root_rows' => $rootRows,
        'phpunit_execution_loaded' => $phpunitLoadedAfter,
        'testbench_lifecycle_loaded' => $testbenchLoadedAfter,
        'descendant_runtime_checks' => count($runtimeChecks),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
} finally {
    if (is_file($runtimeEvidenceFile)) {
        unlink($runtimeEvidenceFile);
    }

    nativePhaseFourRemove($proofDirectory);
}
