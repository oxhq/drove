<?php

declare(strict_types=1);

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Drove\Compatibility\Registry as CompatibilityRegistry;
use Drove\Extension\Contracts\Entrypoint;
use Drove\Extension\Contracts\Matcher;
use Drove\Extension\ExtensionSet;
use Drove\Extension\Manifest;
use Drove\Extension\MatchInput;
use Drove\Extension\MatchResult;
use Drove\Extension\Registry;
use Drove\Kernel\DroverScheduler;
use Drove\Laravel\ApplicationRuntime;
use Drove\Laravel\LaravelTestContext;
use Drove\Laravel\PackageApplicationRuntime;
use Drove\Laravel\State\SqliteCopyDatabaseStateAdapter;
use Drove\Migration\Migrator;
use Drove\Migration\Scanner;
use Drove\Native\Declarations;
use Drove\Native\Expectation;
use Drove\Native\Runner;
use Drove\Native\Selection;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\SpatieLaravelSettingsPluginServiceProvider;
use Filament\Support\Colors\ColorManager;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Tests\Fixtures\Models\Department;
use Filament\Tests\Fixtures\Models\Ticket;
use Filament\Tests\Fixtures\Models\User;
use Filament\Tests\Fixtures\Policies\DepartmentPolicy;
use Filament\Tests\Fixtures\Policies\TicketPolicy;
use Filament\Tests\Fixtures\Providers\AdminPanelProvider;
use Filament\Tests\Fixtures\Providers\AppAuthenticationPanelProvider;
use Filament\Tests\Fixtures\Providers\ConfigurationPanelProvider;
use Filament\Tests\Fixtures\Providers\CustomPanelProvider;
use Filament\Tests\Fixtures\Providers\DomainTenancyPanelProvider;
use Filament\Tests\Fixtures\Providers\EmailAuthenticationPanelProvider;
use Filament\Tests\Fixtures\Providers\Fixtures\Providers\SingleDomainPanel;
use Filament\Tests\Fixtures\Providers\MultiDomainPanel;
use Filament\Tests\Fixtures\Providers\RequiredMultiFactorAuthenticationPanelProvider;
use Filament\Tests\Fixtures\Providers\SlugsPanelProvider;
use Filament\Tests\Fixtures\Providers\SlugTenancyPanelProvider;
use Filament\Tests\Fixtures\Providers\SpaPanelProvider;
use Filament\Tests\Fixtures\Providers\TenancyPanelProvider;
use Filament\Tests\Fixtures\Providers\TenantMenuFlatPanelProvider;
use Filament\Tests\Fixtures\Providers\TenantMenuGroupingPanelProvider;
use Filament\Tests\Fixtures\Providers\TenantMenuRegisterPlacementPanelProvider;
use Filament\Tests\Fixtures\Providers\UserMenuFlatPanelProvider;
use Filament\Tests\Fixtures\Providers\UserMenuGroupingPanelProvider;
use Filament\Tests\Fixtures\Providers\UserMenuLogoutPlacementPanelProvider;
use Filament\Tests\Fixtures\Providers\ViewComponentsServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Migrations\Migrator as LaravelMigrator;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Livewire\LivewireServiceProvider;
use Monolog\Handler\NullHandler;
use Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository;
use Spatie\MediaLibrary\Downloaders\DefaultDownloader;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaCollections\Models\Observers\MediaObserver;
use Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

require __DIR__.'/profile.php';
require __DIR__.'/case-parity.php';

$nativeFilamentEarlyCheckout = $argv[1] ?? null;

if (is_string($nativeFilamentEarlyCheckout)
    && is_file($nativeFilamentEarlyCheckout.'/vendor/autoload.php')) {
    require_once $nativeFilamentEarlyCheckout.'/vendor/autoload.php';
}

const NATIVE_FILAMENT_COMMIT = 'e9348b2e3792088ee877068116b6c1e1559a7df8';
const NATIVE_FILAMENT_FILES = 39;
const NATIVE_FILAMENT_SNAPSHOTS = 170;

function nativeFilamentAssert(bool $condition, string $diagnostic): void
{
    if (! $condition) {
        throw new RuntimeException($diagnostic);
    }
}

final class NativeFilamentPhaseClock
{
    /** @var ArrayObject<string, int|float>|null */
    public static ?ArrayObject $clock = null;
}

/** @param list<string> $command */
function nativeFilamentCommand(array $command, ?string $cwd = null): string
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd, options: ['bypass_shell' => true]);

    if (! is_resource($process)) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_COMMAND_START');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0 || ! is_string($stdout) || ! is_string($stderr)) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_COMMAND_FAILED:'.trim((string) $stderr));
    }

    return $stdout;
}

function nativeFilamentBlob(string $checkout, string $path): string
{
    return nativeFilamentCommand(['git', '-C', $checkout, 'show', NATIVE_FILAMENT_COMMIT.':'.$path]);
}

/** @return list<string> */
function nativeFilamentSelection(string $checkout): array
{
    $paths = preg_split('/\R/', trim(nativeFilamentCommand([
        'git', '-C', $checkout, 'ls-tree', '-r', '--name-only',
        NATIVE_FILAMENT_COMMIT, '--', 'tests/src/Support',
    ]))) ?: [];
    $paths = array_values(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '.php')));
    sort($paths, SORT_STRING);

    return $paths;
}

/** @return list<string> */
function nativeFilamentSnapshotPaths(string $checkout): array
{
    $paths = preg_split('/\R/', trim(nativeFilamentCommand([
        'git', '-C', $checkout, 'ls-tree', '-r', '--name-only',
        NATIVE_FILAMENT_COMMIT, '--', 'tests/.pest/snapshots/src/Support/ColorTest',
    ]))) ?: [];
    $paths = array_values(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '.snap')));
    sort($paths, SORT_STRING);

    return $paths;
}

/** @return array<string, mixed> */
function nativeFilamentBaseline(): array
{
    $source = file_get_contents(__DIR__.'/baseline.json');
    $baseline = is_string($source) ? json_decode($source, true, 16, JSON_THROW_ON_ERROR) : null;

    nativeFilamentAssert(
        is_array($baseline)
            && ($baseline['schema'] ?? null) === 1
            && ($baseline['commit'] ?? null) === NATIVE_FILAMENT_COMMIT
            && ($baseline['files'] ?? null) === NATIVE_FILAMENT_FILES
            && ($baseline['snapshots'] ?? null) === NATIVE_FILAMENT_SNAPSHOTS,
        'DROVE_NATIVE_FILAMENT_BASELINE_INVALID',
    );

    return $baseline;
}

