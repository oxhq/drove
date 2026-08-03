<?php

declare(strict_types=1);

use Drove\Migration\CodemodOptions;

const NATIVE_FILAMENT_ENVIRONMENT_DECLARATION = 'tests/src/Support/ArrayRecordTest.php';

function nativeFilamentOptions(): CodemodOptions
{
    return new CodemodOptions(
        environments: [
            'Filament\\Tests\\TestCase' => [
                'name' => 'filament-native-package',
                'factory' => 'static fn () => \\nativeFilamentRuntime()',
                'declaration_path' => NATIVE_FILAMENT_ENVIRONMENT_DECLARATION,
            ],
        ],
        matchers: [
            'toMatchSnapshot' => [
                'owner' => 'corpus/filament',
                'matcher' => 'snapshot',
            ],
        ],
        trustedFunctions: [
            'tests/src/Support/BladeComponentsTest.php' => ['embeddedHtmlGenerator'],
            'tests/src/Support/Components/ViewComponentTest.php' => ['writePublishedOverride'],
            'tests/src/Support/SpaModeTest.php' => ['generate_href_html'],
            'tests/src/Support/View/Components/ColorMaps/ButtonComponentColorMapTest.php' => ['defaultButtonColorMap'],
            'tests/src/Support/View/Components/ColorMaps/IconButtonComponentColorMapTest.php' => ['defaultIconButtonColorMap'],
            'tests/src/Support/helpersTest.php' => [
                'generate_search_column_expression',
                'get_authorization_response',
                'prepare_inherited_attributes',
            ],
        ],
    );
}

function nativeFilamentProfileSource(string $source, string $path): string
{
    $usesPattern = "~^uses\\(TestCase::class\\)(?:->group\\('serial'\\))?;\\R~m";
    $uses = preg_match_all($usesPattern, $source);

    if ($uses === 0 && ! str_contains($source, '\\Drove\\Native\\')) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_USES_SHAPE:'.$path);
    }

    if ($uses === 1 && $path !== NATIVE_FILAMENT_ENVIRONMENT_DECLARATION) {
        $replacement = $path === 'tests/src/Support/Components/ViewComponentTest.php'
            ? "\\Drove\\Native\\beforeEach()->group('serial');\n"
            : '';
        $source = preg_replace($usesPattern, $replacement, $source, 1, $replacements);

        if (! is_string($source) || $replacements !== 1) {
            throw new RuntimeException('DROVE_NATIVE_FILAMENT_USES_REMOVE:'.$path);
        }
    }

    if ($path === 'tests/src/Support/SpaModeTest.php'
        && ! str_contains($source, '\\nativeFilamentToHtml(')) {
        $source = nativeFilamentLowerHtmlChains($source, false, $path);
    }

    if ($path === 'tests/src/Support/RenderHooksTest.php'
        && ! str_contains($source, '\\nativeFilamentHtmlStringExpectation(')) {
        $source = nativeFilamentLowerHtmlChains($source, true, $path);
    }

    if ($path === 'tests/src/Support/ColorTest.php'
        && ! str_contains($source, '\\nativeFilamentCartesianDataset(')) {
        $source = nativeFilamentLowerColorCartesianDataset($source);
    }

    if ($path === 'tests/src/Support/Services/RelationshipJoinerTest.php'
        && ! str_contains($source, '(static function (mixed $droveValue)')) {
        $source = nativeFilamentLowerRelationshipExpectations($source, $path);
    }

    if ($path === 'tests/src/Support/EvaluatesClosuresTest.php'
        && str_contains($source, '$this->expectException(')) {
        $source = nativeFilamentLowerExpectedExceptions($source);
    }

    if ($path === 'tests/src/Support/Services/RelationshipOrdererTest.php'
        && str_contains($source, 'expect($subquery->joins)->not->toBeEmpty();')) {
        $source = str_replace(
            'expect($subquery->joins)->not->toBeEmpty();',
            'expect(count($subquery->joins ?? []))->toBeGreaterThan(0);',
            $source,
            $replacements,
        );

        if ($replacements !== 1) {
            throw new RuntimeException('DROVE_NATIVE_FILAMENT_RELATIONSHIP_ORDERER_EXPECTATION');
        }
    }

    return $source;
}

