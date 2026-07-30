<?php

declare(strict_types=1);

namespace Drove\Pest;

use Closure;
use Drove\Coverage\Aggregator as CoverageAggregator;
use Drove\Kernel\ScopeContext;
use Drove\Kernel\SkipScope;
use Drove\Kernel\TestOutcome;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use Pest\Contracts\HasPrintableTestCaseName;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Metadata\Api\CodeCoverage as CodeCoverageMetadata;
use PHPUnit\Metadata\Api\HookMethods;
use PHPUnit\Metadata\Api\Requirements;
use PHPUnit\Metadata\Parser\Registry as MetadataRegistry;
use PHPUnit\Runner\ErrorHandler;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class TestCaseRuntime
{
    /** @var array<string, TestCase> */
    private array $cases = [];

    /** @var array<string, array<string, mixed>> */
    private array $descriptors = [];

    /** @var array<string, true> */
    private array $ran = [];

    /** @var array<string, Throwable> */
    private array $throwables = [];

    /**
     * @param  (Closure(TestCase, ScopeContext): void)|null  $prepareCase
     */
    private function __construct(
        private readonly ?Closure $prepareCase,
        private readonly bool $reportUselessTests,
        private readonly bool $capturePhpunitWarnings,
        private readonly ?CoverageAggregator $coverage,
        private readonly bool $disallowTestOutput,
    ) {
        //
    }

    /**
     * @param  (Closure(TestCase, ScopeContext): void)|null  $prepareCase
     */
    public static function fromSuite(
        ScopeCompiler $compiler,
        TestSuite $suite,
        ?Closure $prepareCase = null,
        bool $reportUselessTests = true,
        bool $capturePhpunitWarnings = false,
        ?CoverageAggregator $coverage = null,
        bool $disallowTestOutput = false,
    ): self {
        $runtime = new self(
            $prepareCase,
            $reportUselessTests,
            $capturePhpunitWarnings,
            $coverage,
            $disallowTestOutput,
        );
        $pestFiles = $compiler->files();
        $casesByFile = [];
        $classLifecycles = [];
        $nativeClasses = [];

        foreach ($suite->collect() as $case) {
            if (! $case instanceof TestCase) {
                throw new InvalidArgumentException('Drove only supports PHPUnit TestCase cases.');
            }

            $reflection = new ReflectionClass($case);
            $isGeneratedPest = $case instanceof HasPrintableTestCaseName;

            if ($isGeneratedPest) {
                if (! $reflection->hasProperty('__filename')) {
                    throw new RuntimeException('Drove received an invalid generated Pest case.');
                }

                $filename = $reflection->getStaticPropertyValue('__filename');

                if (! is_string($filename)) {
                    throw new RuntimeException('Drove received an invalid generated Pest case.');
                }

                if (! $compiler->hasPlan($filename)) {
                    throw new RuntimeException('Drove received an unplanned generated Pest case.');
                }

                $descriptor = $compiler->caseDescriptor(
                    $filename,
                    $case->name(),
                    $case->dataName(),
                    $case->dataSetAsString(),
                    $case->groups(),
                );
            } else {
                $filename = $reflection->getFileName();

                if (! is_string($filename)) {
                    throw new RuntimeException('Drove could not locate a native PHPUnit case file.');
                }

                if (isset($nativeClasses[$filename]) && $nativeClasses[$filename] !== $case::class) {
                    throw new RuntimeException(sprintf(
                        'Drove supports one native PHPUnit TestCase class per file; %s contains %s and %s.',
                        $filename,
                        $nativeClasses[$filename],
                        $case::class,
                    ));
                }

                $nativeClasses[$filename] = $case::class;
                $method = $reflection->getMethod($case->name());
                $sourceFile = $method->getFileName();
                $line = $method->getStartLine();

                if (! is_string($sourceFile) || $line === false) {
                    throw new RuntimeException('Drove could not locate a native PHPUnit test source.');
                }

                $descriptor = $compiler->nativeCaseDescriptor(
                    $filename,
                    $sourceFile,
                    $line,
                    $case::class,
                    $case->name(),
                    $case->dataName(),
                    $case->dataSetAsString(),
                    $case->groups(),
                );
            }

            self::assertSupported($case, $reflection);

            if (isset($classLifecycles[$filename])
                && $classLifecycles[$filename]['class'] !== $case::class) {
                throw new RuntimeException(sprintf(
                    'Drove received multiple TestCase classes for %s.',
                    $filename,
                ));
            }

            $classLifecycles[$filename] ??= self::classLifecycle($reflection, $isGeneratedPest);
            $id = $descriptor['test']['id'];

            if (isset($runtime->cases[$id])) {
                throw new RuntimeException('Drove received a duplicate test case.');
            }

            $runtime->cases[$id] = $case;
            $runtime->descriptors[$id] = $descriptor['test'];
            $casesByFile[$descriptor['file']][] = $descriptor;
        }

        foreach ($pestFiles as $filename) {
            $casesByFile[$filename] ??= [];
        }

        $compiler->bindCases($casesByFile);

        foreach ($classLifecycles as $filename => $lifecycle) {
            $compiler->bindClassLifecycle(
                $filename,
                $lifecycle['before_class'],
                $lifecycle['after_class'],
            );
        }

        return $runtime;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function descriptors(): array
    {
        return $this->descriptors;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $id, ?ScopeContext $context = null): array
    {
        $case = $this->cases[$id] ?? throw new OutOfBoundsException(sprintf(
            'No test case was captured for %s.',
            $id,
        ));

        if (isset($this->ran[$id])) {
            throw new LogicException(sprintf('Test case %s was already run.', $id));
        }

        $this->ran[$id] = true;
        Assert::resetCount();
        $errorHandlerEnabled = false;
        $coverageCapture = null;
        $phpunitWarningsBefore = $this->capturePhpunitWarnings
            ? TestResultFacade::result()->numberOfPhpunitWarnings()
            : 0;

        try {
            if ($this->prepareCase instanceof Closure) {
                if (! $context instanceof ScopeContext) {
                    throw new LogicException('Drove case preparation requires a scope context.');
                }

                ($this->prepareCase)($case, $context);
            }

            if (MetadataRegistry::parser()
                ->forMethod($case::class, $case->name())
                ->isWithoutErrorHandler()
                ->isEmpty()) {
                ErrorHandler::instance()->enable($case);
                $errorHandlerEnabled = true;
            }

            $coverageCapture = $this->coverage?->start($case);
            $case->runBare();
        } catch (Throwable $throwable) {
            if ($case->status()->isUnknown()) {
                $this->coverage?->abort($coverageCapture);

                throw $throwable;
            }

            $this->throwables[$id] = $throwable;
        } finally {
            if ($errorHandlerEnabled) {
                ErrorHandler::instance()->disable();
            }

            $case->addToAssertionCount(Assert::getCount());
        }

        $phpunitStatus = $case->status();
        $disposition = $this->descriptors[$id]['disposition'] ?? 'run';
        $risk = null;

        if ($phpunitStatus->isSuccess()
            && $this->reportUselessTests
            && ! $case->doesNotPerformAssertions()
            && $case->numberOfAssertionsPerformed() === 0) {
            $risk = 'This test did not perform any assertions';
        } elseif ($phpunitStatus->isSuccess()
            && $case->doesNotPerformAssertions()
            && $case->numberOfAssertionsPerformed() > 0) {
            $risk = sprintf(
                'This test is not expected to perform assertions but performed %d assertion%s',
                $case->numberOfAssertionsPerformed(),
                $case->numberOfAssertionsPerformed() > 1 ? 's' : '',
            );
        }

        if ($phpunitStatus->isSuccess()
            && $this->disallowTestOutput
            && $case->hasUnexpectedOutput()) {
            $risk = sprintf(
                'Test code or tested code printed unexpected output: %s',
                $case->output(),
            );
        }

        $status = match (true) {
            $risk !== null => 'risky',
            $phpunitStatus->isSuccess() => 'passed',
            $phpunitStatus->isSkipped() && $disposition === 'todo' => 'todo',
            $phpunitStatus->isSkipped() => 'skipped',
            $phpunitStatus->isIncomplete() => 'incomplete',
            $phpunitStatus->isRisky() => 'risky',
            default => 'failed',
        };
        $this->coverage?->finish(
            $case,
            $coverageCapture,
            ! in_array($status, ['skipped', 'todo', 'incomplete', 'risky'], true),
        );

        return [
            'id' => $id,
            'status' => $status,
            'phpunit_status' => $phpunitStatus->asString(),
            'message' => $risk ?? $phpunitStatus->message(),
            'output' => $case->output(),
            'assertions' => $case->numberOfAssertionsPerformed(),
            'phpunit_warnings' => $this->capturePhpunitWarnings
                ? max(
                    0,
                    TestResultFacade::result()->numberOfPhpunitWarnings() - $phpunitWarningsBefore,
                )
                : 0,
            'test_case' => $case::class,
            'method' => $case->name(),
            'dataset' => $this->descriptors[$id]['dataset'] ?? null,
        ];
    }

    public function closure(string $id): Closure
    {
        $runtime = $this;

        return static function (ScopeContext $context) use ($id, $runtime): TestOutcome {
            $result = $runtime->run($id, $context);

            echo $result['output'];

            if ($result['status'] === 'failed') {
                throw $runtime->throwable($id);
            }

            $result['message'] = $result['message'] === '' || $result['message'] === '__TODO__'
                ? null
                : (string) $result['message'];

            return match ($result['status']) {
                'skipped' => TestOutcome::skipped($result),
                'todo' => TestOutcome::todo($result),
                'incomplete' => TestOutcome::incomplete($result),
                'risky' => TestOutcome::risky($result),
                default => TestOutcome::passed($result),
            };
        };
    }

    /**
     * @return array{closure: Closure, runtime: object}
     */
    public function resolver(string $id): array
    {
        $case = $this->cases[$id] ?? throw new OutOfBoundsException(sprintf(
            'No test case was captured for %s.',
            $id,
        ));

        return [
            'closure' => $this->closure($id),
            'runtime' => $case,
        ];
    }

    /**
     * @return array<string, array{closure: Closure, runtime: object}>
     */
    public function resolvers(): array
    {
        $resolvers = [];

        foreach (array_keys($this->cases) as $id) {
            $resolvers[$id] = $this->resolver($id);
        }

        return $resolvers;
    }

    private function throwable(string $id): Throwable
    {
        return $this->throwables[$id] ?? new RuntimeException(sprintf(
            'Test case %s failed without a Throwable.',
            $id,
        ));
    }

    /**
     * @param  ReflectionClass<TestCase>  $reflection
     */
    private static function assertSupported(TestCase $case, ReflectionClass $reflection): void
    {
        if ($case->requires() !== []) {
            throw new InvalidArgumentException('Drove does not support test dependencies yet.');
        }

        $metadata = MetadataRegistry::parser();
        $coverageMetadata = new CodeCoverageMetadata;
        $covers = array_map(
            static fn (object $target): string => $target->description(),
            $coverageMetadata->coversTargets($case::class, $case->name())->asArray(),
        );
        $uses = array_map(
            static fn (object $target): string => $target->description(),
            $coverageMetadata->usesTargets($case::class, $case->name())->asArray(),
        );

        if ((! $coverageMetadata->shouldCodeCoverageBeCollectedFor($case)
                && ($covers !== [] || $uses !== []))
            || array_diff_assoc($covers, array_unique($covers)) !== []
            || array_diff_assoc($uses, array_unique($uses)) !== []
            || array_intersect($covers, $uses) !== []) {
            throw new InvalidArgumentException(
                'Drove does not support conflicting PHPUnit coverage metadata yet.',
            );
        }

        if ($metadata->forMethod($case::class, $case->name())->isRunInSeparateProcess()->isNotEmpty()) {
            throw new InvalidArgumentException('Drove does not support PHPUnit process-isolation metadata yet.');
        }

        $class = $reflection;

        while ($class instanceof ReflectionClass) {
            if ($metadata->forClass($class->getName())->isRunTestsInSeparateProcesses()->isNotEmpty()) {
                throw new InvalidArgumentException('Drove does not support PHPUnit process-isolation metadata yet.');
            }

            $class = $class->getParentClass();
        }

        if (! $reflection->getParentClass() instanceof ReflectionClass) {
            throw new RuntimeException('Drove received an invalid PHPUnit TestCase.');
        }
    }

    /**
     * @param  ReflectionClass<TestCase>  $reflection
     * @return array{
     *     class: class-string<TestCase>,
     *     before_class: (Closure(): void)|null,
     *     after_class: (Closure(): void)|null
     * }
     */
    private static function classLifecycle(
        ReflectionClass $reflection,
        bool $isGeneratedPest,
    ): array {
        $class = $reflection->getName();

        if (! $isGeneratedPest) {
            $hookMethods = (new HookMethods)->hookMethods($class);

            return [
                'class' => $class,
                'before_class' => self::nativeClassHook(
                    $reflection,
                    $hookMethods['beforeClass']->methodNamesSortedByPriority(),
                    true,
                ),
                'after_class' => self::nativeClassHook(
                    $reflection,
                    $hookMethods['afterClass']->methodNamesSortedByPriority(),
                    false,
                ),
            ];
        }

        $hookMethods = (new HookMethods)->hookMethods($class);

        foreach ([
            'beforeClass' => 'setUpBeforeClass',
            'afterClass' => 'tearDownAfterClass',
        ] as $type => $lifecycleMethod) {
            foreach ($hookMethods[$type]->methodNamesSortedByPriority() as $method) {
                if ($method !== $lifecycleMethod
                    && $reflection->hasMethod($method)
                    && $reflection->getMethod($method)
                        ->getDeclaringClass()
                        ->getName() !== TestCase::class) {
                    throw new InvalidArgumentException(sprintf(
                        'Drove does not support PHPUnit attribute class hook %s::%s() on a generated Pest TestCase yet.',
                        $reflection->getName(),
                        $method,
                    ));
                }
            }
        }

        $lifecycleClass = $reflection->getParentClass();

        if (! $lifecycleClass instanceof ReflectionClass) {
            throw new RuntimeException('Drove received an invalid PHPUnit TestCase.');
        }

        $beforeClass = $lifecycleClass->getMethod('setUpBeforeClass')->getDeclaringClass()->getName() === TestCase::class
            ? null
            : static function () use ($class): void {
                $missing = (new Requirements)->requirementsNotSatisfiedFor(
                    $class,
                    'setUpBeforeClass',
                );

                if ($missing !== []) {
                    throw new SkipScope(implode(PHP_EOL, $missing));
                }

                try {
                    $class::setUpBeforeClass();
                } catch (SkippedTest $skipped) {
                    throw new SkipScope($skipped->getMessage(), $skipped->getCode(), previous: $skipped);
                }
            };
        $afterClass = $lifecycleClass->getMethod('tearDownAfterClass')->getDeclaringClass()->getName() === TestCase::class
            ? null
            : static function () use ($class): void {
                $class::tearDownAfterClass();
            };

        return [
            'class' => $class,
            'before_class' => $beforeClass,
            'after_class' => $afterClass,
        ];
    }

    /**
     * @param  ReflectionClass<TestCase>  $reflection
     * @param  list<non-empty-string>  $methods
     * @return (Closure(): void)|null
     */
    private static function nativeClassHook(
        ReflectionClass $reflection,
        array $methods,
        bool $before,
    ): ?Closure {
        $supported = [];

        foreach ($methods as $method) {
            if (! $reflection->hasMethod($method)) {
                continue;
            }

            $hook = $reflection->getMethod($method);

            if ($hook->getDeclaringClass()->getName() === TestCase::class) {
                continue;
            }

            if (! $hook->isPublic() || ! $hook->isStatic()) {
                throw new InvalidArgumentException(sprintf(
                    'Drove requires native PHPUnit class hook %s::%s() to be public and static.',
                    $reflection->getName(),
                    $method,
                ));
            }

            $supported[] = $method;
        }

        if ($supported === []) {
            return null;
        }

        $class = $reflection->getName();

        return static function () use ($before, $class, $supported): void {
            $failure = null;

            foreach ($supported as $method) {
                try {
                    if ($before) {
                        $missing = (new Requirements)->requirementsNotSatisfiedFor(
                            $class,
                            $method,
                        );

                        if ($missing !== []) {
                            if ($failure instanceof Throwable) {
                                break;
                            }

                            throw new SkipScope(implode(PHP_EOL, $missing));
                        }
                    }

                    new ReflectionMethod($class, $method)->invoke(null);
                } catch (SkipScope $skipped) {
                    throw $skipped;
                } catch (Throwable $throwable) {
                    if ($before && $throwable instanceof SkippedTest) {
                        if ($failure instanceof Throwable) {
                            break;
                        }

                        throw new SkipScope($throwable->getMessage(), $throwable->getCode(), previous: $throwable);
                    }

                    $failure ??= $throwable;
                }
            }

            if ($failure instanceof Throwable) {
                throw $failure;
            }
        };
    }
}