/** @return array<string, mixed> */
function nativeFilamentCaseBaseline(): array
{
    $source = file_get_contents(__DIR__.'/baseline-cases.json');
    $baseline = is_string($source) ? json_decode($source, true, 64, JSON_THROW_ON_ERROR) : null;

    nativeFilamentAssert(
        is_array($baseline)
            && ($baseline['schema'] ?? null) === 1
            && ($baseline['commit'] ?? null) === NATIVE_FILAMENT_COMMIT
            && is_array($baseline['cohorts']['nonserial']['rows'] ?? null)
            && is_array($baseline['cohorts']['serial']['rows'] ?? null),
        'DROVE_NATIVE_FILAMENT_CASE_BASELINE_INVALID',
    );

    return $baseline;
}

/** @return list<class-string> */
function nativeFilamentProviders(): array
{
    $providers = [
        ActionsServiceProvider::class,
        BladeHeroiconsServiceProvider::class,
        BladeIconsServiceProvider::class,
        FilamentServiceProvider::class,
        FormsServiceProvider::class,
        InfolistsServiceProvider::class,
        LivewireServiceProvider::class,
        NotificationsServiceProvider::class,
        QueryBuilderServiceProvider::class,
        SchemasServiceProvider::class,
        SpatieLaravelSettingsPluginServiceProvider::class,
        SupportServiceProvider::class,
        TablesServiceProvider::class,
        WidgetsServiceProvider::class,
        AdminPanelProvider::class,
        ConfigurationPanelProvider::class,
        CustomPanelProvider::class,
        EmailAuthenticationPanelProvider::class,
        AppAuthenticationPanelProvider::class,
        RequiredMultiFactorAuthenticationPanelProvider::class,
        DomainTenancyPanelProvider::class,
        MultiDomainPanel::class,
        SingleDomainPanel::class,
        SlugsPanelProvider::class,
        SlugTenancyPanelProvider::class,
        SpaPanelProvider::class,
        TenancyPanelProvider::class,
        TenantMenuFlatPanelProvider::class,
        TenantMenuGroupingPanelProvider::class,
        TenantMenuRegisterPlacementPanelProvider::class,
        UserMenuFlatPanelProvider::class,
        UserMenuGroupingPanelProvider::class,
        UserMenuLogoutPlacementPanelProvider::class,
        ViewComponentsServiceProvider::class,
        PowerJoinsServiceProvider::class,
    ];
    sort($providers, SORT_STRING);

    return $providers;
}

/** @return list<string> */
function nativeFilamentColorKeys(): array
{
    return ['danger', 'gray', 'info', 'primary', 'success', 'warning'];
}

/**
 * @param  array<string, mixed>  $components
 * @param  list<string>  $colors
 * @return array<string, array{mixed, string}>
 */
function nativeFilamentCartesianDataset(array $components, array $colors): array
{
    $rows = [];

    foreach ($components as $componentName => $component) {
        foreach ($colors as $color) {
            $key = sprintf('dataset "%s" / (\'%s\')', $componentName, $color);
            $rows[$key] = [$component, $color];
        }
    }

    nativeFilamentAssert(count($rows) === 150, 'DROVE_NATIVE_FILAMENT_CARTESIAN_COUNT');

    return $rows;
}

function nativeFilamentToHtml(mixed $value): string
{
    nativeFilamentAssert($value instanceof Htmlable, 'DROVE_NATIVE_FILAMENT_HTMLABLE_REQUIRED');

    return $value->toHtml();
}

function nativeFilamentHtmlStringExpectation(mixed $value): Expectation
{
    nativeFilamentAssert($value instanceof HtmlString, 'DROVE_NATIVE_FILAMENT_HTML_STRING_REQUIRED');

    return \Drove\Native\expect($value)
        ->toBeInstanceOf(HtmlString::class)
        ->and($value->toHtml());
}

function nativeFilamentProperty(mixed $value, string $name): mixed
{
    if (is_array($value) && array_key_exists($name, $value)) {
        return $value[$name];
    }

    if (is_object($value) && property_exists($value, $name)) {
        return $value->{$name};
    }

    throw new LogicException('DROVE_NATIVE_FILAMENT_PROPERTY:'.$name);
}

/** @param array<string, mixed> $config */
function nativeFilamentWriteConfig(string $path, array $config): void
{
    $source = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($config, true).";\n";
    nativeFilamentAssert(file_put_contents($path, $source) === strlen($source), 'DROVE_NATIVE_FILAMENT_CONFIG_WRITE');
}

/** @param list<class-string> $providers */
function nativeFilamentProviderManifest(array $providers): string
{
    $imports = [];
    $entries = [];
    $aliases = [];

    foreach ($providers as $provider) {
        $separator = strrpos($provider, '\\');
        $alias = $separator === false ? $provider : substr($provider, $separator + 1);
        nativeFilamentAssert(! isset($aliases[$alias]), 'DROVE_NATIVE_FILAMENT_PROVIDER_ALIAS');
        $aliases[$alias] = true;
        $imports[] = 'use '.$provider.';';
        $entries[] = '    '.$alias.'::class,';
    }

    return "<?php\n\ndeclare(strict_types=1);\n\n"
        .implode("\n", $imports)."\n\nreturn [\n"
        .implode("\n", $entries)."\n];\n";
}

