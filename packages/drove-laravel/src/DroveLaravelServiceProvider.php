<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Drove\Laravel\Console\DroveCommand;
use Illuminate\Support\ServiceProvider;

final class DroveLaravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/config/drove.php',
            'drove',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DroveCommand::class]);
            $this->publishes([
                dirname(__DIR__).'/config/drove.php' => config_path('drove.php'),
            ], 'drove-config');
        }
    }
}
