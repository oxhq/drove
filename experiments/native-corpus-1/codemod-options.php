<?php

declare(strict_types=1);

return (static function (): array {
    $imports = [];

    foreach ([
        'tests/Features/Expect/json.php',
        'tests/Features/Expect/toBe.php',
        'tests/Features/Expect/toBeAlpha.php',
        'tests/Features/Expect/toBeAlphaNumeric.php',
        'tests/Features/Expect/toBeArray.php',
        'tests/Features/Expect/toBeBase64.php',
        'tests/Features/Expect/toBeBetween.php',
        'tests/Features/Expect/toBeBool.php',
        'tests/Features/Expect/toBeCallable.php',
        'tests/Features/Expect/toBeCamelCase.php',
        'tests/Features/Expect/toBeDigits.php',
        'tests/Features/Expect/toBeDirectory.php',
        'tests/Features/Expect/toBeDomain.php',
        'tests/Features/Expect/toBeEmail.php',
        'tests/Features/Expect/toBeEmpty.php',
        'tests/Features/Expect/toBeFalse.php',
        'tests/Features/Expect/toBeFalsy.php',
        'tests/Features/Expect/toBeFile.php',
        'tests/Features/Expect/toBeFloat.php',
        'tests/Features/Expect/toBeGreaterThan.php',
        'tests/Features/Expect/toBeGreaterThanOrEqual.php',
        'tests/Features/Expect/toBeHexadecimal.php',
        'tests/Features/Expect/toBeHostname.php',
        'tests/Features/Expect/toBeIn.php',
        'tests/Features/Expect/toBeInfinite.php',
        'tests/Features/Expect/toBeInstanceOf.php',
        'tests/Features/Expect/toBeInt.php',
        'tests/Features/Expect/toBeIpAddress.php',
        'tests/Features/Expect/toBeIterable.php',
        'tests/Features/Expect/toBeJson.php',
        'tests/Features/Expect/toBeKebabCase.php',
        'tests/Features/Expect/toBeLessThan.php',
        'tests/Features/Expect/toBeLessThanOrEqual.php',
        'tests/Features/Expect/toBeList.php',
        'tests/Features/Expect/toBeLowercase.php',
        'tests/Features/Expect/toBeMacAddress.php',
        'tests/Features/Expect/toBeNAN.php',
        'tests/Features/Expect/toBeNull.php',
        'tests/Features/Expect/toBeNumeric.php',
        'tests/Features/Expect/toBeObject.php',
        'tests/Features/Expect/toBeReadableDirectory.php',
        'tests/Features/Expect/toBeReadableFile.php',
        'tests/Features/Expect/toBeResource.php',
        'tests/Features/Expect/toBeScalar.php',
        'tests/Features/Expect/toBeSlug.php',
        'tests/Features/Expect/toBeSnakeCase.php',
        'tests/Features/Expect/toBeString.php',
        'tests/Features/Expect/toBeStudlyCase.php',
        'tests/Features/Expect/toBeTrue.php',
        'tests/Features/Expect/toBeTruthy.php',
        'tests/Features/Expect/toBeUlid.php',
        'tests/Features/Expect/toBeUppercase.php',
        'tests/Features/Expect/toBeUrl.php',
        'tests/Features/Expect/toBeUuid.php',
        'tests/Features/Expect/toBeWritableDirectory.php',
        'tests/Features/Expect/toBeWritableFile.php',
        'tests/Features/Expect/toContain.php',
        'tests/Features/Expect/toContainEqual.php',
        'tests/Features/Expect/toContainOnlyInstancesOf.php',
        'tests/Features/Expect/toEndWith.php',
        'tests/Features/Expect/toEqual.php',
        'tests/Features/Expect/toEqualCanonicalizing.php',
        'tests/Features/Expect/toEqualWithDelta.php',
        'tests/Features/Expect/toHaveCamelCaseKeys.php',
        'tests/Features/Expect/toHaveCount.php',
        'tests/Features/Expect/toHaveKebabCaseKeys.php',
        'tests/Features/Expect/toHaveKeys.php',
        'tests/Features/Expect/toHaveLength.php',
        'tests/Features/Expect/toHaveProperties.php',
        'tests/Features/Expect/toHaveProperty.php',
        'tests/Features/Expect/toHaveSameSize.php',
        'tests/Features/Expect/toHaveSnakeCaseKeys.php',
        'tests/Features/Expect/toHaveStudlyCaseKeys.php',
        'tests/Features/Expect/toMatch.php',
        'tests/Features/Expect/toMatchArray.php',
        'tests/Features/Expect/toMatchConstraint.php',
        'tests/Features/Expect/toMatchObject.php',
        'tests/Features/Expect/toStartWith.php',
        'tests/Features/Expect/toThrow.php',
        'tests/Unit/Expectations/OppositeExpectation.php',
    ] as $path) {
        $imports[$path]['PHPUnit\\Framework\\ExpectationFailedException'] = 'Drove\\Kernel\\AssertionFailed';
    }

    foreach ([
        'tests/Features/Expect/toBeBase64.php',
        'tests/Features/Expect/toBeDomain.php',
        'tests/Features/Expect/toBeHexadecimal.php',
        'tests/Features/Expect/toBeHostname.php',
        'tests/Features/Expect/toBeIpAddress.php',
        'tests/Features/Expect/toBeMacAddress.php',
        'tests/Features/Expect/toBeUlid.php',
        'tests/Features/Expect/toBeUuid.php',
        'tests/Features/Expect/toHaveCamelCaseKeys.php',
        'tests/Features/Expect/toHaveCount.php',
        'tests/Features/Expect/toHaveKebabCaseKeys.php',
        'tests/Features/Expect/toHaveSameSize.php',
        'tests/Features/Expect/toHaveSnakeCaseKeys.php',
        'tests/Features/Expect/toHaveStudlyCaseKeys.php',
    ] as $path) {
        $imports[$path]['Pest\\Exceptions\\InvalidExpectationValue'] = 'Drove\\Native\\InvalidExpectationValue';
    }

    $imports['tests/Features/Fail.php']['PHPUnit\\Framework\\AssertionFailedError'] = 'Drove\\Kernel\\AssertionFailed';
    $imports['tests/Features/Expect/each.php']['Pest\\Expectation'] = 'Drove\\Native\\Expectation';

    foreach ([
        'tests/Features/Expect/toHaveLineCountLessThan.php',
        'tests/Features/Expect/toHaveMethodsDocumented.php',
        'tests/Features/Expect/toHavePrivateMethodsBesides.php',
        'tests/Features/Expect/toHavePropertiesDocumented.php',
        'tests/Features/Expect/toHaveProtectedMethodsBesides.php',
        'tests/Features/Expect/toHavePublicMethodsBesides.php',
        'tests/Features/Expect/toUseStrictTypes.php',
        'tests/Features/Expect/toUseTrait.php',
    ] as $path) {
        $imports[$path]['Pest\\Arch\\Exceptions\\ArchExpectationFailedException'] = 'Drove\\Kernel\\AssertionFailed';
    }

    $imports['tests/Features/Expect/toHaveLineCountLessThan.php']['Pest\\Expectation'] = 'DroveNativePestCorpus\\DocumentedTarget';
    $imports['tests/Features/Expect/toHaveMethodsDocumented.php'] += [
        'Pest\\Configuration' => 'DroveNativePestCorpus\\DocumentedTarget',
        'Pest\\Expectation' => 'DroveNativePestCorpus\\DocumentedTarget',
        'Tests\\Fixtures\\Inheritance\\ExampleTest' => 'DroveNativePestCorpus\\UndocumentedTarget',
    ];
    $imports['tests/Features/Expect/toHavePropertiesDocumented.php'] += [
        'Pest\\Expectation' => 'DroveNativePestCorpus\\DocumentedTarget',
        'Pest\\Factories\\TestCaseFactory' => 'DroveNativePestCorpus\\DocumentedTarget',
        'Tests\\Fixtures\\Inheritance\\ExampleTest' => 'DroveNativePestCorpus\\UndocumentedTarget',
    ];
    $imports['tests/Features/Expect/toMatchConstraint.php'] = [
        ...($imports['tests/Features/Expect/toMatchConstraint.php'] ?? []),
        'PHPUnit\\Framework\\Constraint\\IsTrue' => 'DroveNativePestCorpus\\TrueConstraint',
        'PHPUnit\\Framework\\ExpectationFailedException' => 'Drove\\Kernel\\AssertionFailed',
    ];
    $imports['tests/Features/Expect/toUseTrait.php'] += [
        'Pest\\Concerns\\Retrievable' => 'DroveNativePestCorpus\\Retrievable',
        'Pest\\Expectations\\EachExpectation' => 'DroveNativePestCorpus\\DoesNotUseRetrievable',
        'Pest\\Expectations\\HigherOrderExpectation' => 'DroveNativePestCorpus\\UsesRetrievable',
    ];

    return [
        'matchers' => [
            'toBeAMacroExpectation' => [
                'owner' => 'drove/native-pest-corpus',
                'matcher' => 'macro-true',
                'negated_matcher' => 'macro-not-true',
            ],
            'toBeAMacroExpectationWithArguments' => [
                'owner' => 'drove/native-pest-corpus',
                'matcher' => 'macro-same',
                'negated_matcher' => 'macro-not-same',
            ],
            'toMatchConstraint' => [
                'owner' => 'drove/native-pest-corpus',
                'matcher' => 'is-true',
                'negated_matcher' => 'is-not-true',
            ],
        ],
        'trustedFunctions' => [
            'tests/Features/Expect/toBeIterable.php' => [
                'gen',
            ],
            'tests/Unit/Plugins/Tia/ViteDepsHelper.php' => [
                'tiaAliasFixtures',
                'tiaAliasResults',
                'tiaJson',
                'tiaStripFixtures',
                'tiaStripResults',
                'tiaViteAliasFixtures',
                'tiaViteAliasResults',
                'tiaViteHelperPath',
            ],
        ],
        'imports' => $imports,
        'subjects' => [
            'tests/Features/References.php' => [
                'Pest\\Panic',
            ],
            'tests/Features/See.php' => [
                'Pest\\Panic',
            ],
            'tests/Unit/Plugins/Concerns/HandleArguments.php' => [
                'Pest\\Plugins\\Concerns\\HandleArguments',
            ],
            'tests/Unit/Plugins/Environment.php' => [
                'Pest\\Contracts\\Plugins\\HandlesArguments',
                'Pest\\Plugins\\Environment',
            ],
            'tests/Unit/Plugins/Tia/ContentHash.php' => [
                'Pest\\Plugins\\Tia\\ContentHash',
            ],
            'tests/Unit/Plugins/Tia/FileState.php' => [
                'Pest\\Plugins\\Tia\\Contracts\\State',
                'Pest\\Plugins\\Tia\\FileState',
            ],
            'tests/Unit/Plugins/Tia/Lockfiles/PackageLock.php' => [
                'Pest\\Plugins\\Tia\\Contracts\\Lockfile',
                'Pest\\Plugins\\Tia\\Lockfiles\\PackageLock',
            ],
            'tests/Unit/Plugins/Tia/Recorder.php' => [
                'Pest\\Plugins\\Tia\\Recorder',
            ],
            'tests/Unit/Plugins/Tia/TableExtractor.php' => [
                'Pest\\Plugins\\Tia\\TableExtractor',
            ],
            'tests/Unit/Plugins/Tia/TestPaths.php' => [
                'Pest\\Plugins\\Tia\\TestPaths',
            ],
            'tests/Unit/Support/Arr.php' => [
                'Pest\\Support\\Arr',
            ],
            'tests/Unit/Support/Backtrace.php' => [
                'Pest\\Support\\Backtrace',
            ],
            'tests/Unit/Support/Reflection.php' => [
                'Pest\\Support\\Reflection',
            ],
            'tests/Unit/Support/Str.php' => [
                'Pest\\Support\\Str',
            ],
        ],
    ];
})();