function nativeFilamentStageApplication(string $stage, string $checkout, string $database): string
{
    $app = $stage.'/application';

    foreach (['app', 'bootstrap/cache', 'config', 'resources/views', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/drove', 'storage/logs'] as $directory) {
        nativeFilamentAssert(mkdir($app.'/'.$directory, 0777, true), 'DROVE_NATIVE_FILAMENT_APP_DIRECTORY');
    }

    $bootstrap = <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

$application = Application::configure(basePath: dirname(__DIR__))->create();
$application->useStoragePath((string) getenv('DROVE_NATIVE_FILAMENT_STORAGE'));

return $application;
PHP;
    $composer = [
        'name' => 'drove/native-filament-proof-application',
        'type' => 'project',
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'extra' => ['laravel' => ['dont-discover' => ['*']]],
    ];
    $composerSource = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $providers = nativeFilamentProviderManifest(nativeFilamentProviders());
    nativeFilamentAssert(file_put_contents($app.'/bootstrap/app.php', $bootstrap."\n") !== false, 'DROVE_NATIVE_FILAMENT_BOOTSTRAP_WRITE');
    nativeFilamentAssert(file_put_contents($app.'/bootstrap/providers.php', $providers) === strlen($providers), 'DROVE_NATIVE_FILAMENT_PROVIDERS_WRITE');
    nativeFilamentAssert(file_put_contents($app.'/composer.json', $composerSource) === strlen($composerSource), 'DROVE_NATIVE_FILAMENT_APP_COMPOSER_WRITE');

    nativeFilamentWriteConfig($app.'/config/app.php', [
        'name' => 'Drove native Filament corpus',
        'env' => 'testing',
        'debug' => false,
        'url' => 'http://localhost',
        'timezone' => 'UTC',
        'locale' => 'en',
        'fallback_locale' => 'en',
        'faker_locale' => 'en_US',
        'cipher' => 'AES-256-CBC',
        'key' => 'base64:yk+bUVuZa1p86Dqjk9OjVK2R1pm6XHxC6xEKFq8utH0=',
        'previous_keys' => [],
        'maintenance' => ['driver' => 'file', 'store' => 'database'],
    ]);
    nativeFilamentWriteConfig($app.'/config/auth.php', [
        'defaults' => ['guard' => 'web', 'passwords' => 'users'],
        'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
        'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]],
        'passwords' => ['users' => ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60]],
        'password_timeout' => 10800,
    ]);
    nativeFilamentWriteConfig($app.'/config/cache.php', [
        'default' => 'array',
        'stores' => ['array' => ['driver' => 'array', 'serialize' => false]],
        'prefix' => 'drove-native-filament',
    ]);
    nativeFilamentWriteConfig($app.'/config/database.php', [
        'default' => 'testing',
        'connections' => [
            'testing' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true],
            'sqlite' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true],
        ],
        'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    ]);
    nativeFilamentWriteConfig($app.'/config/filesystems.php', [
        'default' => 'local',
        'disks' => [
            'local' => ['driver' => 'local', 'root' => $app.'/storage/app/private', 'throw' => false],
            'public' => ['driver' => 'local', 'root' => $app.'/storage/app/public', 'url' => 'http://localhost/storage', 'visibility' => 'public', 'throw' => false],
        ],
        'links' => [],
    ]);
    nativeFilamentWriteConfig($app.'/config/logging.php', [
        'default' => 'null',
        'channels' => ['null' => ['driver' => 'monolog', 'handler' => NullHandler::class]],
    ]);
    nativeFilamentWriteConfig($app.'/config/queue.php', ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]]);
    nativeFilamentWriteConfig($app.'/config/session.php', ['driver' => 'array', 'lifetime' => 120, 'encrypt' => false, 'cookie' => 'drove_filament_session']);
    nativeFilamentWriteConfig($app.'/config/view.php', [
        'paths' => [$app.'/resources/views', $checkout.'/tests/resources/views'],
        'compiled' => $app.'/storage/framework/views',
    ]);
    nativeFilamentWriteConfig($app.'/config/media-library.php', [
        'disk_name' => 'public',
        'max_file_size' => 10 * 1024 * 1024,
        'queue_connection_name' => 'sync',
        'queue_name' => '',
        'queue_conversions_by_default' => false,
        'media_model' => Media::class,
        'media_observer' => MediaObserver::class,
        'use_default_collection_serialization' => false,
        'file_namer' => DefaultFileNamer::class,
        'path_generator' => DefaultPathGenerator::class,
        'url_generator' => DefaultUrlGenerator::class,
        'moves_media_on_update' => false,
        'version_urls' => true,
        'image_optimizers' => [],
        'image_generators' => [],
        'image_driver' => 'gd',
        'ffmpeg_path' => '/usr/bin/ffmpeg',
        'ffprobe_path' => '/usr/bin/ffprobe',
        'temporary_directory_path' => null,
        'jobs' => [],
        'media_downloader' => DefaultDownloader::class,
        'remote' => ['extra_headers' => []],
        'responsive_images' => ['use_tiny_placeholders' => true, 'tiny_placeholder_generator' => null],
        'enable_vapor_uploads' => false,
        'default_loading_attribute_value' => null,
        'prefix' => '',
    ]);
    nativeFilamentWriteConfig($app.'/config/settings.php', [
        'settings' => [],
        'default_repository' => 'database',
        'repositories' => ['database' => ['type' => DatabaseSettingsRepository::class, 'model' => null, 'table' => 'spatie_settings', 'connection' => null]],
        'cache' => ['enabled' => false, 'store' => null, 'prefix' => null, 'ttl' => null, 'memo' => false],
        'auto_discover_settings' => [],
        'global_casts' => [],
        'encoder' => null,
        'decoder' => null,
    ]);

    return $app;
}

/** @return array<string, string> */
function nativeFilamentEnvironment(): array
{
    return [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:yk+bUVuZa1p86Dqjk9OjVK2R1pm6XHxC6xEKFq8utH0=',
        'APP_URL' => 'http://localhost',
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'testing',
        'FILESYSTEM_DISK' => 'local',
        'LOG_CHANNEL' => 'null',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ];
}

