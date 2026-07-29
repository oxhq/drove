<?php

declare(strict_types=1);

use Drove\Environment\CoordinationGuarantee;
use Drove\Environment\EnvironmentPlan;
use Drove\Environment\ResourceCapability;
use Drove\Environment\ResourceKind;
use Drove\Environment\ResourcePlan;
use Drove\Laravel\State\InMemorySqliteDatabaseStateAdapter;
use Drove\Laravel\State\SqliteCopyDatabaseStateAdapter;
use Drove\Laravel\State\TransactionalDatabaseStateAdapter;

require __DIR__.'/vendor/autoload.php';

$unmanaged = static fn (ResourceKind $kind): ResourcePlan => new ResourcePlan(
    $kind,
    null,
    [],
    [sprintf('%s is unmanaged in this proof.', $kind->value)],
);
$planFor = static function (ResourcePlan ...$managed) use ($unmanaged): EnvironmentPlan {
    $resources = [];

    foreach ($managed as $resource) {
        $resources[$resource->kind->value] = $resource;
    }

    foreach (ResourceKind::cases() as $kind) {
        $resources[$kind->value] ??= $unmanaged($kind);
    }

    return new EnvironmentPlan(
        array_reverse(array_values($resources)),
        CoordinationGuarantee::BestEffort,
    );
};
$captureFailure = static function (Closure $operation): ?string {
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage();
    }

    return null;
};

$transaction = new TransactionalDatabaseStateAdapter;
$memory = new InMemorySqliteDatabaseStateAdapter;
$copy = new SqliteCopyDatabaseStateAdapter;
$transactionPlan = $planFor($transaction->resourcePlan());
$memoryPlan = $planFor($memory->resourcePlan());
$copyPlan = $planFor($copy->resourcePlan());
$siblingScopes = [
    ['id' => 'scope:first', 'kind' => 'scope'],
    ['id' => 'scope:second', 'kind' => 'scope'],
];
$mixedScopes = [
    ['id' => 'scope:first', 'kind' => 'scope'],
    ['id' => 'test:first', 'kind' => 'test'],
];
$expectedTopologyFailure = 'Environment resource database (transaction) cannot isolate sibling or mixed scope dispatches.';
$siblingFailure = $captureFailure(
    static fn () => $transactionPlan->assertDispatchSupported($siblingScopes),
);
$mixedFailure = $captureFailure(
    static fn () => $transactionPlan->assertDispatchSupported($mixedScopes),
);
$recursiveFailure = $captureFailure(static fn () => $transactionPlan->assertSuiteSupported([
    'type' => 'suite',
    'id' => 'suite:root',
    'tests' => [],
    'children' => [[
        'type' => 'file',
        'id' => 'file:only',
        'tests' => [],
        'children' => [
            [
                'type' => 'scope',
                'id' => 'scope:first',
                'tests' => [],
                'children' => [],
            ],
            [
                'type' => 'scope',
                'id' => 'scope:second',
                'tests' => [],
                'children' => [],
            ],
        ],
    ]],
]));

$transactionPlan->assertDispatchSupported([
    ['id' => 'test:first', 'kind' => 'test'],
    ['id' => 'test:second', 'kind' => 'test'],
]);
$transactionPlan->assertDispatchSupported([
    ['id' => 'scope:linear', 'kind' => 'scope'],
]);

foreach ([$memoryPlan, $copyPlan] as $scopePlan) {
    $scopePlan->assertDispatchSupported($siblingScopes);
    $scopePlan->assertDispatchSupported($mixedScopes);
}

$sharedPlan = $planFor(new ResourcePlan(
    ResourceKind::Filesystem,
    'fixtures',
    [ResourceCapability::SharedReadOnly],
    [],
));
$sharedPlan->assertDispatchSupported($siblingScopes);
$missingFailure = $captureFailure(static fn () => new EnvironmentPlan(
    [$transaction->resourcePlan()],
    CoordinationGuarantee::BestEffort,
));
$duplicateFailure = $captureFailure(static fn () => new EnvironmentPlan([
    $transaction->resourcePlan(),
    $copy->resourcePlan(),
    $unmanaged(ResourceKind::Filesystem),
    $unmanaged(ResourceKind::Cache),
    $unmanaged(ResourceKind::Queue),
    $unmanaged(ResourceKind::ObjectStorage),
], CoordinationGuarantee::BestEffort));
$unmanagedCapabilityFailure = $captureFailure(static fn () => new ResourcePlan(
    ResourceKind::Cache,
    null,
    [ResourceCapability::Resettable],
    [],
));
$branchOnlyFailure = $captureFailure(static fn () => $planFor(new ResourcePlan(
    ResourceKind::Database,
    'branch-only',
    [ResourceCapability::Branchable],
    [],
))->assertDispatchSupported([
    ['id' => 'test:first', 'kind' => 'test'],
]));
$emptyCapabilitiesFailure = $captureFailure(static fn () => $planFor(new ResourcePlan(
    ResourceKind::Database,
    'empty',
    [],
    [],
))->assertDispatchSupported([
    ['id' => 'test:first', 'kind' => 'test'],
]));
$projection = $transactionPlan->toArray();
$passed = $transaction->resourcePlan()->capabilities() === [
    ResourceCapability::LeafIsolated,
]
    && $memory->resourcePlan()->capabilities() === [
        ResourceCapability::Branchable,
        ResourceCapability::ScopeIsolated,
    ]
    && $copy->resourcePlan()->capabilities() === [
        ResourceCapability::Branchable,
        ResourceCapability::ScopeIsolated,
    ]
    && $siblingFailure === $expectedTopologyFailure
    && $mixedFailure === $expectedTopologyFailure
    && $recursiveFailure === $expectedTopologyFailure
    && $missingFailure
        === 'Drove environment plans must declare every resource kind; missing [filesystem, cache, queue, object-storage].'
    && $duplicateFailure
        === 'Drove environment plans contain duplicate database resources.'
    && $unmanagedCapabilityFailure
        === 'Unmanaged Drove environment resources cannot declare capabilities.'
    && $branchOnlyFailure
        === 'Environment resource database (branch-only) cannot isolate sibling or mixed scope dispatches.'
    && $emptyCapabilitiesFailure
        === 'Environment resource database (empty) cannot isolate sibling or mixed scope dispatches.'
    && ($projection['schema'] ?? null) === 1
    && ($projection['coordination'] ?? null) === 'best-effort'
    && array_keys($projection['resources'] ?? []) === array_map(
        static fn (ResourceKind $kind): string => $kind->value,
        ResourceKind::cases(),
    )
    && array_key_exists('provider', $projection['resources']['filesystem'])
    && $projection['resources']['filesystem']['provider'] === null
    && json_encode($projection, JSON_THROW_ON_ERROR)
        === json_encode($transactionPlan, JSON_THROW_ON_ERROR);

fwrite(STDOUT, json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'environment' => $projection,
    'sibling_failure' => $siblingFailure,
    'mixed_failure' => $mixedFailure,
    'recursive_failure' => $recursiveFailure,
    'descriptor_failures' => [
        'missing' => $missingFailure,
        'duplicate' => $duplicateFailure,
        'unmanaged_capability' => $unmanagedCapabilityFailure,
        'branch_only' => $branchOnlyFailure,
        'empty_capabilities' => $emptyCapabilitiesFailure,
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);

exit($passed ? 0 : 1);
