<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\StringType;

/** Uninterpreted filename bytes, including empty components (7.11.2.1, PDF page108). */
final readonly class FileNameComponent
{
    public function __construct(public StringType $bytes)
    {
    }

    public function escapedBytes(): string
    {
        return str_replace(['\\', '/'], ['\\\\', '\\/'], $this->bytes->value);
    }
}
