<?php

declare(strict_types=1);

namespace Drove\Migration;

use ParseError;
use PhpToken;

final readonly class LaravelMigrator
{
    /** @var array<string, string> */
    private const array HELPERS = [
        'deletejson' => 'deleteJson',
        'get' => 'get',
        'getjson' => 'getJson',
        'patchjson' => 'patchJson',
        'postjson' => 'postJson',
        'putjson' => 'putJson',
    ];

    /** @var array<string, true> */
    private const array CONTEXT_METHODS = [
        'application' => true,
        'assertactionusesformrequest' => true,
        'assertauthenticatedas' => true,
        'assertdatabasecount' => true,
        'assertdatabasehas' => true,
        'assertdatabasemissing' => true,
        'deletejson' => true,
        'followingredirects' => true,
        'get' => true,
        'getjson' => true,
        'patchjson' => true,
        'postjson' => true,
        'putjson' => true,
        'withoutvite' => true,
        'withheaders' => true,
        'withvite' => true,
    ];

    /** @var array<string, true> */
    private const array TEST_CONTEXT_WRAPPERS = [
        'aftereach' => true,
        'beforeeach' => true,
        'it' => true,
        'test' => true,
    ];

    public function __construct(
        private Migrator $portable,
    ) {}

    public function migrate(
        string $source,
        string $path = '<memory>',
        ?CodemodOptions $options = null,
    ): LaravelMigrationResult {
        $portable = $this->portable->migrate($source, $path, $options);

        try {
            $tokens = array_values(PhpToken::tokenize($portable->source, TOKEN_PARSE));
        } catch (ParseError) {
            return new LaravelMigrationResult(
                $portable,
                $portable->source,
                $portable->resultHash,
                $portable->applied,
                $portable->blockers,
                [],
            );
        }

        $pairs = $this->pairs($tokens);
        $nativeClosures = $this->nativeTestClosures($tokens, $pairs);
        $classBodies = $this->classBodies($tokens, $pairs);
        $imports = $this->laravelFunctionImports($tokens);
        $portableBlockers = array_values(array_filter(
            $portable->blockers,
            static fn (Finding $finding): bool => $finding->surface !== 'pest.ambiguous-call'
                || ! isset($imports[strtolower($finding->construct)]),
        ));
        $edits = [];
        $blockers = [];

        foreach ($tokens as $index => $token) {
            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $canonical = strtolower(ltrim($token->text, '\\'));
                $parts = explode('\\', $canonical);

                if (count($parts) === 3
                    && $parts[0] === 'pest'
                    && $parts[1] === 'laravel'
                    && isset(self::HELPERS[$parts[2]])) {
                    $prefix = str_starts_with($token->text, '\\') ? '\\' : '';
                    $edits[] = [
                        'start' => $token->pos,
                        'length' => strlen($token->text),
                        'replacement' => $prefix.'Drove\\Laravel\\'.self::HELPERS[$parts[2]],
                        'codemod' => 'laravel-helper-namespace-v1',
                    ];
                }
            }

            if ($token->is(T_NAME_QUALIFIED)
                && strtolower(ltrim($token->text, '\\')) === 'pest\\laravel') {
                $blockers[] = $this->blocker(
                    'grouped-helper-import',
                    $token->text,
                    $path,
                    $portable->source,
                    $token,
                    'DROVE_LARAVEL_GROUPED_HELPER_IMPORT',
                );
            }

            if ($this->unqualifiedHelperCall($tokens, $index)
                && ! isset($imports[strtolower($token->text)])) {
                $blockers[] = $this->blocker(
                    'ambiguous-helper-call',
                    $token->text.'()',
                    $path,
                    $portable->source,
                    $token,
                    'DROVE_LARAVEL_AMBIGUOUS_HELPER_CALL',
                );
            }
            if (! $token->is(T_VARIABLE)) {
                continue;
            }
            if ($token->text !== '$this') {
                continue;
            }

            if ($this->inside($index, $classBodies)) {
                continue;
            }

            $operator = $this->next($tokens, $index);
            $member = $operator === null ? null : $this->next($tokens, $operator);
            $open = $member === null ? null : $this->next($tokens, $member);

            if ($operator === null
                || ! $tokens[$operator]->is(T_OBJECT_OPERATOR)
                || $member === null
                || ! $tokens[$member]->is(T_STRING)) {
                $blockers[] = $this->blocker(
                    'dynamic-test-context',
                    '$this',
                    $path,
                    $portable->source,
                    $token,
                    'DROVE_LARAVEL_DYNAMIC_TEST_CONTEXT',
                );

                continue;
            }

            if ($open === null || $tokens[$open]->text !== '(') {
                if (! $this->inside($index, $nativeClosures)) {
                    $blockers[] = $this->blocker(
                        'ambiguous-test-context-property',
                        '$this->'.$tokens[$member]->text,
                        $path,
                        $portable->source,
                        $token,
                        'DROVE_LARAVEL_AMBIGUOUS_TEST_CONTEXT',
                    );
                }

                continue;
            }

            $method = strtolower($tokens[$member]->text);

            if ($this->inside($index, $nativeClosures) && isset($pairs[$open])) {
                $arguments = $this->arguments($portable->source, $tokens, $pairs, $open);
                $replacement = $arguments === null
                    ? null
                    : AssertionCallMigrator::replacement($tokens[$member]->text, $arguments);

                if ($replacement !== null) {
                    $close = $pairs[$open];
                    $edits[] = [
                        'start' => $token->pos,
                        'length' => $tokens[$close]->pos + strlen($tokens[$close]->text) - $token->pos,
                        'replacement' => $replacement,
                        'codemod' => 'phpunit-assertion-map-v1',
                    ];

                    continue;
                }
            }

            if (! isset(self::CONTEXT_METHODS[$method])) {
                $blockers[] = $this->blocker(
                    $method === 'assertactionusesformrequest'
                        ? 'custom-test-case-helper'
                        : (str_starts_with($method, 'assert')
                            ? 'base-test-case-assertion'
                            : 'base-test-case-helper'),
                    '$this->'.$tokens[$member]->text.'()',
                    $path,
                    $portable->source,
                    $token,
                    $method === 'assertactionusesformrequest'
                        ? 'DROVE_LARAVEL_CUSTOM_TEST_CASE_HELPER'
                        : 'DROVE_LARAVEL_BASE_TEST_CASE_METHOD',
                );

                continue;
            }

            if (! $this->inside($index, $nativeClosures)) {
                $blockers[] = $this->blocker(
                    'ambiguous-test-context',
                    '$this->'.$tokens[$member]->text.'()',
                    $path,
                    $portable->source,
                    $token,
                    'DROVE_LARAVEL_AMBIGUOUS_TEST_CONTEXT',
                );

                continue;
            }

            $edits[] = [
                'start' => $token->pos,
                'length' => strlen($token->text),
                'replacement' => '\\Drove\\Laravel\\laravelContext()',
                'codemod' => 'laravel-explicit-context-v1',
            ];
        }

        usort(
            $edits,
            static fn (array $left, array $right): int => $right['start'] <=> $left['start'],
        );
        $result = $portable->source;
        $applied = $portable->applied;
        $lastStart = strlen($result) + 1;

        foreach ($edits as $edit) {
            if ($lastStart < $edit['start'] + $edit['length']) {
                continue;
            }

            $result = substr_replace(
                $result,
                $edit['replacement'],
                $edit['start'],
                $edit['length'],
            );
            $lastStart = $edit['start'];
            $applied[$edit['codemod']] = ($applied[$edit['codemod']] ?? 0) + 1;
        }

        ksort($applied, SORT_STRING);
        usort($blockers, static fn (array $left, array $right): int => [
            $left['path'],
            $left['line'],
            $left['column'],
            $left['kind'],
        ] <=> [
            $right['path'],
            $right['line'],
            $right['column'],
            $right['kind'],
        ]);

        return new LaravelMigrationResult(
            $portable,
            $result,
            hash('sha256', $result),
            $applied,
            $portableBlockers,
            $blockers,
        );
    }

    /**
     * @return list<array{kind: string, construct: string, path: string, line: int, column: int, diagnostic: string}>
     */
    public function inspectProjectTestCase(string $source, string $path): array
    {
        try {
            $tokens = array_values(PhpToken::tokenize($source, TOKEN_PARSE));
        } catch (ParseError $error) {
            return [[
                'kind' => 'project-test-case-parse-error',
                'construct' => 'parse-error',
                'path' => $path,
                'line' => max(1, $error->getLine()),
                'column' => 1,
                'diagnostic' => 'DROVE_LARAVEL_PROJECT_TEST_CASE_PARSE_ERROR',
            ]];
        }

        $pairs = $this->pairs($tokens);
        $classes = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_CLASS)) {
                continue;
            }

            $previous = $this->previous($tokens, $index);
            if ($previous !== null && $tokens[$previous]->is(T_DOUBLE_COLON)) {
                continue;
            }

            $open = $this->find($tokens, $index + 1, '{');
            if ($open !== null && isset($pairs[$open])) {
                $classes[] = [$index, $open, $pairs[$open]];
            }
        }

        $blockers = [];

        foreach ($classes as [$classToken, $classOpen, $classClose]) {
            for ($index = $classToken + 1; $index < $classOpen; $index++) {
                if (! $tokens[$index]->is(T_EXTENDS)) {
                    continue;
                }

                $base = $this->next($tokens, $index);
                $blockers[] = $this->blocker(
                    'project-test-case-inheritance',
                    $base === null ? 'extends' : 'extends '.$tokens[$base]->text,
                    $path,
                    $source,
                    $tokens[$index],
                    'DROVE_LARAVEL_PROJECT_TEST_CASE_INHERITANCE',
                );
            }

            $depth = 1;

            for ($index = $classOpen + 1; $index < $classClose; $index++) {
                $token = $tokens[$index];

                if ($token->text === '{') {
                    $depth++;

                    continue;
                }
                if ($token->text === '}') {
                    $depth--;

                    continue;
                }
                if ($depth !== 1) {
                    continue;
                }

                if ($token->is(T_USE)) {
                    $trait = $this->next($tokens, $index);
                    $blockers[] = $this->blocker(
                        'project-test-case-trait',
                        $trait === null ? 'trait use' : 'use '.$tokens[$trait]->text,
                        $path,
                        $source,
                        $token,
                        'DROVE_LARAVEL_PROJECT_TEST_CASE_TRAIT',
                    );
                }

                if (! $token->is(T_FUNCTION)) {
                    continue;
                }

                $method = $this->next($tokens, $index);
                if ($method === null) {
                    continue;
                }
                if (! $tokens[$method]->is(T_STRING)) {
                    continue;
                }

                $name = strtolower($tokens[$method]->text);
                $blockers[] = $this->blocker(
                    in_array($name, ['setup', 'teardown', 'setupbeforeclass', 'teardownafterclass'], true)
                        ? 'project-test-case-lifecycle'
                        : 'project-test-case-helper',
                    $tokens[$method]->text.'()',
                    $path,
                    $source,
                    $tokens[$method],
                    in_array($name, ['setup', 'teardown', 'setupbeforeclass', 'teardownafterclass'], true)
                        ? 'DROVE_LARAVEL_PROJECT_TEST_CASE_LIFECYCLE'
                        : 'DROVE_LARAVEL_PROJECT_TEST_CASE_HELPER',
                );
            }
        }

        return $blockers;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<int, int>
     */
    private function pairs(array $tokens): array
    {
        $pairs = [];
        $stacks = ['(' => [], '{' => [], '[' => []];
        $closing = [')' => '(', '}' => '{', ']' => '['];

        foreach ($tokens as $index => $token) {
            if (isset($stacks[$token->text])) {
                $stacks[$token->text][] = $index;

                continue;
            }

            $open = $closing[$token->text] ?? null;
            if ($open === null) {
                continue;
            }
            if ($stacks[$open] === []) {
                continue;
            }

            $start = array_pop($stacks[$open]);
            $pairs[$start] = $index;
            $pairs[$index] = $start;
        }

        return $pairs;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<int, int>  $pairs
     * @return null|list<string>
     */
    private function arguments(string $source, array $tokens, array $pairs, int $open): ?array
    {
        $close = $pairs[$open] ?? null;

        if ($close === null || $tokens[$open]->text !== '(') {
            return null;
        }

        $arguments = [];
        $start = $tokens[$open]->pos + 1;

        for ($index = $open + 1; $index < $close; $index++) {
            if (isset($pairs[$index])
                && in_array($tokens[$index]->text, ['(', '[', '{'], true)
                && $pairs[$index] < $close) {
                $index = $pairs[$index];

                continue;
            }

            if ($tokens[$index]->text !== ',') {
                continue;
            }

            $arguments[] = trim(substr($source, $start, $tokens[$index]->pos - $start));
            $start = $tokens[$index]->pos + 1;
        }

        $tail = trim(substr($source, $start, $tokens[$close]->pos - $start));

        if ($tail !== '') {
            $arguments[] = $tail;
        }

        return $arguments;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<int, int>  $pairs
     * @return list<array{int, int}>
     */
    private function nativeTestClosures(array $tokens, array $pairs): array
    {
        $ranges = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_FUNCTION)) {
                continue;
            }

            $parameters = $this->next($tokens, $index);
            if ($parameters === null) {
                continue;
            }
            if ($tokens[$parameters]->text !== '(') {
                continue;
            }
            if (! isset($pairs[$parameters])) {
                continue;
            }

            $body = $this->find($tokens, $pairs[$parameters] + 1, '{');
            if ($body === null) {
                continue;
            }
            if (! isset($pairs[$body])) {
                continue;
            }

            $wrapper = null;
            foreach ($pairs as $open => $close) {
                if ($open >= $index) {
                    continue;
                }
                if ($close <= $index) {
                    continue;
                }
                if ($tokens[$open]->text !== '(') {
                    continue;
                }
                if ($wrapper === null || $open > $wrapper) {
                    $wrapper = $open;
                }
            }

            if ($wrapper === null) {
                continue;
            }

            $callee = $this->previous($tokens, $wrapper);
            if ($callee === null) {
                continue;
            }
            if (! $tokens[$callee]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $name = strtolower(ltrim($tokens[$callee]->text, '\\'));
            $parts = explode('\\', $name);
            $method = array_pop($parts);
            if ($parts !== ['drove', 'native']) {
                continue;
            }
            if (! isset(self::TEST_CONTEXT_WRAPPERS[$method])) {
                continue;
            }

            $ranges[] = [$body, $pairs[$body]];
        }

        return $ranges;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<int, int>  $pairs
     * @return list<array{int, int}>
     */
    private function classBodies(array $tokens, array $pairs): array
    {
        $ranges = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_CLASS)) {
                continue;
            }

            $previous = $this->previous($tokens, $index);
            if ($previous !== null && $tokens[$previous]->is(T_DOUBLE_COLON)) {
                continue;
            }

            $open = $this->find($tokens, $index + 1, '{');
            if ($open !== null && isset($pairs[$open])) {
                $ranges[] = [$open, $pairs[$open]];
            }
        }

        return $ranges;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<string, string>
     */
    private function laravelFunctionImports(array $tokens): array
    {
        $imports = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_USE)) {
                continue;
            }

            $function = $this->next($tokens, $index);
            if ($function === null) {
                continue;
            }
            if (! $tokens[$function]->is(T_FUNCTION)) {
                continue;
            }

            $target = $this->next($tokens, $function);
            if ($target === null) {
                continue;
            }
            if (! $tokens[$target]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $canonical = strtolower(ltrim($tokens[$target]->text, '\\'));
            $parts = explode('\\', $canonical);
            if (count($parts) !== 3) {
                continue;
            }
            if (! in_array($parts[0], ['drove', 'pest'], true)) {
                continue;
            }
            if ($parts[1] !== 'laravel') {
                continue;
            }
            if (! isset(self::HELPERS[$parts[2]])) {
                continue;
            }

            $alias = $parts[2];
            $next = $this->next($tokens, $target);
            if ($next !== null && $tokens[$next]->is(T_AS)) {
                $aliasToken = $this->next($tokens, $next);
                if ($aliasToken !== null && $tokens[$aliasToken]->is(T_STRING)) {
                    $alias = strtolower($tokens[$aliasToken]->text);
                }
            }

            $imports[$alias] = $parts[2];
        }

        return $imports;
    }

    /** @param list<PhpToken> $tokens */
    private function unqualifiedHelperCall(array $tokens, int $index): bool
    {
        $token = $tokens[$index];

        if (! $token->is(T_STRING) || ! isset(self::HELPERS[strtolower($token->text)])) {
            return false;
        }

        $open = $this->next($tokens, $index);
        if ($open === null || $tokens[$open]->text !== '(') {
            return false;
        }

        $previous = $this->previous($tokens, $index);

        return $previous === null || ! $tokens[$previous]->is([
            T_FUNCTION,
            T_FN,
            T_NEW,
            T_OBJECT_OPERATOR,
            T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON,
            T_NS_SEPARATOR,
        ]);
    }

    /** @param list<array{int, int}> $ranges */
    private function inside(int $index, array $ranges): bool
    {
        return array_any(
            $ranges,
            static fn (array $range): bool => $range[0] < $index && $index < $range[1],
        );
    }

    /**
     * @return array{kind: string, construct: string, path: string, line: int, column: int, diagnostic: string}
     */
    private function blocker(
        string $kind,
        string $construct,
        string $path,
        string $source,
        PhpToken $token,
        string $diagnostic,
    ): array {
        $lineStart = strrpos(substr($source, 0, $token->pos), "\n");

        return [
            'kind' => $kind,
            'construct' => $construct,
            'path' => $path,
            'line' => $token->line,
            'column' => $token->pos - ($lineStart === false ? -1 : $lineStart),
            'diagnostic' => $diagnostic,
        ];
    }

    /** @param list<PhpToken> $tokens */
    private function next(array $tokens, int $index): ?int
    {
        for ($index++, $count = count($tokens); $index < $count; $index++) {
            if (! $tokens[$index]->isIgnorable()) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<PhpToken> $tokens */
    private function previous(array $tokens, int $index): ?int
    {
        for ($index--; $index >= 0; $index--) {
            if (! $tokens[$index]->isIgnorable()) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<PhpToken> $tokens */
    private function find(array $tokens, int $start, string $text): ?int
    {
        for ($count = count($tokens); $start < $count; $start++) {
            if ($tokens[$start]->text === $text) {
                return $start;
            }
            if ($tokens[$start]->text === ';') {
                return null;
            }
        }

        return null;
    }
}
