<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\Fonts\TrueType\CharacterMap;
use PDF\Document\Fonts\TrueType\CIDToGIDMap;
use PDF\Document\Fonts\TrueType\CIDWidths;
use PDF\Specification\Definition\CIDFontDictionaryTable117Definition;
use PDF\Specification\Dictionary\CIDSystemInfoDictionaryTable116;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;

/** A horizontal CIDFontType2 using the declared CID-to-glyph mapping. */
final class CIDFontType2 extends ManagedFontDictionary
{
    public const string TABLE = '117';
    public readonly CharacterMap $map;

    public function __construct(public readonly FontDescriptor $descriptor, CIDToGIDMap $glyphMap, CIDWidths $widths, Entry ...$entries)
    {
        if ($glyphMap->map !== $widths->map || $glyphMap->map->program !== $descriptor->fontFile->program->source) {
            throw new \InvalidArgumentException('Descriptor, CID map and widths must describe the same font program and repertoire.');
        }
        $this->map = $glyphMap->map;
        $managed = ['Type', 'Subtype', 'BaseFont', 'CIDSystemInfo', 'FontDescriptor', 'DW', 'W', 'DW2', 'W2', 'CIDToGIDMap'];
        foreach ($entries as $entry) {
            if (in_array($entry->key()->value, $managed, true)) {
                throw new \InvalidArgumentException('The CID font manages /' . $entry->key()->value . '.');
            }
        }
        parent::__construct(
            new CIDFontDictionaryTable117Definition(),
            $managed,
            new Entry(new NameType('Type'), new NameType('Font')),
            new Entry(new NameType('Subtype'), new NameType('CIDFontType2')),
            new Entry(new NameType('BaseFont'), new NameType($this->map->program->postScriptName)),
            new Entry(new NameType('CIDSystemInfo'), new CIDSystemInfoDictionaryTable116(
                new Entry(new NameType('Registry'), new LiteralString('Adobe')),
                new Entry(new NameType('Ordering'), new LiteralString('Identity')),
                new Entry(new NameType('Supplement'), new IntegerType(0)),
            )),
            new Entry(new NameType('FontDescriptor'), new IndirectReference($descriptor)),
            new Entry(new NameType('DW'), new IntegerType(0)),
            new Entry(new NameType('W'), $widths),
            new Entry(new NameType('CIDToGIDMap'), new IndirectReference($glyphMap)),
            ...$entries,
        );
    }
}
