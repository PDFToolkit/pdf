<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\Object\StringObject\LiteralString;

/** 7.9.2.3: a string of 7-bit ASCII characters, with no Unicode transcoding. */
final class AsciiString extends LiteralString
{
    public function __construct(string $bytes)
    {
        if (preg_match('/^[\x00-\x7f]*$/D', $bytes) !== 1) {
            throw new \InvalidArgumentException('An ASCII string contains only bytes 00–7F.');
        }
        parent::__construct($bytes);
    }
}
