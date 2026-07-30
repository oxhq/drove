<?php

declare(strict_types=1);

namespace Drove\Migration;

use InvalidArgumentException;
use ParseError;
use PhpToken;

final readonly class CodemodOptions
{
    /**
     * @var array<string, array{
     *     name: string,
     *     factory: string,
     *     declaration_path: string
     * }>
     */
    private array $environments;

    /** @var array<string, array{owner: string, matcher: string}> */
    private array $matchers;

    /**
     * Environment mappings are deliberately opt-in and path-owned. A bare
     * uses(TestCase::class) can become the suite-wide native environment only
     * in the one file selected by declaration_path; scoped uses()->in(...)
     * remains bridge-only.
     *
     * @param  array<array-key, mixed>  $environments
     * @param  array<array-key, mixed>  $matchers
     */
    public function __construct(
        array $environments = [],
        array $matchers = [],
    ) {
        $normalizedEnvironments = [];

        foreach ($environments as $caseClass => $environment) {
            $keys = is_array($environment) ? array_keys($environment) : [];
            sort($keys, SORT_STRING);

            if (! is_string($caseClass)
                || ! is_array($environment)
                || $keys !== ['declaration_path', 'factory', 'name']) {
                throw new InvalidArgumentException(
                    'Drove environment codemod mappings contain an invalid entry.',
                );
            }

            $caseClass = strtolower(ltrim(trim($caseClass), '\\'));
            $name = $environment['name'] ?? null;
            $factory = $environment['factory'] ?? null;
            $path = $environment['declaration_path'] ?? null;

            if ($caseClass === ''
                || preg_match(
                    '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D',
                    $caseClass,
                ) !== 1
                || ! is_string($name)
                || trim($name) === ''
                || preg_match('//u', $name) !== 1
                || ! is_string($factory)
                || trim($factory) === ''
                || preg_match('//u', $factory) !== 1
                || ! is_string($path)
                || $path === ''
                || preg_match('//u', $path) !== 1) {
                throw new InvalidArgumentException(
                    'Drove environment codemod mappings contain an invalid value.',
                );
            }

            $factory = trim($factory);
            $path = str_replace('\\', '/', $path);

            try {
                PhpToken::tokenize('<?php $factory = ('.$factory.');', TOKEN_PARSE);
            } catch (ParseError $error) {
                throw new InvalidArgumentException('Drove environment codemod factories must be valid PHP expressions.', $error->getCode(), previous: $error);
            }

            if (isset($normalizedEnvironments[$caseClass])) {
                throw new InvalidArgumentException(
                    'Drove environment codemod mappings contain a duplicate TestCase.',
                );
            }

            $normalizedEnvironments[$caseClass] = [
                'name' => $name,
                'factory' => $factory,
                'declaration_path' => $path,
            ];
        }

        $normalizedMatchers = [];

        foreach ($matchers as $method => $matcher) {
            $keys = is_array($matcher) ? array_keys($matcher) : [];
            sort($keys, SORT_STRING);

            if (! is_string($method)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method) !== 1
                || ! is_array($matcher)
                || $keys !== ['matcher', 'owner']) {
                throw new InvalidArgumentException(
                    'Drove matcher codemod mappings contain an invalid entry.',
                );
            }

            $owner = $matcher['owner'] ?? null;
            $key = $matcher['matcher'] ?? null;

            if (! is_string($owner)
                || ! is_string($key)
                || preg_match('/^[a-z][a-z0-9._-]*$/D', $owner) !== 1
                || preg_match('/^[a-z][a-z0-9._-]*$/D', $key) !== 1) {
                throw new InvalidArgumentException(
                    'Drove matcher codemod mappings require stable extension keys.',
                );
            }

            $normalizedMatchers[strtolower($method)] = [
                'owner' => $owner,
                'matcher' => $key,
            ];
        }

        ksort($normalizedEnvironments, SORT_STRING);
        ksort($normalizedMatchers, SORT_STRING);
        $this->environments = $normalizedEnvironments;
        $this->matchers = $normalizedMatchers;
    }

    /**
     * @return array{name: string, factory: string, declaration_path: string}|null
     */
    public function environment(string $caseClass, string $path): ?array
    {
        $mapping = $this->environments[strtolower(ltrim($caseClass, '\\'))] ?? null;

        return $mapping !== null
            && $mapping['declaration_path'] === str_replace('\\', '/', $path)
                ? $mapping
                : null;
    }

    /**
     * @return array{owner: string, matcher: string}|null
     */
    public function matcher(string $method): ?array
    {
        return $this->matchers[strtolower($method)] ?? null;
    }
}
