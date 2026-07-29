<?php

declare(strict_types=1);

$GLOBALS['drove_phase_three_executions'] = [];
$GLOBALS['drove_phase_three_hooks'] = [];
$GLOBALS['drove_phase_three_tests'] = [];

$GLOBALS['drove_phase_three_hooks']['file.before_all'] = function (): void {
    $GLOBALS['drove_phase_three_executions'][] = 'file.before_all';
};
beforeAll($GLOBALS['drove_phase_three_hooks']['file.before_all']);

$GLOBALS['drove_phase_three_hooks']['file.before_each_beta'] = function (): void {
    $GLOBALS['drove_phase_three_executions'][] = 'file.before_each_beta';
};
beforeEach($GLOBALS['drove_phase_three_hooks']['file.before_each_beta']);

$GLOBALS['drove_phase_three_hooks']['file.before_each_alpha'] = function (): void {
    $GLOBALS['drove_phase_three_executions'][] = 'file.before_each_alpha';
};
beforeEach($GLOBALS['drove_phase_three_hooks']['file.before_each_alpha']);

$GLOBALS['drove_phase_three_hooks']['file.after_each'] = function (): void {
    $GLOBALS['drove_phase_three_executions'][] = 'file.after_each';
};
afterEach($GLOBALS['drove_phase_three_hooks']['file.after_each']);

$GLOBALS['drove_phase_three_hooks']['file.after_all'] = function (): void {
    $GLOBALS['drove_phase_three_executions'][] = 'file.after_all';
};
afterAll($GLOBALS['drove_phase_three_hooks']['file.after_all']);

describe('outer', function (): void {
    $GLOBALS['drove_phase_three_hooks']['outer.before_each'] = function (): void {
        $GLOBALS['drove_phase_three_executions'][] = 'outer.before_each';
    };
    beforeEach($GLOBALS['drove_phase_three_hooks']['outer.before_each']);

    $GLOBALS['drove_phase_three_hooks']['outer.after_each'] = function (): void {
        $GLOBALS['drove_phase_three_executions'][] = 'outer.after_each';
    };
    afterEach($GLOBALS['drove_phase_three_hooks']['outer.after_each']);

    describe('same', function (): void {
        $GLOBALS['drove_phase_three_hooks']['first.before_each'] = function (): void {
            $GLOBALS['drove_phase_three_executions'][] = 'first.before_each';
        };
        beforeEach($GLOBALS['drove_phase_three_hooks']['first.before_each']);

        $GLOBALS['drove_phase_three_hooks']['first.after_each'] = function (): void {
            $GLOBALS['drove_phase_three_executions'][] = 'first.after_each';
        };
        afterEach($GLOBALS['drove_phase_three_hooks']['first.after_each']);

        $GLOBALS['drove_phase_three_tests']['first'] = function (): void {
            $GLOBALS['drove_phase_three_executions'][] = 'test.first';
        };
        it('runs first duplicate scope', $GLOBALS['drove_phase_three_tests']['first']);
    });

    describe('same', function (): void {
        $GLOBALS['drove_phase_three_hooks']['second.before_each'] = function (): void {
            $GLOBALS['drove_phase_three_executions'][] = 'second.before_each';
        };
        beforeEach($GLOBALS['drove_phase_three_hooks']['second.before_each']);

        $GLOBALS['drove_phase_three_hooks']['second.after_each'] = function (): void {
            $GLOBALS['drove_phase_three_executions'][] = 'second.after_each';
        };
        afterEach($GLOBALS['drove_phase_three_hooks']['second.after_each']);

        $GLOBALS['drove_phase_three_tests']['second'] = function (): void {
            $GLOBALS['drove_phase_three_executions'][] = 'test.second';
        };
        it('runs second duplicate scope', $GLOBALS['drove_phase_three_tests']['second']);
    });
});
