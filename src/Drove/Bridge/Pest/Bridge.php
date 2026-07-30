<?php

declare(strict_types=1);

namespace Drove\Bridge\Pest;

use Closure;
use Composer\InstalledVersions;
use Drove\Bridge\BridgeEntrypoint;
use Drove\Bridge\LoweredSuite;
use Drove\Bridge\PhpUnitSuiteLowerer;
use Drove\Coverage\Aggregator as CoverageAggregator;
use Drove\Kernel\ScopeContext;
use Drove\Pest\ScopeCompiler;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;

final class Bridge implements BridgeEntrypoint
{
    public static function bridgeId(): string
    {
        return 'pest';
    }

    public static function scopeIrSchema(): int
    {
        return 1;
    }

    public static function available(): bool
    {
        $version = InstalledVersions::isInstalled('phpunit/phpunit')
            ? InstalledVersions::getVersion('phpunit/phpunit')
            : null;

        return self::supportsPhpUnitVersion($version)
            && is_file(dirname(__DIR__, 3).'/Kernel.php')
            && class_exists(TestCase::class)
            && class_exists(TestSuite::class)
            && class_exists(ScopeCompiler::class);
    }

    public static function unavailableDiagnostic(): ?string
    {
        return self::available()
            ? null
            : 'DROVE_BRIDGE_PEST_DEPENDENCY_MISSING: Pest and PHPUnit are required by the Pest bridge.';
    }

    private static function supportsPhpUnitVersion(?string $version): bool
    {
        return is_string($version)
            && version_compare($version, '13.2.4', '>=')
            && version_compare($version, '13.2.5', '<');
    }

    public static function activate(string $rootPath, bool $ownsScopeHooks = true): ScopeCompiler
    {
        return ScopeCompiler::activate($rootPath, $ownsScopeHooks);
    }

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
        return PhpUnitSuiteLowerer::lower(
            $compiler,
            $suite,
            $prepareCase,
            $reportUselessTests,
            $capturePhpunitWarnings,
            $coverage,
            $configuration,
        );
    }
}