function nativeFilamentCreateBaseSchema(Application $application): void
{
    $schema = $application->make('db')->connection('testing')->getSchemaBuilder();

    $schema->create('users', static function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
    $schema->create('password_reset_tokens', static function ($table): void {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });
    $schema->create('sessions', static function ($table): void {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });
    $schema->create('cache', static function ($table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->bigInteger('expiration')->index();
    });
    $schema->create('cache_locks', static function ($table): void {
        $table->string('key')->primary();
        $table->string('owner');
        $table->bigInteger('expiration')->index();
    });
    $schema->create('jobs', static function ($table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedSmallInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    $schema->create('job_batches', static function ($table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    $schema->create('failed_jobs', static function ($table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->string('connection');
        $table->string('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
        $table->index(['connection', 'queue', 'failed_at']);
    });
}

function nativeFilamentRuntime(): ApplicationRuntime
{
    $app = getenv('DROVE_NATIVE_FILAMENT_APP');
    $checkout = getenv('DROVE_NATIVE_FILAMENT_CHECKOUT');
    $database = getenv('DROVE_NATIVE_FILAMENT_DATABASE');
    $workspace = getenv('DROVE_NATIVE_FILAMENT_DATABASE_WORKSPACE');
    $preparedEvidence = getenv('DROVE_NATIVE_FILAMENT_PREPARED_EVIDENCE');

    nativeFilamentAssert(
        is_string($app) && is_dir($app)
            && is_string($checkout) && is_dir($checkout)
            && is_string($database) && is_file($database)
            && is_string($workspace) && is_dir($workspace)
            && is_string($preparedEvidence) && $preparedEvidence !== '',
        'DROVE_NATIVE_FILAMENT_RUNTIME_ENVIRONMENT',
    );
    $clock = NativeFilamentPhaseClock::$clock;
    nativeFilamentAssert($clock instanceof ArrayObject, 'DROVE_NATIVE_FILAMENT_PHASE_CLOCK_MISSING');
    $planningStartedNs = $clock['planning_started_ns'];
    nativeFilamentAssert(is_int($planningStartedNs) && $planningStartedNs >= 0, 'DROVE_NATIVE_FILAMENT_PLANNING_CLOCK_MISSING');
    $environmentStartedNs = hrtime(true);
    $clock['planning_ns'] = $environmentStartedNs - $planningStartedNs;

    $runtime = PackageApplicationRuntime::boot(
        $app,
        new SqliteCopyDatabaseStateAdapter('testing', $workspace, true),
        nativeFilamentProviders(),
        static function (Application $application) use ($checkout, $database, $preparedEvidence): void {
            Gate::policy(Ticket::class, TicketPolicy::class);
            Gate::policy(Department::class, DepartmentPolicy::class);
            (new LaravelTestContext($application))->withoutVite();
            nativeFilamentCreateBaseSchema($application);
            $migrator = $application->make(LaravelMigrator::class);
            $migrations = $migrator->usingConnection('testing', static function () use ($checkout, $migrator): array {
                if (! $migrator->repositoryExists()) {
                    $migrator->getRepository()->createRepository();
                }

                return $migrator->run($checkout.'/tests/database/migrations');
            });
            nativeFilamentAssert(count($migrations) >= 27, 'DROVE_NATIVE_FILAMENT_MIGRATION_FAILED');
            $colors = array_keys($application->make(ColorManager::class)->getColors());
            sort($colors, SORT_STRING);
            nativeFilamentAssert($colors === nativeFilamentColorKeys(), 'DROVE_NATIVE_FILAMENT_COLOR_KEYS');
            $evidence = json_encode([
                'tables' => count($application->make('db')->connection('testing')->getSchemaBuilder()->getTableListing()),
                'migrations' => (int) $application->make('db')->connection('testing')->table('migrations')->count(),
                'colors' => $colors,
                'before_execution_sha256' => hash_file('sha256', $database),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            nativeFilamentAssert(file_put_contents($preparedEvidence, $evidence) === strlen($evidence), 'DROVE_NATIVE_FILAMENT_PREPARED_WRITE');
        },
    );
    $environmentPreparedNs = hrtime(true);
    $preparationNs = $clock['preparation_ns'];
    nativeFilamentAssert(is_int($preparationNs), 'DROVE_NATIVE_FILAMENT_PREPARATION_CLOCK_MISSING');
    $clock['preparation_ns'] = $preparationNs + $environmentPreparedNs - $environmentStartedNs;
    $clock['execution_started_ns'] = $environmentPreparedNs;

    return $runtime;
}

function nativeFilamentRuntimeGuard(string $evidenceFile, string $source): void
{
    $fault = getenv('DROVE_NATIVE_FILAMENT_FAULT_RUNTIME_GUARD');

    if ($fault === 'class' && ! class_exists('Drove\\Bridge\\Pest\\InjectedRuntime', false)) {
        eval('namespace Drove\\Bridge\\Pest; final class InjectedRuntime {}');
    } elseif ($fault === 'interface' && ! interface_exists('Drove\\Bridge\\InjectedRuntime', false)) {
        eval('namespace Drove\\Bridge; interface InjectedRuntime {}');
    } elseif ($fault === 'trait' && ! trait_exists('Drove\\Bridge\\InjectedRuntime', false)) {
        eval('namespace Drove\\Bridge; trait InjectedRuntime {}');
    } elseif ($fault === 'file') {
        $directory = sys_get_temp_dir().'/drove-native-filament-guard-'.getmypid().'/src/Drove/Bridge';
        nativeFilamentAssert(is_dir($directory) || mkdir($directory, 0700, true), 'DROVE_NATIVE_FILAMENT_GUARD_FAULT_DIRECTORY');
        $path = $directory.'/InjectedRuntime.php';
        nativeFilamentAssert(file_put_contents($path, "<?php\n", LOCK_EX) === 6, 'DROVE_NATIVE_FILAMENT_GUARD_FAULT_FILE');
        require $path;
    }

    $symbols = [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()];
    $classes = array_values(array_filter($symbols, static fn (string $class): bool => str_starts_with($class, 'Pest\\')
        || str_starts_with($class, 'PHPUnit\\')
        || str_starts_with($class, 'Orchestra\\Testbench\\')
        || str_starts_with($class, 'Drove\\Bridge\\')
        || str_starts_with($class, 'Drove\\Pest\\')
        || strcasecmp($class, 'Drove\\Laravel\\TestbenchBridge') === 0
        || strcasecmp($class, 'Filament\\Tests\\TestCase') === 0));
    $files = array_values(array_filter(array_map(
        static fn (string $path): string => str_replace('\\', '/', $path),
        get_included_files(),
    ), static fn (string $path): bool => preg_match('~/(?:pestphp|phpunit|orchestra/testbench[^/]*)/~i', $path) === 1
        || str_ends_with($path, '/tests/src/TestCase.php')
        || str_contains($path, '/src/Drove/Bridge/')
        || str_contains($path, '/src/Drove/Pest/')
        || str_ends_with($path, '/packages/drove-laravel/src/TestbenchBridge.php')));
    $row = json_encode(['pid' => getmypid(), 'source' => $source, 'classes' => $classes, 'files' => $files], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    nativeFilamentAssert(file_put_contents($evidenceFile, $row, FILE_APPEND | LOCK_EX) === strlen($row), 'DROVE_NATIVE_FILAMENT_RUNTIME_EVIDENCE');
    nativeFilamentAssert($classes === [] && $files === [], 'DROVE_NATIVE_FILAMENT_EXTERNAL_RUNTIME_LOADED');
}

/** @return array{files: int, sha256: string} */
function nativeFilamentTreeHash(string $root): array
{
    if (! is_dir($root)) {
        return ['files' => 0, 'sha256' => hash('sha256', '')];
    }

    $rows = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $rows[] = $path.':'.hash_file('sha256', $file->getPathname());
    }

    sort($rows, SORT_STRING);

    return ['files' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
}

/** @param list<string> $paths */
function nativeFilamentStageTests(
    string $stage,
    string $checkout,
    string $droveRoot,
    string $runtimeEvidence,
    array $paths,
): array {
    $migrator = new Migrator(new Scanner(CompatibilityRegistry::load($droveRoot.'/resources/drove-bridge-compatibility.json')));
    $options = nativeFilamentOptions();
    $identity = [];

    foreach ($paths as $path) {
        $source = nativeFilamentBlob($checkout, $path);
        $profiled = nativeFilamentProfileSource($source, $path);
        $result = $migrator->migrate($profiled, $path, $options);
        nativeFilamentAssert($result->blockers === [], 'DROVE_NATIVE_FILAMENT_MIGRATION_BLOCKED:'.$path);
        $second = $migrator->migrate(
            nativeFilamentProfileSource($result->source, $path),
            $path,
            $options,
        );
        nativeFilamentAssert(
            $second->blockers === []
                && ! $second->changed()
                && $second->source === $result->source
                && $second->resultHash === $result->resultHash,
            'DROVE_NATIVE_FILAMENT_MIGRATION_NOT_IDEMPOTENT:'.$path,
        );
        $target = $stage.'/'.$path;
        nativeFilamentAssert(is_dir(dirname($target)) || mkdir(dirname($target), 0777, true), 'DROVE_NATIVE_FILAMENT_STAGE_DIRECTORY');
        $instrumented = rtrim($result->source)."\n\n"
            .'\\Drove\\Native\\afterEach(static function (): void {'."\n"
            .'    \\nativeFilamentRuntimeGuard('.var_export($runtimeEvidence, true).', '.var_export($path, true).');'."\n"
            .'});'."\n";
        nativeFilamentAssert(file_put_contents($target, $instrumented) === strlen($instrumented), 'DROVE_NATIVE_FILAMENT_STAGE_WRITE');
        $identity[] = [
            'path' => $path,
            'source' => hash('sha256', $source),
            'profiled' => hash('sha256', $profiled),
            'result' => $result->resultHash,
            'second_pass' => $second->resultHash,
        ];
    }

    return $identity;
}

final class NativeFilamentSnapshotMatcher implements Matcher
{
    /** @param array<string, string> $snapshots */
    public function __construct(
        private readonly array $snapshots,
        private readonly string $evidenceFile,
    ) {}

    public function match(MatchInput $input): MatchResult
    {
        $case = $input->case;
        $source = $case['source']['path'] ?? null;
        $dataset = $case['dataset'] ?? null;
        $name = $case['name'] ?? null;

        if (! is_string($source)
            || str_replace('\\', '/', $source) !== 'tests/src/Support/ColorTest.php'
            || ! is_array($dataset)
            || ! is_string($name)) {
            return MatchResult::fail('Filament snapshot matcher received an invalid native case identity.');
        }

        $suffix = ' ['.$dataset['label'].']';
        $base = str_ends_with($name, $suffix) ? substr($name, 0, -strlen($suffix)) : $name;
        $display = $this->datasetDisplay($base, $dataset['key']);
        $file = 'tests/.pest/snapshots/src/Support/ColorTest/'
            .preg_replace('/[^A-Za-z0-9]/', '_', $base.' with data set "'.$display.'"').'.snap';
        $expected = $this->snapshots[$file] ?? null;
        $actual = json_encode($input->actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! is_string($expected)) {
            return MatchResult::fail('No pinned Filament snapshot exists for native case '.$name.'.');
        }

        $row = json_encode(['pid' => getmypid(), 'case_id' => $case['id'], 'path' => $file], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        nativeFilamentAssert(file_put_contents($this->evidenceFile, $row, FILE_APPEND | LOCK_EX) === strlen($row), 'DROVE_NATIVE_FILAMENT_SNAPSHOT_EVIDENCE');

        return rtrim($actual, "\r\n") === rtrim($expected, "\r\n")
            ? MatchResult::pass()
            : MatchResult::fail('Pinned Filament snapshot diverged for native case '.$name.'.');
    }

    private function datasetDisplay(string $name, int|string $key): string
    {
        if ($name === 'it generates component classes' && is_string($key)) {
            return $key;
        }

        $values = match ($name) {
            'it generates colors from a HEX value',
            "it generates colors from a HEX value using Filament v3's algorithm" => ['#49D359', '#8A2BE2', '#A52A2A', '#000000', '#FFFFFF'],
            'it generates colors from an RGB value',
            "it generates colors from an RGB value using Filament v3's algorithm" => ['rgb(128, 8, 8)', 'rgb(93, 255, 2)', 'rgb(243, 243, 21)', 'rgb(0, 0, 0)', 'rgb(255, 255, 255)'],
            default => [],
        };

        return is_int($key) && isset($values[$key])
            ? "('".$values[$key]."')"
            : throw new RuntimeException('DROVE_NATIVE_FILAMENT_SNAPSHOT_DATASET');
    }
}

final readonly class NativeFilamentSnapshotEntrypoint implements Entrypoint
{
    /** @param array<string, string> $snapshots */
    public function __construct(
        private array $snapshots,
        private string $evidenceFile,
    ) {}

    public function register(Registry $registry): void
    {
        $registry->registerMatcher('snapshot', new NativeFilamentSnapshotMatcher($this->snapshots, $this->evidenceFile));
    }
}

/** @param array<string, string> $snapshots */
function nativeFilamentExtensions(array $snapshots, string $evidenceFile): ExtensionSet
{
    $manifest = Manifest::fromComposerPackage([
        'name' => 'corpus/filament',
        'version' => '1.0.0',
        'type' => 'library',
        'autoload' => ['psr-4' => ['DroveFilamentCorpus\\' => __DIR__]],
        'extra' => ['drove' => ['extension' => [
            'schema' => 1,
            'id' => 'corpus/filament',
            'entrypoint' => 'DroveFilamentCorpus\\SnapshotEntrypoint',
            'api' => ['min' => 1, 'max' => 1],
            'contributions' => ['matcher'],
            'configuration' => [],
        ]]],
    ]);
    $registry = new Registry($manifest, []);
    (new NativeFilamentSnapshotEntrypoint($snapshots, $evidenceFile))->register($registry);

    return new ExtensionSet([$registry->freeze()]);
}

function nativeFilamentRemoveTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

/**
 * @return array{
 *     requested: int,
 *     observed: int,
 *     cases: int,
 *     one_fork_per_case: bool,
 *     scheduler: array{
 *         schema: int,
 *         forks: int,
 *         scope_workers: int,
 *         executor_workers: int,
 *         process_anchors: int,
 *         peak_live_pids: int,
 *         peak_outstanding_tasks: int,
 *         outstanding_task_limit: int
 *     }
 * }
 */
function nativeFilamentConcurrencyBarrier(int $processes, string $stage): array
{
    $barrier = $stage.'/concurrency-barrier';
    nativeFilamentAssert(mkdir($barrier, 0700), 'DROVE_NATIVE_FILAMENT_BARRIER_DIRECTORY');

    try {
        $registry = Declarations::capture(static function () use ($barrier, $processes): void {
            for ($index = 0; $index < $processes; $index++) {
                \Drove\Native\test('concurrency barrier '.$index, static function () use ($barrier, $index, $processes): void {
                    $marker = $barrier.'/'.$index.'.ready';
                    nativeFilamentAssert(file_put_contents($marker, (string) getmypid(), LOCK_EX) !== false, 'DROVE_NATIVE_FILAMENT_BARRIER_WRITE');
                    $deadline = hrtime(true) + 10_000_000_000;

                    while (true) {
                        $markers = glob($barrier.'/*.ready');
                        nativeFilamentAssert(is_array($markers), 'DROVE_NATIVE_FILAMENT_BARRIER_GLOB');

                        if (count($markers) === $processes) {
                            break;
                        }

                        nativeFilamentAssert(hrtime(true) < $deadline, 'DROVE_NATIVE_FILAMENT_BARRIER_TIMEOUT');
                        usleep(1_000);
                    }
                });
            }
        }, dirname(__DIR__, 2), 'Filament scheduler concurrency barrier');
        $scheduler = new DroverScheduler(
            'native-filament-barrier-'.bin2hex(random_bytes(8)),
            $processes,
            [],
            15_000,
        );
        $run = new Runner($scheduler)->run($registry);
        $topology = $scheduler->topologyTelemetry();
        $expectedTopology = [
            'schema' => 1,
            'forks' => $processes,
            'scope_workers' => 0,
            'executor_workers' => $processes,
            'process_anchors' => 0,
            'peak_live_pids' => $processes,
            'peak_outstanding_tasks' => $processes,
            'outstanding_task_limit' => 2 * $processes,
        ];
        $observed = $run['observed_concurrency']['global'] ?? null;
        $tests = is_array($run['tests'] ?? null) ? $run['tests'] : [];
        nativeFilamentAssert(
            ($run['status'] ?? null) === 'passed'
                && ($run['exit_code'] ?? null) === 0
                && count($tests) === $processes
                && $observed === $processes
                && $topology === $expectedTopology,
            'DROVE_NATIVE_FILAMENT_CONCURRENCY_BARRIER',
        );

        return [
            'requested' => $processes,
            'observed' => $observed,
            'cases' => count($tests),
            'one_fork_per_case' => $topology['forks'] === count($tests),
            'scheduler' => $topology,
        ];
    } finally {
        nativeFilamentRemoveTree($barrier);
    }
}

try {
    (static function (array $arguments): void {
        $proofStartedNs = hrtime(true);
        /** @var ArrayObject<string, int|float> $phaseClock */
        $phaseClock = new ArrayObject([
            'preparation_ns' => 0,
            'planning_ns' => 0,
            'planning_started_ns' => -1,
            'execution_started_ns' => -1,
        ]);
        NativeFilamentPhaseClock::$clock = $phaseClock;
        $checkout = $arguments[1] ?? null;
        $droveRoot = $arguments[2] ?? dirname(__DIR__, 2);
        $cohort = getenv('DROVE_NATIVE_FILAMENT_COHORT') ?: 'nonserial';
        $processes = filter_var(getenv('DROVE_NATIVE_FILAMENT_PROCESSES') ?: '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
        $debugName = getenv('DROVE_NATIVE_FILAMENT_DEBUG_NAME') ?: null;

        if (! is_string($checkout)
            || ! is_file($checkout.'/vendor/autoload.php')
            || ! is_string($droveRoot)
            || ! is_file($droveRoot.'/resources/drove-bridge-compatibility.json')
            || ! in_array($cohort, ['nonserial', 'serial'], true)
            || ! is_int($processes)
            || ($cohort === 'serial' && $processes !== 1)) {
            fwrite(STDERR, "Usage: proof.php /pinned/filament [/drove]; cohort nonserial|serial, processes 1..30.\n");
            exit(2);
        }

        require $checkout.'/vendor/autoload.php';

        if (! function_exists('Drove\\Native\\environment')) {
            require $checkout.'/vendor/oxhq/drove/src/Drove/Native/functions.php';
        }

        nativeFilamentAssert(trim(nativeFilamentCommand(['git', '-C', $checkout, 'rev-parse', 'HEAD'])) === NATIVE_FILAMENT_COMMIT, 'DROVE_NATIVE_FILAMENT_COMMIT_MISMATCH');
        nativeFilamentAssert(trim(nativeFilamentCommand(['git', '-C', $checkout, 'status', '--porcelain', '--', 'packages', 'tests'])) === '', 'DROVE_NATIVE_FILAMENT_SOURCE_DIRTY');
        $baseline = nativeFilamentBaseline();
        $caseBaseline = nativeFilamentCaseBaseline();
        $paths = nativeFilamentSelection($checkout);
        $snapshotPaths = nativeFilamentSnapshotPaths($checkout);
        nativeFilamentAssert(count($paths) === NATIVE_FILAMENT_FILES, 'DROVE_NATIVE_FILAMENT_SELECTION_COUNT');
        nativeFilamentAssert(count($snapshotPaths) === NATIVE_FILAMENT_SNAPSHOTS, 'DROVE_NATIVE_FILAMENT_SNAPSHOT_COUNT');
        $snapshots = [];

        foreach ($snapshotPaths as $path) {
            $snapshots[$path] = nativeFilamentBlob($checkout, $path);
        }

        $snapshotTreeBefore = nativeFilamentTreeHash($checkout.'/tests/.pest/snapshots/src/Support/ColorTest');
        $sourceTreeBefore = nativeFilamentTreeHash($checkout.'/tests/resources');
        $token = bin2hex(random_bytes(8));
        $stage = sys_get_temp_dir().'/drove-native-filament-'.$token;
        $database = $stage.'/prepared.sqlite';
        $databaseWorkspace = $stage.'/database-copies';
        $runtimeEvidence = $stage.'/runtime.jsonl';
        $snapshotEvidence = $stage.'/snapshots.jsonl';
        $preparedEvidence = $stage.'/prepared.json';

        try {
            nativeFilamentAssert(mkdir($stage, 0777, true), 'DROVE_NATIVE_FILAMENT_STAGE_CREATE');
            nativeFilamentAssert(mkdir($databaseWorkspace, 0777, true), 'DROVE_NATIVE_FILAMENT_DATABASE_WORKSPACE');
            nativeFilamentAssert(touch($database), 'DROVE_NATIVE_FILAMENT_DATABASE_CREATE');
            $app = nativeFilamentStageApplication($stage, $checkout, $database);
            $resourcesBefore = nativeFilamentTreeHash($app.'/resources');
            $identity = nativeFilamentStageTests($stage, $checkout, $droveRoot, $runtimeEvidence, $paths);

            foreach ([
                ...nativeFilamentEnvironment(),
                'DROVE_NATIVE_FILAMENT_APP' => $app,
                'DROVE_NATIVE_FILAMENT_CHECKOUT' => $checkout,
                'DROVE_NATIVE_FILAMENT_DATABASE' => $database,
                'DROVE_NATIVE_FILAMENT_DATABASE_WORKSPACE' => $databaseWorkspace,
                'DROVE_NATIVE_FILAMENT_PREPARED_EVIDENCE' => $preparedEvidence,
                'DROVE_NATIVE_FILAMENT_STORAGE' => $app.'/storage',
            ] as $name => $value) {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }

            $extensions = nativeFilamentExtensions($snapshots, $snapshotEvidence);
            $registry = Declarations::capture(static function () use ($stage, $paths): void {
                foreach ($paths as $path) {
                    require $stage.'/'.$path;
                }
            }, $stage, 'Filament native support corpus', $extensions);
            $selection = new Selection(
                $debugName,
                $cohort === 'serial' ? ['serial'] : [],
                $cohort === 'nonserial' ? ['serial'] : [],
            );
            $scheduler = new DroverScheduler('native-filament-'.$cohort.'-'.$token, $processes, [], 60_000);
            $planningStartedNs = hrtime(true);
            $phaseClock['planning_started_ns'] = $planningStartedNs;
            $phaseClock['preparation_ns'] = $planningStartedNs - $proofStartedNs;
            $run = new Runner($scheduler)->run($registry, $selection);
            $runnerFinishedNs = hrtime(true);
            $executionStartedNs = $phaseClock['execution_started_ns'];
            nativeFilamentAssert(
                is_int($executionStartedNs)
                    && $executionStartedNs >= 0
                    && $executionStartedNs <= $runnerFinishedNs,
                'DROVE_NATIVE_FILAMENT_EXECUTION_CLOCK_MISSING',
            );
            $executionNs = $runnerFinishedNs - $executionStartedNs;
            $verificationStartedNs = $runnerFinishedNs;
            $tests = is_array($run['tests'] ?? null) ? $run['tests'] : [];
            $actual = [
                'cases' => count($tests),
                'assertions' => array_sum(array_map(static fn (array $test): int => (int) ($test['assertions'] ?? 0), $tests)),
            ];
            $topology = $scheduler->topologyTelemetry();
            $expectedTopology = [
                'schema' => 1,
                'forks' => $actual['cases'],
                'scope_workers' => 0,
                'executor_workers' => $actual['cases'],
                'process_anchors' => 0,
                'peak_live_pids' => min($actual['cases'], 2 * $processes),
                'peak_outstanding_tasks' => min($actual['cases'], 2 * $processes),
                'outstanding_task_limit' => 2 * $processes,
            ];
            $expectedConcurrency = min($actual['cases'], $processes);
            $observedConcurrency = $run['observed_concurrency']['global'] ?? null;
            $concurrencyBarrier = nativeFilamentConcurrencyBarrier($processes, $stage);
            $expected = $baseline[$cohort] ?? null;
            $caseRows = nativeFilamentRowsFromRun($tests);
            $expectedCaseRows = $caseBaseline['cohorts'][$cohort]['rows'] ?? null;
            $runtimeRows = is_file($runtimeEvidence) ? file($runtimeEvidence, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $runtimeRows = is_array($runtimeRows) ? array_map(static fn (string $row): array => json_decode($row, true, 8, JSON_THROW_ON_ERROR), $runtimeRows) : [];
            $snapshotRows = is_file($snapshotEvidence) ? file($snapshotEvidence, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $snapshotRows = is_array($snapshotRows) ? array_map(static fn (string $row): array => json_decode($row, true, 8, JSON_THROW_ON_ERROR), $snapshotRows) : [];
            $pids = array_values(array_unique(array_column($runtimeRows, 'pid')));
            $snapshotUsed = array_values(array_unique(array_column($snapshotRows, 'path')));
            sort($snapshotUsed, SORT_STRING);
            $debug = is_string($debugName) && $debugName !== '';
            $databaseHash = hash_file('sha256', $database);
            $resourcesAfter = nativeFilamentTreeHash($app.'/resources');
            $workspaceResidue = nativeFilamentTreeHash($databaseWorkspace);

            nativeFilamentAssert(
                ($run['status'] ?? null) === 'passed'
                    && ($run['exit_code'] ?? null) === 0
                    && $tests !== []
                    && array_all($tests, static fn (array $test): bool => ($test['status'] ?? null) === 'passed')
                    && count($runtimeRows) === $actual['cases']
                    && count($pids) === $actual['cases']
                    && ! in_array(getmypid(), $pids, true)
                    && $topology === $expectedTopology
                    && $observedConcurrency === $expectedConcurrency
                    && array_all($runtimeRows, static fn (array $row): bool => ($row['classes'] ?? null) === [] && ($row['files'] ?? null) === [])
                    && is_array($expectedCaseRows)
                    && $caseRows === $expectedCaseRows
                    && is_string($databaseHash)
                    && $resourcesAfter === $resourcesBefore
                    && $workspaceResidue['files'] === 0
                    && ($debug || is_array($expected)
                        && $actual['cases'] === ($expected['cases'] ?? null)
                        && $actual['assertions'] === ($expected['assertions'] ?? null))
                    && ($debug || $cohort === 'serial' && $snapshotRows === []
                        || $cohort === 'nonserial'
                            && count($snapshotRows) === NATIVE_FILAMENT_SNAPSHOTS
                            && $snapshotUsed === $snapshotPaths),
                'DROVE_NATIVE_FILAMENT_PARITY_DIVERGED:'.json_encode([
                    'actual' => $actual,
                    'expected' => $expected,
                    'status' => $run['status'] ?? null,
                    'failures' => array_slice(array_values(array_filter($tests, static fn (array $test): bool => ($test['status'] ?? null) !== 'passed')), 0, 5),
                    'runtime_rows' => count($runtimeRows),
                    'unique_pids' => count($pids),
                    'snapshots' => count($snapshotRows),
                    'snapshot_unique' => count($snapshotUsed),
                    'case_rows_sha256' => nativeFilamentCaseRowsHash($caseRows),
                    'expected_case_rows_sha256' => is_array($expectedCaseRows)
                        ? nativeFilamentCaseRowsHash($expectedCaseRows)
                        : null,
                    'workspace' => $workspaceResidue,
                    'resources_before' => $resourcesBefore,
                    'resources_after' => $resourcesAfter,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );

            nativeFilamentAssert(nativeFilamentTreeHash($checkout.'/tests/.pest/snapshots/src/Support/ColorTest') === $snapshotTreeBefore, 'DROVE_NATIVE_FILAMENT_SNAPSHOT_TREE_MUTATED');
            nativeFilamentAssert(nativeFilamentTreeHash($checkout.'/tests/resources') === $sourceTreeBefore, 'DROVE_NATIVE_FILAMENT_SOURCE_TREE_MUTATED');
            $prepared = json_decode((string) file_get_contents($preparedEvidence), true, 8, JSON_THROW_ON_ERROR);
            nativeFilamentAssert(
                is_array($prepared)
                    && ($prepared['migrations'] ?? 0) >= 27
                    && ($prepared['tables'] ?? 0) >= 20
                    && ($prepared['before_execution_sha256'] ?? null) === $databaseHash,
                'DROVE_NATIVE_FILAMENT_PREPARED_DATABASE',
            );
            $verificationNs = hrtime(true) - $verificationStartedNs;
            $preparationNs = $phaseClock['preparation_ns'];
            $planningNs = $phaseClock['planning_ns'];
            nativeFilamentAssert(
                is_int($preparationNs) && is_int($planningNs),
                'DROVE_NATIVE_FILAMENT_PHASE_TELEMETRY_INVALID',
            );
            $phasesMs = [
                'preparation' => round($preparationNs / 1_000_000, 3),
                'planning' => round($planningNs / 1_000_000, 3),
                'execution' => round($executionNs / 1_000_000, 3),
                'verification' => round($verificationNs / 1_000_000, 3),
            ];
            nativeFilamentAssert(
                array_all($phasesMs, static fn (float $milliseconds): bool => $milliseconds >= 0),
                'DROVE_NATIVE_FILAMENT_PHASE_TELEMETRY_INVALID',
            );

            echo json_encode([
                'ok' => true,
                'corpus' => 'filamentphp/filament',
                'commit' => NATIVE_FILAMENT_COMMIT,
                'cohort' => $cohort,
                'debug_name' => $debugName,
                'processes' => $processes,
                'phases_ms' => $phasesMs,
                'native' => $actual,
                'baseline' => $expected,
                'case_parity' => [
                    'fields' => ['id', 'status', 'assertions', 'stdout', 'stderr'],
                    'cases' => count($caseRows),
                    'semantic_sha256' => nativeFilamentCaseRowsHash($caseRows),
                    'baseline_runner' => 'pestphp/pest',
                    'rows' => $caseRows,
                ],
                'one_fork_per_case' => $topology['forks'] === $actual['cases'] && count($pids) === $actual['cases'],
                'observed_concurrency' => $observedConcurrency,
                'concurrency_barrier' => $concurrencyBarrier,
                'snapshot_provider' => [
                    'tracked' => NATIVE_FILAMENT_SNAPSHOTS,
                    'matched' => count($snapshotRows),
                    'read_only_tree_sha256' => $snapshotTreeBefore['sha256'],
                ],
                'prepared_database' => $prepared,
                'prepared_database_sha256' => $databaseHash,
                'source_identity_sha256' => hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'external_test_runtime' => ['pest' => false, 'phpunit' => false, 'testbench' => false, 'project_test_case' => false],
                'entrypoint' => 'Drove Native Declarations + PackageApplicationRuntime + typed snapshot matcher',
                'scheduler' => $topology,
                'residue' => ['database_copies' => $workspaceResidue['files'], 'resource_tree_unchanged' => $resourcesAfter === $resourcesBefore],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } finally {
            nativeFilamentRemoveTree($stage);
            NativeFilamentPhaseClock::$clock = null;
        }
    })($argv);
} catch (Throwable $failure) {
    fwrite(STDERR, 'DROVE_NATIVE_FILAMENT_PROOF_FAILED:'.json_encode([
        'exception' => $failure::class,
        'message' => $failure->getMessage(),
    ], JSON_UNESCAPED_SLASHES)."\n");
    exit(1);
}
