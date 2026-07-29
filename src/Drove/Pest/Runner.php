<?php

declare(strict_types=1);

namespace Drove\Pest;

use Drove\Console\Renderer;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\PcntlScheduler;
use InvalidArgumentException;
use ParaTest\Options;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\TextUI\Configuration\BootstrapLoader;
use PHPUnit\TextUI\Configuration\Builder;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\Configuration\TestSuiteBuilder;
use PHPUnit\TextUI\Exception as PHPUnitCliException;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * @internal
 */
final class Runner
{
    /** @var list<string> */
    private const array UNSUPPORTED_OPTIONS = [
        '--coverage',
        '--coverage-',
        '--no-coverage',
        '--process-isolation',
        '--teamcity',
        '--testdox',
        '--testdox-',
        '--log-',
        '--colors',
        '--debug',
        '--display-',
        '--no-output',
        '--no-progress',
        '--list-',
        '--order-by',
        '--random-order-seed',
        '--reverse-list',
        '--stop-on-',
        '--fail-on-',
        '--do-not-fail-on-',
        '--strict-',
        '--disallow-test-output',
        '--enforce-time-limit',
        '--default-time-limit',
        '--warm-coverage-cache',
        '--generate-configuration',
        '--migrate-configuration',
        '--retry',
        '--compact',
        '--profile',
        '--dirty',
        '--todo',
        '--todos',
        '--flaky',
        '--notes',
        '--assignee',
        '--issue',
        '--ticket',
        '--pr',
        '--pull-request',
        '--profanity',
        '--type-coverage',
        '--mutate',
        '--bail',
        '--shard',
    ];

    /**
     * @param  list<string>  $arguments
     */
    public static function main(array $arguments, string $rootPath): int
    {
        try {
            return (new self)->run($arguments, $rootPath);
        } catch (InvalidArgumentException|PHPUnitCliException $exception) {
            fwrite(STDERR, 'Drove: '.$exception->getMessage().PHP_EOL);

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
        if ($arguments === []) {
            throw new InvalidArgumentException('Drove requires an executable argument.');
        }

        [$phpunitArguments, $concurrency] = $this->arguments($arguments);
        $this->rejectUnsupportedOptions($phpunitArguments);
        $rootPath = realpath($rootPath);

        if ($rootPath === false) {
            throw new InvalidArgumentException('The project root does not exist.');
        }

        $previousDirectory = getcwd();

        if (! is_string($previousDirectory) || ! chdir($rootPath)) {
            throw new InvalidArgumentException('Drove could not enter the project root.');
        }

        $previousArguments = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = $phpunitArguments;
        $outputLevel = ob_get_level();
        $runFailure = null;
        $run = null;

        try {
            PestKernel::boot(
                PestTestSuite::getInstance($rootPath, 'tests'),
                new ArgvInput($phpunitArguments),
                new BufferedOutput,
            );
            $compiler = ScopeCompiler::activate($rootPath, ownsHooks: true);
            $configuration = (new Builder)->build($phpunitArguments);

            if ($configuration->processIsolation()) {
                throw new InvalidArgumentException('Drove does not support processIsolation from PHPUnit XML yet.');
            }

            if ($configuration->enforceTimeLimit()) {
                throw new InvalidArgumentException('Drove does not support enforceTimeLimit from PHPUnit XML yet.');
            }

            (new PhpHandler)->handle($configuration->php());
            (new BootstrapLoader)->handle($configuration);
            $suite = (new TestSuiteBuilder)->build($configuration);
            (new TestSuiteFilterProcessor)->process($configuration, $suite);
            $runtime = TestCaseRuntime::fromSuite($compiler, $suite);
            $resolvers = $runtime->resolvers();

            $executor = new LifecycleExecutor(
                new PcntlScheduler(
                    'drove-'.bin2hex(random_bytes(8)),
                    $concurrency,
                ),
                $compiler->hook(...),
                static fn (string $id): array => $resolvers[$id],
            );
            $run = $executor->run(
                $compiler->suitePlan($compiler->files()),
            );
        } catch (Throwable $throwable) {
            $runFailure = $throwable;
        } finally {
            while (ob_get_level() > $outputLevel) {
                ob_end_clean();
            }

            if ($previousArguments === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArguments;
            }

            chdir($previousDirectory);
        }

        if ($runFailure instanceof Throwable) {
            throw $runFailure;
        }

        $exitCode = $run['exit_code'] ?? null;

        if (! is_int($exitCode)) {
            throw new RuntimeException('Drove produced an invalid exit code.');
        }

        if (fwrite(STDOUT, (new Renderer)->render($run)) === false) {
            throw new RuntimeException('Drove could not write its result.');
        }

        return $exitCode;
    }

    /**
     * @param  list<string>  $arguments
     * @return array{list<string>, int}
     */
    private function arguments(array $arguments): array
    {
        $phpunitArguments = [];
        $parallel = false;
        $processes = null;
        $literal = false;

        foreach ($arguments as $index => $argument) {
            if ($literal || $index === 0) {
                $phpunitArguments[] = $argument;

                continue;
            }

            if ($argument === '--') {
                $literal = true;
                $phpunitArguments[] = $argument;

                continue;
            }

            if ($argument === '--parallel') {
                $parallel = true;

                continue;
            }

            if ($argument === '--processes') {
                if ($processes !== null || ! isset($arguments[$index + 1])) {
                    throw new InvalidArgumentException('Drove requires one positive --processes value.');
                }

                $processes = $this->processes($arguments[$index + 1]);

                continue;
            }

            if ($index > 1 && $arguments[$index - 1] === '--processes') {
                continue;
            }

            if (str_starts_with($argument, '--processes=')) {
                if ($processes !== null) {
                    throw new InvalidArgumentException('Drove accepts --processes only once.');
                }

                $processes = $this->processes(substr($argument, strlen('--processes=')));

                continue;
            }

            $phpunitArguments[] = $argument;
        }

        if ($processes !== null && ! $parallel) {
            throw new InvalidArgumentException('--processes requires --parallel.');
        }

        return [
            $phpunitArguments,
            $parallel ? ($processes ?? Options::getNumberOfCPUCores()) : 1,
        ];
    }

    private function processes(string $value): int
    {
        $processes = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
        ]);

        if (! is_int($processes)) {
            throw new InvalidArgumentException('Drove requires a positive --processes value.');
        }

        return $processes;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function rejectUnsupportedOptions(array $arguments): void
    {
        $literal = false;

        foreach (array_slice($arguments, 1) as $argument) {
            if ($literal) {
                continue;
            }

            if ($argument === '--') {
                $literal = true;

                continue;
            }

            foreach (self::UNSUPPORTED_OPTIONS as $option) {
                if ($argument === $option
                    || str_starts_with($argument, $option.'=')
                    || (str_ends_with($option, '-') && str_starts_with($argument, $option))) {
                    throw new InvalidArgumentException(sprintf(
                        'The %s mode is not supported yet.',
                        explode('=', $argument, 2)[0],
                    ));
                }
            }
        }
    }
}
