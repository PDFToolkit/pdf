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
use PDF\Syntax\Object\NameType;

/** Table 117: normalized CFF CID font or external named CID font, with explicit W/DW/W2/DW2. */
final class CIDFontType0 extends ManagedFontDictionary implements VersionedComponent
{
    public const string TABLE = '117';

    public function __construct(public readonly FontDescriptor $descriptor, public readonly CMapSystemInfo $systemInfo, public readonly CIDMetrics $metrics = new CIDMetrics(), Entry ...$entries)
    {
        $descriptor->assertFor(FontSubtype::CIDFontType0);
        $descriptor->assertCollection($systemInfo);
        $managed = ['Type', 'Subtype', 'BaseFont', 'CIDSystemInfo', 'FontDescriptor', 'DW', 'W', 'DW2', 'W2', 'CIDToGIDMap'];
        foreach ($entries as $entry) {
            if (in_array($entry->key()->value, $managed, true)) {
                throw new \InvalidArgumentException('The Type 0 CID font manages /' . $entry->key()->value . '.');
            }
        }
        parent::__construct(
            new CIDFontDictionaryTable117Definition(),
            $managed,
            new Entry(new NameType('Type'), new NameType('Font')),
            new Entry(new NameType('Subtype'), new NameType('CIDFontType0')),
            new Entry(new NameType('BaseFont'), $descriptor->fontName),
            new Entry(new NameType('CIDSystemInfo'), $systemInfo->dictionary()),
            new Entry(new NameType('FontDescriptor'), new IndirectReference($descriptor)),
            ...[...$metrics->entries(), ...$entries]
        );
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF12, 'Type 0 CID font');
    }
}
