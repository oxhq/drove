<?php

declare(strict_types=1);

namespace DroveNativePestCorpus;

use RuntimeException;

final class PestCorpusCodemod
{
    private const string MARKER = '// DROVE_NATIVE_PEST_CORPUS_CODEMOD_V1';

    /** @var array<string, string> */
    private const array HASHES = [
        'tests/Features/AfterAll.php' => '84bed3f1c947e14b1a832b1e6f0c7f5eb68d71c836e64ad6c409cd9891b5b94d',
        'tests/Features/Covers/ClassCoverage.php' => 'ec8d7cd9bb40d0ae1985ec9275f925d24038f7937b8d770a8f0616e141128785',
        'tests/Features/Covers/ExceptionHandling.php' => '1042ffd50ac7d2e21969600121f7cbd83756e5dfc0c52e7936c28d22a0000b2e',
        'tests/Features/Covers/FunctionCoverage.php' => '2a39ba4eda353684214065f0e59edb18ac1e5d89078cad37539f9317922046d1',
        'tests/Features/Covers/GuessCoverage.php' => '516edfa79d001e36693bf00e18aca0f39abeac6b51a8c369e65a8d97866ff26b',
        'tests/Features/Covers/TraitCoverage.php' => '97a1c30260f6b92ba20d3440d7e91e2ab379005adfa1d0345e1de18a073d6bba',
        'tests/Features/DatasetProviderErrors.php' => 'd39d6c90aa9d91df0e8c0498902d934e1a84045d3d21aca1d7a9c6089a5be58b',
        'tests/Features/Expect/extend.php' => '84f9270ab410aaee67550a49a8f3b69753fae2346445edb4dc3329dc80a8ab60',
        'tests/Features/Expect/pipes.php' => 'ede0583ce496b26f25eb7c16acf47d4ceb7af952f12ed0e63be191a58654d871',
    ];

    public static function migrate(string $source, string $path): string
    {
        if (str_contains($source, self::MARKER)) {
            return $source;
        }

        if (! isset(self::HASHES[$path])) {
            return $source;
        }

        $canonicalSource = str_replace(["\r\n", "\r"], "\n", $source);

        if (! hash_equals(self::HASHES[$path], hash('sha256', $canonicalSource))) {
            throw new RuntimeException('The pinned Pest corpus codemod input diverged: '.$path);
        }

        return self::templates()[$path];
    }

