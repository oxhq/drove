<?php

declare(strict_types=1);

namespace Drove\Migration;

use Drove\Compatibility\Status;
use Drove\Native\Surface\SupportedSurface;
use ParseError;
use PhpToken;

final readonly class Migrator
{
    /** @var array<string, string> */
    private const array PORTABLE = [
        'afterall' => 'afterAll',
        'aftereach' => 'afterEach',
        'beforeall' => 'beforeAll',
        'beforeeach' => 'beforeEach',
        'dataset' => 'dataset',
        'describe' => 'describe',
        'expect' => 'expect',
        'it' => 'it',
        'test' => 'test',
    ];

    /** @var array<string, true> */
    private array $declarationMethods;

    /** @var array<string, true> */
    private array $expectationMethods;

    /** @var array<string, true> */
    private array $hookMethods;

    public function __construct(
        private Scanner $scanner,
        ?SupportedSurface $surface = null,
    ) {
        $surface ??= SupportedSurface::load();
        $this->declarationMethods = $surface->methodSet('declaration');
        $this->expectationMethods = $surface->methodSet('expectation');
        $this->hookMethods = $surface->methodSet('hook');
    }

    public function migrate(
        string $source,
        string $path = '<memory>',
        ?CodemodOptions $options = null,
    ): MigrationResult {
        $originalHash = hash('sha256', $source);
        $options ??= new CodemodOptions;
        $before = $this->scanner->scan($source, $path, $options);

        if (array_any(
            $before,
            static fn (Finding $finding): bool => $finding->surface === 'migration.parse-error',
        )) {
            return new MigrationResult(
                $source,
                $originalHash,
                $originalHash,
                [],
                $before,
            );
        }

        try {
            $tokens = array_values(PhpToken::tokenize($source, TOKEN_PARSE));
        } catch (ParseError) {
            return new MigrationResult(
                $source,
                $originalHash,
                $originalHash,
                [],
                $before,
            );
        }

        $pestImports = $this->pestFunctionImports($tokens);
        $namespace = $this->namespaceName($tokens);
        $classImports = $this->classImports($tokens);
        $environmentContextIsUnambiguous = $this->namespaceCount($tokens) <= 1;
        $ambiguous = $this->ambiguousFunctionNames($tokens);

        foreach (array_keys($pestImports) as $import) {
            unset($ambiguous[$import]);
        }

        $namespaced = $this->hasNamespace($tokens);
        $edits = $this->trustedImportEdits($tokens, $path, $options);
        array_push($edits, ...$this->higherOrderExpectationEdits($source, $tokens, $options));
        array_push($edits, ...$this->assertionCountEdits($tokens));
        $environmentCandidates = [];

        foreach ($tokens as $index => $token) {
            $call = $this->call($tokens, $index, $namespaced);

            if ($call === null) {
                continue;
            }

            [$name, $open, $frontend] = $call;
            $sourceName = $name;

            if ($frontend === 'pest'
                && ! str_contains($token->text, '\\')
                && isset($pestImports[$name])) {
                $name = $pestImports[$name];
            }

            $ambiguousPestCall = $frontend === 'pest'
                && ! $options->trustsFunction($path, $sourceName)
                && (isset($ambiguous[$sourceName])
                    || $this->namespacedPestCallIsAmbiguous(
                        $token,
                        $namespaced,
                        $pestImports,
                    ));
            $close = $this->closingParenthesis($tokens, $open);

            if ($frontend === 'pest'
                && ! $ambiguousPestCall
                && $name === 'uses'
                && $environmentContextIsUnambiguous) {
                $candidate = $this->environmentEdit(
                    $source,
                    $path,
                    $tokens,
                    $index,
                    $open,
                    $close,
                    $options,
                    $namespace,
                    $classImports,
                );

                if ($candidate !== null) {
                    $environmentCandidates[] = $candidate;
                }
            }

            if (in_array($frontend, ['native', 'pest'], true)
                && ! $ambiguousPestCall
                && $name === 'expect') {
                $edit = $this->matcherEdit(
                    $source,
                    $tokens,
                    $index,
                    $open,
                    $close,
                    $options,
                    $ambiguous,
                    $namespaced,
                    $pestImports,
                    $path,
                );

                if ($edit !== null) {
                    $edits[] = $edit;
                }
            }

            if ($frontend === 'pest'
                && ! $ambiguousPestCall
                && $name === 'test') {
                $edit = $this->testContextFailEdit(
                    $source,
                    $tokens,
                    $index,
                    $open,
                    $close,
                    $options,
                    $ambiguous,
                    $namespaced,
                    $pestImports,
                    $path,
                );

                if ($edit !== null) {
                    $edits[] = $edit;
                }
            }
        }

        if (count($environmentCandidates) === 1) {
            $edits[] = $environmentCandidates[0];
        }

        foreach ($tokens as $index => $token) {
            $call = $this->call($tokens, $index, $namespaced);

            if ($call === null) {
                continue;
            }

            [$name, $open, $frontend] = $call;
            $sourceName = $name;

            if ($frontend === 'pest'
                && ! str_contains($token->text, '\\')
                && isset($pestImports[$name])) {
                $name = $pestImports[$name];
            }
            if ($frontend !== 'pest') {
                continue;
            }
            if (! $options->trustsFunction($path, $sourceName)
                && (isset($ambiguous[$sourceName])
                    || $this->namespacedPestCallIsAmbiguous(
                        $token,
                        $namespaced,
                        $pestImports,
                    ))) {
                continue;
            }
            if (! isset(self::PORTABLE[$name])) {
                continue;
            }
            if ($this->overlaps($token->pos, strlen($token->text), $edits)) {
                continue;
            }

            $close = $this->closingParenthesis($tokens, $open);
            if ($this->isFirstClassCallable($tokens, $open, $close)) {
                continue;
            }
            if (in_array($name, ['afterall', 'aftereach', 'beforeall', 'beforeeach'], true)
                && $this->hookProxy($tokens, $open, $close, $name)) {
                continue;
            }
            if (! $this->portableChain($tokens, $name, $close)) {
                continue;
            }

            $edits[] = [
                'start' => $token->pos,
                'length' => strlen($token->text),
                'replacement' => '\\Drove\\Native\\'.self::PORTABLE[$name],
                'codemod' => 'native-qualified-call-v1',
            ];
        }

        usort(
            $edits,
            static fn (array $left, array $right): int => $right['start'] <=> $left['start'],
        );
        $applied = [];
        $result = $source;
        $lastStart = strlen($source) + 1;

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
        $after = $this->scanner->scan($result, $path, $options);
        $blockers = array_values(array_filter(
            $after,
            static fn (Finding $finding): bool => $finding->status !== Status::Supported,
        ));

        return new MigrationResult(
            $result,
            $originalHash,
            hash('sha256', $result),
            $applied,
            $blockers,
        );
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $classImports
     * @return array{start: int, length: int, replacement: string, codemod: string}|null
     */
    private function environmentEdit(
        string $source,
        string $path,
        array $tokens,
        int $call,
        int $open,
        int $close,
        CodemodOptions $options,
        string $namespace,
        array $classImports,
    ): ?array {
        $terminator = $this->next($tokens, $close);

        if ($terminator === null || $tokens[$terminator]->text !== ';') {
            return null;
        }

        $argument = trim($this->between($source, $tokens[$open], $tokens[$close]));
        $compact = preg_replace('/\s+/', '', $argument);

        if (! is_string($compact)
            || preg_match(
                '/^((?:\\\\)?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*)::class$/D',
                $compact,
                $matches,
            ) !== 1) {
            return null;
        }

        $mapping = $options->environment(
            $this->resolveClassName($matches[1], $namespace, $classImports),
            $path,
        );

        if ($mapping === null) {
            return null;
        }

        $start = $tokens[$call]->pos;
        $end = $tokens[$terminator]->pos + strlen($tokens[$terminator]->text);

        return [
            'start' => $start,
            'length' => $end - $start,
            'replacement' => sprintf(
                '\\Drove\\Native\\environment(%s, %s);',
                var_export($mapping['name'], true),
                $mapping['factory'],
            ),
            'codemod' => 'uses-environment-map-v1',
        ];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<string, true>  $ambiguous
     * @param  array<string, string>  $pestImports
     * @return array{start: int, length: int, replacement: string, codemod: string}|null
     */
    private function matcherEdit(
        string $source,
        array $tokens,
        int $call,
        int $open,
        int $close,
        CodemodOptions $options,
        array $ambiguous,
        bool $namespaced,
        array $pestImports,
        string $path,
    ): ?array {
        if (! $this->hasBoundTestContext(
            $tokens,
            $call,
            $ambiguous,
            $namespaced,
            $pestImports,
            $options,
            $path,
        )) {
            return null;
        }

        $operator = $this->next($tokens, $close);
        $member = $operator === null ? null : $this->next($tokens, $operator);
        $methodOpen = $member === null ? null : $this->next($tokens, $member);
        $negated = false;

        if ($member !== null
            && $tokens[$member]->is(T_STRING)
            && strtolower($tokens[$member]->text) === 'not') {
            $negated = true;
            $cursor = $member;

            if ($methodOpen !== null && $tokens[$methodOpen]->text === '(') {
                $notClose = $this->closingParenthesis($tokens, $methodOpen);

                if ($this->next($tokens, $methodOpen) !== $notClose) {
                    return null;
                }

                $cursor = $notClose;
            }

            $operator = $this->next($tokens, $cursor);
            $member = $operator === null ? null : $this->next($tokens, $operator);
            $methodOpen = $member === null ? null : $this->next($tokens, $member);
        }

        if ($operator === null
            || ! $tokens[$operator]->is(T_OBJECT_OPERATOR)
            || $member === null
            || ! $tokens[$member]->is(T_STRING)
            || $methodOpen === null
            || $tokens[$methodOpen]->text !== '(') {
            return null;
        }

        $mapping = $options->matcher($tokens[$member]->text);

        if ($mapping === null || $negated && $mapping['negated_matcher'] === null) {
            return null;
        }

        $methodClose = $this->closingParenthesis($tokens, $methodOpen);
        $terminator = $this->next($tokens, $methodClose);

        if ($terminator === null
            || $tokens[$terminator]->text !== ';'
            || $this->containsTopLevelComma($tokens, $open, $close)) {
            return null;
        }

        $actual = trim($this->between($source, $tokens[$open], $tokens[$close]));

        if ($actual === '') {
            return null;
        }

        $arguments = trim($this->between(
            $source,
            $tokens[$methodOpen],
            $tokens[$methodClose],
        ));
        $start = $tokens[$call]->pos;
        $end = $tokens[$methodClose]->pos + strlen($tokens[$methodClose]->text);
        $replacement = sprintf(
            '$this->assertWith(%s, %s, %s%s)',
            var_export($mapping['owner'], true),
            var_export($negated ? $mapping['negated_matcher'] : $mapping['matcher'], true),
            $actual,
            $arguments === '' ? '' : ', '.$arguments,
        );

        return [
            'start' => $start,
            'length' => $end - $start,
            'replacement' => $replacement,
            'codemod' => 'typed-matcher-map-v1',
        ];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<string, true>  $ambiguous
     * @param  array<string, string>  $pestImports
     * @return array{start: int, length: int, replacement: string, codemod: string}|null
     */
    private function testContextFailEdit(
        string $source,
        array $tokens,
        int $call,
        int $open,
        int $close,
        CodemodOptions $options,
        array $ambiguous,
        bool $namespaced,
        array $pestImports,
        string $path,
    ): ?array {
        if (trim($this->between($source, $tokens[$open], $tokens[$close])) !== ''
            || ! $this->hasBoundTestContext(
                $tokens,
                $call,
                $ambiguous,
                $namespaced,
                $pestImports,
                $options,
                $path,
            )) {
            return null;
        }

        $operator = $this->next($tokens, $close);
        $member = $operator === null ? null : $this->next($tokens, $operator);
        $methodOpen = $member === null ? null : $this->next($tokens, $member);

        if ($operator === null
            || ! $tokens[$operator]->is(T_OBJECT_OPERATOR)
            || $member === null
            || ! $tokens[$member]->is(T_STRING)
            || strtolower($tokens[$member]->text) !== 'fail'
            || $methodOpen === null
            || $tokens[$methodOpen]->text !== '(') {
            return null;
        }

        $methodClose = $this->closingParenthesis($tokens, $methodOpen);
        if ($this->containsTopLevelComma($tokens, $methodOpen, $methodClose)) {
            return null;
        }

        $arguments = trim($this->between(
            $source,
            $tokens[$methodOpen],
            $tokens[$methodClose],
        ));
        $start = $tokens[$call]->pos;
        $end = $tokens[$methodClose]->pos + strlen($tokens[$methodClose]->text);

        return [
            'start' => $start,
            'length' => $end - $start,
            'replacement' => '\\Drove\\Native\\TestContext::fail('.$arguments.')',
            'codemod' => 'native-test-context-fail-v1',
        ];
    }

    /**
     * The matcher codemod emits `$this->assertWith(...)`, so it is only safe
     * inside a non-static closure that Drove binds to a per-test TestContext.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, true>  $ambiguous
     * @param  array<string, string>  $pestImports
     */
    private function hasBoundTestContext(
        array $tokens,
        int $call,
        array $ambiguous,
        bool $namespaced,
        array $pestImports,
        CodemodOptions $options,
        string $path,
    ): bool {
        $closure = $this->innermostClosureContaining($tokens, $call);

        if ($closure === null) {
            return false;
        }

        [$closureToken, , $closureEnd] = $closure;
        $previous = $this->previous($tokens, $closureToken);

        if ($previous !== null && $tokens[$previous]->is(T_STATIC)) {
            return false;
        }

        $candidate = null;

        foreach ($tokens as $index => $token) {
            if ($index >= $closureToken) {
                break;
            }

            $wrapper = $this->call($tokens, $index, $namespaced);

            if ($wrapper === null) {
                continue;
            }

            [$name, $open, $frontend] = $wrapper;
            $sourceName = $name;

            if ($frontend === 'pest'
                && ! str_contains($token->text, '\\')
                && isset($pestImports[$name])) {
                $name = $pestImports[$name];
            }
            if (! in_array($frontend, ['native', 'pest'], true)) {
                continue;
            }
            if (! in_array($name, ['test', 'it', 'beforeeach', 'aftereach'], true)) {
                continue;
            }
            if ($frontend === 'pest'
                && ! $options->trustsFunction($path, $sourceName)
                && (isset($ambiguous[$sourceName])
                    || $this->namespacedPestCallIsAmbiguous(
                        $token,
                        $namespaced,
                        $pestImports,
                    ))) {
                continue;
            }

            $close = $this->closingParenthesis($tokens, $open);
            if ($open >= $closureToken) {
                continue;
            }
            if ($close < $closureEnd) {
                continue;
            }
            if (! $this->isDirectCallArgument($tokens, $open, $closureToken)) {
                continue;
            }

            if ($candidate === null || $open > $candidate) {
                $candidate = $open;
            }
        }

        return $candidate !== null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<array{start: int, length: int, replacement: string, codemod: string}>
     */
    private function trustedImportEdits(
        array $tokens,
        string $path,
        CodemodOptions $options,
    ): array {
        $edits = [];
        $depth = 0;
        $importDepth = 0;

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
                    if ($tokens[$cursor]->text === '{') {
                        $importDepth = $depth + 1;

                        break;
                    }

                    if ($tokens[$cursor]->text === ';') {
                        $importDepth = $depth;

                        break;
                    }
                }
            }

            if ($token->is(T_USE) && $depth === $importDepth) {
                $first = $this->next($tokens, $index);

                $functionImport = $first !== null && $tokens[$first]->is(T_FUNCTION);
                $symbol = $functionImport ? $this->next($tokens, $first) : $first;

                if ($symbol !== null
                    && $tokens[$symbol]->text !== '('
                    && ($first === null || ! $tokens[$first]->is(T_CONST))) {
                    $statement = '';
                    $end = null;

                    for ($cursor = $symbol, $count = count($tokens); $cursor < $count; $cursor++) {
                        if ($tokens[$cursor]->text === ';') {
                            $end = $tokens[$cursor];

                            break;
                        }

                        if ($tokens[$cursor]->is(T_AS)) {
                            $statement .= ' as ';
                        } elseif (! $tokens[$cursor]->isIgnorable()) {
                            $statement .= $tokens[$cursor]->text;
                        }
                    }

                    if ($end instanceof PhpToken
                        && preg_match(
                            '/^(\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*)(?:\s+as\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*))?$/iD',
                            $statement,
                            $match,
                        ) === 1) {
                        $source = ltrim($match[1], '\\');
                        $target = $options->import($path, $source);

                        if ($target !== null) {
                            $parts = explode('\\', $source);
                            $alias = $match[2] ?? $parts[array_key_last($parts)];
                            $edits[] = [
                                'start' => $token->pos,
                                'length' => $end->pos + strlen($end->text) - $token->pos,
                                'replacement' => sprintf(
                                    $functionImport ? 'use function \\%s as %s;' : 'use \\%s as %s;',
                                    $target,
                                    $alias,
                                ),
                                'codemod' => $functionImport
                                    ? 'trusted-function-import-map-v1'
                                    : 'trusted-import-map-v1',
                            ];
                        }
                    }
                }
            }

            if ($token->text === '{') {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }
        }

        return $edits;
    }

    /** @param list<PhpToken> $tokens */
    private function hookProxy(array $tokens, int $open, int $close, string $name): bool
    {
        $argument = $this->next($tokens, $open);
        $chain = $this->chain($tokens, $close);

        if ($argument !== null && $argument !== $close && $chain === []) {
            return false;
        }

        if (! in_array($name, ['beforeeach', 'aftereach'], true)
            || $chain === []) {
            return true;
        }

        $expectation = false;

        foreach ($chain as $method) {
            if (! $method['called'] || $method['nullsafe']) {
                return true;
            }

            if (! $expectation && $method['name'] === 'expect') {
                $expectation = true;

                continue;
            }

            if ($expectation) {
                if (! isset($this->hookMethods[$method['name']])) {
                    return true;
                }

                continue;
            }

            if (! isset($this->declarationMethods[$method['name']])
                || $method['name'] === 'with') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<array{name: string, called: bool, nullsafe: bool}>
     */
    private function chain(array $tokens, int $close): array
    {
        $chain = [];
        $cursor = $close;

        while (true) {
            $operator = $this->next($tokens, $cursor);

            if ($operator === null
                || ! $tokens[$operator]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                break;
            }

            $member = $this->next($tokens, $operator);

            if ($member === null || ! $tokens[$member]->is(T_STRING)) {
                break;
            }

            $open = $this->next($tokens, $member);
            $called = $open !== null && $tokens[$open]->text === '(';
            $chain[] = [
                'name' => strtolower($tokens[$member]->text),
                'called' => $called,
                'nullsafe' => $tokens[$operator]->is(T_NULLSAFE_OBJECT_OPERATOR),
            ];

            if (! $called && strtolower($tokens[$member]->text) !== 'not') {
                break;
            }

            $cursor = $called
                ? $this->closingParenthesis($tokens, $open)
                : $member;
        }

        return $chain;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array{int, int, int}|null
     */
    private function innermostClosureContaining(array $tokens, int $target): ?array
    {
        $candidate = null;

        foreach ($tokens as $index => $token) {
            if ($index >= $target) {
                break;
            }

            if (! $token->is([T_FUNCTION, T_FN])) {
                continue;
            }

            $range = $this->closureRange($tokens, $index);
            if ($range === null) {
                continue;
            }
            if ($target <= $range[0]) {
                continue;
            }
            if ($target >= $range[1]) {
                continue;
            }

            $candidate = [$index, $range[0], $range[1]];
        }

        return $candidate;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array{int, int}|null
     */
    private function closureRange(array $tokens, int $function): ?array
    {
        if ($tokens[$function]->is(T_FUNCTION)) {
            $cursor = $this->next($tokens, $function);

            if ($cursor !== null && $tokens[$cursor]->text === '&') {
                $cursor = $this->next($tokens, $cursor);
            }

            if ($cursor === null || $tokens[$cursor]->text !== '(') {
                return null;
            }

            for ($index = $cursor, $count = count($tokens); $index < $count; $index++) {
                if ($tokens[$index]->text !== '{') {
                    continue;
                }

                return [$index, $this->closingDelimiter($tokens, $index, '{', '}')];
            }

            return null;
        }

        $arrow = null;

        for ($index = $function + 1, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]->is(T_DOUBLE_ARROW)) {
                $arrow = $index;

                break;
            }
        }

        if ($arrow === null) {
            return null;
        }

        $depth = 0;

        for ($index = $arrow + 1, $count = count($tokens); $index < $count; $index++) {
            $text = $tokens[$index]->text;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return [$arrow, $index];
                }

                $depth--;
            } elseif ($depth === 0 && in_array($text, [',', ';'], true)) {
                return [$arrow, $index];
            }
        }

        return [$arrow, count($tokens)];
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function isDirectCallArgument(array $tokens, int $open, int $argument): bool
    {
        $depth = 0;

        for ($index = $open + 1; $index < $argument; $index++) {
            if (in_array($tokens[$index]->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($tokens[$index]->text, [')', ']', '}'], true)) {
                $depth--;
            }
        }

        return $depth === 0;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function closingDelimiter(
        array $tokens,
        int $open,
        string $opening,
        string $closing,
    ): int {
        $depth = 0;

        for ($index = $open, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]->text === $opening) {
                $depth++;
            } elseif ($tokens[$index]->text === $closing) {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return $open;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function portableChain(array $tokens, string $name, int $close): bool
    {
        if (in_array($name, ['afterall', 'aftereach', 'beforeall', 'beforeeach'], true)) {
            $expectation = false;

            foreach ($this->chain($tokens, $close) as $method) {
                if (! $method['called'] || $method['nullsafe']) {
                    return false;
                }

                if (! $expectation && $method['name'] === 'expect') {
                    $expectation = true;

                    continue;
                }

                if ($expectation) {
                    if (! isset($this->hookMethods[$method['name']])) {
                        return false;
                    }

                    continue;
                }

                if (! isset($this->declarationMethods[$method['name']])
                    || $method['name'] === 'with') {
                    return false;
                }
            }

            return true;
        }

        $cursor = $close;

        while (true) {
            $operator = $this->next($tokens, $cursor);

            if ($operator === null
                || ! $tokens[$operator]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                return true;
            }

            if ($tokens[$operator]->is(T_NULLSAFE_OBJECT_OPERATOR)) {
                return false;
            }

            $member = $this->next($tokens, $operator);
            $open = $member === null ? null : $this->next($tokens, $member);

            if ($member === null
                || ! $tokens[$member]->is(T_STRING)) {
                return false;
            }

            $method = strtolower($tokens[$member]->text);
            $called = $open !== null && $tokens[$open]->text === '(';
            $portable = $name === 'expect'
                ? $called
                    ? isset($this->expectationMethods[$method])
                    : true
                : isset($this->declarationMethods[$method]);

            if (! $portable || ($name !== 'expect' && ! $called)) {
                return false;
            }

            $cursor = $called
                ? $this->closingParenthesis($tokens, $open)
                : $member;
        }
    }

    /**
     * Pest's property-style expectation syntax is migration input, not a
     * native runtime protocol. Broadcast chains lower to an explicit callback,
     * zero-argument matcher properties lower to calls, and JSON properties
     * lower to property().
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{start: int, length: int, replacement: string, codemod: string}>
     */
    private function higherOrderExpectationEdits(
        string $source,
        array $tokens,
        CodemodOptions $options,
    ): array {
        $edits = [];
        $broadcastRanges = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_OBJECT_OPERATOR)) {
                continue;
            }

            $member = $this->next($tokens, $index);
            if ($member === null) {
                continue;
            }
            if (! $tokens[$member]->is(T_STRING)) {
                continue;
            }
            if (strtolower($tokens[$member]->text) !== 'each') {
                continue;
            }

            $afterMember = $this->next($tokens, $member);
            $segmentCursor = $afterMember;

            if ($afterMember !== null && $tokens[$afterMember]->text === '(') {
                $eachClose = $this->closingParenthesis($tokens, $afterMember);
                $argument = $this->next($tokens, $afterMember);

                if ($argument !== $eachClose) {
                    continue;
                }

                $segmentCursor = $this->next($tokens, $eachClose);
            }

            $chain = '';
            $last = null;

            while ($segmentCursor !== null && $tokens[$segmentCursor]->is(T_OBJECT_OPERATOR)) {
                $chainMember = $this->next($tokens, $segmentCursor);

                if ($chainMember === null || ! $tokens[$chainMember]->is(T_STRING)) {
                    break;
                }

                $method = strtolower($tokens[$chainMember]->text);

                if (in_array($method, ['and', 'each'], true)) {
                    break;
                }

                $open = $this->next($tokens, $chainMember);

                if ($open !== null && $tokens[$open]->text === '(') {
                    $methodClose = $this->closingParenthesis($tokens, $open);
                    $arguments = substr(
                        $source,
                        $tokens[$open]->pos,
                        $tokens[$methodClose]->pos + strlen($tokens[$methodClose]->text) - $tokens[$open]->pos,
                    );
                    $chain .= '->'.$tokens[$chainMember]->text.$arguments;
                    $last = $methodClose;
                    $segmentCursor = $this->next($tokens, $methodClose);

                    continue;
                }

                $chain .= '->'.$tokens[$chainMember]->text.'()';
                $last = $chainMember;
                $segmentCursor = $this->next($tokens, $chainMember);
            }
            if ($chain === '') {
                continue;
            }
            if ($last === null) {
                continue;
            }

            $start = $token->pos;
            $end = $tokens[$last]->pos + strlen($tokens[$last]->text);
            $edits[] = [
                'start' => $start,
                'length' => $end - $start,
                'replacement' => '->each(static function (\\Drove\\Native\\Expectation $expectation): void { $expectation'.$chain.'; })',
                'codemod' => 'native-higher-order-each-v1',
            ];
            $broadcastRanges[] = [$start, $end];
        }

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_OBJECT_OPERATOR)) {
                continue;
            }
            if (array_any(
                $broadcastRanges,
                static fn (array $range): bool => $token->pos >= $range[0] && $token->pos < $range[1],
            )) {
                continue;
            }
            $member = $this->next($tokens, $index);
            $afterMember = $member === null ? null : $this->next($tokens, $member);
            if ($member === null) {
                continue;
            }
            if (! $tokens[$member]->is(T_STRING)) {
                continue;
            }
            if ($afterMember !== null && $tokens[$afterMember]->text === '(') {
                continue;
            }

            $method = strtolower($tokens[$member]->text);

            if ($method === 'not') {
                $nextOperator = $this->next($tokens, $member);
                $nextMember = $nextOperator === null ? null : $this->next($tokens, $nextOperator);
                $nextOpen = $nextMember === null ? null : $this->next($tokens, $nextMember);

                if ($nextOperator !== null
                    && $tokens[$nextOperator]->is(T_OBJECT_OPERATOR)
                    && $nextMember !== null
                    && $tokens[$nextMember]->is(T_STRING)
                    && $nextOpen !== null
                    && $tokens[$nextOpen]->text === '('
                    && $options->matcher($tokens[$nextMember]->text) !== null) {
                    continue;
                }
            }

            if ($method === 'not'
                || isset($this->expectationMethods[$method])) {
                $edits[] = [
                    'start' => $tokens[$member]->pos,
                    'length' => strlen($tokens[$member]->text),
                    'replacement' => $tokens[$member]->text.'()',
                    'codemod' => 'native-higher-order-call-v1',
                ];

                continue;
            }

            $previous = $this->previous($tokens, $index);
            if ($previous === null) {
                continue;
            }
            if ($tokens[$previous]->text !== ')') {
                continue;
            }

            $open = $this->openingParenthesis($tokens, $previous);
            $json = $open === null ? null : $this->previous($tokens, $open);
            if ($json === null) {
                continue;
            }
            if (! $tokens[$json]->is(T_STRING)) {
                continue;
            }
            if (strtolower($tokens[$json]->text) !== 'json') {
                continue;
            }

            $edits[] = [
                'start' => $tokens[$member]->pos,
                'length' => strlen($tokens[$member]->text),
                'replacement' => "property('".str_replace("'", "\\'", $tokens[$member]->text)."')",
                'codemod' => 'native-higher-order-property-v1',
            ];
        }

        return $edits;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<array{start: int, length: int, replacement: string, codemod: string}>
     */
    private function assertionCountEdits(array $tokens): array
    {
        $edits = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_STATIC)) {
                continue;
            }

            $operator = $this->next($tokens, $index);
            $member = $operator === null ? null : $this->next($tokens, $operator);
            $open = $member === null ? null : $this->next($tokens, $member);
            if ($operator === null) {
                continue;
            }
            if (! $tokens[$operator]->is(T_DOUBLE_COLON)) {
                continue;
            }
            if ($member === null) {
                continue;
            }
            if (! $tokens[$member]->is(T_STRING)) {
                continue;
            }
            if (strtolower($tokens[$member]->text) !== 'getcount') {
                continue;
            }
            if ($open === null) {
                continue;
            }
            if ($tokens[$open]->text !== '(') {
                continue;
            }

            $close = $this->closingParenthesis($tokens, $open);

            if ($this->next($tokens, $open) !== $close) {
                continue;
            }

            $edits[] = [
                'start' => $token->pos,
                'length' => $tokens[$close]->pos + strlen($tokens[$close]->text) - $token->pos,
                'replacement' => '$this->assertionCount()',
                'codemod' => 'native-assertion-count-v1',
            ];
        }

        return $edits;
    }

    /** @param list<PhpToken> $tokens */
    private function openingParenthesis(array $tokens, int $close): ?int
    {
        $depth = 0;

        for ($index = $close; $index >= 0; $index--) {
            if ($tokens[$index]->text === ')') {
                $depth++;
            } elseif ($tokens[$index]->text === '(' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array{string, int, 'native'|'other'|'pest'}|null
     */
    private function call(array $tokens, int $index, bool $namespaced): ?array
    {
        $token = $tokens[$index];

        if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
            return null;
        }

        $open = $this->next($tokens, $index);

        if ($open === null || $tokens[$open]->text !== '(') {
            return null;
        }

        $previous = $this->previous($tokens, $index);

        if ($previous !== null
            && ($tokens[$previous]->is([
                T_FUNCTION,
                T_FN,
                T_NEW,
                T_OBJECT_OPERATOR,
                T_NULLSAFE_OBJECT_OPERATOR,
                T_DOUBLE_COLON,
            ])
                || in_array($tokens[$previous]->text, ['->', '?->', '::'], true))) {
            return null;
        }

        $canonical = strtolower(ltrim($token->text, '\\'));
        $parts = explode('\\', $canonical);
        $name = end($parts);

        if (count($parts) === 3
            && $parts[0] === 'drove'
            && $parts[1] === 'native') {
            return $namespaced && ! str_starts_with($token->text, '\\')
                ? [$name, $open, 'other']
                : [$name, $open, 'native'];
        }

        if ((count($parts) === 2 && $parts[0] === 'pest')
            || count($parts) === 1) {
            return [$name, $open, 'pest'];
        }

        return [$name, $open, 'other'];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<string, true>
     */
    private function ambiguousFunctionNames(array $tokens): array
    {
        $ambiguous = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_FUNCTION)) {
                $name = $this->next($tokens, $index);

                if ($name !== null && $tokens[$name]->is(T_STRING)) {
                    $ambiguous[strtolower($tokens[$name]->text)] = true;
                }
            }

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

            for ($cursor = $function + 1, $count = count($tokens); $cursor < $count; $cursor++) {
                if ($tokens[$cursor]->text === ';') {
                    break;
                }

                if ($tokens[$cursor]->is(T_AS)) {
                    $alias = $this->next($tokens, $cursor);

                    if ($alias !== null && $tokens[$alias]->is(T_STRING)) {
                        $ambiguous[strtolower($tokens[$alias]->text)] = true;
                    }
                } elseif ($tokens[$cursor]->is([
                    T_STRING,
                    T_NAME_QUALIFIED,
                    T_NAME_FULLY_QUALIFIED,
                ])) {
                    $next = $this->next($tokens, $cursor);

                    if ($next !== null && in_array($tokens[$next]->text, [',', ';', '}'], true)) {
                        $parts = explode('\\', ltrim($tokens[$cursor]->text, '\\'));
                        $ambiguous[strtolower($parts[array_key_last($parts)])] = true;
                    }
                }
            }
        }

        return $ambiguous;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<string, string>
     */
    private function pestFunctionImports(array $tokens): array
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

            for ($cursor = $function + 1, $count = count($tokens); $cursor < $count; $cursor++) {
                $target = $tokens[$cursor];

                if ($target->text === ';') {
                    break;
                }

                if (! $target->is([
                    T_STRING,
                    T_NAME_QUALIFIED,
                    T_NAME_FULLY_QUALIFIED,
                ])) {
                    continue;
                }

                $canonical = strtolower(ltrim($target->text, '\\'));
                $parts = explode('\\', $canonical);
                if (count($parts) !== 2) {
                    continue;
                }
                if ($parts[0] !== 'pest') {
                    continue;
                }

                $name = $parts[1];
                $alias = $name;
                $next = $this->next($tokens, $cursor);

                if ($next !== null && $tokens[$next]->is(T_AS)) {
                    $aliasToken = $this->next($tokens, $next);
                    if ($aliasToken === null) {
                        continue;
                    }
                    if (! $tokens[$aliasToken]->is(T_STRING)) {
                        continue;
                    }

                    $alias = strtolower($tokens[$aliasToken]->text);
                }

                if (isset(self::PORTABLE[$name]) || $name === 'uses') {
                    $imports[$alias] = $name;
                }
            }
        }

        return $imports;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function namespaceName(array $tokens): string
    {
        foreach ($tokens as $index => $token) {
            if (! $token->is(T_NAMESPACE)) {
                continue;
            }

            $name = '';

            for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
                if (in_array($tokens[$cursor]->text, [';', '{'], true)) {
                    return trim($name, '\\');
                }

                if (! $tokens[$cursor]->isIgnorable()) {
                    $name .= $tokens[$cursor]->text;
                }
            }
        }

        return '';
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<string, string>
     */
    private function classImports(array $tokens): array
    {
        $imports = [];
        $depth = 0;
        $importDepth = 0;

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
                    if ($tokens[$cursor]->text === '{') {
                        $importDepth = $depth + 1;

                        break;
                    }

                    if ($tokens[$cursor]->text === ';') {
                        $importDepth = $depth;

                        break;
                    }
                }
            }

            if ($token->is(T_USE) && $depth === $importDepth) {
                $next = $this->next($tokens, $index);

                if ($next !== null
                    && $tokens[$next]->text !== '('
                    && ! $tokens[$next]->is([T_FUNCTION, T_CONST])) {
                    foreach ($this->classImportItems($tokens, $index) as $alias => $class) {
                        $imports[$alias] = $class;
                    }
                }
            }

            if ($token->text === '{') {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }
        }

        ksort($imports, SORT_STRING);

        return $imports;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<string, string>
     */
    private function classImportItems(array $tokens, int $use): array
    {
        $statement = '';

        for ($cursor = $use + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            if ($token->text === ';') {
                break;
            }

            if ($token->is(T_AS)) {
                $statement .= ' as ';
            } elseif (! $token->isIgnorable()) {
                $statement .= $token->text;
            }
        }

        $prefix = '';
        $items = $statement;

        if (preg_match('/^(.*)\\\\\{(.*)\}$/sD', $statement, $group) === 1) {
            $prefix = rtrim($group[1], '\\').'\\';
            $items = $group[2];
        }

        $imports = [];

        foreach (explode(',', $items) as $item) {
            if (preg_match(
                '/^(.+?)(?:\s+as\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*))?$/isD',
                trim($item),
                $match,
            ) !== 1) {
                continue;
            }

            $class = trim($prefix.$match[1], '\\');
            $parts = explode('\\', $class);
            $alias = $match[2] ?? $parts[array_key_last($parts)];
            $imports[strtolower($alias)] = $class;
        }

        return $imports;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function resolveClassName(string $class, string $namespace, array $imports): string
    {
        if (str_starts_with($class, '\\')) {
            return ltrim($class, '\\');
        }

        if (str_starts_with(strtolower($class), 'namespace\\')) {
            $relative = substr($class, strlen('namespace\\'));

            return ltrim($namespace.'\\'.$relative, '\\');
        }

        $parts = explode('\\', $class);
        $alias = strtolower($parts[0]);

        if (isset($imports[$alias])) {
            array_shift($parts);

            return $imports[$alias].($parts === [] ? '' : '\\'.implode('\\', $parts));
        }

        return ltrim($namespace.'\\'.$class, '\\');
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function hasNamespace(array $tokens): bool
    {
        return $this->namespaceCount($tokens) > 0;
    }

    /**
     * Class imports are resolved once per source file. More than one namespace
     * would require a position-aware import table, so environment migration
     * must fail closed instead of selecting an import from another namespace.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function namespaceCount(array $tokens): int
    {
        return count(array_filter(
            $tokens,
            static fn (PhpToken $token): bool => $token->is(T_NAMESPACE),
        ));
    }

    /**
     * @param  array<string, string>  $pestImports
     */
    private function namespacedPestCallIsAmbiguous(
        PhpToken $token,
        bool $namespaced,
        array $pestImports,
    ): bool {
        if (! $namespaced) {
            return false;
        }

        if (! str_contains($token->text, '\\')) {
            return ! isset($pestImports[strtolower($token->text)]);
        }

        return ! str_starts_with(strtolower($token->text), '\\pest\\');
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function closingParenthesis(array $tokens, int $open): int
    {
        $depth = 0;

        for ($index = $open, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]->text === '(') {
                $depth++;
            } elseif ($tokens[$index]->text === ')') {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return $open;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function containsTopLevelComma(array $tokens, int $open, int $close): bool
    {
        $depth = 0;

        for ($index = $open + 1; $index < $close; $index++) {
            if (in_array($tokens[$index]->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($tokens[$index]->text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($tokens[$index]->text === ',' && $depth === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function isFirstClassCallable(array $tokens, int $open, int $close): bool
    {
        $argument = $this->next($tokens, $open);

        return $argument !== null
            && $argument < $close
            && $tokens[$argument]->is(T_ELLIPSIS)
            && $this->next($tokens, $argument) === $close;
    }

    private function between(string $source, PhpToken $open, PhpToken $close): string
    {
        $start = $open->pos + strlen($open->text);

        return substr($source, $start, $close->pos - $start);
    }

    /**
     * @param  list<array{start: int, length: int, replacement: string, codemod: string}>  $edits
     */
    private function overlaps(int $start, int $length, array $edits): bool
    {
        $end = $start + $length;

        return array_any(
            $edits,
            static fn (array $edit): bool => $start < $edit['start'] + $edit['length']
                && $end > $edit['start'],
        );
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function next(array $tokens, int $index): ?int
    {
        for ($index++, $count = count($tokens); $index < $count; $index++) {
            if (! $tokens[$index]->isIgnorable()) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function previous(array $tokens, int $index): ?int
    {
        for ($index--; $index >= 0; $index--) {
            if (! $tokens[$index]->isIgnorable()) {
                return $index;
            }
        }

        return null;
    }
}
