<?php

declare(strict_types=1);

namespace Drove\Coverage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\Api\CodeCoverage as CodeCoverageMetadata;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\Runner\CodeCoverageInitializationStatus;
use PHPUnit\TextUI\Configuration\CodeCoverageFilterRegistry;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Output\Facade as OutputFacade;
use RuntimeException;
use SebastianBergmann\CodeCoverage\Data\ProcessedBranchCoverageData;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Data\ProcessedFunctionCoverageData;
use SebastianBergmann\CodeCoverage\Data\ProcessedPathCoverageData;
use SebastianBergmann\CodeCoverage\Test\Target\TargetCollection;

/**
 * Collects one serialized coverage fragment per forked test and merges those
 * fragments in the root process before PHPUnit's report generators run.
 *
 * @internal
 */
final readonly class Aggregator
{
    private function __construct(
        private Configuration $configuration,
        private string $directory,
        private int $rootPid,
    ) {
        //
    }

    public static function fromConfiguration(Configuration $configuration): ?self
    {
        $status = CodeCoverage::instance()->init(
            $configuration,
            CodeCoverageFilterRegistry::instance(),
            false,
        );

        if ($status === CodeCoverageInitializationStatus::NOT_REQUESTED) {
            return null;
        }

        if ($status !== CodeCoverageInitializationStatus::SUCCEEDED) {
            throw new InvalidArgumentException(
                'Drove could not initialize the requested code coverage driver.',
            );
        }

        $directory = sys_get_temp_dir().'/drove-coverage-'.bin2hex(random_bytes(12));

        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Drove could not create its coverage workspace.');
        }

        $pid = getmypid();

        if (! is_int($pid)) {
            rmdir($directory);

            throw new RuntimeException('Drove could not resolve its coverage root process.');
        }

        return new self($configuration, $directory, $pid);
    }

    /**
     * @return array{covers: TargetCollection, uses: TargetCollection}|null
     */
    public function start(TestCase $case): ?array
    {
        $metadata = new CodeCoverageMetadata;
        $covers = TargetCollection::fromArray([]);
        $uses = TargetCollection::fromArray([]);

        if ($this->configuration->disableCoverageTargeting()) {
            $collect = true;
        } else {
            $covers = $metadata->coversTargets($case::class, $case->name());
            $uses = $metadata->usesTargets($case::class, $case->name());
            $collect = $metadata->shouldCodeCoverageBeCollectedFor($case);
        }

        if (! $collect) {
            return null;
        }

        CodeCoverage::instance()->start($case);

        return ['covers' => $covers, 'uses' => $uses];
    }

    /**
     * @param  array{covers: TargetCollection, uses: TargetCollection}|null  $capture
     */
    public function finish(TestCase $case, ?array $capture, bool $append): void
    {
        if ($capture === null) {
            return;
        }

        CodeCoverage::instance()->stop(
            $append,
            $append ? $capture['covers'] : false,
            $append ? $capture['uses'] : null,
        );

        $coverage = CodeCoverage::instance()->codeCoverage();
        $fragment = serialize([
            'data' => $coverage->getData(),
            'tests' => $coverage->getTests(),
        ]);
        $path = $this->fragmentPath($case);
        $temporary = $path.'.tmp';

        if (file_put_contents($temporary, $fragment, LOCK_EX) !== strlen($fragment)
            || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('Drove could not write a coverage fragment.');
        }
    }

    /**
     * @param  array{covers: TargetCollection, uses: TargetCollection}|null  $capture
     */
    public function abort(?array $capture): void
    {
        if ($capture !== null) {
            CodeCoverage::instance()->stop(false, false);
        }
    }

    public function mergeAndReport(): void
    {
        $this->assertRootProcess();
        $paths = glob($this->directory.'/*.cov') ?: [];
        sort($paths, SORT_STRING);
        $data = null;
        $tests = [];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            $fragment = is_string($contents)
                ? unserialize($contents, ['allowed_classes' => [
                    ProcessedCodeCoverageData::class,
                    ProcessedFunctionCoverageData::class,
                    ProcessedBranchCoverageData::class,
                    ProcessedPathCoverageData::class,
                ]])
                : null;

            if (! is_array($fragment)
                || ! ($fragment['data'] ?? null) instanceof ProcessedCodeCoverageData
                || ! is_array($fragment['tests'] ?? null)) {
                throw new RuntimeException('Drove received an invalid coverage fragment.');
            }

            if ($data instanceof ProcessedCodeCoverageData) {
                $data->merge($fragment['data']);
            } else {
                $data = clone $fragment['data'];
            }

            $tests = array_merge($tests, $fragment['tests']);
        }

        if ($data instanceof ProcessedCodeCoverageData) {
            $coverage = CodeCoverage::instance()->codeCoverage();
            $coverage->setData($data);
            $coverage->setTests($tests);
        }

        CodeCoverage::instance()->generateReports(
            OutputFacade::printerFor('php://stdout'),
            $this->configuration,
        );
    }

    public function cleanup(): void
    {
        if (getmypid() !== $this->rootPid || ! is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory.'/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($this->directory);
    }

    private function fragmentPath(TestCase $case): string
    {
        return $this->directory.'/'.hash(
            'sha256',
            $case->valueObjectForEvents()->id().':'.getmypid(),
        ).'.cov';
    }

    private function assertRootProcess(): void
    {
        if (getmypid() !== $this->rootPid) {
            throw new RuntimeException('Coverage aggregation must run in the Drove root process.');
        }
    }
}
