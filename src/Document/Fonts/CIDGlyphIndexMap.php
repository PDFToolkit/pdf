<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** Table 117: explicitly normalized CID-to-GID indices without reading a font program. */
final class CIDGlyphIndexMap extends FontStream
{
    /** @var array<int, int> */
    public readonly array $indices;
    public function __construct(CIDGlyphIndex ...$indices)
    {
        $map = [];
        foreach ($indices as $index) {
            $cid = $index->cid->integer();
            if (isset($map[$cid])) {
                throw new \InvalidArgumentException('A CID-to-GID map has one mapping per CID.');
            }
            $map[$cid] = $index->gid->integer();
        }
        if (($map[0] ?? null) !== 0) {
            throw new \InvalidArgumentException('The explicit CID 0 mapping identifies the .notdef glyph index 0.');
        }
        ksort($map);
        $this->indices = $map;
        $bytes = '';
        for ($cid = 0, $last = max(array_keys($map)); $cid <= $last; ++$cid) {
            $bytes .= pack('n', $map[$cid] ?? 0);
        }
        parent::__construct($bytes, null);
    }
}
