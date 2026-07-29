<?php

declare(strict_types=1);

use Drove\Kernel\StateAdapterException;
use Drove\Laravel\LaravelRuntime;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\TestSuite;

require __DIR__.'/vendor/autoload.php';

abstract class ProfileGuardTestCase extends TestCase
{
    public function placeholder(): void
    {
        //
    }
}

final class FirstProfileGuardTest extends ProfileGuardTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('drove.profile', 'first');
    }
}

final class SecondProfileGuardTest extends ProfileGuardTestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('drove.profile', 'second');
    }
}

final class LoadsEnvironmentProfileGuardTest extends ProfileGuardTestCase
{
    protected $loadEnvironmentVariables = true;
}

final class SkipsEnvironmentProfileGuardTest extends ProfileGuardTestCase
{
    protected $loadEnvironmentVariables = false;
}

final class WorkbenchProfileGuardTest extends ProfileGuardTestCase
{
    use WithWorkbench;
}

trait NestedWorkbenchProfile
{
    use WithWorkbench;
}

final class NestedWorkbenchProfileGuardTest extends ProfileGuardTestCase
{
    use NestedWorkbenchProfile;
}

final class PlainProfileGuardTest extends ProfileGuardTestCase {}

final class MethodAttributeGuardTest extends ProfileGuardTestCase
{
    #[WithConfig('drove.profile', 'method')]
    public function mutatesApplication(): void
    {
        //
    }
}

#[WithConfig('drove.profile', 'class')]
final class ClassAttributeGuardTest extends ProfileGuardTestCase
{
    //
}

final class CallbackGuardTest extends ProfileGuardTestCase {}

putenv('APP_ENV=testing');
putenv('DROVE_LARAVEL_RUNTIME=testbench');

$failure = static function (TestSuite $suite): ?string {
    try {
        LaravelRuntime::bootForSuite(__DIR__, $suite);
    } catch (StateAdapterException $exception) {
        return $exception->getMessage();
    }

    return null;
};

$profiles = TestSuite::empty('profile guard');
$profiles->addTest(new FirstProfileGuardTest('placeholder'));
$profiles->addTest(new SecondProfileGuardTest('placeholder'));
$profileFailure = $failure($profiles);

$propertyProfiles = TestSuite::empty('property profile guard');
$propertyProfiles->addTest(new LoadsEnvironmentProfileGuardTest('placeholder'));
$propertyProfiles->addTest(new SkipsEnvironmentProfileGuardTest('placeholder'));
$propertyProfileFailure = $failure($propertyProfiles);

$traitProfiles = TestSuite::empty('trait profile guard');
$traitProfiles->addTest(new WorkbenchProfileGuardTest('placeholder'));
$traitProfiles->addTest(new PlainProfileGuardTest('placeholder'));
$traitProfileFailure = $failure($traitProfiles);

$nestedTraitProfiles = TestSuite::empty('nested trait profile guard');
$nestedTraitProfiles->addTest(new NestedWorkbenchProfileGuardTest('placeholder'));
$nestedTraitProfiles->addTest(new PlainProfileGuardTest('placeholder'));
$nestedTraitProfileFailure = $failure($nestedTraitProfiles);

$methodAttribute = TestSuite::empty('method attribute guard');
$methodAttribute->addTest(new MethodAttributeGuardTest('mutatesApplication'));
$methodAttributeFailure = $failure($methodAttribute);

$classAttribute = TestSuite::empty('class attribute guard');
$classAttribute->addTest(new ClassAttributeGuardTest('placeholder'));
$classAttributeFailure = $failure($classAttribute);

$callbackCase = new CallbackGuardTest('placeholder');
$callbackCase->setUpTheEnvironmentUsing(
    static function (Closure $setUp): void {
        // Deliberately omit $setUp(); LaravelRuntime must still bind its app.
    },
);
$callbackSuite = TestSuite::empty('callback guard');
$callbackSuite->addTest($callbackCase);
$callbackFailure = $failure($callbackSuite);

$passed = str_contains(
    (string) $profileFailure,
    'requires one Orchestra Testbench application profile',
)
    && str_contains(
        (string) $propertyProfileFailure,
        'requires one Orchestra Testbench application profile',
    )
    && str_contains(
        (string) $traitProfileFailure,
        'requires one Orchestra Testbench application profile',
    )
    && str_contains(
        (string) $nestedTraitProfileFailure,
        'requires one Orchestra Testbench application profile',
    )
    && str_contains(
        (string) $methodAttributeFailure,
        'WithConfig on MethodAttributeGuardTest::mutatesApplication()',
    )
    && str_contains(
        (string) $classAttributeFailure,
        'WithConfig on class ClassAttributeGuardTest',
    )
    && str_contains(
        (string) $callbackFailure,
        'pre-existing Orchestra Testbench setup callback',
    );

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'profile_failure' => $profileFailure,
    'property_profile_failure' => $propertyProfileFailure,
    'trait_profile_failure' => $traitProfileFailure,
    'nested_trait_profile_failure' => $nestedTraitProfileFailure,
    'method_attribute_failure' => $methodAttributeFailure,
    'class_attribute_failure' => $classAttributeFailure,
    'callback_failure' => $callbackFailure,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
