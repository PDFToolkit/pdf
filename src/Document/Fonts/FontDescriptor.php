<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Specification\Definition\FontDescriptorsTable122Definition;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Table 122 descriptor built solely from explicit PDF values. */
final class FontDescriptor extends ManagedFontDictionary
{
    public const string TABLE = '122';

    public function __construct(
        public readonly FontFile2 $fontFile,
        public readonly NameType $fontName,
        FontDescriptorFlags $flags,
        Rectangle $fontBBox,
        NumericType $italicAngle,
        NumericType $ascent,
        NumericType $descent,
        NumericType $capHeight,
        NumericType $stemV,
        Entry ...$entries,
    ) {
        if ($stemV->value < 0 || $capHeight->value < 0 || $ascent->value < 0 || $descent->value > 0) {
            throw new \InvalidArgumentException('Font descriptor heights, ascent, descent and StemV must have valid signs.');
        }
        $managed = ['Type', 'FontName', 'Flags', 'FontBBox', 'ItalicAngle', 'Ascent', 'Descent', 'CapHeight', 'StemV', 'FontFile', 'FontFile2', 'FontFile3', 'CharSet'];
        foreach ($entries as $entry) {
            if (in_array($entry->key()->value, $managed, true)) {
                throw new \InvalidArgumentException('The font descriptor manages /' . $entry->key()->value . '.');
            }
        }
        parent::__construct(
            new FontDescriptorsTable122Definition(),
            $managed,
            new Entry(new NameType('Type'), new NameType('FontDescriptor')),
            new Entry(new NameType('FontName'), $fontName),
            new Entry(new NameType('Flags'), $flags),
            new Entry(new NameType('FontBBox'), $fontBBox),
            new Entry(new NameType('ItalicAngle'), $italicAngle),
            new Entry(new NameType('Ascent'), $ascent),
            new Entry(new NameType('Descent'), $descent),
            new Entry(new NameType('CapHeight'), $capHeight),
            new Entry(new NameType('StemV'), $stemV),
            new Entry(new NameType('FontFile2'), new IndirectReference($fontFile)),
            ...$entries,
        );
    }
}
