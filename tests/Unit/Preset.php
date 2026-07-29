<?php

use Pest\Preset;

pest()->presets()->custom('myFramework', fn (array $userNamespaces): array => [
    expect($userNamespaces)->toBe(['Pest']),
]);

test('preset invalid name', function (): void {
    $baseNamespaces = new ReflectionProperty(Preset::class, 'baseNamespaces');
    $method = new ReflectionMethod(Preset::class, 'baseNamespaces');
    $cachedNamespaces = $baseNamespaces->getValue();
    $hadInternalSuiteFlag = array_key_exists('__PEST_INTERNAL_TEST_SUITE', $GLOBALS);
    $internalSuiteFlag = $GLOBALS['__PEST_INTERNAL_TEST_SUITE'] ?? null;

    try {
        unset($GLOBALS['__PEST_INTERNAL_TEST_SUITE']);
        $baseNamespaces->setValue(null, null);
        $namespaces = $method->invoke(new Preset);

        if (! is_array($namespaces) || ! in_array('Drove', $namespaces, true)) {
            throw new RuntimeException('The downstream Drove namespace was filtered.');
        }
    } finally {
        $baseNamespaces->setValue(null, $cachedNamespaces);

        if ($hadInternalSuiteFlag) {
            $GLOBALS['__PEST_INTERNAL_TEST_SUITE'] = $internalSuiteFlag;
        } else {
            unset($GLOBALS['__PEST_INTERNAL_TEST_SUITE']);
        }
    }

    $this->preset()->myAnotherFramework();
})->throws(InvalidArgumentException::class, 'The preset [myAnotherFramework] does not exist. The available presets are [php, laravel, strict, security, relaxed, myFramework].');

arch()->preset()->myFramework();
