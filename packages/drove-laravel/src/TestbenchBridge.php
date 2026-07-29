<?php

declare(strict_types=1);

namespace Drove\Laravel;

use Closure;
use Drove\Kernel\StateAdapterException;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @internal
 */
final class TestbenchBridge
{
    private const string TEST_CASE = 'Orchestra\\Testbench\\TestCase';

    private const string ATTRIBUTE_NAMESPACE = 'Orchestra\\Testbench\\Attributes\\';

    private const string CONCERN_NAMESPACE = 'Orchestra\\Testbench\\Concerns\\';

    /** @var list<string> */
    private const array APPLICATION_PROPERTIES = [
        'enablesPackageDiscoveries',
        'loadEnvironmentVariables',
    ];

    /** @var list<string> */
    private const array APPLICATION_MUTATORS = [
        'applicationConsoleKernelUsingWorkbench',
        'applicationExceptionHandlerUsingWorkbench',
        'applicationHttpKernelUsingWorkbench',
        'createApplication',
        'defineDatabaseMigrations',
        'defineEnvironment',
        'defineRoutes',
        'defineWebRoutes',
        'getApplicationAliases',
        'getApplicationBasePath',
        'getApplicationBootstrapFile',
        'getApplicationProviders',
        'getApplicationTimezone',
        'getEnvironmentSetUp',
        'getPackageAliases',
        'getPackageBootstrappers',
        'getPackageProviders',
        'hasCustomApplicationKernels',
        'ignorePackageDiscoveriesFrom',
        'overrideApplicationAliases',
        'overrideApplicationBindings',
        'overrideApplicationProviders',
        'resolveApplication',
        'resolveApplicationBootstrappers',
        'resolveApplicationConfiguration',
        'resolveApplicationConsoleKernel',
        'resolveApplicationCore',
        'resolveApplicationEnvironmentVariables',
        'resolveApplicationExceptionHandler',
        'resolveApplicationFacades',
        'resolveApplicationHttpKernel',
        'resolveApplicationHttpMiddlewares',
        'resolveApplicationRateLimiting',
        'resolveApplicationResolvingCallback',
        'setUpApplicationRoutes',
        'usesTestbenchDefaultSkeleton',
    ];

    /** @var array<string, int> */
    private const array REQUIRED_METHOD_ARGUMENTS = [
        'createApplication' => 0,
        'setUpParallelTestingCallbacks' => 0,
        'setUpTheEnvironmentUsing' => 1,
        'setUpTheTestEnvironmentUsingTestCase' => 0,
    ];

    /** @var list<string> */
    private const array REQUIRED_PROPERTIES = [
        'app',
        'testCaseSetUpCallback',
    ];

    public static function isTestCase(TestCase $case): bool
    {
        return is_a($case, self::TEST_CASE);
    }

    public static function inspect(TestCase $case): void
    {
        self::assertCompatibleContract($case);
        self::assertNoAttributes($case);
        self::assertNoSetupCallback($case);
    }

    /**
     * @return class-string
     */
    public static function profile(TestCase $case): string
    {
        $class = self::caseClass($case);
        $ancestry = [];

        while ($class !== self::TEST_CASE) {
            $ancestry[] = $class;
            $class = get_parent_class($class);

            if (! is_string($class)) {
                throw new StateAdapterException(sprintf(
                    'Drove Laravel could not resolve the Orchestra Testbench profile for %s.',
                    $case::class,
                ));
            }
        }

        $custom = array_values(array_filter(
            $ancestry,
            static fn (string $candidate): bool => ! str_starts_with(
                $candidate,
                'Orchestra\\Testbench\\',
            ),
        ));
        $profile = end($custom);

        if (! is_string($profile)) {
            throw new StateAdapterException(sprintf(
                'Drove Laravel could not resolve the Orchestra Testbench profile for %s.',
                $case::class,
            ));
        }

        foreach ($custom as $candidate) {
            if ($candidate === $profile) {
                continue;
            }

            $reflection = new ReflectionClass($candidate);

            foreach (self::APPLICATION_MUTATORS as $method) {
                if ($reflection->hasMethod($method)
                    && $reflection->getMethod($method)
                        ->getDeclaringClass()
                        ->getName() === $candidate) {
                    return self::caseClass($case);
                }
            }

            foreach (self::APPLICATION_PROPERTIES as $property) {
                if ($reflection->hasProperty($property)
                    && $reflection->getProperty($property)
                        ->getDeclaringClass()
                        ->getName() === $candidate) {
                    return self::caseClass($case);
                }
            }

            $traits = class_uses_recursive($candidate);
            $parent = get_parent_class($candidate);

            if (is_string($parent)) {
                $traits = array_diff($traits, class_uses_recursive($parent));
            }

            foreach ($traits as $trait) {
                if (str_starts_with($trait, self::CONCERN_NAMESPACE)) {
                    return self::caseClass($case);
                }
            }
        }

        return $profile;
    }

    public static function createApplication(TestCase $case): Application
    {
        self::assertCompatibleContract($case);
        $application = (new ReflectionMethod($case, 'createApplication'))
            ->invoke($case);

        if (! $application instanceof Application) {
            throw new StateAdapterException(
                'Orchestra Testbench createApplication() did not return an Application.',
            );
        }

        return $application;
    }

