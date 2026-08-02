<?php

declare(strict_types=1);

namespace Drove\Native;

use Drove\Console\Renderer;
use Drove\Extension\CliOptionDefinition;
use Drove\Extension\CliOptionType;
use Drove\Extension\Discovery;
use Drove\Extension\ExtensionException;
use Drove\Extension\ExtensionSet;
use Drove\Extension\Loader;
use Drove\Kernel\DroverScheduler;
use Drove\Native\Surface\SupportedSurface;
use Drove\Version;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class Command
{
    private const array CONCURRENCY = [1, 2, 4, 8, 16, 30];

    private const array CORE_OPTIONS = [
        'exclude-group',
        'filter',
        'group',
        'help',
        'native',
        'pest',
        'processes',
        'version',
    ];

    /**
     * @param  list<string>  $arguments
     */
    public static function main(array $arguments, string $rootPath): int
    {
        try {
            return (new self)->run($arguments, $rootPath);
        } catch (ExtensionException|InvalidArgumentException $exception) {
            fwrite(STDERR, 'Drove native: '.$exception->getMessage().PHP_EOL);

            return 2;
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);

            return 1;
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments, string $rootPath): int
    {
        if (in_array('--version', $arguments, true)) {
            fwrite(STDOUT, 'Drove '.Version::current().PHP_EOL);

            return 0;
        }

        $root = realpath($rootPath);

        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException('The project root does not exist.');
        }

        $extensions = $this->extensions($root);

        if (in_array('--help', $arguments, true)) {
            fwrite(STDOUT, $this->help($extensions));

            return 0;
        }

        $options = $this->options($arguments, $extensions);
        $selection = new Selection(
            $options['filter'],
            $options['groups'],
            $options['excluded_groups'],
        );
        $files = $this->files($root, $options['paths']);
        $surface = SupportedSurface::load();
        $diagnostics = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if (! is_string($source)) {
                throw new InvalidArgumentException(sprintf(
                    'Native source %s could not be read.',
                    $this->relative($root, $file),
                ));
            }

            array_push(
                $diagnostics,
                ...$surface->scan($source, $this->relative($root, $file)),
            );
        }

        if ($diagnostics !== []) {
            foreach ($diagnostics as $diagnostic) {
                fwrite(STDERR, sprintf(
                    '%s %s:%d:%d %s%s',
                    $diagnostic['code'],
                    $diagnostic['path'],
                    $diagnostic['line'],
                    $diagnostic['column'],
                    $diagnostic['message'],
                    PHP_EOL,
                ));
            }

            return 2;
        }

        $extensionOutput = [];

        foreach ($options['extension_values'] as $option) {
            $output = $extensions->executeCli(
                $option['owner'],
                $option['key'],
                $option['value'],
            )->output;

            if ($output !== '') {
                $extensionOutput[] = [
                    'owner' => $option['owner'],
                    'key' => $option['key'],
                    'output' => $output,
                ];
            }
        }

        if ($extensionOutput !== []) {
            fwrite(STDOUT, $this->extensionCliOutput($extensionOutput));
        }

        $registry = Declarations::capture(
            static function () use ($files): void {
                foreach ($files as $file) {
                    require $file;
                }

                (new ClassFrontend)->declareFiles($files);
            },
            $root,
            'Drove native',
            $extensions,
        );
        $run = new Runner(new DroverScheduler(
            'native-'.bin2hex(random_bytes(8)),
            $options['processes'],
        ))->run($registry, $selection);
        fwrite(STDOUT, new Renderer()->render($run));

        return $run['exit_code'];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{
     *     processes: int,
     *     filter: ?string,
     *     groups: list<string>,
     *     excluded_groups: list<string>,
     *     extension_values: list<array{
     *         owner: string,
     *         key: string,
     *         value: bool|int|string
     *     }>,
     *     paths: list<string>
     * }
     */
    private function options(array $arguments, ExtensionSet $extensions): array
    {
        $processes = 1;
        $filter = null;
        $groups = [];
        $excludedGroups = [];
        $extensionValues = [];
        $paths = [];
        $seenProcesses = false;
        $extensionOptions = $this->extensionOptions($extensions);
        $seenExtensions = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--processes=')) {
                if ($seenProcesses) {
                    throw new InvalidArgumentException('--processes may be specified only once.');
                }

                $value = substr($argument, strlen('--processes='));

                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    throw new InvalidArgumentException('--processes requires an integer.');
                }

                $processes = (int) $value;
                $seenProcesses = true;

                continue;
            }

            if (str_starts_with($argument, '--filter=')) {
                if ($filter !== null) {
                    throw new InvalidArgumentException('--filter may be specified only once.');
                }

                $filter = substr($argument, strlen('--filter='));

                continue;
            }

            if (str_starts_with($argument, '--group=')) {
                $groups[] = substr($argument, strlen('--group='));

                continue;
            }

            if (str_starts_with($argument, '--exclude-group=')) {
                $excludedGroups[] = substr($argument, strlen('--exclude-group='));

                continue;
            }

            if (str_starts_with($argument, '--')) {
                [$name, $value] = array_pad(
                    explode('=', substr($argument, 2), 2),
                    2,
                    null,
                );
                $extension = $extensionOptions[$name] ?? null;

                if ($extension !== null) {
                    if (isset($seenExtensions[$name])) {
                        throw new InvalidArgumentException(sprintf(
                            'Extension option --%s may be specified only once.',
                            $name,
                        ));
                    }

                    $type = $extension['definition']->type;

                    if ($type === CliOptionType::Boolean && $value !== null) {
                        throw new InvalidArgumentException(sprintf(
                            'Boolean extension option --%s does not accept a value.',
                            $name,
                        ));
                    }

                    if ($type !== CliOptionType::Boolean && $value === null) {
                        throw new InvalidArgumentException(sprintf(
                            'Extension option --%s requires a %s value.',
                            $name,
                            $type->value,
                        ));
                    }

                    if ($type === CliOptionType::Integer
                        && filter_var($value, FILTER_VALIDATE_INT) === false) {
                        throw new InvalidArgumentException(sprintf(
                            'Extension option --%s requires an integer.',
                            $name,
                        ));
                    }

                    $extensionValues[] = [
                        'owner' => $extension['owner'],
                        'key' => $extension['key'],
                        'value' => match ($type) {
                            CliOptionType::Boolean => true,
                            CliOptionType::Integer => (int) $value,
                            CliOptionType::String => (string) $value,
                        },
                    ];
                    $seenExtensions[$name] = true;

                    continue;
                }
            }

            if (str_starts_with($argument, '-')) {
                throw new InvalidArgumentException(sprintf('Unknown native option %s.', $argument));
            }

            $paths[] = $argument;
        }

        if (! in_array($processes, self::CONCURRENCY, true)) {
            throw new InvalidArgumentException(
                '--processes must be one of 1, 2, 4, 8, 16, or 30.',
            );
        }

        return [
            'processes' => $processes,
            'filter' => $filter,
            'groups' => $groups,
            'excluded_groups' => $excludedGroups,
            'extension_values' => $extensionValues,
            'paths' => $paths === [] ? ['tests'] : $paths,
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function files(string $root, array $paths): array
    {
        $root = str_replace('\\', '/', $root);
        $files = [];

        foreach ($paths as $path) {
            $candidate = realpath($this->absolute($root, $path));

            if ($candidate === false) {
                throw new InvalidArgumentException(sprintf('Native path %s does not exist.', $path));
            }

            $candidate = str_replace('\\', '/', $candidate);

            if ($candidate !== $root && ! str_starts_with($candidate, rtrim($root, '/').'/')) {
                throw new InvalidArgumentException(sprintf(
                    'Native path %s is outside the project root.',
                    $path,
                ));
            }

            if (is_file($candidate)) {
                if (strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) !== 'php') {
                    throw new InvalidArgumentException(sprintf(
                        'Native source %s is not a PHP file.',
                        $path,
                    ));
                }

                $files[$candidate] = true;

                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $candidate,
                FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower((string) $file->getExtension()) === 'php') {
                    $resolved = realpath($file->getPathname());

                    if ($resolved === false) {
                        throw new InvalidArgumentException(sprintf(
                            'Native source %s could not be resolved.',
                            $file->getPathname(),
                        ));
                    }

                    $resolved = str_replace('\\', '/', $resolved);

                    if ($resolved !== $root
                        && ! str_starts_with($resolved, rtrim($root, '/').'/')) {
                        throw new InvalidArgumentException(sprintf(
                            'Native source %s resolves outside the project root.',
                            $file->getPathname(),
                        ));
                    }

                    $files[$resolved] = true;
                }
            }
        }

        $files = array_keys($files);
        sort($files, SORT_STRING);

        if ($files === []) {
            throw new InvalidArgumentException('No native PHP source files were found.');
        }

        return $files;
    }

    private function extensions(string $root): ExtensionSet
    {
        $installed = $root.'/vendor/composer/installed.json';

        if (! is_file($installed)) {
            return ExtensionSet::empty();
        }

        $composer = file_get_contents($root.'/composer.json');

        if (! is_string($composer)) {
            throw new InvalidArgumentException('Drove could not read the project composer.json.');
        }

        $project = json_decode($composer, true);

        if (! is_array($project)) {
            throw new InvalidArgumentException('The project composer.json is not valid JSON.');
        }

        $configuration = $project['extra']['drove']['extensions'] ?? [];

        if (! is_array($configuration)) {
            throw new InvalidArgumentException(
                'Composer extra.drove.extensions must be an extension configuration object.',
            );
        }

        return Loader::load(
            new Discovery()->fromInstalledJson($installed),
            $configuration,
        );
    }

    /**
     * @return array<string, array{
     *     owner: string,
     *     key: string,
     *     definition: CliOptionDefinition
     * }>
     */
    private function extensionOptions(ExtensionSet $extensions): array
    {
        $options = [];

        foreach ($extensions->cliOptions() as $option) {
            $name = $option['definition']->longName;

            if (in_array($name, self::CORE_OPTIONS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Extension %s CLI option --%s conflicts with Drove.',
                    $option['owner'],
                    $name,
                ));
            }

            $options[$name] = $option;
        }

        return $options;
    }

    private function absolute(string $root, string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('Native source paths cannot be empty.');
        }

        return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1
            ? $path
            : $root.'/'.$path;
    }

    private function relative(string $root, string $path): string
    {
        return substr($path, strlen(rtrim(str_replace('\\', '/', $root), '/')) + 1);
    }

    private function help(ExtensionSet $extensions): string
    {
        $help = <<<'HELP'
Drove native

Usage:
  drove [options] [paths...]

Options:
  --processes=N         Isolation lanes: 1, 2, 4, 8, 16, or 30.
  --filter=TEXT         Select case-sensitive test names containing TEXT.
  --group=NAME          Include tests in NAME; repeatable, any match wins.
  --exclude-group=NAME  Exclude tests in NAME; repeatable, exclusion wins.
  --help                Show this native help.
  --version             Show the installed Drove version.

Pest compatibility:
  drove --pest [Pest/PHPUnit options...]

HELP;

        $options = $this->extensionOptions($extensions);

        if ($options === []) {
            return $help;
        }

        $help .= PHP_EOL.'Extension options:'.PHP_EOL;

        foreach ($options as $option) {
            $definition = $option['definition'];
            $value = match ($definition->type) {
                CliOptionType::Boolean => '',
                CliOptionType::Integer => '=INT',
                CliOptionType::String => '=VALUE',
            };
            $help .= sprintf(
                '  --%-20s %s%s',
                $definition->longName.$value,
                $definition->description,
                PHP_EOL,
            );
        }

        return $help;
    }

    /**
     * CLI contribution output remains opaque presentation data. Prefixing
     * every line prevents it from being confused with Drove's run summary.
     *
     * @param  list<array{owner: string, key: string, output: string}>  $outputs
     */
    private function extensionCliOutput(array $outputs): string
    {
        $lines = ['Extension option output:'];

        foreach ($outputs as $output) {
            $lines[] = sprintf(' [%s:%s]', $output['owner'], $output['key']);
            $rendered = rtrim($output['output'], "\r\n");

            foreach (preg_split('/\R/', $rendered) ?: [$rendered] as $outputLine) {
                $lines[] = '   option | '.$outputLine;
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL.PHP_EOL;
    }
}
