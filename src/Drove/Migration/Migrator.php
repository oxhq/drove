<?php

declare(strict_types=1);

namespace Drove\Migration;

use Drove\Bridge\CompatibilityStatus;
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

    /** @var list<string> */
    private const array PORTABLE_DECLARATION_METHODS = [
        'group',
        'skip',
        'timeout',
        'todo',
        'with',
    ];

    public function __construct(
        private Scanner $scanner,
    ) {
        //
    }

    public function migrate(
        string $source,
        string $path = '<memory>',
        ?CodemodOptions $options = null,
    ): MigrationResult {
        $originalHash = hash('sha256', $source);
        $options ??= new CodemodOptions;
        $before = $this->scanner->scan($source, $path);

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
        $edits = [];
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
            if (isset($ambiguous[$sourceName])) {
                continue;
            }
            if ($this->namespacedPestCallIsAmbiguous(
                $token,
                $namespaced,
                $pestImports,
            )) {
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
        $after = $this->scanner->scan($result, $path);
        $blockers = array_values(array_filter(
            $after,
            static fn (Finding $finding): bool => $finding->status !== CompatibilityStatus::Supported,
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
    ): ?array {
        if (! $this->hasBoundTestContext(
            $tokens,
            $call,
            $ambiguous,
            $namespaced,
            $pestImports,
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
            || $methodOpen === null
            || $tokens[$methodOpen]->text !== '(') {
            return null;
        }

        $mapping = $options->matcher($tokens[$member]->text);

        if ($mapping === null) {
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
            var_export($mapping['matcher'], true),
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
                || ! $tokens[$member]->is(T_STRING)
                || $open === null
                || $tokens[$open]->text !== '(') {
                return false;
            }

            $method = strtolower($tokens[$member]->text);
            $portable = $name === 'expect'
                ? in_array($method, ['tobe', 'toequal'], true)
                : in_array($method, self::PORTABLE_DECLARATION_METHODS, true);

            if (! $portable) {
                return false;
            }

            $cursor = $this->closingParenthesis($tokens, $open);
        }
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