    public static function bind(
        TestCase $case,
        Application $application,
        string $profile,
    ): void {
        self::assertCompatibleContract($case);
        $caseProfile = self::profile($case);

        if ($caseProfile !== $profile) {
            throw new StateAdapterException(sprintf(
                'Drove Laravel cannot bind Orchestra Testbench application profile %s to %s.',
                $caseProfile,
                $profile,
            ));
        }

        $app = new ReflectionProperty($case, 'app');
        $app->setAccessible(true);
        $bound = $app->getValue($case);

        if ($bound !== null && $bound !== $application) {
            throw new StateAdapterException(sprintf(
                'Orchestra Testbench TestCase %s is already bound to another application.',
                $case::class,
            ));
        }

        (new ReflectionMethod($case, 'setUpTheEnvironmentUsing'))->invoke(
            $case,
            static function (Closure $setUp) use (
                $app,
                $application,
                $case,
            ): void {
                $prepared = false;
                $preparedSetUp = static function () use (
                    &$prepared,
                    $app,
                    $application,
                    $case,
                    $setUp,
                ): void {
                    if ($prepared) {
                        return;
                    }

                    $prepared = true;
                    $app->setValue($case, $application);
                    (new ReflectionMethod(
                        $case,
                        'setUpTheTestEnvironmentUsingTestCase',
                    ))->invoke($case);
                    (new ReflectionMethod(
                        $case,
                        'setUpParallelTestingCallbacks',
                    ))->invoke($case);
                    $setUp();
                };

                $preparedSetUp();
            },
        );
    }

    public static function isDuskProfile(string $profile): bool
    {
        return is_a($profile, 'Orchestra\\Testbench\\Dusk\\TestCase', true);
    }

    private static function assertCompatibleContract(TestCase $case): void
    {
        $class = self::caseClass($case);
        $reflection = new ReflectionClass($class);

        foreach (self::REQUIRED_METHOD_ARGUMENTS as $name => $arguments) {
            if (! $reflection->hasMethod($name)) {
                self::incompatibleContract($class, $name.'()');
            }

            $method = $reflection->getMethod($name);

            if ($method->isStatic()
                || $method->getNumberOfRequiredParameters() > $arguments) {
                self::incompatibleContract($class, $name.'()');
            }
        }

        foreach (self::REQUIRED_PROPERTIES as $name) {
            if (! $reflection->hasProperty($name)
                || $reflection->getProperty($name)->isStatic()) {
                self::incompatibleContract($class, '$'.$name);
            }
        }
    }

    private static function incompatibleContract(
        string $class,
        string $member,
    ): never {
        throw new StateAdapterException(sprintf(
            'Drove Laravel does not support the Orchestra Testbench contract exposed by %s; expected compatible %s.',
            $class,
            $member,
        ));
    }

    /**
     * @return class-string<TestCase>
     */
    private static function caseClass(TestCase $case): string
    {
        $class = $case::class;

        if (is_a($case, 'Pest\\Contracts\\HasPrintableTestCaseName')) {
            $class = get_parent_class($case);
        }

        if (! is_string($class)
            || ! is_a($class, self::TEST_CASE, true)) {
            throw new StateAdapterException(sprintf(
                'Drove Laravel could not resolve the concrete Orchestra Testbench TestCase for %s.',
                $case::class,
            ));
        }

        return $class;
    }

    private static function assertNoAttributes(TestCase $case): void
    {
        $class = self::caseClass($case);
        $reflection = new ReflectionClass($class);

        while (! str_starts_with(
            $reflection->getName(),
            'Orchestra\\Testbench\\',
        )) {
            foreach ($reflection->getAttributes() as $attribute) {
                if (str_starts_with(
                    $attribute->getName(),
                    self::ATTRIBUTE_NAMESPACE,
                )) {
                    throw new StateAdapterException(sprintf(
                        'Drove Laravel does not support Orchestra Testbench attribute %s on class %s.',
                        $attribute->getName(),
                        $reflection->getName(),
                    ));
                }
            }

            $parent = $reflection->getParentClass();

            if (! $parent instanceof ReflectionClass) {
                break;
            }

            $reflection = $parent;
        }

        $method = new ReflectionMethod($case, $case->name());

        foreach ($method->getAttributes() as $attribute) {
            if (str_starts_with(
                $attribute->getName(),
                self::ATTRIBUTE_NAMESPACE,
            )) {
                throw new StateAdapterException(sprintf(
                    'Drove Laravel does not support Orchestra Testbench attribute %s on %s::%s().',
                    $attribute->getName(),
                    self::caseClass($case),
                    $method->getName(),
                ));
            }
        }
    }

    private static function assertNoSetupCallback(TestCase $case): void
    {
        $callback = new ReflectionProperty($case, 'testCaseSetUpCallback');
        $callback->setAccessible(true);

        if ($callback->getValue($case) !== null) {
            throw new StateAdapterException(sprintf(
                'Drove Laravel does not support a pre-existing Orchestra Testbench setup callback on %s.',
                self::caseClass($case),
            ));
        }
    }
}
