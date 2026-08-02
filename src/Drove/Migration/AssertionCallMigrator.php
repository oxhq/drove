<?php

declare(strict_types=1);

namespace Drove\Migration;

final class AssertionCallMigrator
{
    /** @param list<string> $arguments */
    public static function replacement(string $method, array $arguments): ?string
    {
        $method = strtolower($method);

        if ($method === 'fail' && count($arguments) <= 1) {
            return '\\Drove\\Native\\TestContext::fail('.($arguments[0] ?? '').')';
        }

        $arity = match ($method) {
            'asserttrue', 'assertfalse', 'assertnull', 'assertnotnull',
            'assertfileexists', 'assertisarray' => 1,
            'assertsame', 'assertnotsame', 'assertequals', 'assertnotequals',
            'assertinstanceof', 'assertstringcontainsstring',
            'assertstringnotcontainsstring', 'assertmatchesregularexpression',
            'assertstringstartswith', 'assertlessthan', 'assertcount',
            'assertcontains' => 2,
            default => null,
        };

        if ($arity === null) {
            return null;
        }

        if (! in_array(count($arguments), [$arity, $arity + 1], true)) {
            return null;
        }

        $messageArgument = count($arguments) === $arity + 1
            ? $arguments[$arity]
            : null;

        if ($messageArgument !== null && in_array($method, [
            'assertcontains',
            'assertstringcontainsstring',
            'assertstringnotcontainsstring',
        ], true)) {
            $gatewayMethod = match ($method) {
                'assertcontains' => 'assertContains',
                'assertstringcontainsstring' => 'assertStringContainsString',
                'assertstringnotcontainsstring' => 'assertStringNotContainsString',
            };

            return sprintf(
                '\\Drove\\Native\\Assert::%s(%s)',
                $gatewayMethod,
                implode(', ', $arguments),
            );
        }

        $message = $messageArgument === null ? '' : ', '.$messageArgument;
        $unaryMessage = $messageArgument ?? '';

        return match ($method) {
            'asserttrue' => '\\Drove\\Native\\expect('.$arguments[0].')->toBeTrue('.$unaryMessage.')',
            'assertfalse' => '\\Drove\\Native\\expect('.$arguments[0].')->toBeFalse('.$unaryMessage.')',
            'assertnull' => '\\Drove\\Native\\expect('.$arguments[0].')->toBeNull('.$unaryMessage.')',
            'assertnotnull' => '\\Drove\\Native\\expect('.$arguments[0].')->not()->toBeNull('.$unaryMessage.')',
            'assertsame' => '\\Drove\\Native\\expect('.$arguments[1].')->toBe('.$arguments[0].$message.')',
            'assertnotsame' => '\\Drove\\Native\\expect('.$arguments[1].')->not()->toBe('.$arguments[0].$message.')',
            'assertequals' => '\\Drove\\Native\\expect('.$arguments[1].')->toEqual('.$arguments[0].$message.')',
            'assertnotequals' => '\\Drove\\Native\\expect('.$arguments[1].')->not()->toEqual('.$arguments[0].$message.')',
            'assertinstanceof' => '\\Drove\\Native\\expect('.$arguments[1].')->toBeInstanceOf('.$arguments[0].$message.')',
            'assertstringcontainsstring' => '\\Drove\\Native\\expect('.$arguments[1].')->toContain('.$arguments[0].$message.')',
            'assertstringnotcontainsstring' => '\\Drove\\Native\\expect('.$arguments[1].')->not()->toContain('.$arguments[0].$message.')',
            'assertmatchesregularexpression' => '\\Drove\\Native\\expect('.$arguments[1].')->toMatch('.$arguments[0].$message.')',
            'assertstringstartswith' => '\\Drove\\Native\\expect('.$arguments[1].')->toStartWith('.$arguments[0].$message.')',
            'assertlessthan' => '\\Drove\\Native\\expect('.$arguments[1].')->toBeLessThan('.$arguments[0].$message.')',
            'assertfileexists' => '\\Drove\\Native\\expect('.$arguments[0].')->toBeFile('.$unaryMessage.')',
            'assertisarray' => '\\Drove\\Native\\expect('.$arguments[0].')->toBeArray('.$unaryMessage.')',
            'assertcount' => '\\Drove\\Native\\expect('.$arguments[1].')->toHaveCount('.$arguments[0].$message.')',
            'assertcontains' => '\\Drove\\Native\\expect('.$arguments[1].')->toContain('.$arguments[0].$message.')',
        };
    }
}
