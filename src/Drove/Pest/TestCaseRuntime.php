<?php

declare(strict_types=1);

namespace Drove\Pest;

use Closure;
use Drove\Kernel\TestOutcome;
use LogicException;
use OutOfBoundsException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class TestCaseRuntime
{
    /** @var array<string, TestCase> */
    private array $cases = [];

    /** @var array<string, array<string, mixed>> */
    private array $descriptors = [];

    /** @var array<string, true> */
    private array $ran = [];

    /** @var array<string, Throwable> */
    private array $throwables = [];

    private function __construct()
    {
        //
    }

    public static function fromSuite(ScopeCompiler $compiler, TestSuite $suite): self
    {
        $runtime = new self;
        $casesByFile = array_fill_keys($compiler->files(), []);

        foreach ($suite->collect() as $case) {
            if (! $case instanceof TestCase) {
                continue;
            }

            $reflection = new ReflectionClass($case);

            if (! $reflection->hasProperty('__filename')) {
                continue;
            }

            $filename = $reflection->getStaticPropertyValue('__filename');

            if (! is_string($filename)) {
                continue;
            }

            if (! $compiler->hasPlan($filename)) {
                continue;
            }

            $descriptor = $compiler->caseDescriptor(
                $filename,
                $case->name(),
                $case->dataName(),
                $case->dataSetAsString(),
                $case->groups(),
            );
            $id = $descriptor['test']['id'];

            if (isset($runtime->cases[$id])) {
                throw new RuntimeException('Drove received a duplicate generated case.');
            }

            $runtime->cases[$id] = $case;
            $runtime->descriptors[$id] = $descriptor['test'];
            $casesByFile[$descriptor['file']][] = $descriptor;
        }

        $compiler->bindCases($casesByFile);

        return $runtime;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function descriptors(): array
    {
        return $this->descriptors;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $id): array
    {
        $case = $this->cases[$id] ?? throw new OutOfBoundsException(sprintf(
            'No generated Pest case was captured for %s.',
            $id,
        ));

        if (isset($this->ran[$id])) {
            throw new LogicException(sprintf('Generated Pest case %s was already run.', $id));
        }

        $this->ran[$id] = true;
        Assert::resetCount();

        try {
            $case->runBare();
        } catch (Throwable $throwable) {
            if ($case->status()->isUnknown()) {
                throw $throwable;
            }

            $this->throwables[$id] = $throwable;
        } finally {
            $case->addToAssertionCount(Assert::getCount());
        }

        $phpunitStatus = $case->status();
        $disposition = $this->descriptors[$id]['disposition'] ?? 'run';
        $status = match (true) {
            $phpunitStatus->isSuccess() => 'passed',
            $phpunitStatus->isSkipped() && $disposition === 'todo' => 'todo',
            $phpunitStatus->isSkipped() => 'skipped',
            $phpunitStatus->isIncomplete() => 'incomplete',
            $phpunitStatus->isRisky() => 'risky',
            default => 'failed',
        };

        return [
            'id' => $id,
            'status' => $status,
            'phpunit_status' => $phpunitStatus->asString(),
            'message' => $phpunitStatus->message(),
            'output' => $case->output(),
            'assertions' => $case->numberOfAssertionsPerformed(),
            'test_case' => $case::class,
            'method' => $case->name(),
            'dataset' => $this->descriptors[$id]['dataset'] ?? null,
        ];
    }

    public function closure(string $id): Closure
    {
        $runtime = $this;

        return static function () use ($id, $runtime): TestOutcome {
            $result = $runtime->run($id);

            echo $result['output'];

            if ($result['status'] === 'failed') {
                throw $runtime->throwable($id);
            }

            if (in_array($result['status'], ['incomplete', 'risky'], true)) {
                throw $runtime->throwable($id);
            }

            $message = $result['message'] === '' || $result['message'] === '__TODO__'
                ? null
                : (string) $result['message'];

            return match ($result['status']) {
                'skipped' => TestOutcome::skipped($message),
                'todo' => TestOutcome::todo($message),
                default => TestOutcome::passed($result),
            };
        };
    }

    /**
     * @return array{closure: Closure, runtime: object}
     */
    public function resolver(string $id): array
    {
        $case = $this->cases[$id] ?? throw new OutOfBoundsException(sprintf(
            'No generated Pest case was captured for %s.',
            $id,
        ));

        return [
            'closure' => $this->closure($id),
            'runtime' => $case,
        ];
    }

    /**
     * @return array<string, array{closure: Closure, runtime: object}>
     */
    public function resolvers(): array
    {
        $resolvers = [];

        foreach (array_keys($this->cases) as $id) {
            $resolvers[$id] = $this->resolver($id);
        }

        return $resolvers;
    }

    private function throwable(string $id): Throwable
    {
        return $this->throwables[$id] ?? new RuntimeException(sprintf(
            'Generated Pest case %s failed without a Throwable.',
            $id,
        ));
    }
}
