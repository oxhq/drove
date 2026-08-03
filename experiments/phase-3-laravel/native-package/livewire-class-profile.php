<?php

declare(strict_types=1);

return [
    'method_receivers' => [
        'get' => '\\Drove\\Laravel\\laravelContext()',
        'post' => '\\Drove\\Laravel\\laravelContext()',
        'withoutExceptionHandling' => '\\Drove\\Laravel\\laravelContext()',
        'makeACleanSlate' => '\\Drove\\Laravel\\Proof\\livewirePackageContext()',
        'livewireClassesPath' => '\\Drove\\Laravel\\Proof\\livewirePackageContext()',
        'livewireViewsPath' => '\\Drove\\Laravel\\Proof\\livewirePackageContext()',
        'livewireComponentsPath' => '\\Drove\\Laravel\\Proof\\livewirePackageContext()',
        'livewireTestsPath' => '\\Drove\\Laravel\\Proof\\livewirePackageContext()',
    ],
    'property_replacements' => [
        'app' => '\\Drove\\Laravel\\laravelContext()->application()',
    ],
    'parent_receivers' => [
        'makeACleanSlate' => '\\Drove\\Laravel\\Proof\\livewirePackageContext()',
    ],
    'fluent_assertions' => [
        'assertSetStrict',
        'assertSee',
        'assertSeeText',
        'assertSeeInOrder',
        'assertDontSee',
        'assertOk',
        'assertForbidden',
        'assertNotFound',
        'assertSuccessful',
        'assertViewIs',
        'assertViewHas',
    ],
];
