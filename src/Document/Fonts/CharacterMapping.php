<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\NumericType;

/** One Unicode scalar value, CID, glyph index and width in PDF's 1000-unit space. */
final readonly class CharacterMapping
{
    public function __construct(public int $codePoint, public int $cid, public int $glyphId, public NumericType $width)
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) || $cid < 1 || $cid > 0xFFFF || $glyphId < 1 || $glyphId > 0xFFFF || $width->value < 0) {
            throw new \InvalidArgumentException('Invalid Unicode, CID, GID or PDF glyph width.');
        }
    }
}
