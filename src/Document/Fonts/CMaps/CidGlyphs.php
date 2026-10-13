<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;

/** Explicit knowledge of which CIDs have glyphs; never inspects a font program. */
final readonly class CidGlyphs
{
    /** @var array<int, true> */
    private array $available;

    public function __construct(IntegerType ...$cids)
    {
        $available = [];
        foreach ($cids as $cid) {
            if ($cid->integer() < 0 || $cid->integer() > 65535) {
                throw new \InvalidArgumentException('A PDF 1.7 CID is between zero and 65535.');
            }
            $available[$cid->integer()] = true;
        }
        $this->available = $available;
    }
    public function contains(IntegerType $cid): bool
    {
        return isset($this->available[$cid->integer()]);
    }
}
