<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\IntegerType;

final readonly class CIDGlyphIndex
{
    public function __construct(public IntegerType $cid, public IntegerType $gid)
    {
        if ($cid->integer() < 0 || $cid->integer() > 65535 || $gid->integer() < 0 || $gid->integer() > 65535) {
            throw new \InvalidArgumentException('CIDs and glyph indices are unsigned sixteen-bit values.');
        }
    }
}
