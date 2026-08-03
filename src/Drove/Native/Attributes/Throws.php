<?php

declare(strict_types=1);

namespace Drove\Native\Attributes;

use Attribute;
use InvalidArgumentException;
use Throwable;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Throws
{
    /** @param non-empty-string|null $class */
    public function __construct(
        public ?string $class = null,
        public ?string $message = null,
        public ?int $code = null,
    ) {
        if ($class === null && $message === null && $code === null) {
            throw new InvalidArgumentException('A native Throws attribute requires a class, message, or code.');
        }

        if ($class !== null && ! is_a($class, Throwable::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Native expected exception %s is not throwable.',
                $class,
            ));
        }
    }

}
