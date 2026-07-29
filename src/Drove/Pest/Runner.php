<?php

declare(strict_types=1);

namespace Drove\Pest;

use Closure;
use Drove\Console\Renderer;
use Drove\Kernel\DroverScheduler;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\ScopeContext;
use InvalidArgumentException;
use ParaTest\Options;
use Pest\Kernel as PestKernel;
use Pest\TestSuite as PestTestSuite;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Runner\DeprecationCollector\Facade as DeprecationCollector;
use PHPUnit\Runner\TestSuiteSorter;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;
use PHPUnit\TextUI\Configuration\BootstrapLoader;
use PHPUnit\TextUI\Configuration\Builder;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\Configuration\TestSuiteBuilder;
use PHPUnit\TextUI\Exception as PHPUnitCliException;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use ReflectionException;
use ReflectionMethod;
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
        $failOnRisky = false;
        $failOnEmptyTestSuite = false;
        $failOnPhpunitWarning = true;
        $phpunitWarnings = false;

        try {
            PestKernel::boot(
                PestTestSuite::getInstance($rootPath, 'tests'),
                new ArgvInput($phpunitArguments),
                new BufferedOutput,
            );
            $compiler = ScopeCompiler::activate($rootPath, ownsScopeHooks: true);
            $configuration = (new Builder)->build($phpunitArguments);

            if ($configuration->processIsolation()) {
                throw new InvalidArgumentException('Drove does not support processIsolation from PHPUnit XML yet.');
            }

            if ($configuration->enforceTimeLimit()) {
                throw new InvalidArgumentException('Drove does not support enforceTimeLimit from PHPUnit XML yet.');
            }

            if ($configuration->failOnIncomplete()) {
                throw new InvalidArgumentException('Drove does not support failOnIncomplete from PHPUnit XML yet.');
            }

            if ($configuration->failOnAllIssues()
                || $configuration->failOnDeprecation()
                || $configuration->failOnPhpunitDeprecation()
                || $configuration->failOnPhpunitNotice()
                || $configuration->failOnNotice()
                || $configuration->failOnSkipped()
                || $configuration->failOnWarning()) {
                throw new InvalidArgumentException('Drove does not support this PHPUnit failOn policy from XML yet.');
            }

            if ($configuration->stopOnDefectThreshold() > 0
                || $configuration->stopOnDeprecationThreshold() > 0
                || $configuration->stopOnErrorThreshold() > 0
                || $configuration->stopOnFailureThreshold() > 0
                || $configuration->stopOnIncompleteThreshold() > 0
                || $configuration->stopOnNoticeThreshold() > 0
                || $configuration->stopOnRiskyThreshold() > 0
                || $configuration->stopOnSkippedThreshold() > 0
                || $configuration->stopOnWarningThreshold() > 0
                || $configuration->hasSpecificDeprecationToStopOn()) {
                throw new InvalidArgumentException('Drove does not support PHPUnit stopOn policies from XML yet.');
            }

            if ($configuration->beStrictAboutChangesToGlobalState()) {
                throw new InvalidArgumentException('Drove does not support beStrictAboutChangesToGlobalState from PHPUnit XML yet.');
            }

            if ($configuration->strictCoverage()
                || $configuration->requireCoverageContribution()
                || $configuration->requireCoverageMetadata()) {
                throw new InvalidArgumentException('Drove does not support strict PHPUnit coverage modes from XML yet.');
            }

            if ($configuration->disallowTestOutput()) {
                throw new InvalidArgumentException('Drove does not support disallowTestOutput from PHPUnit XML yet.');
            }

            if ($configuration->extensionBootstrappers() !== []) {
                throw new InvalidArgumentException('Drove does not support PHPUnit extensions yet.');
            }

            if ($configuration->executionOrder() !== TestSuiteSorter::ORDER_DEFAULT
                || $configuration->executionOrderDefects() !== TestSuiteSorter::ORDER_DEFAULT) {
                throw new InvalidArgumentException('Drove does not support non-default PHPUnit execution order yet.');
            }

            $failOnRisky = $configuration->failOnRisky();
            $failOnEmptyTestSuite = $configuration->failOnEmptyTestSuite();
            $failOnPhpunitWarning = $configuration->failOnPhpunitWarning();
            DeprecationCollector::init();
            TestResultFacade::init();
            (new PhpHandler)->handle($configuration->php());
            (new BootstrapLoader)->handle($configuration);
            $this->bootLaravelBeforeSuite($rootPath);
            $suite = (new TestSuiteBuilder)->build($configuration);
            (new TestSuiteFilterProcessor)->process($configuration, $suite);
            $laravel = $this->laravelRuntime($rootPath, $suite);
            $runtime = TestCaseRuntime::fromSuite(
                $compiler,
                $suite,
                $this->callback($laravel, 'bindTestCase'),
                $configuration->reportUselessTests(),
                capturePhpunitWarnings: true,
            );
            EventFacade::instance()->seal();
            $phpunitResult = TestResultFacade::result();

            if ($phpunitResult->hasErrors()) {
                throw new InvalidArgumentException(
                    'Drove cannot plan a suite that triggered a PHPUnit error during discovery.',
                );
            }

            $phpunitWarnings = $phpunitResult->testTriggeredPhpunitWarningEvents() !== []
                || array_any(
                    $phpunitResult->testRunnerTriggeredWarningEvents(),
                    static fn (object $event): bool => $event->message()
                        !== 'No tests found in class "Pest\\TestCases\\IgnorableTestCase".',
                );
            $resolvers = $runtime->resolvers();

            $executor = new LifecycleExecutor(
                new DroverScheduler(
                    'drove-'.bin2hex(random_bytes(8)),
                    $concurrency,
                ),
                $compiler->hook(...),
                static fn (string $id): array => $resolvers[$id],
                beforeDispatch: $this->callback($laravel, 'beforeDispatch'),
                enterDescendant: $this->callback($laravel, 'enterDescendant'),
                leaveDescendant: $this->callback($laravel, 'leaveDescendant'),
                afterDispatch: $this->callback($laravel, 'afterDispatch'),
            );
            $run = $executor->run(
                $compiler->suitePlan($compiler->files()),
                $this->scopeContext($laravel),
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

        if ($failOnRisky
            && array_any(
                is_array($run['tests'] ?? null) ? $run['tests'] : [],
                static fn (mixed $test): bool => is_array($test)
                    && ($test['status'] ?? null) === 'risky',
            )) {
            $run['exit_code'] = $exitCode = 1;
        }

        $phpunitWarnings = $phpunitWarnings || array_any(
            is_array($run['tests'] ?? null) ? $run['tests'] : [],
            static fn (mixed $test): bool => is_array($test)
                && is_array($test['value'] ?? null)
                && ($test['value']['phpunit_warnings'] ?? 0) > 0,
        );
        $run['phpunit_warnings'] = $phpunitWarnings;

        if ($failOnPhpunitWarning && $phpunitWarnings) {
            $run['exit_code'] = $exitCode = 1;
        }

        if ($failOnEmptyTestSuite
            && (is_array($run['tests'] ?? null) ? $run['tests'] : []) === []) {
            $run['exit_code'] = $exitCode = 1;
        }

        if (fwrite(STDOUT, (new Renderer)->render($run)) === false) {
            throw new RuntimeException('Drove could not write its result.');
        }

        return $exitCode;
    }

    private function laravelRuntime(string $rootPath, TestSuite $suite): ?object
    {
        $class = 'Drove\\Laravel\\LaravelRuntime';

        if (! class_exists($class)) {
            return null;
        }

        try {
            $boot = new ReflectionMethod($class, 'bootForSuite');
        } catch (ReflectionException) {
            throw new RuntimeException('drove-laravel is missing bootForSuite().');
        }

        $runtime = $boot->invoke(null, $rootPath, $suite);

        if (! is_object($runtime)) {
            throw new RuntimeException('drove-laravel returned an invalid runtime.');
        }

        foreach ([
            'scopeContext',
            'bindTestCase',
            'beforeDispatch',
            'enterDescendant',
            'leaveDescendant',
            'afterDispatch',
        ] as $method) {
            if (! method_exists($runtime, $method)) {
                throw new RuntimeException(sprintf(
                    'drove-laravel is missing %s().',
                    $method,
                ));
            }
        }

        return $runtime;
    }

    private function bootLaravelBeforeSuite(string $rootPath): void
    {
        $class = 'Drove\\Laravel\\LaravelRuntime';

        if (! class_exists($class)) {
            return;
        }

        try {
            $boot = new ReflectionMethod($class, 'bootBeforeSuite');
        } catch (ReflectionException) {
            throw new RuntimeException('drove-laravel is missing bootBeforeSuite().');
        }

        $runtime = $boot->invoke(null, $rootPath);

        if ($runtime !== null && ! is_object($runtime)) {
            throw new RuntimeException('drove-laravel returned an invalid pre-suite runtime.');
        }
    }

    private function callback(?object $runtime, string $method): ?Closure
    {
        if ($runtime === null) {
            return null;
        }

        return new ReflectionMethod($runtime, $method)->getClosure($runtime);
    }

    private function scopeContext(?object $runtime): ?ScopeContext
    {
        if ($runtime === null) {
            return null;
        }

        $context = $this->callback($runtime, 'scopeContext')?->__invoke();

        return $context instanceof ScopeContext
            ? $context
            : throw new RuntimeException('drove-laravel returned an invalid scope context.');
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
