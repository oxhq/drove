<?php

declare(strict_types=1);

namespace Drove\Laravel\Proof;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

use function Drove\Laravel\laravelContext;

final readonly class LivewirePackageContext
{
    public function __construct(private Application $application)
    {
        //
    }

    public function makeACleanSlate(): void
    {
        Livewire::flushState();
        File::cleanDirectory($this->application->storagePath('framework/views'));
        $this->application->forgetInstance('livewire.factory');
        RateLimiter::clear('livewire-checksum-failures:127.0.0.1');
        File::deleteDirectory($this->application->path());
        File::deleteDirectory($this->application->resourcePath('views'));
        File::deleteDirectory($this->application->basePath('tests/Feature'));
        File::deleteDirectory($this->application->basePath('stubs'));
        File::delete($this->application->bootstrapPath('cache/livewire-components.php'));

        foreach ([
            $this->application->path(),
            $this->application->resourcePath('views/layouts'),
            $this->application->resourcePath('views/pages'),
            $this->application->basePath('tests/Feature'),
        ] as $generatedViewPath) {
            File::deleteDirectory($generatedViewPath);
            File::ensureDirectoryExists($generatedViewPath);
        }
    }

    public function livewireClassesPath(string $path = ''): string
    {
        return $this->application->path('Livewire'.($path === '' ? '' : '/'.$path));
    }

    public function livewireViewsPath(string $path = ''): string
    {
        return $this->application->resourcePath('views/livewire'.($path === '' ? '' : '/'.$path));
    }

    public function livewireComponentsPath(string $path = ''): string
    {
        return $this->application->resourcePath('views/components'.($path === '' ? '' : '/'.$path));
    }

    public function livewireTestsPath(string $path = ''): string
    {
        return $this->application->basePath('tests/Feature/Livewire'.($path === '' ? '' : '/'.$path));
    }
}

function livewirePackageContext(): LivewirePackageContext
{
    return new LivewirePackageContext(laravelContext()->application());
}
