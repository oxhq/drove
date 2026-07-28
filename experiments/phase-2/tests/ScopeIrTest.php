<?php

declare(strict_types=1);

beforeAll(function (): void {
    $proof = $GLOBALS['drove_before_all_proof'];
    $proof->count++;
    $proof->pid = getmypid();

    $GLOBALS['drove_scope_state']->mutations[] = 'prepared';
});

afterAll(function (): void {
    $proof = $GLOBALS['drove_after_all_proof'];
    $proof->count++;
    $proof->pid = getmypid();
});

describe('prepared siblings', function (): void {
    it('runs beta from prepared state', function (): void {
        $rootPid = $GLOBALS['drove_root_pid'];
        $scopeState = $GLOBALS['drove_scope_state'];
        $startState = $scopeState->mutations;
        $scopeState->mutations[] = 'beta';

        $GLOBALS['drove_pest_execution'] = [
            'marker' => 'beta',
            'start_state' => $startState,
            'pid' => getmypid(),
            'ppid' => posix_getppid(),
            'generated_method' => $this->name(),
            'end_state' => $scopeState->mutations,
        ];

        expect($startState)->toBe(['root', 'prepared'])
            ->and(getmypid())->not->toBe($rootPid)
            ->and(posix_getppid())->toBe($rootPid)
            ->and($scopeState->mutations)->toBe(['root', 'prepared', 'beta']);
    });

    it('runs alpha from prepared state', function (): void {
        $rootPid = $GLOBALS['drove_root_pid'];
        $scopeState = $GLOBALS['drove_scope_state'];
        $startState = $scopeState->mutations;
        $scopeState->mutations[] = 'alpha';

        $GLOBALS['drove_pest_execution'] = [
            'marker' => 'alpha',
            'start_state' => $startState,
            'pid' => getmypid(),
            'ppid' => posix_getppid(),
            'generated_method' => $this->name(),
            'end_state' => $scopeState->mutations,
        ];

        expect($startState)->toBe(['root', 'prepared'])
            ->and(getmypid())->not->toBe($rootPid)
            ->and(posix_getppid())->toBe($rootPid)
            ->and($scopeState->mutations)->toBe(['root', 'prepared', 'alpha']);
    });
});
