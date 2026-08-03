<?php

declare(strict_types=1);

namespace Drove\Extension;

use Closure;
use Throwable;

final readonly class ExtensionSet
{
    /** @var list<RegisteredExtension> */
    private array $extensions;

    /** @var array<string, RegisteredExtension> */
    private array $byId;

    /** @var array<string, array<string, CliOptionDefinition>> */
    private array $cliDefinitions;

    /** @param array<array-key, RegisteredExtension> $extensions */
    public function __construct(array $extensions)
    {
        $byId = [];

        foreach ($extensions as $extension) {
            if (isset($byId[$extension->manifest->id])) {
                throw new ExtensionException(
                    Diagnostic::DuplicateId,
                    sprintf('Duplicate extension id %s.', $extension->manifest->id),
                );
            }

            $byId[$extension->manifest->id] = $extension;
        }

        ksort($byId, SORT_STRING);
        $this->byId = $byId;
        $this->extensions = array_values($byId);
        $cliDefinitions = [];
        $cliOwners = [];

        foreach ($this->extensions as $extension) {
            foreach ($extension->cliOptions as $key => $option) {
                $definition = $this->invoke(
                    $extension->manifest->id,
                    ContributionKind::Cli,
                    $key,
                    $option->definition(...),
                );
                $existingOwner = $cliOwners[$definition->longName] ?? null;

                if ($existingOwner !== null) {
                    throw new ExtensionException(
                        Diagnostic::DuplicateContribution,
                        sprintf(
                            'Extension %s CLI option --%s conflicts with %s.',
                            $extension->manifest->id,
                            $definition->longName,
                            $existingOwner,
                        ),
                    );
                }

                $cliOwners[$definition->longName] = $extension->manifest->id;
                $cliDefinitions[$extension->manifest->id][$key] = $definition;
            }
        }

        $this->cliDefinitions = $cliDefinitions;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->extensions === [];
    }

    /**
     * @return array{
     *     schema: 1,
     *     extensions: list<array{
     *         id: string,
     *         package_version: string,
     *         api_version: int,
     *         contributions: array<string, list<string>>,
     *         configuration_sha256: string,
     *         annotations: array<string, bool|float|int|string|null>,
     *         resources: array<string, array{
     *             kind: string,
     *             provider: ?string,
     *             capabilities: list<string>,
     *             limitations: list<string>
     *         }>
     *     }>
     * }
     */
    public function planMetadata(PlanView $plan): array
    {
        $metadata = [];

        foreach ($this->extensions as $extension) {
            $contributions = [];

            foreach ($extension->manifest->contributions() as $kind) {
                $contributions[$kind->value] = array_keys($this->items($extension, $kind));
            }

            ksort($contributions, SORT_STRING);
            $annotations = [];

            foreach ($extension->planAnnotators as $key => $annotator) {
                $value = $this->invoke(
                    $extension->manifest->id,
                    ContributionKind::Planner,
                    $key,
                    static fn () => $annotator->value($plan),
                );

                if ((is_float($value) && ! is_finite($value))
                    || (is_string($value) && preg_match('//u', $value) !== 1)) {
                    throw new ExtensionException(
                        Diagnostic::ContributionFailed,
                        sprintf(
                            'Extension %s planner contribution %s returned an unserializable value.',
                            $extension->manifest->id,
                            $key,
                        ),
                    );
                }

                $annotations[$key] = $value;
            }

            $resources = [];

            foreach ($extension->resourceProviders as $key => $provider) {
                $resources[$key] = $this->invoke(
                    $extension->manifest->id,
                    ContributionKind::ResourceProvider,
                    $key,
                    static fn () => $provider->resourcePlan()->toArray(),
                );
            }

            $metadata[] = [
                'id' => $extension->manifest->id,
                'package_version' => $extension->manifest->packageVersion,
                'api_version' => $extension->manifest->apiVersion,
                'contributions' => $contributions,
                'configuration_sha256' => $extension->configurationHash,
                'annotations' => $annotations,
                'resources' => $resources,
            ];
        }

        return ['schema' => 1, 'extensions' => $metadata];
    }

    public function contextValue(string $owner, string $key): mixed
    {
        $field = $this->contribution(
            $owner,
            $key,
            ContributionKind::Context->value,
            $this->extension($owner)->contextFields,
        );

        return $this->invoke(
            $owner,
            ContributionKind::Context,
            $key,
            $field->value(...),
        );
    }

    /**
     * @param  list<mixed>  $arguments
     * @param  array<string, mixed>  $case
     */
    public function match(
        string $owner,
        string $key,
        mixed $actual,
        array $arguments = [],
        array $case = [],
    ): MatchResult {
        $matcher = $this->contribution(
            $owner,
            $key,
            ContributionKind::Matcher->value,
            $this->extension($owner)->matchers,
        );

        return $this->invoke(
            $owner,
            ContributionKind::Matcher,
            $key,
            static fn () => $matcher->match(new MatchInput($actual, $arguments, $case)),
        );
    }

    /**
     * @return list<array{owner: string, key: string, output: string}>
     */
    public function reports(RunSummary $run): array
    {
        $reports = [];

        foreach ($this->extensions as $extension) {
            foreach ($extension->reporters as $key => $reporter) {
                $output = $this->invoke(
                    $extension->manifest->id,
                    ContributionKind::Reporter,
                    $key,
                    static fn () => $reporter->report($run),
                );

                if (preg_match('//u', $output) !== 1) {
                    throw new ExtensionException(
                        Diagnostic::ContributionFailed,
                        sprintf(
                            'Extension %s reporter contribution %s returned invalid UTF-8.',
                            $extension->manifest->id,
                            $key,
                        ),
                    );
                }

                $reports[] = [
                    'owner' => $extension->manifest->id,
                    'key' => $key,
                    'output' => $output,
                ];
            }
        }

        return $reports;
    }

    public function executeCli(
        string $owner,
        string $key,
        bool|int|string $value,
    ): CliResult {
        $option = $this->contribution(
            $owner,
            $key,
            ContributionKind::Cli->value,
            $this->extension($owner)->cliOptions,
        );

        return $this->invoke(
            $owner,
            ContributionKind::Cli,
            $key,
            fn () => $option->execute(new CliInput($this->cliDefinitions[$owner][$key], $value)),
        );
    }

    /**
     * @return list<array{owner: string, key: string, definition: CliOptionDefinition}>
     */
    public function cliOptions(): array
    {
        $options = [];

        foreach ($this->extensions as $extension) {
            foreach ($this->cliDefinitions[$extension->manifest->id] ?? [] as $key => $definition) {
                $options[] = [
                    'owner' => $extension->manifest->id,
                    'key' => $key,
                    'definition' => $definition,
                ];
            }
        }

        return $options;
    }

    private function extension(string $owner): RegisteredExtension
    {
        return $this->byId[$owner] ?? throw new ExtensionException(
            Diagnostic::UnknownOwner,
            sprintf('No Drove extension is registered with id %s.', $owner),
        );
    }

    /**
     * @template T of object
     *
     * @param  array<string, T>  $contributions
     * @return T
     */
    private function contribution(
        string $owner,
        string $key,
        string $kind,
        array $contributions,
    ): object {
        return $contributions[$key] ?? throw new ExtensionException(
            Diagnostic::UnknownContribution,
            sprintf('Extension %s has no %s contribution named %s.', $owner, $kind, $key),
        );
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function invoke(
        string $owner,
        ContributionKind $kind,
        string $key,
        Closure $callback,
    ): mixed {
        try {
            return $callback();
        } catch (Throwable $throwable) {
            throw new ExtensionException(
                Diagnostic::ContributionFailed,
                sprintf(
                    'Extension %s %s contribution %s failed: %s',
                    $owner,
                    $kind->value,
                    $key,
                    $throwable->getMessage(),
                ),
                $throwable,
            );
        }
    }

    /**
     * @return array<string, object>
     */
    private function items(
        RegisteredExtension $extension,
        ContributionKind $kind,
    ): array {
        return match ($kind) {
            ContributionKind::Matcher => $extension->matchers,
            ContributionKind::Context => $extension->contextFields,
            ContributionKind::Planner => $extension->planAnnotators,
            ContributionKind::ResourceProvider => $extension->resourceProviders,
            ContributionKind::Reporter => $extension->reporters,
            ContributionKind::Cli => $extension->cliOptions,
        };
    }
}