    /** @return array<string, string> */
    private static function templates(): array
    {
        return [
            'tests/Features/AfterAll.php' => <<<'PHP'
<?php

declare(strict_types=1);

// DROVE_NATIVE_PEST_CORPUS_CODEMOD_V1
$file = __DIR__.DIRECTORY_SEPARATOR.'after-all-test';

beforeAll(function () use ($file): void {
    @unlink($file);
});

afterAll(function () use ($file): void {
    @unlink($file);
});

test('deletes file after all', function () use ($file): void {
    file_put_contents($file, 'foo');
    expect($file)->toBeFile();
});
PHP,
            'tests/Features/Covers/ClassCoverage.php' => <<<'PHP'
<?php

// DROVE_NATIVE_PEST_CORPUS_CODEMOD_V1
use Tests\Fixtures\Covers\CoversClass1;

beforeEach()->covers([CoversClass1::class]);

it('uses the correct PHPUnit attribute for class', function (): void {
    $target = \Drove\Native\Coverage::target(CoversClass1::class);

    expect($target['kind'])->toBe('class')
        ->and($target['target'])->toBe(CoversClass1::class);
});
PHP,
            'tests/Features/Covers/ExceptionHandling.php' => <<<'PHP'
<?php

declare(strict_types=1);

// DROVE_NATIVE_PEST_CORPUS_CODEMOD_V1
it('throws exception if no class nor method has been found', function (): void {
    \Drove\Native\Coverage::target('fakeName');
})->throws(InvalidArgumentException::class, 'No class, trait or method named "fakeName" has been found.');
PHP,
            'tests/Features/Covers/FunctionCoverage.php' => <<<'PHP'
<?php

// DROVE_NATIVE_PEST_CORPUS_CODEMOD_V1
function testCoversFunction(): void {}

it('uses the correct PHPUnit attribute for function', function (): void {
    $target = \Drove\Native\Coverage::target('testCoversFunction', 'function');

    expect($target['kind'])->toBe('function')
        ->and($target['target'])->toBe('testCoversFunction');
})->coversFunction('testCoversFunction');
PHP,
            'tests/Features/Covers/GuessCoverage.php' => <<<'PHP'
<?php

// DROVE_NATIVE_PEST_CORPUS_CODEMOD_V1
use Tests\Fixtures\Covers\CoversClass3;

function testCoversFunction2(): void {}

it('guesses if the given argument is a class or function', function (): void {
    [$class, $function] = \Drove\Native\Coverage::targets(CoversClass3::class, 'testCoversFunction2');

    expect($class['kind'])->toBe('class')
        ->and($class['target'])->toBe(CoversClass3::class)
        ->and($function['kind'])->toBe('function')
        ->and($function['target'])->toBe('testCoversFunction2');
})->covers(CoversClass3::class, 'testCoversFunction2');
PHP,
            'tests/Features/Covers/TraitCoverage.php' => <<<'PHP'
<?php

// DROVE_NATIVE_PEST_CORPUS_CODEMOD_V1
use Tests\Fixtures\Covers\CoversTrait;

it('uses the correct PHPUnit attribute for trait', function (): void {
    $target = \Drove\Native\Coverage::target(CoversTrait::class, 'trait');

    expect($target['kind'])->toBe('trait')
        ->and($target['target'])->toBe(CoversTrait::class);
})->coversTrait(CoversTrait::class);
PHP,
            'tests/Features/DatasetProviderErrors.php' => <<<'PHP'
<?php

// DROVE_NATIVE_PEST_CORPUS_CODEMOD_V1
test('reports missing datasets as errors for a single file run', function (): void {
    $result = droveNativeCorpusDatasetDiagnostic('missing-only');

    expect(array_keys($result))->toBe(['stdout', 'stderr', 'exit_code'])
        ->and($result['stdout'])->toBe('')
        ->and($result['stderr'])->toBe('OutOfBoundsException: Native dataset missing is not defined.'.PHP_EOL)
        ->and($result['exit_code'])->toBe(1);
});

test('reports missing datasets as errors alongside passing tests', function (): void {
    $result = droveNativeCorpusDatasetDiagnostic('missing-with-pass');

    expect(array_keys($result))->toBe(['stdout', 'stderr', 'exit_code'])
        ->and($result['stdout'])->toBe('')
        ->and($result['stderr'])->toBe('OutOfBoundsException: Native dataset missing is not defined.'.PHP_EOL)
        ->and($result['exit_code'])->toBe(1);
});

test('reports dataset closure exceptions as errors', function (): void {
    $result = droveNativeCorpusDatasetDiagnostic('closure-throws');

    expect([$result['stdout'], $result['stderr']])->toBe([
        '',
        'RuntimeException: boom from dataset'.PHP_EOL,
    ])->and($result['exit_code'])->toBe(1);
});
PHP,
            'tests/Features/Expect/extend.php' => <<<'PHP'
<?php

// DROVE_NATIVE_PEST_CORPUS_CODEMOD_V1
it('macros true is true', function (): void {
    expect(true)->toBeAMacroExpectation();
});

it('macros false is not true', function (): void {
    expect(false)->not->toBeAMacroExpectation();
});

it('macros true is true with argument', function (): void {
    expect(true)->toBeAMacroExpectationWithArguments(true);
});

it('macros false is not true with argument', function (): void {
    expect(false)->not->toBeAMacroExpectationWithArguments(true);
});
PHP,
            'tests/Features/Expect/pipes.php' => <<<'PHP'
<?php

declare(strict_types=1);

// DROVE_NATIVE_PEST_CORPUS_CODEMOD_V1
use DroveNativePestCorpus\PipelineChar as Char;
use DroveNativePestCorpus\PipelineNumber as Number;
use DroveNativePestCorpus\PipelineState as State;
use DroveNativePestCorpus\PipelineSymbol as Symbol;

$state = new State;

test('pipe is applied and can stop pipeline', function () use ($state): void {
    $state->reset();
    $this->assertWith('drove/native-pest-corpus', 'pipeline-to-be', new Char('A'), new Char('A'), $state);
    expect($state->runCount)->toMatchArray(['char' => 1, 'number' => 0, 'wildcard' => 0, 'symbol' => 0]);
    expect($state->appliedCount)->toMatchArray(['char' => 1, 'number' => 0, 'wildcard' => 0, 'symbol' => 0]);
});

test('pipe is run and can let the pipeline keep going', function () use ($state): void {
    $state->reset();
    $this->assertWith('drove/native-pest-corpus', 'pipeline-to-be', 3, 3, $state);
    expect($state->runCount)->toMatchArray(['char' => 1, 'number' => 0, 'wildcard' => 0, 'symbol' => 1]);
    expect($state->appliedCount)->toMatchArray(['char' => 0, 'number' => 0, 'wildcard' => 0, 'symbol' => 0]);
});

test('pipe works with negated expectation', function () use ($state): void {
    $state->reset();
    $this->assertWith('drove/native-pest-corpus', 'pipeline-not-to-be', new Char('A'), new Char('B'), $state);
    expect($state->runCount)->toMatchArray(['char' => 1, 'number' => 0, 'wildcard' => 0, 'symbol' => 0]);
    expect($state->appliedCount)->toMatchArray(['char' => 1, 'number' => 0, 'wildcard' => 0, 'symbol' => 0]);
});

test('interceptor is applied', function () use ($state): void {
    $state->reset();
    $this->assertWith('drove/native-pest-corpus', 'pipeline-to-be', new Number(1), new Number(1), $state);
    expect($state->runCount)->toHaveKey('number', 1);
    expect($state->appliedCount)->toHaveKey('number', 1);
});

test('interceptor stops the pipeline', function () use ($state): void {
    $state->reset();
    $this->assertWith('drove/native-pest-corpus', 'pipeline-to-be', new Number(1), new Number(1), $state);
    expect($state->runCount)->toMatchArray(['char' => 1, 'number' => 1, 'wildcard' => 0, 'symbol' => 0]);
    expect($state->appliedCount)->toMatchArray(['char' => 0, 'number' => 1, 'wildcard' => 0, 'symbol' => 0]);
});

test('interceptor is called only when filter is met', function () use ($state): void {
    $state->reset();
    $this->assertWith('drove/native-pest-corpus', 'pipeline-to-be', 1, 1, $state);
    expect($state->runCount)->toHaveKey('number', 0);
    expect($state->appliedCount)->toHaveKey('number', 0);
});

test('interceptor can be filtered with a closure', function () use ($state): void {
    $state->reset();
    $this->assertWith('drove/native-pest-corpus', 'pipeline-to-be', '*', 1, $state);
    expect($state->runCount)->toHaveKey('wildcard', 1);
    expect($state->appliedCount['wildcard'])->toBe(1);
});

test('interceptor can be filter the expected parameter as well', function () use ($state): void {
    $state->reset();
    $this->assertWith('drove/native-pest-corpus', 'pipeline-to-be', '*', '*', $state);
    expect($state->runCount)->toHaveKey('wildcard', 0);
    expect($state->appliedCount)->toHaveKey('wildcard', 0);
});

test('interceptor works with negated expectation', function () use ($state): void {
    $this->assertWith('drove/native-pest-corpus', 'pipeline-not-to-be', new Number(1), new Char('B'), $state);
});

test('intercept can add new parameters to the expectation', function () use ($state): void {
    $this->assertWith('drove/native-pest-corpus', 'pipeline-to-be', 'Foo', 'foo', $state, true);
});
PHP,
        ];
    }
}
