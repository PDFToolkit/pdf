<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

use PDF\Syntax\Filters\Filter;

/** Table 117: two-byte, big-endian glyph indices, starting with CID 0. */
final class CIDToGIDMap extends FontStream
{
    public function __construct(public readonly CharacterMap $map, Filter ...$filters)
    {
        $bytes = "\x00\x00";
        foreach ($map->mappings as $mapping) {
            $bytes .= pack('n', $mapping->glyphId);
        }
        parent::__construct($bytes, null, ...$filters);
    }
}
