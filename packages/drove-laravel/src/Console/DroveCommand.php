<?php

declare(strict_types=1);

namespace Drove\Laravel\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class DroveCommand extends Command
{
    protected $signature = 'drove
        {arguments?* : Arguments forwarded to Drove; place a double dash before Drove options}';

    protected $description = 'Run Drove in an isolated PHP subprocess';

    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->components->error('Drove currently requires Linux process semantics.');

            return self::INVALID;
        }

        $binary = config('drove.binary', 'vendor/bin/drove');

        if (! is_string($binary) || $binary === '') {
            $this->components->error('The drove.binary configuration must be a path.');

            return self::INVALID;
        }

        if (! str_starts_with($binary, DIRECTORY_SEPARATOR)) {
            $binary = base_path($binary);
        }

        $binary = realpath($binary);

        if ($binary === false || ! is_file($binary)) {
            $this->components->error('The Drove executable could not be found.');

            return self::INVALID;
        }

        $arguments = $this->argument('arguments');

        if (! is_array($arguments)
            || array_any($arguments, static fn (mixed $argument): bool => ! is_string($argument))) {
            $this->components->error('Drove arguments must be strings.');

            return self::INVALID;
        }

        $process = new Process(
            [PHP_BINARY, $binary, ...$arguments],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DROVE_LARAVEL' => '1',
            ],
        );
        $process->setTimeout(null);

        return $process->run(static function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });
    }
}
