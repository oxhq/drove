<?php

declare(strict_types=1);

namespace Drove\Native\Surface;

use JsonException;
use JsonSerializable;
use ParseError;
use PhpToken;
use RuntimeException;
use UnexpectedValueException;

final readonly class SupportedSurface implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    private function __construct(
        private array $manifest,
    ) {
        $this->validate();
    }

    public static function load(?string $path = null): self
    {
        $path ??= __DIR__.'/supported-surface.json';
        $contents = is_file($path) && is_readable($path)
            ? file_get_contents($path)
            : false;

        if (! is_string($contents)) {
            throw new RuntimeException('Drove could not read its native supported-surface manifest.');
        }

        try {
            $manifest = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'The Drove native supported-surface manifest is not valid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($manifest)) {
            throw new UnexpectedValueException(
                'The Drove native supported-surface manifest must be an object.',
            );
        }

        return new self($manifest);
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->manifest;
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(
            $this->canonical($this->manifest),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @return list<array{
     *     code: string,
     *     construct: string,
     *     path: string,
     *     line: int,
     *     column: int,
     *     message: string
     * }>
     */
    public function scanFile(string $path): array
    {
        $source = is_file($path) && is_readable($path)
            ? file_get_contents($path)
            : false;

        if (! is_string($source)) {
            throw new RuntimeException('Drove could not read native source for compatibility scanning.');
        }

        return $this->scan($source, $path);
    }

    /**
     * @return list<array{
     *     code: string,
     *     construct: string,
     *     path: string,
     *     line: int,
     *     column: int,
     *     message: string
     * }>
     */
    public function scan(string $source, string $path = '<memory>'): array
    {
        if ($path === '' || preg_match('//u', $path) !== 1) {
            throw new RuntimeException('Drove compatibility scans require a valid UTF-8 source path.');
        }

        try {
            $tokens = array_values(PhpToken::tokenize($source, TOKEN_PARSE));
        } catch (ParseError $error) {
            return [$this->diagnostic(
                $this->code('parse'),
                'parse-error',
                $path,
                max(1, $error->getLine()),
                1,
            )];
        }

        $declarations = $this->names('functions', 'declaration');
        $expectations = $this->names('functions', 'expectation');
        $unsupportedFunctions = $this->codes('functions');
        $unsupportedMethods = $this->codes('methods');
        $knownFunctions = array_fill_keys([
            ...array_keys($declarations),
            ...array_keys($expectations),
            ...array_keys($unsupportedFunctions),
            ...array_keys($this->names('functions', 'dataset')),
            ...array_keys($this->names('functions', 'environment')),
            ...array_keys($this->names('functions', 'hook')),
        ], true);
        $namespaces = $this->namespaceContexts($tokens);
        $functionAliases = $this->functionAliases(
            $tokens,
            $knownFunctions,
            $namespaces,
        );
        $diagnostics = [];

        foreach ($tokens as $index => $token) {
            $call = $this->call($tokens, $index);

            if ($call === null) {
                continue;
            }

            [$name, $open, $qualified] = $call;
            $frontend = null;
            $namespace = $namespaces[$index] ?? ['name' => '', 'scope' => 0];

            if ($qualified) {
                $resolved = $this->qualifiedFunction(
                    $token->text,
                    $namespace['name'],
                );

                if ($resolved === null) {
                    continue;
                }

                ['name' => $name, 'frontend' => $frontend] = $resolved;
            } elseif (array_key_exists($name, $functionAliases[$namespace['scope']] ?? [])) {
                $alias = $functionAliases[$namespace['scope']][$name];

                if ($alias === false) {
                    $diagnostics[] = $this->at(
                        $this->code('function_alias'),
                        'ambiguous-function-alias',
                        $path,
                        $source,
                        $token,
                    );

                    continue;
                }

                if ($alias === null) {
                    continue;
                }

                ['name' => $name, 'frontend' => $frontend] = $alias;
            } elseif (isset($knownFunctions[$name])) {
                $frontend = $namespace['name'] === 'drove\\native'
                    ? 'native'
                    : 'pest';
            }

            if ($frontend === 'pest') {
                $diagnostics[] = $this->at(
                    $this->code('frontend'),
                    'Pest\\'.$name,
                    $path,
                    $source,
                    $token,
                );

                continue;
            }

            if (isset($unsupportedFunctions[$name])) {
                $diagnostics[] = $this->at(
                    $unsupportedFunctions[$name],
                    $name,
                    $path,
                    $source,
                    $token,
                );
            }

            $context = isset($declarations[$name])
                ? 'declaration'
                : (isset($expectations[$name]) ? 'expectation' : null);

            if ($context === null) {
                continue;
            }

            $close = $this->closingParenthesis($tokens, $open);

            if ($this->isFirstClassCallable($tokens, $open, $close)) {
                $diagnostics[] = $this->at(
                    $this->code('function_alias'),
                    'dynamic-function-alias',
                    $path,
                    $source,
                    $token,
                );

                continue;
            }

            array_push(
                $diagnostics,
                ...$this->scanChain(
                    $tokens,
                    $close,
                    $context,
                    $unsupportedMethods,
                    $path,
                    $source,
                ),
            );
        }

        usort($diagnostics, static fn (array $left, array $right): int => [
            $left['path'],
            $left['line'],
            $left['column'],
            $left['code'],
            $left['construct'],
        ] <=> [
            $right['path'],
            $right['line'],
            $right['column'],
            $right['code'],
            $right['construct'],
        ]);

        return $diagnostics;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->manifest;
    }

    private function validate(): void
    {
        $this->exactKeys(
            $this->manifest,
            ['api', 'diagnostics', 'functions', 'methods', 'schema', 'surface', 'unsupported'],
            'manifest',
        );

        if (($this->manifest['schema'] ?? null) !== 1
            || ($this->manifest['api'] ?? null) !== 1
            || ($this->manifest['surface'] ?? null) !== 'drove-native') {
            $this->invalid('identity');
        }

        $functions = $this->section('functions');
        $this->exactKeys(
            $functions,
            ['dataset', 'declaration', 'environment', 'expectation', 'hook'],
            'functions',
        );

        foreach ($functions as $name => $values) {
            $this->identifierList($values, 'functions.'.$name);
        }

        $methods = $this->section('methods');
        $this->exactKeys($methods, ['context', 'declaration', 'expectation'], 'methods');

        foreach ($methods as $name => $values) {
            $this->identifierList($values, 'methods.'.$name);
        }

        $unsupported = $this->section('unsupported');
        $this->exactKeys($unsupported, ['functions', 'methods'], 'unsupported');
        $this->codeMap($unsupported['functions'] ?? null, 'unsupported.functions');
        $this->codeMap($unsupported['methods'] ?? null, 'unsupported.methods');
        $diagnostics = $this->section('diagnostics');
        $this->exactKeys(
            $diagnostics,
            ['declaration_method', 'expectation_method', 'frontend', 'function_alias', 'higher_order', 'parse'],
            'diagnostics',
        );
        $this->codeMap($diagnostics, 'diagnostics');
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array{string, int, bool}|null
     */
    private function call(array $tokens, int $index): ?array
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

        return [
            strtolower($token->text),
            $open,
            str_contains(ltrim($token->text, '\\'), '\\'),
        ];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<int, array{name: string, scope: int}>
     */
    private function namespaceContexts(array $tokens): array
    {
        $contexts = [];
        $namespace = '';
        $scope = 0;
        $nextScope = 1;
        $braceDepth = 0;
        $restoreAtDepth = [];

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $contexts[$index] = ['name' => $namespace, 'scope' => $scope];
            $token = $tokens[$index];

            if ($token->is(T_NAMESPACE)) {
                $cursor = $this->next($tokens, $index);
                $declared = '';

                if ($cursor !== null
                    && $tokens[$cursor]->is([T_STRING, T_NAME_QUALIFIED])) {
                    $declared = strtolower($tokens[$cursor]->text);
                    $cursor = $this->next($tokens, $cursor);
                }

                if ($cursor !== null && $tokens[$cursor]->text === ';') {
                    $namespace = $declared;
                    $scope = $nextScope++;
                    $index = $cursor;

                    continue;
                }

                if ($cursor !== null && $tokens[$cursor]->text === '{') {
                    $braceDepth++;
                    $restoreAtDepth[$braceDepth] = [$namespace, $scope];
                    $namespace = $declared;
                    $scope = $nextScope++;
                    $index = $cursor;

                    continue;
                }
            }

            if ($token->text === '{') {
                $braceDepth++;
            } elseif ($token->text === '}') {
                if (array_key_exists($braceDepth, $restoreAtDepth)) {
                    [$namespace, $scope] = $restoreAtDepth[$braceDepth];
                    unset($restoreAtDepth[$braceDepth]);
                }

                $braceDepth--;
            }
        }

        return $contexts;
    }

    /**
     * @return array{name: string, frontend: 'native'|'pest'}|null
     */
    private function qualifiedFunction(string $name, string $namespace): ?array
    {
        $lower = strtolower($name);

        if (str_starts_with($lower, '\\')) {
            $canonical = ltrim($lower, '\\');
        } elseif (str_starts_with($lower, 'namespace\\')) {
            $relative = substr($lower, strlen('namespace\\'));
            $canonical = $namespace === '' ? $relative : $namespace.'\\'.$relative;
        } else {
            $canonical = $namespace === '' ? $lower : $namespace.'\\'.$lower;
        }

        return $this->surfaceFunction($canonical);
    }

    /**
     * @return array{name: string, frontend: 'native'|'pest'}|null
     */
    private function surfaceFunction(string $canonical): ?array
    {
        $parts = explode('\\', strtolower(trim($canonical, '\\')));

        if (count($parts) === 3
            && $parts[0] === 'drove'
            && $parts[1] === 'native') {
            return ['name' => $parts[2], 'frontend' => 'native'];
        }

        if (count($parts) === 2 && $parts[0] === 'pest') {
            return ['name' => $parts[1], 'frontend' => 'pest'];
        }

        if (count($parts) === 1) {
            return ['name' => $parts[0], 'frontend' => 'pest'];
        }

        return null;
    }

    /**
     * Resolves namespace function imports without executing source. Grouped
     * imports and mixed grouped imports are handled explicitly. Imports are
     * isolated to the namespace block that declared them.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, true>  $relevant
     * @param  array<int, array{name: string, scope: int}>  $contexts
     * @return array<int, array<string, array{name: string, frontend: 'native'|'pest'}|false|null>>
     */
    private function functionAliases(array $tokens, array $relevant, array $contexts): array
    {
        $imports = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_USE)) {
                continue;
            }

            $first = $this->next($tokens, $index);

            if ($first === null || $tokens[$first]->text === '(') {
                continue;
            }

            $end = $first;

            while (isset($tokens[$end]) && $tokens[$end]->text !== ';') {
                $end++;
            }

            if (! isset($tokens[$end])) {
                continue;
            }

            $functionOnly = $tokens[$first]->is(T_FUNCTION);
            $start = $functionOnly ? $this->next($tokens, $first) : $first;

            if ($start === null || $start >= $end) {
                continue;
            }

            $groupOpen = null;

            for ($cursor = $start; $cursor < $end; $cursor++) {
                if ($tokens[$cursor]->text === '{') {
                    $groupOpen = $cursor;

                    break;
                }
            }

            if (! $functionOnly && $groupOpen === null) {
                continue;
            }

            $prefix = '';
            $specificationStart = $start;
            $specificationEnd = $end - 1;
            $requiresFunctionMarker = false;

            if ($groupOpen !== null) {
                $prefix = $this->joinedTokenText($tokens, $start, $groupOpen - 1);
                $specificationStart = $groupOpen + 1;
                $specificationEnd = $this->groupClose($tokens, $groupOpen, $end);
                $requiresFunctionMarker = ! $functionOnly;
            }

            foreach ($this->importSpecifications(
                $tokens,
                $specificationStart,
                $specificationEnd,
            ) as $specification) {
                if ($requiresFunctionMarker) {
                    if ($specification === [] || ! $specification[0]->is(T_FUNCTION)) {
                        continue;
                    }

                    array_shift($specification);
                }

                $import = $this->functionImport($specification, $prefix);

                if ($import === null) {
                    continue;
                }

                [$alias, $canonical] = $import;
                $scope = $contexts[$index]['scope'] ?? 0;
                $imports[$scope][$alias][$canonical] = true;
            }
        }

        $aliases = [];

        foreach ($imports as $scope => $scopeImports) {
            foreach ($scopeImports as $alias => $canonicals) {
                $names = array_keys($canonicals);
                $relevantNames = [];

                foreach ($names as $name) {
                    $surface = $this->surfaceFunction($name);

                    if ($surface !== null && isset($relevant[$surface['name']])) {
                        $relevantNames[$name] = $surface;
                    }
                }

                if ($relevantNames === []) {
                    $aliases[$scope][$alias] = null;

                    continue;
                }

                $aliases[$scope][$alias] = count($names) === 1
                    ? $relevantNames[array_key_first($relevantNames)]
                    : false;
            }
        }

        return $aliases;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<list<PhpToken>>
     */
    private function importSpecifications(array $tokens, int $start, int $end): array
    {
        $specifications = [];
        $current = [];

        for ($index = $start; $index <= $end; $index++) {
            $token = $tokens[$index] ?? null;

            if (! $token instanceof PhpToken || $token->isIgnorable()) {
                continue;
            }

            if ($token->text === ',') {
                if ($current !== []) {
                    $specifications[] = $current;
                    $current = [];
                }

                continue;
            }

            $current[] = $token;
        }

        if ($current !== []) {
            $specifications[] = $current;
        }

        return $specifications;
    }

    /**
     * @param  list<PhpToken>  $specification
     * @return array{string, string}|null
     */
    private function functionImport(array $specification, string $prefix): ?array
    {
        if ($specification === []) {
            return null;
        }

        $as = array_find_key(
            $specification,
            static fn (PhpToken $token): bool => $token->is(T_AS),
        );
        $nameTokens = $as === null
            ? $specification
            : array_slice($specification, 0, $as);
        $aliasToken = $as === null ? null : ($specification[$as + 1] ?? null);
        $name = trim(
            rtrim($prefix, '\\')
                .($prefix === '' ? '' : '\\')
                .implode('', array_column($nameTokens, 'text')),
            '\\',
        );

        if ($name === '') {
            return null;
        }

        $parts = explode('\\', $name);
        $canonical = strtolower($name);
        $alias = $aliasToken instanceof PhpToken && $aliasToken->is(T_STRING)
            ? strtolower($aliasToken->text)
            : strtolower($parts[array_key_last($parts)]);

        return [$alias, $canonical];
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function joinedTokenText(array $tokens, int $start, int $end): string
    {
        $text = '';

        for ($index = $start; $index <= $end; $index++) {
            $token = $tokens[$index] ?? null;

            if ($token instanceof PhpToken && ! $token->isIgnorable()) {
                $text .= $token->text;
            }
        }

        return $text;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function groupClose(array $tokens, int $open, int $statementEnd): int
    {
        for ($index = $open + 1; $index < $statementEnd; $index++) {
            if ($tokens[$index]->text === '}') {
                return $index - 1;
            }
        }

        return $statementEnd - 1;
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

    /**
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $unsupported
     * @return list<array{
     *     code: string,
     *     construct: string,
     *     path: string,
     *     line: int,
     *     column: int,
     *     message: string
     * }>
     */
    private function scanChain(
        array $tokens,
        int $close,
        string $context,
        array $unsupported,
        string $path,
        string $source,
    ): array {
        $diagnostics = [];
        $cursor = $this->next($tokens, $close);
        $allowed = $this->names('methods', $context);

        while ($cursor !== null
            && $tokens[$cursor]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            $member = $this->next($tokens, $cursor);

            if ($member === null || ! $tokens[$member]->is(T_STRING)) {
                $diagnostics[] = $this->at(
                    $this->code('higher_order'),
                    'dynamic-member',
                    $path,
                    $source,
                    $tokens[$cursor],
                );

                break;
            }

            $method = strtolower($tokens[$member]->text);
            $open = $this->next($tokens, $member);

            if ($open === null || $tokens[$open]->text !== '(') {
                $diagnostics[] = $this->at(
                    $this->code('higher_order'),
                    $tokens[$member]->text,
                    $path,
                    $source,
                    $tokens[$member],
                );

                break;
            }

            if (! isset($allowed[$method])) {
                $diagnostics[] = $this->at(
                    $unsupported[$method] ?? $this->code($context.'_method'),
                    $tokens[$member]->text,
                    $path,
                    $source,
                    $tokens[$member],
                );
            }

            $close = $this->closingParenthesis($tokens, $open);
            $cursor = $this->next($tokens, $close);
        }

        return $diagnostics;
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
            } elseif ($tokens[$index]->text === ')' && --$depth === 0) {
                return $index;
            }
        }

        throw new UnexpectedValueException('Drove encountered an unbalanced parsed call.');
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

    /**
     * @return array<string, true>
     */
    private function names(string $section, string $name): array
    {
        $values = $this->manifest[$section][$name] ?? [];
        $names = [];

        foreach ($values as $value) {
            $names[strtolower($value)] = true;
        }

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function codes(string $name): array
    {
        $values = $this->manifest['unsupported'][$name] ?? [];
        $codes = [];

        foreach ($values as $construct => $code) {
            $codes[strtolower($construct)] = $code;
        }

        return $codes;
    }

    private function code(string $name): string
    {
        return $this->manifest['diagnostics'][$name];
    }

    /**
     * @return array{
     *     code: string,
     *     construct: string,
     *     path: string,
     *     line: int,
     *     column: int,
     *     message: string
     * }
     */
    private function at(
        string $code,
        string $construct,
        string $path,
        string $source,
        PhpToken $token,
    ): array {
        $before = substr($source, 0, $token->pos);
        $lastNewline = strrpos($before, "\n");
        $column = $token->pos - ($lastNewline === false ? 0 : $lastNewline + 1) + 1;

        return $this->diagnostic($code, $construct, $path, $token->line, $column);
    }

    /**
     * @return array{
     *     code: string,
     *     construct: string,
     *     path: string,
     *     line: int,
     *     column: int,
     *     message: string
     * }
     */
    private function diagnostic(
        string $code,
        string $construct,
        string $path,
        int $line,
        int $column,
    ): array {
        return [
            'code' => $code,
            'construct' => $construct,
            'path' => $path,
            'line' => $line,
            'column' => $column,
            'message' => sprintf('Unsupported Drove native construct [%s].', $construct),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $name): array
    {
        $section = $this->manifest[$name] ?? null;

        if (! is_array($section)) {
            $this->invalid($name);
        }

        return $section;
    }

    private function identifierList(mixed $value, string $name): void
    {
        if (! is_array($value)
            || ! array_is_list($value)
            || $value === []
            || array_any(
                $value,
                static fn (mixed $item): bool => ! is_string($item)
                    || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $item) !== 1,
            )
            || count(array_unique(array_map('strtolower', $value))) !== count($value)) {
            $this->invalid($name);
        }
    }

    private function codeMap(mixed $value, string $name): void
    {
        if (! is_array($value)
            || array_is_list($value)
            || array_any(
                array_keys($value),
                static fn (mixed $key): bool => ! is_string($key)
                    || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1,
            )
            || array_any(
                $value,
                static fn (mixed $code): bool => ! is_string($code)
                    || preg_match('/^DROVE_NATIVE_[A-Z0-9_]+$/D', $code) !== 1,
            )) {
            $this->invalid($name);
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $expected
     */
    private function exactKeys(array $value, array $expected, string $name): void
    {
        $keys = array_keys($value);

        if (array_any($keys, static fn (mixed $key): bool => ! is_string($key))) {
            $this->invalid($name);
        }

        sort($keys, SORT_STRING);

        if ($keys !== $expected) {
            $this->invalid($name);
        }
    }

    private function invalid(string $name): never
    {
        throw new UnexpectedValueException(
            sprintf('The Drove native supported-surface manifest contains an invalid %s section.', $name),
        );
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }
}
