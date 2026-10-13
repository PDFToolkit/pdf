<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\DataStructure\ByteString;

/** 9.8.3.2: two family-class bytes followed by ten external PANOSE classification bytes. */
final class Panose extends ByteString
{
    public function __construct(string $bytes)
    {
        if (strlen($bytes) !== 12) {
            throw new \InvalidArgumentException('PDF Panose contains exactly twelve bytes.');
        }
        parent::__construct($bytes);
    }
}
