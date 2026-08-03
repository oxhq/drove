<?php

declare(strict_types=1);

return [
    'trustedFunctions' => [
        'tests/Feature/Customer/DashboardTest.php' => ['beforeEach', 'test'],
        'tests/Feature/Customer/EmailLogTokenTest.php' => ['beforeEach', 'test', 'now'],
        'tests/Feature/Customer/EstimateTest.php' => ['beforeEach', 'test'],
        'tests/Feature/Customer/ExpenseTest.php' => ['beforeEach', 'test'],
        'tests/Feature/Customer/InvoiceTest.php' => ['beforeEach', 'test'],
        'tests/Feature/Customer/PaymentTest.php' => ['beforeEach', 'test'],
        'tests/Feature/Customer/ProfileTest.php' => ['beforeEach', 'test'],
        'tests/Unit/CompanySettingTest.php' => ['fake'],
        'tests/Unit/SettingTest.php' => ['fake'],
    ],
    'imports' => [
        'tests/Unit/CompanySettingTest.php' => [
            'Pest\Faker\fake' => 'fake',
        ],
        'tests/Unit/SettingTest.php' => [
            'Pest\Faker\fake' => 'fake',
        ],
    ],
    'subjects' => [],
];
