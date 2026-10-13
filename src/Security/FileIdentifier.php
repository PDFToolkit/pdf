<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\StringType;

/** One explicit 128-bit file identifier, supplied as a PDF byte-string object. */
final readonly class FileIdentifier
{
    public function __construct(public StringType $value)
    {
        if (strlen($value->value) !== 16) {
            throw new \InvalidArgumentException('A file identifier must contain exactly 16 bytes.');
        }
    }
}
