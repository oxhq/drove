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

    /** @var array<string, array{owner: string, matcher: string, negated_matcher: string|null}> */
    private array $matchers;

    /** @var array<string, array<string, true>> */
    private array $trustedFunctions;

    /** @var array<string, array<string, string>> */
    private array $imports;

    /** @var array<string, array<string, string>> */
    private array $subjects;

    /**
     * Environment mappings are deliberately opt-in and path-owned. A bare
     * uses(TestCase::class) can become the suite-wide native environment only
     * in the one file selected by declaration_path; scoped uses()->in(...)
     * remains bridge-only.
     *
     * @param  array<array-key, mixed>  $environments
     * @param  array<array-key, mixed>  $matchers
     * @param  array<array-key, mixed>  $trustedFunctions
     * @param  array<array-key, mixed>  $imports
     * @param  array<array-key, mixed>  $subjects
     */
    public function __construct(
        array $environments = [],
        array $matchers = [],
        array $trustedFunctions = [],
        array $imports = [],
        array $subjects = [],
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
                || ! in_array($keys, [
                    ['matcher', 'owner'],
                    ['matcher', 'negated_matcher', 'owner'],
                ], true)) {
                throw new InvalidArgumentException(
                    'Drove matcher codemod mappings contain an invalid entry.',
                );
            }

            $owner = $matcher['owner'] ?? null;
            $key = $matcher['matcher'] ?? null;
            $negated = $matcher['negated_matcher'] ?? null;

            if (! is_string($owner)
                || ! is_string($key)
                || preg_match(
                    '~^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$~D',
                    $owner,
                ) !== 1
                || preg_match('/^[a-z][a-z0-9._-]*$/D', $key) !== 1
                || ($negated !== null && (! is_string($negated)
                    || preg_match('/^[a-z][a-z0-9._-]*$/D', $negated) !== 1))) {
                throw new InvalidArgumentException(
                    'Drove matcher codemod mappings require stable extension keys.',
                );
            }

            $normalizedMatchers[strtolower($method)] = [
                'owner' => $owner,
                'matcher' => $key,
                'negated_matcher' => $negated,
            ];
        }

        ksort($normalizedEnvironments, SORT_STRING);
        ksort($normalizedMatchers, SORT_STRING);
        $normalizedTrustedFunctions = $this->normalizeTrustedFunctions($trustedFunctions);
        $normalizedImports = $this->normalizeImports($imports);
        $normalizedSubjects = $this->normalizeSubjects($subjects);
        $this->environments = $normalizedEnvironments;
        $this->matchers = $normalizedMatchers;
        $this->trustedFunctions = $normalizedTrustedFunctions;
        $this->imports = $normalizedImports;
        $this->subjects = $normalizedSubjects;
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
     * @return array{owner: string, matcher: string, negated_matcher: string|null}|null
     */
    public function matcher(string $method): ?array
    {
        return $this->matchers[strtolower($method)] ?? null;
    }

    public function trustsFunction(string $path, string $function): bool
    {
        return isset($this->trustedFunctions[$this->path($path)][strtolower($function)]);
    }

    public function import(string $path, string $class): ?string
    {
        return $this->imports[$this->path($path)][strtolower(ltrim($class, '\\'))] ?? null;
    }

    public function allowsSubject(string $path, string $class): bool
    {
        return isset($this->subjects[$this->path($path)][strtolower(ltrim($class, '\\'))]);
    }

    /**
     * @return list<string>
     */
    public function subjects(string $path): array
    {
        return array_values($this->subjects[$this->path($path)] ?? []);
    }

    /**
     * @param  array<array-key, mixed>  $trustedFunctions
     * @return array<string, array<string, true>>
     */
    private function normalizeTrustedFunctions(array $trustedFunctions): array
    {
        $normalized = [];

        foreach ($trustedFunctions as $path => $functions) {
            if (! is_string($path)
                || $path === ''
                || preg_match('//u', $path) !== 1
                || ! is_array($functions)
                || ! array_is_list($functions)
                || $functions === []) {
                throw new InvalidArgumentException('Drove trusted-function mappings contain an invalid entry.');
            }

            $path = $this->path($path);

            foreach ($functions as $function) {
                if (! is_string($function)
                    || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $function) !== 1
                    || isset($normalized[$path][strtolower($function)])) {
                    throw new InvalidArgumentException('Drove trusted-function mappings contain an invalid function.');
                }

                $normalized[$path][strtolower($function)] = true;
            }

            ksort($normalized[$path], SORT_STRING);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $imports
     * @return array<string, array<string, string>>
     */
    private function normalizeImports(array $imports): array
    {
        $normalized = [];
        $classPattern = '/^(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\\\\)*[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D';

        foreach ($imports as $path => $mappings) {
            if (! is_string($path)
                || $path === ''
                || preg_match('//u', $path) !== 1
                || ! is_array($mappings)
                || array_is_list($mappings)) {
                throw new InvalidArgumentException('Drove import mappings contain an invalid entry.');
            }

            $path = $this->path($path);

            foreach ($mappings as $source => $target) {
                $source = is_string($source) ? ltrim($source, '\\') : $source;
                $target = is_string($target) ? ltrim($target, '\\') : $target;
                $key = is_string($source) ? strtolower($source) : '';

                if (! is_string($source)
                    || ! is_string($target)
                    || preg_match($classPattern, $source) !== 1
                    || preg_match($classPattern, $target) !== 1
                    || strcasecmp($source, $target) === 0
                    || isset($normalized[$path][$key])) {
                    throw new InvalidArgumentException('Drove import mappings contain an invalid symbol mapping.');
                }

                $normalized[$path][$key] = $target;
            }

            ksort($normalized[$path], SORT_STRING);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $subjects
     * @return array<string, array<string, string>>
     */
    private function normalizeSubjects(array $subjects): array
    {
        $normalized = [];
        $classPattern = '/^(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\\\\)*[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D';

        foreach ($subjects as $path => $classes) {
            if (! is_string($path)
                || $path === ''
                || preg_match('//u', $path) !== 1
                || ! is_array($classes)
                || ! array_is_list($classes)
                || $classes === []) {
                throw new InvalidArgumentException('Drove production-subject mappings contain an invalid entry.');
            }

            $path = $this->path($path);

            foreach ($classes as $class) {
                $class = is_string($class) ? ltrim($class, '\\') : $class;
                $key = is_string($class) ? strtolower($class) : '';

                if (! is_string($class)
                    || preg_match($classPattern, $class) !== 1
                    || $this->isForbiddenSubject($class)
                    || isset($normalized[$path][$key])) {
                    throw new InvalidArgumentException('Drove production-subject mappings contain an invalid class.');
                }

                $normalized[$path][$key] = $class;
            }

            ksort($normalized[$path], SORT_STRING);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function isForbiddenSubject(string $class): bool
    {
        $class = strtolower($class);

        return in_array($class, [
            'pest\\expectation',
            'pest\\functions',
            'pest\\kernel',
            'pest\\pest',
            'pest\\testsuite',
        ], true)
            || str_starts_with($class, 'pest\\bootstrappers\\')
            || str_starts_with($class, 'pest\\factories\\')
            || str_starts_with($class, 'pest\\pendingcalls\\')
            || str_starts_with($class, 'pest\\testcases\\')
            || str_starts_with($class, 'drove\\pest\\')
            || str_starts_with($class, 'drove\\bridge\\pest\\')
            || str_starts_with($class, 'drove\\bridge\\phpunit\\');
    }

    private function path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
