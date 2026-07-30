<?php

declare(strict_types=1);

namespace DroveNativePhaseTwo;

use Drove\Environment\ResourceCapability;
use Drove\Environment\ResourceKind;
use Drove\Environment\ResourcePlan;
use Drove\Environment\ResourceProvider;
use Drove\Extension\CliInput;
use Drove\Extension\CliOptionDefinition;
use Drove\Extension\CliOptionType;
use Drove\Extension\CliResult;
use Drove\Extension\Contracts\CliOption;
use Drove\Extension\Contracts\ContextField;
use Drove\Extension\Contracts\Entrypoint;
use Drove\Extension\Contracts\Matcher;
use Drove\Extension\Contracts\PlanAnnotator;
use Drove\Extension\Contracts\Reporter;
use Drove\Extension\MatchInput;
use Drove\Extension\MatchResult;
use Drove\Extension\PlanView;
use Drove\Extension\Registry;
use Drove\Extension\RunSummary;
use ReflectionMethod;
use RuntimeException;
use stdClass;

final class AlphaEntrypoint implements Entrypoint
{
    public function register(Registry $registry): void
    {
        $label = $registry->configuration('label');

        if (! is_string($label)) {
            throw new RuntimeException('The proof label must be a string.');
        }

        $registry->registerMatcher('even', new class implements Matcher
        {
            public function match(MatchInput $input): MatchResult
            {
                return is_int($input->actual) && $input->actual % 2 === 0
                    ? MatchResult::pass()
                    : MatchResult::fail('Expected an even integer.');
            }
        });
        $registry->registerContextField('label', new readonly class($label) implements ContextField
        {
            public function __construct(private string $label) {}

            public function value(): string
            {
                return $this->label;
            }
        });
        $registry->registerPlanAnnotator('test-count', new class implements PlanAnnotator
        {
            public function value(PlanView $plan): int
            {
                return $plan->testCount;
            }
        });
        $registry->registerResourceProvider('cache', new class implements ResourceProvider
        {
            public function resourcePlan(): ResourcePlan
            {
                return new ResourcePlan(
                    ResourceKind::Cache,
                    'proof/shared-cache',
                    [ResourceCapability::SharedReadOnly],
                    [],
                );
            }
        });
        $registry->registerReporter('summary', new class implements Reporter
        {
            public function report(RunSummary $run): string
            {
                return sprintf('%s:%d', $run->status, $run->testCounts['passed'] ?? 0);
            }
        });
        $registry->registerCliOption('label', new readonly class($label) implements CliOption
        {
            public function __construct(private string $label) {}

            public function definition(): CliOptionDefinition
            {
                return new CliOptionDefinition(
                    'phase-label',
                    'Print the configured Phase 2 label.',
                    CliOptionType::String,
                );
            }

            public function execute(CliInput $input): CliResult
            {
                return new CliResult($this->label.':'.$input->value);
            }
        });
    }
}

final class ZetaEntrypoint implements Entrypoint
{
    public function register(Registry $registry): void
    {
        $registry->registerReporter('summary', new class implements Reporter
        {
            public function report(RunSummary $run): string
            {
                return 'zeta:'.$run->status;
            }
        });
    }
}

final class DuplicateEntrypoint implements Entrypoint
{
    public function register(Registry $registry): void
    {
        $reporter = new class implements Reporter
        {
            public function report(RunSummary $run): string
            {
                return $run->status;
            }
        };
        $registry->registerReporter('duplicate', $reporter);
        $registry->registerReporter('duplicate', $reporter);
    }
}

final class UntypedEntrypoint implements Entrypoint
{
    public function register(Registry $registry): void
    {
        new ReflectionMethod($registry, 'registerReporter')
            ->invoke($registry, 'untyped', new stdClass);
    }
}

final class ThrowsEntrypoint implements Entrypoint
{
    public function register(Registry $registry): void
    {
        throw new RuntimeException('registration sentinel');
    }
}

final class InternalTypeErrorEntrypoint implements Entrypoint
{
    public function register(Registry $registry): void
    {
        new ReflectionMethod(self::class, 'acceptInteger')->invoke(null, 'not-an-integer');
    }

    public static function acceptInteger(int $value): void
    {
        //
    }
}

final class EmptyEntrypoint implements Entrypoint
{
    public function register(Registry $registry): void
    {
        //
    }
}

final class MissingConfigEntrypoint implements Entrypoint
{
    public function register(Registry $registry): void
    {
        $registry->configuration('missing');
    }
}

final class FailingReporterEntrypoint implements Entrypoint
{
    public function register(Registry $registry): void
    {
        $registry->registerReporter('failing', new class implements Reporter
        {
            public function report(RunSummary $run): string
            {
                throw new RuntimeException('reporter sentinel');
            }
        });
    }
}
