<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Filters\Filter;

/** Table 117: two-byte, big-endian glyph indices, including unmapped CID gaps. */
final class CIDToGIDMap extends FontStream
{
    public function __construct(public readonly CharacterMap $map, Filter ...$filters)
    {
        $bytes = "\x00\x00";
        $last = 0;
        foreach ($map->mappings as $mapping) {
            $bytes .= str_repeat("\x00\x00", $mapping->cid - $last - 1) . pack('n', $mapping->glyphId);
            $last = $mapping->cid;
        }
        parent::__construct($bytes, null, ...$filters);
    }
}
