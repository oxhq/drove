<?php

declare(strict_types=1);

namespace Drove\Migration;

use Drove\Compatibility\Registry;
use Drove\Compatibility\Status;
use Drove\Native\Surface\SupportedSurface;
use ParseError;
use PhpToken;
use RuntimeException;

final readonly class Scanner
{
    /** @var array<string, string> */
    private const array PORTABLE = [
        'afterall' => 'after-all',
        'aftereach' => 'after-each',
        'beforeall' => 'before-all',
        'beforeeach' => 'before-each',
        'dataset' => 'dataset',
        'describe' => 'describe',
        'expect' => 'expect',
        'it' => 'it',
        'test' => 'test',
    ];

    /** @var array<string, string> */
    private const array UNSUPPORTED_METHODS = [
        'depends' => 'pest.dependency',
        'repeat' => 'pest.repetition',
        'tomatchsnapshot' => 'pest.snapshot',
    ];

    /** @var array<string, true> */
    private const array PEST_RUNTIME_FUNCTIONS = [
        'fixture' => true,
        'pest' => true,
        'testdirectory' => true,
    ];

    /** @var array<string, true> */
    private array $declarationMethods;

    /** @var array<string, true> */
    private array $expectationMethods;

    /** @var array<string, true> */
    private array $hookMethods;

    public function __construct(
        private Registry $registry,
        ?SupportedSurface $surface = null,
    ) {
        $surface ??= SupportedSurface::load();
        $this->declarationMethods = $surface->methodSet('declaration');
        $this->expectationMethods = $surface->methodSet('expectation');
        $this->hookMethods = $surface->methodSet('hook');
    }

    /**
     * @return list<Finding>
     */
    public function scanFile(string $path, ?CodemodOptions $options = null): array
    {
        $source = is_file($path) && is_readable($path)
            ? file_get_contents($path)
            : false;

        if (! is_string($source)) {
            throw new RuntimeException('Drove could not read source for migration scanning.');
        }

        return $this->scan($source, $path, $options);
    }

    /**
     * @return list<Finding>
     */
    public function scan(
        string $source,
        string $path = '<memory>',
        ?CodemodOptions $options = null,
    ): array {
        if ($path === '' || preg_match('//u', $path) !== 1) {
            throw new RuntimeException('Drove migration scans require a valid UTF-8 source path.');
        }

        try {
            $tokens = array_values(PhpToken::tokenize($source, TOKEN_PARSE));
        } catch (ParseError $error) {
            return [$this->finding(
                'migration.parse-error',
                'parse-error',
                $path,
                $source,
                new PhpToken(T_STRING, 'parse-error', max(1, $error->getLine()), 0),
            )];
        }

        $options ??= new CodemodOptions;

        $pestImports = $this->pestFunctionImports($tokens);
        $ambiguous = $this->ambiguousFunctionNames($tokens);

        foreach (array_keys($pestImports) as $import) {
            unset($ambiguous[$import]);
        }

        $namespaced = $this->hasNamespace($tokens);
        $findings = $this->importFindings($tokens, $path, $source, $options);

        foreach ($tokens as $index => $token) {
            $call = $this->call($tokens, $index, $namespaced);

            if ($call === null) {
                continue;
            }

            [$name, $open, $frontend] = $call;
            $sourceName = $name;
            $importedName = $frontend === 'pest'
                && ! str_contains($token->text, '\\')
                ? ($pestImports[$name] ?? null)
                : null;

            if (is_string($importedName)) {
                $name = $importedName;
            }

            if ($frontend === 'pest' && $name === 'register_shutdown_function') {
                $findings[] = $this->finding(
                    'php.shutdown-callback',
                    $token->text.'()',
                    $path,
                    $source,
                    $token,
                );

                continue;
            }

            if ($frontend === 'pest'
                && (isset(self::PEST_RUNTIME_FUNCTIONS[$name])
                    || str_starts_with(strtolower($token->text), 'pest\\')
                        && ! isset(self::PORTABLE[$name])
                        && ! in_array($name, ['arch', 'uses'], true))) {
                $findings[] = $this->finding(
                    'pest.self-test.runtime-call',
                    $token->text.'()',
                    $path,
                    $source,
                    $token,
                );

                continue;
            }

            if ($frontend === 'ambiguous'
                && ! isset(self::PORTABLE[$name])
                && ! in_array($name, ['arch', 'uses'], true)) {
                continue;
            }

            if ($frontend === 'ambiguous'
                || ($frontend === 'pest'
                    && ! $options->trustsFunction($path, $sourceName)
                    && (isset($ambiguous[$name])
                        || $this->namespacedPestCallIsAmbiguous(
                            $token,
                            $namespaced,
                            $pestImports,
                        )))) {
                $findings[] = $this->finding(
                    'pest.ambiguous-call',
                    $token->text,
                    $path,
                    $source,
                    $token,
                );

                continue;
            }

            $close = $this->closingParenthesis($tokens, $open);

            if (in_array($name, ['afterall', 'aftereach', 'beforeall', 'beforeeach'], true)
                && $this->hookProxy($tokens, $open, $close, $name)) {
                $findings[] = $this->finding(
                    'pest.hook-proxy',
                    $token->text.'()',
                    $path,
                    $source,
                    $token,
                );

                continue;
            }

            if ($this->isFirstClassCallable($tokens, $open, $close)) {
                $findings[] = $this->finding(
                    'pest.dynamic-call',
                    $token->text.'(...)',
                    $path,
                    $source,
                    $token,
                );

                continue;
            }

            if ($frontend === 'native') {
                $surface = $this->nativeSurface($name);

                if ($surface !== null) {
                    $findings[] = $this->finding(
                        $surface,
                        $token->text,
                        $path,
                        $source,
                        $token,
                    );
                }

                continue;
            }

            if (isset(self::PORTABLE[$name])) {
                $findings[] = $this->finding(
                    'pest.portable.'.self::PORTABLE[$name],
                    $token->text,
                    $path,
                    $source,
                    $token,
                );
            } elseif ($name === 'uses') {
                $method = $this->chainMethod($tokens, $close);
                $findings[] = $this->finding(
                    $method === 'in' ? 'pest.uses.environment' : 'pest.uses',
                    $method === null ? 'uses()' : 'uses()->'.$method.'()',
                    $path,
                    $source,
                    $token,
                );
            } elseif ($name === 'arch') {
                $findings[] = $this->finding(
                    'pest.architecture',
                    $token->text,
                    $path,
                    $source,
                    $token,
                );
            } else {
                continue;
            }

            if ($name === 'expect') {
                $findings = [
                    ...$findings,
                    ...$this->expectationFinding(
                        $tokens,
                        $close,
                        $path,
                        $source,
                    ),
                ];
            } elseif (in_array($name, ['afterall', 'aftereach', 'beforeall', 'beforeeach'], true)) {
                // Hook modifier chains have already been validated by hookProxy().
            } elseif (isset(self::PORTABLE[$name])) {
                foreach ($this->chain($tokens, $close) as $method) {
                    if (! $method['called']
                        || $method['nullsafe']
                        || ! isset(self::UNSUPPORTED_METHODS[$method['name']])
                        && ! isset($this->declarationMethods[$method['name']])) {
                        $findings[] = $this->finding(
                            'pest.unknown-modifier',
                            '->'.$method['member']->text,
                            $path,
                            $source,
                            $method['member'],
                        );

                        continue;
                    }

                    if (isset(self::UNSUPPORTED_METHODS[$method['name']])) {
                        $findings[] = $this->finding(
                            self::UNSUPPORTED_METHODS[$method['name']],
                            '->'.$method['name'].'()',
                            $path,
                            $source,
                            $method['member'],
                        );
                    }
                }
            }
        }

        usort($findings, static fn (Finding $left, Finding $right): int => [
            $left->path,
            $left->line,
            $left->column,
            $left->surface,
            $left->construct,
        ] <=> [
            $right->path,
            $right->line,
            $right->column,
            $right->surface,
            $right->construct,
        ]);

        return $findings;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<Finding>
     */
    private function importFindings(
        array $tokens,
        string $path,
        string $source,
        CodemodOptions $options,
    ): array {
        $findings = [];

        foreach ($this->classImportStatements($tokens) as $import) {
            $class = strtolower(ltrim($import['class'], '\\'));

            if ($options->allowsSubject($path, $class)) {
                continue;
            }

            if ($options->import($path, $class) !== null) {
                continue;
            }

            $surface = match ($class) {
                'phpunit\\framework\\expectationfailedexception' => 'pest.self-test.phpunit-assertion-exception',
                'pest\\exceptions\\invalidexpectationvalue' => 'pest.self-test.invalid-expectation',
                'pest\\support\\datasetinfo', 'pest\\support\\exceptiontrace' => 'pest.self-test.runtime-import',
                default => null,
            };

            if ($surface === null
                && (str_starts_with($class, 'phpunit\\')
                    || str_starts_with($class, 'pest\\')
                        && ! str_starts_with($class, 'pest\\support\\'))) {
                $surface = 'pest.self-test.runtime-import';
            }

            if ($surface === null) {
                continue;
            }

            $findings[] = $this->finding(
                $surface,
                $import['class'],
                $path,
                $source,
                $import['token'],
            );
        }

        return $findings;
    }

    /**
     * Only simple top-level class imports are migration candidates. Grouped,
     * mixed, function, and trait imports remain outside this codemod.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{class: string, alias: string, token: PhpToken, use: PhpToken, end: PhpToken}>
     */
    private function classImportStatements(array $tokens): array
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
                $first = $this->next($tokens, $index);

                if ($first !== null
                    && $tokens[$first]->text !== '('
                    && ! $tokens[$first]->is([T_FUNCTION, T_CONST])) {
                    $statement = '';
                    $end = null;

                    for ($cursor = $first, $count = count($tokens); $cursor < $count; $cursor++) {
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
                        $class = ltrim($match[1], '\\');
                        $parts = explode('\\', $class);
                        $imports[] = [
                            'class' => $class,
                            'alias' => $match[2] ?? $parts[array_key_last($parts)],
                            'token' => $tokens[$first],
                            'use' => $token,
                            'end' => $end,
                        ];
                    }
                }
            }

            if ($token->text === '{') {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }
        }

        return $imports;
    }

    /** @param list<PhpToken> $tokens */
    private function hookProxy(array $tokens, int $open, int $close, string $name): bool
    {
        $argument = $this->next($tokens, $open);
        $chain = $this->chain($tokens, $close);

        if ($argument !== null && $argument !== $close && $chain === []) {
            return false;
        }

        if (! in_array($name, ['beforeeach', 'aftereach'], true)) {
            return true;
        }

        if ($chain === []) {
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
     * @return array{string, int, 'ambiguous'|'native'|'pest'}|null
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
                ? [$name, $open, 'ambiguous']
                : [$name, $open, 'native'];
        }

        if (count($parts) === 2 && $parts[0] === 'pest') {
            return [$name, $open, 'pest'];
        }

        if (count($parts) === 1) {
            return [$name, $open, 'pest'];
        }

        return [$name, $open, 'ambiguous'];
    }

    private function nativeSurface(string $name): ?string
    {
        return match ($name) {
            'test', 'it', 'describe' => 'native.test',
            'beforeall', 'beforeeach', 'aftereach', 'afterall' => 'native.lifecycle',
            'dataset' => 'native.dataset',
            'environment' => 'native.environment',
            'expect' => 'native.expectation.identity',
            default => null,
        };
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<Finding>
     */
    private function expectationFinding(
        array $tokens,
        int $close,
        string $path,
        string $source,
    ): array {
        $findings = [];

        foreach ($this->chain($tokens, $close) as $method) {
            if ($method['nullsafe']) {
                $findings[] = $this->finding(
                    'pest.higher-order',
                    'nullsafe higher-order expectation',
                    $path,
                    $source,
                    $method['member'],
                );

                continue;
            }

            if (isset(self::UNSUPPORTED_METHODS[$method['name']])) {
                $findings[] = $this->finding(
                    self::UNSUPPORTED_METHODS[$method['name']],
                    '->'.$method['name'].'()',
                    $path,
                    $source,
                    $method['member'],
                );

                continue;
            }

            if ($method['name'] === 'extend') {
                $findings[] = $this->finding(
                    'pest.custom-expectation.definition',
                    'expect()->extend()',
                    $path,
                    $source,
                    $method['member'],
                );

                continue;
            }

            if (! isset($this->expectationMethods[$method['name']])) {
                $findings[] = $this->finding(
                    'pest.custom-expectation.call',
                    '->'.$method['member']->text.($method['called'] ? '()' : ''),
                    $path,
                    $source,
                    $method['member'],
                );
            }
        }

        return $findings;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function chainMethod(array $tokens, int $close): ?string
    {
        $chain = $this->chain($tokens, $close);
        $first = $chain[0] ?? null;

        return $first !== null && $first['called'] ? $first['name'] : null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<array{
     *     name: string,
     *     member: PhpToken,
     *     called: bool,
     *     nullsafe: bool
     * }>
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
                'member' => $tokens[$member],
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

                if (isset(self::PORTABLE[$name])
                    || in_array($name, ['arch', 'uses'], true)) {
                    $imports[$alias] = $name;
                }
            }
        }

        return $imports;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function hasNamespace(array $tokens): bool
    {
        return array_any(
            $tokens,
            static fn (PhpToken $token): bool => $token->is(T_NAMESPACE),
        );
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

    private function finding(
        string $surface,
        string $construct,
        string $path,
        string $source,
        PhpToken $token,
    ): Finding {
        $definition = $this->registry->surface($surface);
        $lineStart = strrpos(substr($source, 0, $token->pos), "\n");
        $column = $token->pos - ($lineStart === false ? -1 : $lineStart);

        return new Finding(
            $surface,
            Status::from($definition['status']),
            $construct,
            $path,
            max(1, $token->line),
            max(1, $column),
            $definition['diagnostic'],
            $definition['codemod'],
        );
    }
}
