<?php

declare(strict_types=1);

namespace Drove\Extension;

use Drove\Environment\ResourceProvider;
use Drove\Extension\Contracts\CliOption;
use Drove\Extension\Contracts\ContextField;
use Drove\Extension\Contracts\Matcher;
use Drove\Extension\Contracts\PlanAnnotator;
use Drove\Extension\Contracts\Reporter;

final class Registry
{
    /** @var array<string, Matcher> */
    private array $matchers = [];

    /** @var array<string, ContextField> */
    private array $contextFields = [];

    /** @var array<string, PlanAnnotator> */
    private array $planAnnotators = [];

    /** @var array<string, ResourceProvider> */
    private array $resourceProviders = [];

    /** @var array<string, Reporter> */
    private array $reporters = [];

    /** @var array<string, CliOption> */
    private array $cliOptions = [];

    private bool $frozen = false;

    /** @param array<string, bool|float|int|string> $configuration */
    public function __construct(
        private readonly Manifest $manifest,
        private readonly array $configuration,
    ) {
        //
    }

    public function configuration(string $key): bool|float|int|string
    {
        return $this->configuration[$key] ?? throw new ExtensionException(
            Diagnostic::ConfigurationMissing,
            sprintf('Extension %s has no configuration value named %s.', $this->manifest->id, $key),
        );
    }

    public function registerMatcher(string $key, Matcher $matcher): void
    {
        $this->assertRegistration(ContributionKind::Matcher, $key, $this->matchers);
        $this->matchers[$key] = $matcher;
    }

    public function registerContextField(string $key, ContextField $field): void
    {
        $this->assertRegistration(ContributionKind::Context, $key, $this->contextFields);
        $this->contextFields[$key] = $field;
    }

    public function registerPlanAnnotator(string $key, PlanAnnotator $annotator): void
    {
        $this->assertRegistration(ContributionKind::Planner, $key, $this->planAnnotators);
        $this->planAnnotators[$key] = $annotator;
    }

    public function registerResourceProvider(string $key, ResourceProvider $provider): void
    {
        $this->assertRegistration(ContributionKind::ResourceProvider, $key, $this->resourceProviders);
        $this->resourceProviders[$key] = $provider;
    }

    public function registerReporter(string $key, Reporter $reporter): void
    {
        $this->assertRegistration(ContributionKind::Reporter, $key, $this->reporters);
        $this->reporters[$key] = $reporter;
    }

    public function registerCliOption(string $key, CliOption $option): void
    {
        $this->assertRegistration(ContributionKind::Cli, $key, $this->cliOptions);
        $this->cliOptions[$key] = $option;
    }

    public function freeze(): RegisteredExtension
    {
        foreach ($this->manifest->contributions() as $kind) {
            if ($this->items($kind) === []) {
                throw new ExtensionException(
                    Diagnostic::MissingContribution,
                    sprintf(
                        'Extension %s declared %s but registered no contributions.',
                        $this->manifest->id,
                        $kind->value,
                    ),
                );
            }
        }

        $this->frozen = true;
        $matchers = $this->matchers;
        $contextFields = $this->contextFields;
        $planAnnotators = $this->planAnnotators;
        $resourceProviders = $this->resourceProviders;
        $reporters = $this->reporters;
        $cliOptions = $this->cliOptions;

        foreach ([
            &$matchers,
            &$contextFields,
            &$planAnnotators,
            &$resourceProviders,
            &$reporters,
            &$cliOptions,
        ] as &$items) {
            ksort($items, SORT_STRING);
        }

        unset($items);

        return new RegisteredExtension(
            $this->manifest,
            hash('sha256', json_encode(
                $this->configuration,
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            )),
            $matchers,
            $contextFields,
            $planAnnotators,
            $resourceProviders,
            $reporters,
            $cliOptions,
        );
    }

    /**
     * @param  array<string, object>  $registered
     */
    private function assertRegistration(
        ContributionKind $kind,
        string $key,
        array $registered,
    ): void {
        if ($this->frozen) {
            throw new ExtensionException(
                Diagnostic::LateRegistration,
                sprintf('Extension %s attempted registration after freeze.', $this->manifest->id),
            );
        }

        if (preg_match('/^[a-z][a-z0-9._-]*$/D', $key) !== 1) {
            throw new ExtensionException(
                Diagnostic::UntypedContribution,
                sprintf(
                    'Extension %s contribution keys must be stable lowercase identifiers.',
                    $this->manifest->id,
                ),
            );
        }

        if (! in_array($kind, $this->manifest->contributions(), true)) {
            throw new ExtensionException(
                Diagnostic::UntypedContribution,
                sprintf('Extension %s did not declare contribution kind %s.', $this->manifest->id, $kind->value),
            );
        }

        if (isset($registered[$key])) {
            throw new ExtensionException(
                Diagnostic::DuplicateContribution,
                sprintf('Extension %s registered duplicate %s key %s.', $this->manifest->id, $kind->value, $key),
            );
        }
    }

    /**
     * @return array<string, object>
     */
    private function items(ContributionKind $kind): array
    {
        return match ($kind) {
            ContributionKind::Matcher => $this->matchers,
            ContributionKind::Context => $this->contextFields,
            ContributionKind::Planner => $this->planAnnotators,
            ContributionKind::ResourceProvider => $this->resourceProviders,
            ContributionKind::Reporter => $this->reporters,
            ContributionKind::Cli => $this->cliOptions,
        };
    }
}
