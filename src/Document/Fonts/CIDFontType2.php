<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Document\Fonts\CMaps\CMapSystemInfo;
use PDF\Specification\Definition\CIDFontDictionaryTable117Definition;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;

/** Table 117: repertoire-bound or independently normalized Type 2 CID font, with vertical metrics. */
final class CIDFontType2 extends ManagedFontDictionary implements VersionedComponent
{
    public const string TABLE = '117';
    public readonly ?CharacterMap $map;
    public readonly CMapSystemInfo $systemInfo;

    public function __construct(public readonly FontDescriptor $descriptor, CIDToGIDMap|CIDGlyphIndexMap|CIDIdentityGlyphMap|null $glyphMap, CIDWidths|CIDMetrics $widths, Entry|CIDVerticalMetrics|CMapSystemInfo ...$entries)
    {
        $descriptor->assertFor(FontSubtype::CIDFontType2);
        $this->map = $glyphMap instanceof CIDToGIDMap ? $glyphMap->map : null;
        if ($glyphMap instanceof CIDToGIDMap) {
            if (!$widths instanceof CIDWidths || $glyphMap->map !== $widths->map || $glyphMap->map->fontFile !== $descriptor->fontFile || $glyphMap->map->fontName->value !== $descriptor->fontName->value) {
                throw new \InvalidArgumentException('Descriptor, CID map and widths describe the same embedded font, name and repertoire.');
            }
        } elseif ($widths instanceof CIDWidths) {
            throw new \InvalidArgumentException('Repertoire-derived widths require their matching repertoire-derived glyph map.');
        }
        if (($descriptor->fontFile === null) !== ($glyphMap === null)) {
            throw new \InvalidArgumentException('Only embedded Type 2 CID fonts supply a CID-to-GID mapping; embedded programs supply one explicitly.');
        }
        $managed = ['Type', 'Subtype', 'BaseFont', 'CIDSystemInfo', 'FontDescriptor', 'DW', 'W', 'DW2', 'W2', 'CIDToGIDMap'];
        $additional = [];
        $vertical = null;
        $info = null;
        foreach ($entries as $entry) {
            if ($entry instanceof CIDVerticalMetrics) {
                if ($vertical !== null || ($widths instanceof CIDMetrics && $widths->vertical !== null)) {
                    throw new \InvalidArgumentException('A CID font has one vertical metric set.');
                }
                $vertical = $entry;
            } elseif ($entry instanceof CMapSystemInfo) {
                if ($info !== null) {
                    throw new \InvalidArgumentException('A CID font has one character collection.');
                }
                $info = $entry;
            } else {
                if (in_array($entry->key()->value, $managed, true)) {
                    throw new \InvalidArgumentException('The CID font manages /' . $entry->key()->value . '.');
                }
                $additional[] = $entry;
            }
        }
        $this->systemInfo = $info ?? new CMapSystemInfo(new LiteralString('Adobe'), new LiteralString('Identity'), new IntegerType(0));
        $descriptor->assertCollection($this->systemInfo);
        $required = [
            new Entry(new NameType('Type'), new NameType('Font')),
            new Entry(new NameType('Subtype'), new NameType('CIDFontType2')),
            new Entry(new NameType('BaseFont'), $descriptor->fontName),
            new Entry(new NameType('CIDSystemInfo'), $this->systemInfo->dictionary()),
            new Entry(new NameType('FontDescriptor'), new IndirectReference($descriptor)),
        ];
        if ($widths instanceof CIDMetrics) {
            array_push($required, ...$widths->entries());
        } else {
            $required[] = new Entry(new NameType('DW'), new IntegerType(0));
            $required[] = new Entry(new NameType('W'), $widths);
        }
        if ($vertical !== null) {
            $required[] = new Entry(new NameType('DW2'), $vertical->defaults);
            $required[] = new Entry(new NameType('W2'), $vertical->widths);
        }
        if ($glyphMap !== null) {
            $required[] = new Entry(new NameType('CIDToGIDMap'), $glyphMap instanceof CIDIdentityGlyphMap ? $glyphMap->object() : new IndirectReference($glyphMap));
        }
        parent::__construct(new CIDFontDictionaryTable117Definition(), $managed, ...[...$required, ...$additional]);
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF12, 'Type 2 CID font');
        if ($this->descriptor->fontFile instanceof FontFile2) {
            $version->require(PdfVersion::PDF13, 'Embedded TrueType Type 2 CID font');
        }
    }
}
