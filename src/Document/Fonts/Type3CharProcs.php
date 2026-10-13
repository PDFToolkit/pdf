<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\IndirectReference;

/** Table 112 CharProcs: exact glyph-name to indirect stream identities. */
final class Type3CharProcs extends DictionaryType
{
    /** @var array<string, Type3Glyph> */
    public readonly array $glyphs;
    public function __construct(Type3CharProc ...$glyphs)
    {
        $entries = [];
        $map = [];
        foreach ($glyphs as $glyph) {
            if (isset($map[$glyph->name->value])) {
                throw new \InvalidArgumentException('A Type 3 glyph name has one CharProc.');
            }
            $map[$glyph->name->value] = $glyph->glyph;
            $entries[] = new Entry($glyph->name, new IndirectReference($glyph->glyph));
        }
        $this->glyphs = $map;
        parent::__construct(...$entries);
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct a new CharProcs object to replace glyphs.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Construct a new CharProcs object to replace glyphs.');
    }
}
