<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Encodes declared Unicode characters to two-byte Identity-H character codes. */
final readonly class TextEncoder
{
    public function __construct(public CharacterMap $map)
    {
    }

    public function encode(UnicodeText $text): HexadecimalString
    {
        $bytes = '';
        foreach ($text->codePoints as $point) {
            $bytes .= pack('n', $this->map->character($point)->cid);
        }
        return new HexadecimalString($bytes);
    }
}
