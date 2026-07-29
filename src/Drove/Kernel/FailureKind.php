<?php

declare(strict_types=1);

namespace Drove\Kernel;

use Throwable;

/**
 * @internal
 */
enum FailureKind: string
{
    case AssertionFailure = 'assertion_failure';
    case PhpException = 'php_exception';
    case PhpFatalError = 'php_fatal_error';
    case SetupFailure = 'setup_failure';
    case TeardownFailure = 'teardown_failure';
    case Timeout = 'timeout';
    case SignalTermination = 'signal_termination';
    case OutOfMemory = 'out_of_memory';
    case StateAdapterFailure = 'state_adapter_failure';
    case ForkFailure = 'fork_failure';
    case ChildProtocolFailure = 'child_protocol_failure';
    case NativeEngineCrash = 'native_engine_crash';
    case BlockedDescendant = 'blocked_descendant';
    case UserInterruption = 'user_interruption';

    public static function for(Throwable $throwable, string $phase): self
    {
        if (str_starts_with($phase, 'before')) {
            return self::SetupFailure;
        }

        if (str_starts_with($phase, 'after') || $phase === 'defer') {
            return self::TeardownFailure;
        }

        return is_a($throwable, 'PHPUnit\\Framework\\AssertionFailedError')
            ? self::AssertionFailure
            : self::PhpException;
    }

    public static function forFatal(string $message): self
    {
        return str_contains($message, 'Allowed memory size')
            ? self::OutOfMemory
            : self::PhpFatalError;
    }
}
