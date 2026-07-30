<?php

declare(strict_types=1);

namespace Drove\Extension;

use Drove\Environment\ResourceProvider;
use Drove\Extension\Contracts\CliOption;
use Drove\Extension\Contracts\ContextField;
use Drove\Extension\Contracts\Matcher;
use Drove\Extension\Contracts\PlanAnnotator;
use Drove\Extension\Contracts\Reporter;

final readonly class RegisteredExtension
{
    /**
     * @param  array<string, Matcher>  $matchers
     * @param  array<string, ContextField>  $contextFields
     * @param  array<string, PlanAnnotator>  $planAnnotators
     * @param  array<string, ResourceProvider>  $resourceProviders
     * @param  array<string, Reporter>  $reporters
     * @param  array<string, CliOption>  $cliOptions
     */
    public function __construct(
        public Manifest $manifest,
        public string $configurationHash,
        public array $matchers,
        public array $contextFields,
        public array $planAnnotators,
        public array $resourceProviders,
        public array $reporters,
        public array $cliOptions,
    ) {
        //
    }
}
