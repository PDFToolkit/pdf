<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Filters\Filter;

/** 9.10.3: character codes map to UTF-16BE, including surrogate pairs. */
final class ToUnicodeCMap extends FontStream
{
    public function __construct(public readonly CharacterMap $map, Filter ...$filters)
    {
        $lines = [
            '/CIDInit /ProcSet findresource begin', '12 dict begin', 'begincmap',
            '/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def',
            '/CMapName /Adobe-Identity-UCS def', '/CMapType 2 def',
            '1 begincodespacerange', '<0000> <FFFF>', 'endcodespacerange',
        ];
        foreach (array_chunk($map->mappings, 100) as $chunk) {
            $lines[] = count($chunk) . ' beginbfchar';
            foreach ($chunk as $mapping) {
                $point = $mapping->codePoint;
                $utf16 = $point <= 0xFFFF ? pack('n', $point) : pack('nn', 0xD800 + (($point - 0x10000) >> 10), 0xDC00 + (($point - 0x10000) & 0x3FF));
                $lines[] = sprintf('<%04X> <%s>', $mapping->cid, strtoupper(bin2hex($utf16)));
            }
            $lines[] = 'endbfchar';
        }
        array_push($lines, 'endcmap', 'CMapName currentdict /CMap defineresource pop', 'end', 'end');
        parent::__construct(implode("\n", $lines) . "\n", null, ...$filters);
    }
}
