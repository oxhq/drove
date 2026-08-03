<?php

declare(strict_types=1);

namespace Drove\Migration;

use Drove\Compatibility\Status;
use Drove\Native\Attributes\TestClass;
use InvalidArgumentException;
use ParseError;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

final class ClassMigrator
{
    /** @var array<string, string> */
    private const array ATTRIBUTES = [
        DataProvider::class => \Drove\Native\Attributes\DataProvider::class,
        Group::class => \Drove\Native\Attributes\Group::class,
        Test::class => \Drove\Native\Attributes\Test::class,
    ];

    /**
     * @param array{
     *     method_receivers?: array<mixed, mixed>,
     *     property_replacements?: array<mixed, mixed>,
     *     parent_receivers?: array<mixed, mixed>,
     *     fluent_assertions?: list<mixed>
     * } $profile
     */
    public function migrate(
        string $source,
        string $path = '<memory>',
        string $externalTestCase = 'Tests\\TestCase',
        array $profile = [],
    ): MigrationResult {
        $beforeHash = hash('sha256', $source);
        $profile = $this->normalizeProfile($profile);

        try {
            $tokens = array_values(PhpToken::tokenize($source, TOKEN_PARSE));
        } catch (ParseError $error) {
            return new MigrationResult($source, $beforeHash, $beforeHash, [], [$this->finding([
                'code' => 'class.parse-error',
                'line' => $error->getLine(),
                'symbol' => $error->getMessage(),
            ], $path)]);
        }

        $externalTestCase = ltrim($externalTestCase, '\\');
        $imports = $this->imports($source, $tokens);
        $targets = $this->targetClasses($source, $tokens, $imports, $externalTestCase);
        $edits = [];
        $changes = [];
        $blockers = [];

        foreach ($targets as $target) {
            [$preprocessEdits, $preprocessChanges] = $this->preprocess(
                $source,
                $tokens,
                $target['body_open_index'],
                $target['body_close_index'],
                $profile,
            );
            array_push($edits, ...$preprocessEdits);
            array_push($changes, ...$preprocessChanges);
        }

        if ($edits !== []) {
            $source = $this->applyEdits($source, $edits);
            $tokens = array_values(PhpToken::tokenize($source, TOKEN_PARSE));
            $imports = $this->imports($source, $tokens);
            $targets = $this->targetClasses($source, $tokens, $imports, $externalTestCase);
            $edits = [];
        }

        foreach ($imports as $import) {
            if ($import['name'] === $externalTestCase) {
                $edits[] = [$import['line_start'], $import['line_end'], ''];
                $changes[] = 'remove-external-test-case-import';
            }
        }

        foreach ($tokens as $token) {
            $name = ltrim($token->text, '\\');

            if (! isset(self::ATTRIBUTES[$name])) {
                continue;
            }

            $prefix = str_starts_with($token->text, '\\') ? '\\' : '';
            $edits[] = [
                $token->pos,
                $token->pos + strlen($token->text),
                $prefix.self::ATTRIBUTES[$name],
            ];
            $changes[] = 'map-phpunit-attribute:'.substr($name, strrpos($name, '\\') + 1);
        }

        foreach ($targets as $target) {
            if ($target['extends_start'] !== null) {
                $edits[] = [$target['extends_start'], $target['body_start'], ' '];
                $changes[] = 'remove-external-test-case-inheritance';
            }

            if (! $target['marked']) {
                $edits[] = [
                    $target['class_start'],
                    $target['class_start'],
                    "#[\\Drove\\Native\\Attributes\\TestClass]\n",
                ];
                $changes[] = 'add-native-test-class';
            }

            [$expectedEdits, $expectedChanges, $expectedBlockers, $handledCalls] = $this->expectedExceptions(
                $source,
                $tokens,
                $target['body_open_index'],
                $target['body_close_index'],
            );
            array_push($edits, ...$expectedEdits);
            array_push($changes, ...$expectedChanges);
            array_push($blockers, ...$expectedBlockers);

            [$callEdits, $callChanges, $callBlockers] = $this->calls(
                $source,
                $tokens,
                $target['body_open_index'],
                $target['body_close_index'],
                $handledCalls,
                $profile['fluent_assertions'],
            );
            array_push($edits, ...$callEdits);
            array_push($changes, ...$callChanges);
            array_push($blockers, ...$callBlockers);
        }

        if ($targets === []) {
            $blockers[] = [
                'code' => 'class.test-class-not-found',
                'line' => 1,
                'symbol' => $externalTestCase,
            ];
        }

        foreach ($tokens as $token) {
            $name = ltrim($token->text, '\\');

            if (str_starts_with($name, 'PHPUnit\\Framework\\Attributes\\')
                && ! isset(self::ATTRIBUTES[$name])) {
                $blockers[] = [
                    'code' => 'class.phpunit-attribute.unsupported',
                    'line' => $token->line,
                    'symbol' => $name,
                ];
            }
        }

        $orderedEdits = $edits;
        usort($orderedEdits, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

        for ($index = 1; isset($orderedEdits[$index]); $index++) {
            if ($orderedEdits[$index][0] < $orderedEdits[$index - 1][1]) {
                $blockers[] = [
                    'code' => 'class.edit-overlap',
                    'line' => substr_count(substr($source, 0, $orderedEdits[$index][0]), "\n") + 1,
                    'symbol' => 'overlapping-source-transform',
                ];
            }
        }

        $migrated = $this->applyEdits($source, $edits);
        $applied = array_count_values($changes);
        ksort($applied, SORT_STRING);
        $blockers = $this->uniqueBlockers($blockers);

        return new MigrationResult(
            $migrated,
            $beforeHash,
            hash('sha256', $migrated),
            $applied,
            array_map(fn (array $blocker): Finding => $this->finding($blocker, $path), $blockers),
        );
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<string, array{name: string, line_start: int, line_end: int}>
     */
    private function imports(string $source, array $tokens): array
    {
        $imports = [];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            $depth += $token->text === '{' ? 1 : 0;
            $depth -= $token->text === '}' ? 1 : 0;
            if ($depth !== 0) {
                continue;
            }
            if ($token->id !== T_USE) {
                continue;
            }

            $cursor = $this->nextUseful($tokens, $index + 1);
            if ($cursor === null) {
                continue;
            }
            if (in_array($tokens[$cursor]->id, [T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $name = ltrim($tokens[$cursor]->text, '\\');
            $alias = substr($name, strrpos($name, '\\') + 1);
            $end = $cursor;

            while (isset($tokens[$end]) && $tokens[$end]->text !== ';') {
                if ($tokens[$end]->id === T_AS) {
                    $aliasIndex = $this->nextUseful($tokens, $end + 1);
                    $alias = $aliasIndex === null ? $alias : $tokens[$aliasIndex]->text;
                }

                $end++;
            }

            if (! isset($tokens[$end])) {
                continue;
            }

            $startPos = $token->pos;
            $lineStart = strrpos(substr($source, 0, $startPos), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $lineEnd = $tokens[$end]->pos + strlen($tokens[$end]->text);

            if (substr($source, $lineEnd, 2) === "\r\n") {
                $lineEnd += 2;
            } elseif (substr($source, $lineEnd, 1) === "\n") {
                $lineEnd++;
            }

            $imports[$alias] = [
                'name' => $name,
                'line_start' => $lineStart,
                'line_end' => $lineEnd,
            ];
        }

        return $imports;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<string, array{name: string, line_start: int, line_end: int}>  $imports
     * @return list<array{class_start: int, body_start: int, body_open_index: int, body_close_index: int, extends_start: ?int, marked: bool}>
     */
    private function targetClasses(
        string $source,
        array $tokens,
        array $imports,
        string $externalTestCase,
    ): array {
        $targets = [];

        foreach ($tokens as $index => $token) {
            if ($token->id !== T_CLASS) {
                continue;
            }
            if ($this->precededByNew($tokens, $index)) {
                continue;
            }
            $body = $index;
            $extends = null;

            while (isset($tokens[$body]) && $tokens[$body]->text !== '{') {
                if ($tokens[$body]->id === T_EXTENDS) {
                    $extends = $body;
                }

                $body++;
            }

            if (! isset($tokens[$body])) {
                continue;
            }

            $prefix = substr($source, max(0, $token->pos - 160), min(160, $token->pos));
            $marked = preg_match(
                '/#\[\\\\?Drove\\\\Native\\\\Attributes\\\\TestClass\]\s*$/',
                $prefix,
            ) === 1 || (($imports['TestClass']['name'] ?? null) === TestClass::class
                && preg_match('/#\[TestClass\]\s*$/', $prefix) === 1);
            $matchesBase = false;
            $extendsStart = null;

            if ($extends !== null) {
                $nameIndex = $this->nextUseful($tokens, $extends + 1);

                if ($nameIndex !== null) {
                    $name = ltrim($tokens[$nameIndex]->text, '\\');
                    $resolved = $imports[$name]['name'] ?? $name;
                    $matchesBase = $resolved === $externalTestCase;
                }

                if ($matchesBase) {
                    $extendsStart = $tokens[$extends]->pos;

                    while ($extendsStart > $token->pos
                        && in_array($source[$extendsStart - 1], [' ', "\t"], true)) {
                        $extendsStart--;
                    }
                }
            }

            if (! $matchesBase && ! $marked) {
                continue;
            }

            $targets[] = [
                'class_start' => $token->pos,
                'body_start' => $tokens[$body]->pos,
                'body_open_index' => $body,
                'body_close_index' => $this->closingBrace($tokens, $body),
                'extends_start' => $extendsStart,
                'marked' => $marked,
            ];
        }

        return $targets;
    }

    /**
     * @param array{
     *     method_receivers?: array<mixed, mixed>,
     *     property_replacements?: array<mixed, mixed>,
     *     parent_receivers?: array<mixed, mixed>,
     *     fluent_assertions?: list<mixed>
     * } $profile
     * @return array{
     *     method_receivers: array<string, non-empty-string>,
     *     property_replacements: array<string, non-empty-string>,
     *     parent_receivers: array<string, non-empty-string>,
     *     fluent_assertions: array<string, true>
     * }
     */
    private function normalizeProfile(array $profile): array
    {
        $normalized = [
            'method_receivers' => [],
            'property_replacements' => [],
            'parent_receivers' => [],
            'fluent_assertions' => [],
        ];

        foreach (['method_receivers', 'property_replacements', 'parent_receivers'] as $section) {
            foreach ($profile[$section] ?? [] as $name => $expression) {
                if (! is_string($name) || $name === '' || ! is_string($expression)) {
                    throw new InvalidArgumentException(sprintf(
                        'Native class migration profile %s entries require a name and receiver expression.',
                        $section,
                    ));
                }

                $expression = trim($expression);

                if ($expression === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Native class migration profile %s entries require a name and receiver expression.',
                        $section,
                    ));
                }

                $normalized[$section][strtolower($name)] = $expression;
            }
        }

        foreach ($profile['fluent_assertions'] ?? [] as $method) {
            if (! is_string($method) || $method === '') {
                throw new InvalidArgumentException(
                    'Native class migration fluent assertions require non-empty method names.',
                );
            }

            $normalized['fluent_assertions'][strtolower($method)] = true;
        }

        return $normalized;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param array{
     *     method_receivers: array<string, non-empty-string>,
     *     property_replacements: array<string, non-empty-string>,
     *     parent_receivers: array<string, non-empty-string>,
     *     fluent_assertions: array<string, true>
     * } $profile
     * @return array{list<array{int, int, string}>, list<string>}
     */
    private function preprocess(
        string $source,
        array $tokens,
        int $start,
        int $end,
        array $profile,
    ): array {
        $edits = [];
        $changes = [];

        foreach ($this->methodRanges($tokens, $start, $end) as $method) {
            $headerStart = $tokens[$method['declaration']]->pos;
            $header = substr($source, $headerStart, $tokens[$method['body_open']]->pos - $headerStart);
            $returnTypeRemoved = false;
            $depth = 0;

            for ($index = $method['body_open'] + 1; $index < $method['body_close']; $index++) {
                if ($tokens[$index]->text === '{') {
                    $depth++;

                    continue;
                }

                if ($tokens[$index]->text === '}') {
                    $depth--;

                    continue;
                }

                $receiver = $tokens[$index];
                if ($depth !== 0) {
                    continue;
                }
                if ($receiver->id !== T_VARIABLE) {
                    continue;
                }
                if ($receiver->text !== '$this') {
                    continue;
                }

                $operator = $this->nextUseful($tokens, $index + 1);
                $name = $operator === null ? null : $this->nextUseful($tokens, $operator + 1);
                $open = $name === null ? null : $this->nextUseful($tokens, $name + 1);
                if ($operator === null) {
                    continue;
                }
                if ($tokens[$operator]->id !== T_OBJECT_OPERATOR) {
                    continue;
                }
                if ($name === null) {
                    continue;
                }
                if (strtolower($tokens[$name]->text) !== 'marktestincomplete') {
                    continue;
                }
                if ($open === null) {
                    continue;
                }
                if ($tokens[$open]->text !== '(') {
                    continue;
                }

                $close = $this->closingParenthesis($tokens, $open);
                $semicolon = $this->nextUseful($tokens, $close + 1);
                $previous = $this->previousUseful($tokens, $index - 1);
                $arguments = $this->argumentRanges($source, $tokens, $open, $close);
                if ($semicolon === null) {
                    continue;
                }
                if ($tokens[$semicolon]->text !== ';') {
                    continue;
                }
                if ($previous !== null && ! in_array($tokens[$previous]->text, ['{', '}', ';'], true)) {
                    continue;
                }
                if (count($arguments) > 1) {
                    continue;
                }

                $reason = "'Test marked incomplete.'";

                if ($arguments !== []) {
                    $reason = trim(substr(
                        $source,
                        $arguments[0][0],
                        $arguments[0][1] - $arguments[0][0],
                    ));

                    if (preg_match('/\A(?:\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")\z/sD', $reason) !== 1) {
                        continue;
                    }
                }

                if (! $returnTypeRemoved
                    && preg_match('/:\s*void(?=\s*\z)/iD', $header, $returnType, PREG_OFFSET_CAPTURE) === 1) {
                    $return = $returnType[0];
                    $returnStart = $headerStart + $return[1];
                    $edits[] = [$returnStart, $returnStart + strlen($return[0]), ''];
                    $returnTypeRemoved = true;
                } elseif (! $returnTypeRemoved && preg_match('/\)\s*:/D', $header) === 1) {
                    continue;
                }

                $edits[] = [
                    $receiver->pos,
                    $tokens[$close]->pos + strlen($tokens[$close]->text),
                    'return \\Drove\\Kernel\\TestOutcome::incomplete('.$reason.')',
                ];
                $changes[] = 'map-incomplete-outcome';
                $index = $close;
            }
        }

        for ($index = $start + 1; $index < $end; $index++) {
            $receiver = $tokens[$index];

            if ($receiver->id === T_CLASS && $this->precededByNew($tokens, $index)) {
                $body = $index;

                while ($body < $end && $tokens[$body]->text !== '{') {
                    $body++;
                }

                if ($body < $end) {
                    $index = $this->closingBrace($tokens, $body);
                }

                continue;
            }

            if ($receiver->id === T_VARIABLE && $receiver->text === '$this') {
                $operator = $this->nextUseful($tokens, $index + 1);
                $name = $operator === null ? null : $this->nextUseful($tokens, $operator + 1);
                if ($operator === null) {
                    continue;
                }
                if ($tokens[$operator]->id !== T_OBJECT_OPERATOR) {
                    continue;
                }
                if ($name === null) {
                    continue;
                }
                if ($tokens[$name]->id !== T_STRING) {
                    continue;
                }

                $member = strtolower($tokens[$name]->text);
                $after = $this->nextUseful($tokens, $name + 1);

                if ($after !== null && $tokens[$after]->text === '('
                    && isset($profile['method_receivers'][$member])) {
                    $edits[] = [
                        $receiver->pos,
                        $receiver->pos + strlen($receiver->text),
                        $profile['method_receivers'][$member],
                    ];
                    $changes[] = 'map-context-receiver:'.$tokens[$name]->text;
                } elseif (($after === null || $tokens[$after]->text !== '(')
                    && isset($profile['property_replacements'][$member])) {
                    $edits[] = [
                        $receiver->pos,
                        $tokens[$name]->pos + strlen($tokens[$name]->text),
                        $profile['property_replacements'][$member],
                    ];
                    $changes[] = 'map-context-property:'.$tokens[$name]->text;
                }

                continue;
            }

            if (strtolower($receiver->text) !== 'parent') {
                continue;
            }

            $operator = $this->nextUseful($tokens, $index + 1);
            $name = $operator === null ? null : $this->nextUseful($tokens, $operator + 1);
            $open = $name === null ? null : $this->nextUseful($tokens, $name + 1);
            if ($operator === null) {
                continue;
            }
            if ($tokens[$operator]->id !== T_DOUBLE_COLON) {
                continue;
            }
            if ($name === null) {
                continue;
            }
            if ($tokens[$name]->id !== T_STRING) {
                continue;
            }
            if ($open === null) {
                continue;
            }
            if ($tokens[$open]->text !== '(') {
                continue;
            }

            $method = strtolower($tokens[$name]->text);
            $close = $this->closingParenthesis($tokens, $open);
            $semicolon = $this->nextUseful($tokens, $close + 1);
            $previous = $this->previousUseful($tokens, $index - 1);

            if (in_array($method, ['setup', 'teardown'], true)
                && $semicolon !== null && $tokens[$semicolon]->text === ';'
                && $this->argumentRanges($source, $tokens, $open, $close) === []
                && ($previous === null || in_array($tokens[$previous]->text, ['{', '}', ';'], true))) {
                $edits[] = [
                    $receiver->pos,
                    $tokens[$semicolon]->pos + strlen($tokens[$semicolon]->text),
                    '',
                ];
                $changes[] = 'remove-external-parent-lifecycle';

                continue;
            }

            if (isset($profile['parent_receivers'][$method])) {
                $edits[] = [
                    $receiver->pos,
                    $tokens[$name]->pos,
                    $profile['parent_receivers'][$method].'->',
                ];
                $changes[] = 'map-parent-receiver:'.$tokens[$name]->text;
            }
        }

        return [$edits, $changes];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<int, true>  $handledCalls
     * @param  array<string, true>  $fluentAssertions
     * @return array{list<array{int, int, string}>, list<string>, list<array{code: string, line: int, symbol: string}>}
     */
    private function calls(
        string $source,
        array $tokens,
        int $start,
        int $end,
        array $handledCalls = [],
        array $fluentAssertions = [],
    ): array {
        $edits = [];
        $changes = [];
        $blockers = [];
        $declaredMethods = $this->declaredMethods($tokens, $start, $end);

        for ($index = $start + 1; $index < $end; $index++) {
            $receiver = $tokens[$index];

            if ($receiver->id === T_CLASS && $this->precededByNew($tokens, $index)) {
                $body = $index;

                while ($body < $end && $tokens[$body]->text !== '{') {
                    $body++;
                }

                if ($body < $end) {
                    $index = $this->closingBrace($tokens, $body);
                }

                continue;
            }

            if ($receiver->id === T_VARIABLE && $receiver->text === '$this') {
                if (isset($handledCalls[$receiver->pos])) {
                    continue;
                }

                $operator = $this->nextUseful($tokens, $index + 1);
                $methodIndex = $operator === null ? null : $this->nextUseful($tokens, $operator + 1);
                $open = $methodIndex === null ? null : $this->nextUseful($tokens, $methodIndex + 1);
                if ($operator === null) {
                    continue;
                }
                if ($tokens[$operator]->id !== T_OBJECT_OPERATOR) {
                    continue;
                }
                if ($methodIndex === null) {
                    continue;
                }
                if ($tokens[$methodIndex]->id !== T_STRING) {
                    continue;
                }
                if ($open === null) {
                    continue;
                }
                if ($tokens[$open]->text !== '(') {
                    continue;
                }

                $method = $tokens[$methodIndex]->text;
                $close = $this->closingParenthesis($tokens, $open);

                $arguments = array_map(
                    static fn (array $range): string => trim(substr(
                        $source,
                        $range[0],
                        $range[1] - $range[0],
                    )),
                    $this->argumentRanges($source, $tokens, $open, $close),
                );
                $replacement = AssertionCallMigrator::replacement($method, $arguments);

                if ($replacement !== null) {
                    $edits[] = [
                        $receiver->pos,
                        $tokens[$close]->pos + strlen($tokens[$close]->text),
                        $replacement,
                    ];
                    $changes[] = 'map-assertion:'.$method;

                    continue;
                }

                if (isset($declaredMethods[strtolower($method)])) {
                    continue;
                }

                $blockers[] = [
                    'code' => str_starts_with(strtolower($method), 'assert')
                        ? 'class.assertion.untranslated'
                        : (str_starts_with(strtolower($method), 'expectexception')
                            ? 'class.expected-exception.untranslated'
                            : 'class.helper.untranslated'),
                    'line' => $receiver->line,
                    'symbol' => '$this->'.$method,
                ];

                continue;
            }

            if (in_array(strtolower($receiver->text), ['self', 'static', 'parent'], true)) {
                $operator = $this->nextUseful($tokens, $index + 1);
                $methodIndex = $operator === null ? null : $this->nextUseful($tokens, $operator + 1);

                if ($operator !== null && $tokens[$operator]->id === T_DOUBLE_COLON
                    && $methodIndex !== null && $tokens[$methodIndex]->id === T_STRING) {
                    $method = $tokens[$methodIndex]->text;

                    if (str_starts_with(strtolower($method), 'assert')
                        || str_starts_with(strtolower($method), 'expectexception')
                        || in_array(strtolower($method), ['setup', 'teardown', 'setupbeforeclass', 'teardownafterclass'], true)) {
                        $blockers[] = [
                            'code' => strtolower($receiver->text) === 'parent'
                                ? 'class.parent-lifecycle.untranslated'
                                : 'class.static-helper.untranslated',
                            'line' => $receiver->line,
                            'symbol' => $receiver->text.'::'.$method,
                        ];
                    }
                }
            }

            if ($receiver->id === T_OBJECT_OPERATOR) {
                $methodIndex = $this->nextUseful($tokens, $index + 1);
                $previous = $this->previousUseful($tokens, $index - 1);

                if ($methodIndex !== null
                    && $tokens[$methodIndex]->id === T_STRING
                    && str_starts_with(strtolower($tokens[$methodIndex]->text), 'assert')
                    && ! isset($fluentAssertions[strtolower($tokens[$methodIndex]->text)])
                    && ($previous === null
                        || $tokens[$previous]->id !== T_VARIABLE
                        || $tokens[$previous]->text !== '$this')) {
                    $blockers[] = [
                        'code' => 'class.fluent-assertion.untranslated',
                        'line' => $receiver->line,
                        'symbol' => '->'.$tokens[$methodIndex]->text,
                    ];
                }
            }
        }

        return [$edits, $changes, $blockers];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array{
     *     list<array{int, int, string}>,
     *     list<string>,
     *     list<array{code: string, line: int, symbol: string}>,
     *     array<int, true>
     * }
     */
    private function expectedExceptions(
        string $source,
        array $tokens,
        int $start,
        int $end,
    ): array {
        $edits = [];
        $changes = [];
        $blockers = [];
        $handled = [];

        foreach ($this->methodRanges($tokens, $start, $end) as $method) {
            $constraints = [];
            $callEdits = [];
            $blockerCount = count($blockers);

            for ($index = $method['body_open'] + 1; $index < $method['body_close']; $index++) {
                $receiver = $tokens[$index];

                if ($receiver->id === T_CLASS && $this->precededByNew($tokens, $index)) {
                    $body = $index;

                    while ($body < $method['body_close'] && $tokens[$body]->text !== '{') {
                        $body++;
                    }

                    if ($body < $method['body_close']) {
                        $index = $this->closingBrace($tokens, $body);
                    }

                    continue;
                }
                if ($receiver->id !== T_VARIABLE) {
                    continue;
                }
                if ($receiver->text !== '$this') {
                    continue;
                }

                $operator = $this->nextUseful($tokens, $index + 1);
                $nameIndex = $operator === null ? null : $this->nextUseful($tokens, $operator + 1);
                $open = $nameIndex === null ? null : $this->nextUseful($tokens, $nameIndex + 1);
                if ($operator === null) {
                    continue;
                }
                if ($tokens[$operator]->id !== T_OBJECT_OPERATOR) {
                    continue;
                }
                if ($nameIndex === null) {
                    continue;
                }
                if ($tokens[$nameIndex]->id !== T_STRING) {
                    continue;
                }
                if ($open === null) {
                    continue;
                }
                if ($tokens[$open]->text !== '(') {
                    continue;
                }

                $call = strtolower($tokens[$nameIndex]->text);
                $constraint = match ($call) {
                    'expectexception' => 'class',
                    'expectexceptionmessage' => 'message',
                    'expectexceptioncode' => 'code',
                    default => null,
                };

                if ($constraint === null) {
                    continue;
                }

                $handled[$receiver->pos] = true;
                $close = $this->closingParenthesis($tokens, $open);
                $semicolon = $this->nextUseful($tokens, $close + 1);
                $arguments = $this->argumentRanges($source, $tokens, $open, $close);

                if ($semicolon === null || $tokens[$semicolon]->text !== ';'
                    || count($arguments) !== 1) {
                    $blockers[] = [
                        'code' => 'class.expected-exception.untranslated',
                        'line' => $receiver->line,
                        'symbol' => '$this->'.$tokens[$nameIndex]->text,
                    ];

                    continue;
                }

                $argument = trim(substr(
                    $source,
                    $arguments[0][0],
                    $arguments[0][1] - $arguments[0][0],
                ));

                if (isset($constraints[$constraint])
                    || ! $this->constantExceptionConstraint($constraint, $argument)) {
                    $blockers[] = [
                        'code' => 'class.expected-exception.untranslated',
                        'line' => $receiver->line,
                        'symbol' => '$this->'.$tokens[$nameIndex]->text,
                    ];

                    continue;
                }

                $constraints[$constraint] = $argument;
                $callEdits[] = [
                    $receiver->pos,
                    $tokens[$semicolon]->pos + strlen($tokens[$semicolon]->text),
                    '',
                ];
            }
            if ($constraints === []) {
                continue;
            }
            if (count($blockers) !== $blockerCount) {
                continue;
            }

            $declaration = $tokens[$method['declaration']]->pos;
            $lineStart = strrpos(substr($source, 0, $declaration), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $indent = substr($source, $lineStart, $declaration - $lineStart);
            $attribute = sprintf(
                '#[\\Drove\\Native\\Attributes\\Throws(%s, %s, %s)]'."\n".$indent,
                $constraints['class'] ?? 'null',
                $constraints['message'] ?? 'null',
                $constraints['code'] ?? 'null',
            );
            $edits[] = [$declaration, $declaration, $attribute];
            array_push($edits, ...$callEdits);
            $changes[] = 'map-expected-exception';
        }

        return [$edits, $changes, $blockers, $handled];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<array{declaration: int, body_open: int, body_close: int}>
     */
    private function methodRanges(array $tokens, int $start, int $end): array
    {
        $methods = [];
        $depth = 0;

        for ($index = $start + 1; $index < $end; $index++) {
            if ($depth === 0 && $tokens[$index]->id === T_FUNCTION) {
                $body = $index;

                while ($body < $end && ! in_array($tokens[$body]->text, ['{', ';'], true)) {
                    $body++;
                }
                if ($body >= $end) {
                    continue;
                }
                if ($tokens[$body]->text !== '{') {
                    continue;
                }

                $declaration = $index;
                $previous = $this->previousUseful($tokens, $index - 1);

                while ($previous !== null && in_array($tokens[$previous]->id, [
                    T_ABSTRACT,
                    T_FINAL,
                    T_PRIVATE,
                    T_PROTECTED,
                    T_PUBLIC,
                    T_STATIC,
                ], true)) {
                    $declaration = $previous;
                    $previous = $this->previousUseful($tokens, $previous - 1);
                }

                $methods[] = [
                    'declaration' => $declaration,
                    'body_open' => $body,
                    'body_close' => $this->closingBrace($tokens, $body),
                ];
                $index = $methods[array_key_last($methods)]['body_close'];

                continue;
            }

            $depth += $tokens[$index]->text === '{' ? 1 : 0;
            $depth -= $tokens[$index]->text === '}' ? 1 : 0;
        }

        return $methods;
    }

    private function constantExceptionConstraint(string $constraint, string $argument): bool
    {
        return match ($constraint) {
            'class' => preg_match(
                '/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*::class\z/D',
                $argument,
            ) === 1,
            'message' => preg_match(
                '/\A(?:\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")\z/sD',
                $argument,
            ) === 1,
            'code' => preg_match('/\A-?[0-9]+\z/D', $argument) === 1,
            default => false,
        };
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<string, true>
     */
    private function declaredMethods(array $tokens, int $start, int $end): array
    {
        $methods = [];
        $depth = 0;

        for ($index = $start + 1; $index < $end; $index++) {
            $depth += $tokens[$index]->text === '{' ? 1 : 0;
            $depth -= $tokens[$index]->text === '}' ? 1 : 0;
            if ($depth !== 0) {
                continue;
            }
            if ($tokens[$index]->id !== T_FUNCTION) {
                continue;
            }

            $name = $this->nextUseful($tokens, $index + 1);

            if ($name !== null && $tokens[$name]->id === T_STRING) {
                $methods[strtolower($tokens[$name]->text)] = true;
            }
        }

        return $methods;
    }

    /** @param list<PhpToken> $tokens */
    private function precededByNew(array $tokens, int $index): bool
    {
        for ($index--; $index >= 0; $index--) {
            if ($tokens[$index]->isIgnorable()) {
                continue;
            }

            return $tokens[$index]->id === T_NEW;
        }

        return false;
    }

    /** @param list<PhpToken> $tokens */
    private function nextUseful(array $tokens, int $index): ?int
    {
        for (; isset($tokens[$index]); $index++) {
            if (! $tokens[$index]->isIgnorable()) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<PhpToken> $tokens */
    private function previousUseful(array $tokens, int $index): ?int
    {
        for (; $index >= 0; $index--) {
            if (! $tokens[$index]->isIgnorable()) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<PhpToken> $tokens */
    private function closingBrace(array $tokens, int $open): int
    {
        $depth = 0;

        for ($index = $open; isset($tokens[$index]); $index++) {
            $depth += $tokens[$index]->text === '{' ? 1 : 0;
            $depth -= $tokens[$index]->text === '}' ? 1 : 0;

            if ($depth === 0) {
                return $index;
            }
        }

        return count($tokens) - 1;
    }

    /** @param list<PhpToken> $tokens */
    private function closingParenthesis(array $tokens, int $open): int
    {
        $depth = 0;

        for ($index = $open; isset($tokens[$index]); $index++) {
            $depth += $tokens[$index]->text === '(' ? 1 : 0;
            $depth -= $tokens[$index]->text === ')' ? 1 : 0;

            if ($depth === 0) {
                return $index;
            }
        }

        return count($tokens) - 1;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<array{int, int}>
     */
    private function argumentRanges(string $source, array $tokens, int $open, int $close): array
    {
        $ranges = [];
        $start = $tokens[$open]->pos + 1;
        $depth = 0;

        for ($index = $open + 1; $index < $close; $index++) {
            $text = $tokens[$index]->text;
            $depth += in_array($text, ['(', '[', '{'], true) ? 1 : 0;
            $depth -= in_array($text, [')', ']', '}'], true) ? 1 : 0;

            if ($text === ',' && $depth === 0) {
                $ranges[] = [$start, $tokens[$index]->pos];
                $start = $tokens[$index]->pos + 1;
            }
        }

        if (trim(substr($source, $start, $tokens[$close]->pos - $start)) !== '') {
            $ranges[] = [$start, $tokens[$close]->pos];
        }

        return $ranges;
    }

    /**
     * @param  list<array{int, int, string}>  $edits
     */
    private function applyEdits(string $source, array $edits): string
    {
        usort($edits, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
        $lastStart = strlen($source) + 1;

        foreach ($edits as [$start, $end, $replacement]) {
            if ($end > $lastStart) {
                continue;
            }

            $source = substr_replace($source, $replacement, $start, $end - $start);
            $lastStart = $start;
        }

        return $source;
    }

    /**
     * @param  list<array{code: string, line: int, symbol: string}>  $blockers
     * @return list<array{code: string, line: int, symbol: string}>
     */
    private function uniqueBlockers(array $blockers): array
    {
        $unique = [];

        foreach ($blockers as $blocker) {
            $unique[implode('|', $blocker)] = $blocker;
        }

        $blockers = array_values($unique);
        usort($blockers, static fn (array $left, array $right): int => [
            $left['line'],
            $left['code'],
            $left['symbol'],
        ] <=> [
            $right['line'],
            $right['code'],
            $right['symbol'],
        ]);

        return $blockers;
    }

    /** @param array{code: string, line: int, symbol: string} $blocker */
    private function finding(array $blocker, string $path): Finding
    {
        return new Finding(
            $blocker['code'],
            Status::Unsupported,
            $blocker['symbol'],
            $path,
            $blocker['line'],
            1,
            'DROVE_NATIVE_CLASS_'.strtoupper(str_replace(['.', '-'], '_', $blocker['code'])),
            null,
        );
    }
}
