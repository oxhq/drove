<?php

declare(strict_types=1);

namespace DroveClassFixture;

use Drove\Native\Attributes\DataProvider;
use Drove\Native\Attributes\Group;
use Drove\Native\Attributes\Test;
use Drove\Native\Attributes\TestClass;

use function Drove\Native\expect;

#[Group('livewire')]
#[TestClass]
final class LivewireShapedUnitTest
{
    public static int $beforeClass = 0;

    public static int $afterClass = 0;

    private int $value = 0;

    protected static function setUpBeforeClass(): void
    {
        self::$beforeClass++;
    }

    protected static function tearDownAfterClass(): void
    {
        self::$afterClass++;
    }

    protected function setUp(): void
    {
        self::assertExternalRuntimeUnloaded();
        expect(self::$beforeClass)->toBe(1);
        $this->value = 40;
        echo 'setup|';
    }

    protected function tearDown(): void
    {
        expect($this->value)->toBe(40);
        echo 'teardown|';
    }

    public function test_convention_method(): void
    {
        expect($this->value)->toBe(40);
        echo 'convention|';
    }

    #[Test]
    #[DataProvider('additionRows')]
    #[Group('dataset')]
    public function adds_values(int $left, int $right, int $sum): void
    {
        expect($left + $right)->toBe($sum);
        echo sprintf('dataset:%d|', $sum);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function additionRows(): iterable
    {
        yield 'small' => [1, 2, 3];
        yield 'larger' => [20, 22, 42];
    }

    private static function assertExternalRuntimeUnloaded(): void
    {
        foreach ([
            'PHPUnit\\Framework\\TestCase',
            'Orchestra\\Testbench\\TestCase',
            'Pest\\Kernel',
        ] as $class) {
            if (class_exists($class, false)) {
                throw new \RuntimeException('The native class descendant loaded '.$class.'.');
            }
        }

        foreach (get_included_files() as $file) {
            $file = str_replace('\\', '/', $file);

            if (str_contains($file, '/vendor/phpunit/phpunit/')
                || str_contains($file, '/vendor/orchestra/testbench/')
                || str_ends_with($file, '/src/Functions.php')
                || str_ends_with($file, '/src/Pest.php')) {
                throw new \RuntimeException('The native class descendant loaded an external runtime file.');
            }
        }
    }
}

final class TestNamedHelper
{
    public function testConnection(): never
    {
        throw new \LogicException('An unmarked helper was mistaken for a native test class.');
    }
}
