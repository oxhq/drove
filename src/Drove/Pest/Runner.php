<?php

declare(strict_types=1);

namespace Drove\Pest;

use Closure;
use Drove\CompatibilityRegistry;
use Drove\Console\Renderer;
use Drove\Coverage\Aggregator as CoverageAggregator;
use Drove\Environment\EnvironmentRuntime;
use Drove\Kernel\DroverScheduler;
use Drove\Kernel\LifecycleExecutor;
use Drove\Kernel\ScopeContext;
use Drove\Plugins\Manager as PluginManager;
use Drove\Replay\Artifact as ReplayArtifact;
use Drove\Version;
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
    private ?ReplayArtifact $replay = null;

    /** @var list<string> */
    private const array UNSUPPORTED_OPTIONS = [
        '--coverage',
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
        $runner = new self;

        try {
            return $runner->run($arguments, $rootPath);
        } catch (InvalidArgumentException|PHPUnitCliException $exception) {
            fwrite(STDERR, 'Drove: '.$exception->getMessage().PHP_EOL);

            return 2;
        } catch (Throwable $throwable) {
            try {
                $runner->replay?->writeCrash($throwable);
            } catch (Throwable $replayFailure) {
                fwrite(
                    STDERR,
                    'Drove replay: '.$replayFailure->getMessage().PHP_EOL,
                );
            }

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

        if ($this->hasOption($arguments, '--version')) {
            fwrite(STDOUT, 'Drove '.Version::current().PHP_EOL);

            return 0;
        }

        if ($this->hasOption($arguments, '--compatibility')) {
            fwrite(STDOUT, CompatibilityRegistry::json());

            return 0;
        }

        $options = $this->arguments($arguments);
        $phpunitArguments = $options['phpunit_arguments'];
        $concurrency = $options['processes'];
        $this->rejectUnsupportedOptions($phpunitArguments);
        $rootPath = realpath($rootPath);

        if ($rootPath === false) {
            throw new InvalidArgumentException('The project root does not exist.');
        }

        if ($options['replay'] !== null) {
            $this->replay = ReplayArtifact::create(
                $rootPath,
                $options['replay'],
                $options['replay_on_failure'],
                $phpunitArguments,
                $concurrency,
                $options['timeout_ms'],
            );
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
        $coverage = null;
        $plugins = new PluginManager;

        try {
            PestKernel::boot(
                PestTestSuite::getInstance($rootPath, 'tests'),
                new ArgvInput($phpunitArguments),
                new BufferedOutput,
            );
            $plugins->boot($arguments, $rootPath);
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
            $coverage = CoverageAggregator::fromConfiguration($configuration);
            $runtime = TestCaseRuntime::fromSuite(
                $compiler,
                $suite,
                $this->callback($laravel, 'bindTestCase'),
                $configuration->reportUselessTests(),
                capturePhpunitWarnings: true,
                coverage: $coverage,
                disallowTestOutput: $configuration->disallowTestOutput(),
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
            $plan = $compiler->suitePlan(
                $compiler->files(),
                ['default_test_timeout_ms' => $options['timeout_ms']],
            );

            if ($laravel instanceof EnvironmentRuntime) {
                $laravel->assertPlanSupported($plan);
                $plan['environment'] = $laravel->environmentPlan()->toArray();
            }

            $plugins->inspectPlan($plan);
            $this->replay?->recordPlan($plan);

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
                $plan,
                $this->scopeContext($laravel),
            );
            $coverage?->mergeAndReport();
        } catch (Throwable $throwable) {
            $runFailure = $throwable;
        } finally {
            $coverage?->cleanup();

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

        $plugins->reportRun($run);
        $this->replay?->writeRun($run);

        if (fwrite(STDOUT, (new Renderer)->render($run)) === false) {
            throw new RuntimeException('Drove could not write its result.');
        }

        return $exitCode;
    }

    private function laravelRuntime(string $rootPath, TestSuite $suite): ?EnvironmentRuntime
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

        if (! $runtime instanceof EnvironmentRuntime) {
            throw new RuntimeException('drove-laravel returned an invalid runtime.');
        }

        if (! method_exists($runtime, 'bindTestCase')) {
            throw new RuntimeException(
                'drove-laravel is missing bindTestCase().',
            );
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

        if ($runtime !== null && ! $runtime instanceof EnvironmentRuntime) {
            throw new RuntimeException('drove-laravel returned an invalid pre-suite runtime.');
        }
    }

    private function callback(?object $runtime, string $method): ?Closure
    {
        if (! $runtime instanceof EnvironmentRuntime) {
            return null;
        }

        return new ReflectionMethod($runtime, $method)->getClosure($runtime);
    }

    private function scopeContext(?EnvironmentRuntime $runtime): ?ScopeContext
    {
        if (! $runtime instanceof EnvironmentRuntime) {
            return null;
        }

        return $runtime->scopeContext();
    }

    /**
     * @param  list<string>  $arguments
     * @return array{
     *     phpunit_arguments: list<string>,
     *     processes: int,
     *     timeout_ms: int,
     *     replay: ?string,
     *     replay_on_failure: bool
     * }
     */
    private function arguments(array $arguments): array
    {
        $phpunitArguments = [];
        $parallel = false;
        $processes = null;
        $timeoutMs = 0;
        $replay = null;
        $replayOnFailure = false;
        $literal = false;
        $skip = [];

        foreach ($arguments as $index => $argument) {
            if (isset($skip[$index])) {
                continue;
            }

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
                $skip[$index + 1] = true;

                continue;
            }

            if (str_starts_with($argument, '--processes=')) {
                if ($processes !== null) {
                    throw new InvalidArgumentException('Drove accepts --processes only once.');
                }

                $processes = $this->processes(substr($argument, strlen('--processes=')));

                continue;
            }

            if ($argument === '--drove-timeout-ms') {
                if ($timeoutMs !== 0 || ! isset($arguments[$index + 1])) {
                    throw new InvalidArgumentException(
                        'Drove accepts one positive --drove-timeout-ms value.',
                    );
                }

                $timeoutMs = $this->positiveInteger(
                    $arguments[$index + 1],
                    '--drove-timeout-ms',
                );
                $skip[$index + 1] = true;

                continue;
            }

            if (str_starts_with($argument, '--drove-timeout-ms=')) {
                if ($timeoutMs !== 0) {
                    throw new InvalidArgumentException(
                        'Drove accepts --drove-timeout-ms only once.',
                    );
                }

                $timeoutMs = $this->positiveInteger(
                    substr($argument, strlen('--drove-timeout-ms=')),
                    '--drove-timeout-ms',
                );

                continue;
            }

            if (in_array($argument, ['--replay', '--replay-on-failure'], true)) {
                $value = $arguments[$index + 1] ?? null;

                if ($replay !== null
                    || ! is_string($value)
                    || $value === ''
                    || str_starts_with($value, '--')) {
                    throw new InvalidArgumentException(
                        'Drove accepts one replay artifact path.',
                    );
                }

                $replay = $value;
                $replayOnFailure = $argument === '--replay-on-failure';
                $skip[$index + 1] = true;

                continue;
            }

            foreach (['--replay=' => false, '--replay-on-failure=' => true] as $prefix => $failureOnly) {
                if (! str_starts_with($argument, $prefix)) {
                    continue;
                }

                if ($replay !== null) {
                    throw new InvalidArgumentException(
                        'Drove accepts one replay artifact path.',
                    );
                }

                $replay = substr($argument, strlen($prefix));
                $replayOnFailure = $failureOnly;

                continue 2;
            }

            $phpunitArguments[] = $argument;
        }

        if ($processes !== null && ! $parallel) {
            throw new InvalidArgumentException('--processes requires --parallel.');
        }

        return [
            'phpunit_arguments' => $phpunitArguments,
            'processes' => $parallel ? ($processes ?? Options::getNumberOfCPUCores()) : 1,
            'timeout_ms' => $timeoutMs,
            'replay' => $replay,
            'replay_on_failure' => $replayOnFailure,
        ];
    }

    private function processes(string $value): int
    {
        return $this->positiveInteger($value, '--processes');
    }

    /**
     * @param  list<string>  $arguments
     */
    private function hasOption(array $arguments, string $option): bool
    {
        foreach (array_slice($arguments, 1) as $argument) {
            if ($argument === '--') {
                return false;
            }

            if ($argument === $option) {
                return true;
            }
        }

        return false;
    }

    private function positiveInteger(string $value, string $option): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
        ]);

        if (! is_int($integer)) {
            throw new InvalidArgumentException(sprintf(
                'Drove requires a positive %s value.',
                $option,
            ));
        }

        return $integer;
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
