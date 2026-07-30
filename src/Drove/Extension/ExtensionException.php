<?php

declare(strict_types=1);

namespace Drove\Extension;

use RuntimeException;
use Throwable;

final class ExtensionException extends RuntimeException
{
    public function __construct(
        public readonly Diagnostic $diagnostic,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('[%s] %s', $diagnostic->value, $message),
            previous: $previous,
        );
    }
}
