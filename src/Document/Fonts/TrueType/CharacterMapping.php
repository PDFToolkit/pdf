<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** One character has its own CID even when another character uses the same glyph. */
final readonly class CharacterMapping
{
    public function __construct(public int $codePoint, public int $cid, public int $glyphId, public float $width)
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) || $cid < 1 || $cid > 0xFFFF || $glyphId < 1 || $glyphId > 0xFFFF || !is_finite($width) || $width < 0) {
            throw new \InvalidArgumentException('Invalid Unicode, CID, GID or glyph width.');
        }
    }
}