function nativeFilamentLowerExpectedExceptions(string $source): string
{
    $first = <<<'PHP'
    $this->expectException(BindingResolutionException::class);

    $isEvaluatingClosures->evaluate(function (RecordModel $recordModel): void {
        throw new RuntimeException('Should not be called because named parameter not provided.');
    });
PHP;
    $firstReplacement = <<<'PHP'
    expect(fn () => $isEvaluatingClosures->evaluate(function (RecordModel $recordModel): void {
        throw new RuntimeException('Should not be called because named parameter not provided.');
    }))->toThrow(BindingResolutionException::class);
PHP;
    $second = <<<'PHP'
    $this->expectException(BindingResolutionException::class);

    $isEvaluatingClosures->evaluate(function (RecordModel $recordModel): void {
        throw new RuntimeException('Should not be called.');
    });
PHP;
    $secondReplacement = <<<'PHP'
    expect(fn () => $isEvaluatingClosures->evaluate(function (RecordModel $recordModel): void {
        throw new RuntimeException('Should not be called.');
    }))->toThrow(BindingResolutionException::class);
PHP;
    $source = str_replace($first, $firstReplacement, $source, $firstCount);
    $source = str_replace($second, $secondReplacement, $source, $secondCount);

    if ($firstCount !== 1 || $secondCount !== 1) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_EXPECTED_EXCEPTION_SHAPE');
    }

    return $source;
}

function nativeFilamentLowerRelationshipExpectations(string $source, string $path): string
{
    $tokens = array_values(PhpToken::tokenize($source, TOKEN_PARSE));
    $assertions = [
        'tobe' => true,
        'tobefalse' => true,
        'tobetrue' => true,
        'tobenull' => true,
        'tohavecount' => true,
        'tocontain' => true,
    ];
    $edits = [];

    foreach ($tokens as $index => $token) {
        if (! $token->is(T_STRING) || strtolower($token->text) !== 'expect') {
            continue;
        }

        $open = nativeFilamentNextToken($tokens, $index);

        if ($open === null || $tokens[$open]->text !== '(') {
            continue;
        }

        $close = nativeFilamentClosingToken($tokens, $open, '(', ')');
        $actual = trim(substr(
            $source,
            $tokens[$open]->pos + 1,
            $tokens[$close]->pos - $tokens[$open]->pos - 1,
        ));
        $cursor = nativeFilamentNextToken($tokens, $close);
        $subject = '$droveValue';
        $current = $subject;
        $negated = false;
        $statements = [];

        while ($cursor !== null && $tokens[$cursor]->is(T_OBJECT_OPERATOR)) {
            $member = nativeFilamentNextToken($tokens, $cursor);
            $next = $member === null ? null : nativeFilamentNextToken($tokens, $member);

            if ($member === null || ! $tokens[$member]->is(T_STRING)) {
                throw new RuntimeException('DROVE_NATIVE_FILAMENT_RELATIONSHIP_CHAIN:'.$path);
            }

            $method = strtolower($tokens[$member]->text);
            $called = $next !== null && $tokens[$next]->text === '(';
            $arguments = '';
            $segmentEnd = $member;

            if ($called) {
                $methodClose = nativeFilamentClosingToken($tokens, $next, '(', ')');
                $arguments = substr(
                    $source,
                    $tokens[$next]->pos + 1,
                    $tokens[$methodClose]->pos - $tokens[$next]->pos - 1,
                );
                $segmentEnd = $methodClose;
            }

            if ($method === 'not' && ! $called) {
                $negated = true;
            } elseif ($method === 'and' && $called) {
                $subject = trim($arguments);

                if (str_starts_with($subject, $actual)) {
                    $subject = '$droveValue'.substr($subject, strlen($actual));
                }

                $current = $subject;
                $negated = false;
            } elseif (isset($assertions[$method]) && $called) {
                $statements[] = sprintf(
                    '\\Drove\\Native\\expect(%s)%s->%s(%s);',
                    $current,
                    $negated ? '->not()' : '',
                    $tokens[$member]->text,
                    $arguments,
                );
                $current = $subject;
                $negated = false;
            } elseif ($called) {
                $current .= '->'.$tokens[$member]->text.'('.$arguments.')';
            } else {
                $current = sprintf(
                    '\\nativeFilamentProperty(%s, %s)',
                    $current,
                    var_export($tokens[$member]->text, true),
                );
            }

            $cursor = nativeFilamentNextToken($tokens, $segmentEnd);
        }

        if ($statements === []) {
            continue;
        }

        if ($cursor === null || $tokens[$cursor]->text !== ';' || $negated) {
            throw new RuntimeException('DROVE_NATIVE_FILAMENT_RELATIONSHIP_TERMINATOR:'.$path);
        }

        $body = implode(' ', $statements);
        $edits[] = [
            'start' => $token->pos,
            'length' => $tokens[$cursor]->pos + 1 - $token->pos,
            'replacement' => sprintf(
                '(static function (mixed $droveValue) use ($user): void { %s })(%s);',
                $body,
                $actual,
            ),
        ];
    }

    if (count($edits) !== 6) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_RELATIONSHIP_COUNT:'.count($edits));
    }

    usort($edits, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);

    foreach ($edits as $edit) {
        $source = substr_replace($source, $edit['replacement'], $edit['start'], $edit['length']);
    }

    return $source;
}

