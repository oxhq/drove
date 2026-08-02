<?php

declare(strict_types=1);

namespace Drove\Migration;

use Drove\Compatibility\Status;
use InvalidArgumentException;
use JsonSerializable;

final readonly class Finding implements JsonSerializable
{
    public function __construct(
        public string $surface,
        public Status $status,
        public string $construct,
        public string $path,
        public int $line,
        public int $column,
        public string $diagnostic,
        public ?string $codemod,
    ) {
        if ($surface === ''
            || $construct === ''
            || $path === ''
            || $line < 1
            || $column < 1
            || preg_match('/^DROVE_[A-Z0-9_]+$/D', $diagnostic) !== 1
            || ($codemod !== null && $codemod === '')) {
            throw new InvalidArgumentException('Drove received an invalid migration finding.');
        }
    }

    /**
     * @return array{
     *     surface: string,
     *     status: string,
     *     construct: string,
     *     path: string,
     *     line: int,
     *     column: int,
     *     diagnostic: string,
     *     codemod: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'surface' => $this->surface,
            'status' => $this->status->value,
            'construct' => $this->construct,
            'path' => $this->path,
            'line' => $this->line,
            'column' => $this->column,
            'diagnostic' => $this->diagnostic,
            'codemod' => $this->codemod,
        ];
    }
}
