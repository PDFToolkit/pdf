<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Explicit PDF repertoire; this value never reads a font program or UTF-8 text. */
final readonly class CharacterMap
{
    /** @var array<int, CharacterMapping> */
    private array $characters;
    /** @var list<CharacterMapping> */
    public array $mappings;

    public function __construct(public FontFile2 $fontFile, public NameType $fontName, public NumericType $notdefWidth, CharacterMapping ...$mappings)
    {
        if ($notdefWidth->value < 0) {
            throw new \InvalidArgumentException('The PDF width of CID 0 must be nonnegative.');
        }
        $characters = [];
        $cids = [];
        foreach ($mappings as $mapping) {
            if (isset($characters[$mapping->codePoint]) || isset($cids[$mapping->cid])) {
                throw new \InvalidArgumentException('Unicode scalar values and CIDs must be unique within a repertoire.');
            }
            $characters[$mapping->codePoint] = $mapping;
            $cids[$mapping->cid] = $mapping;
        }
        ksort($cids, SORT_NUMERIC);
        $this->characters = $characters;
        $this->mappings = array_values($cids);
    }

    public function character(int $codePoint): CharacterMapping
    {
        return $this->characters[$codePoint] ?? throw new \OutOfRangeException(sprintf('U+%04X is not in this font repertoire.', $codePoint));
    }
}