function nativeFilamentLowerHtmlChains(
    string $source,
    bool $preserveInstanceAssertion,
    string $path,
): string {
    $tokens = array_values(PhpToken::tokenize($source, TOKEN_PARSE));
    $edits = [];

    foreach ($tokens as $index => $token) {
        if (! $token->is(T_STRING) || strtolower($token->text) !== 'expect') {
            continue;
        }

        $open = nativeFilamentNextToken($tokens, $index);

        if ($open === null || $tokens[$open]->text !== '(') {
            continue;
        }

        $close = nativeFilamentClosingToken($tokens, $open, '(', ')');
        $cursor = nativeFilamentNextToken($tokens, $close);
        $prefixEnd = $close;

        while ($cursor !== null && $tokens[$cursor]->is(T_OBJECT_OPERATOR)) {
            $member = nativeFilamentNextToken($tokens, $cursor);
            $methodOpen = $member === null ? null : nativeFilamentNextToken($tokens, $member);

            if ($member === null
                || ! $tokens[$member]->is(T_STRING)
                || $methodOpen === null
                || $tokens[$methodOpen]->text !== '(') {
                break;
            }

            $methodClose = nativeFilamentClosingToken($tokens, $methodOpen, '(', ')');

            if (strtolower($tokens[$member]->text) === 'tohtml') {
                if (nativeFilamentNextToken($tokens, $methodOpen) !== $methodClose) {
                    throw new RuntimeException('DROVE_NATIVE_FILAMENT_TO_HTML_ARGUMENTS:'.$path);
                }

                $actual = trim(substr(
                    $source,
                    $tokens[$open]->pos + 1,
                    $tokens[$close]->pos - $tokens[$open]->pos - 1,
                ));
                $prefix = substr(
                    $source,
                    $tokens[$close]->pos + 1,
                    $tokens[$cursor]->pos - $tokens[$close]->pos - 1,
                );
                $expectedPrefix = $preserveInstanceAssertion
                    ? "\n        ->toBeInstanceOf(HtmlString::class)\n        "
                    : '';

                if (trim($prefix) !== trim($expectedPrefix)) {
                    throw new RuntimeException('DROVE_NATIVE_FILAMENT_TO_HTML_PREFIX:'.$path);
                }

                $replacement = $preserveInstanceAssertion
                    ? '\\nativeFilamentHtmlStringExpectation('.$actual.')'
                    : '\\Drove\\Native\\expect(\\nativeFilamentToHtml('.$actual.'))';
                $edits[] = [
                    'start' => $token->pos,
                    'length' => $tokens[$methodClose]->pos + 1 - $token->pos,
                    'replacement' => $replacement,
                ];
                $prefixEnd = $methodClose;

                break;
            }

            $prefixEnd = $methodClose;
            $cursor = nativeFilamentNextToken($tokens, $methodClose);
        }
    }

    if ($edits === []) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_TO_HTML_MISSING:'.$path);
    }

    usort($edits, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);

    foreach ($edits as $edit) {
        $source = substr_replace($source, $edit['replacement'], $edit['start'], $edit['length']);
    }

    return $source;
}

function nativeFilamentLowerColorCartesianDataset(string $source): string
{
    $test = strpos($source, "it('generates component classes'");

    if ($test === false) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_COLOR_TEST_MISSING');
    }

    $first = strpos($source, '->with([', $test);

    if ($first === false) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_COLOR_DATASET_MISSING');
    }

    $source = substr_replace(
        $source,
        '->with(\\nativeFilamentCartesianDataset([',
        $first,
        strlen('->with(['),
    );
    $tail = "])\n    ->with(fn (): array => array_keys(app(ColorManager::class)->getColors()));";
    $replacement = '], \\nativeFilamentColorKeys()));';
    $tailPosition = strpos($source, $tail, $first);

    if ($tailPosition === false || strpos($source, $tail, $tailPosition + 1) !== false) {
        throw new RuntimeException('DROVE_NATIVE_FILAMENT_COLOR_DATASET_SHAPE');
    }

    return substr_replace($source, $replacement, $tailPosition, strlen($tail));
}

/**
 * @param  list<PhpToken>  $tokens
 */
function nativeFilamentNextToken(array $tokens, int $index): ?int
{
    for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
        if (! $tokens[$cursor]->isIgnorable()) {
            return $cursor;
        }
    }

    return null;
}

/**
 * @param  list<PhpToken>  $tokens
 */
function nativeFilamentClosingToken(
    array $tokens,
    int $open,
    string $opening,
    string $closing,
): int {
    $depth = 0;

    for ($index = $open, $count = count($tokens); $index < $count; $index++) {
        if ($tokens[$index]->text === $opening) {
            $depth++;
        } elseif ($tokens[$index]->text === $closing && --$depth === 0) {
            return $index;
        }
    }

    throw new RuntimeException('DROVE_NATIVE_FILAMENT_UNCLOSED_TOKEN');
}
