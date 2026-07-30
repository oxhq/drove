<?php

declare(strict_types=1);

namespace Drove\Bridge;

use Closure;
use Drove\Coverage\Aggregator as CoverageAggregator;
use Drove\Kernel\ScopeContext;
use Drove\Pest\ScopeCompiler;
use Drove\Pest\TestCaseRuntime;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;

/**
 * Thin compatibility seam over the already proven PHPUnit runtime. It owns no
 * lifecycle or scheduling behavior; its output is the existing Scope IR plus
 * resolver tables consumed by LifecycleExecutor.
 *
 * @internal
 */
final class PhpUnitSuiteLowerer
{
    /**
     * @param  (Closure(TestCase, ScopeContext): void)|null  $prepareCase
     * @param  array{name?: string, scope_concurrency?: array<string, int>, test_timeouts?: array<string, int>, default_test_timeout_ms?: int}  $configuration
     */
    public static function lower(
        ScopeCompiler $compiler,
        TestSuite $suite,
        ?Closure $prepareCase = null,
        bool $reportUselessTests = true,
        bool $capturePhpunitWarnings = false,
        ?CoverageAggregator $coverage = null,
        array $configuration = [],
    ): LoweredSuite {
        $runtime = TestCaseRuntime::fromSuite(
            $compiler,
            $suite,
            $prepareCase,
            $reportUselessTests,
            $capturePhpunitWarnings,
            $coverage,
        );

        return new LoweredSuite(
            $compiler->suitePlan($compiler->files(), $configuration),
            $runtime->resolvers(),
            $compiler->hook(...),
        );
    }
}
