<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\Fonts\TrueType\FontFile2;
use PDF\Specification\Definition\FontDescriptorsTable122Definition;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Table 122 font descriptor, with explicit StemV and classification flags. */
final class FontDescriptor extends ManagedFontDictionary
{
    public const string TABLE = '122';

    public function __construct(public readonly FontFile2 $fontFile, FontDescriptorFlags $flags, NumericType $stemV, ?NumericType $capHeight = null, Entry ...$entries)
    {
        $program = $fontFile->program->source;
        $metrics = $program->metrics;
        if ($stemV->value < 0) {
            throw new \InvalidArgumentException('StemV must be nonnegative.');
        }
        $capHeight ??= $program->capHeight === null ? null : new NumericType($metrics->scale($program->capHeight));
        if ($capHeight === null || $capHeight->value < 0) {
            throw new \InvalidArgumentException('A nonnegative CapHeight must be supplied when absent from font metrics.');
        }
        $box = array_map(static fn (int $value): NumericType => new NumericType($metrics->scale($value)), $program->boundingBox);
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
            new Entry(new NameType('FontName'), new NameType($program->postScriptName)),
            new Entry(new NameType('Flags'), $flags),
            new Entry(new NameType('FontBBox'), new Rectangle(...$box)),
            new Entry(new NameType('ItalicAngle'), new NumericType($program->italicAngle)),
            new Entry(new NameType('Ascent'), new NumericType($metrics->scale($program->ascent))),
            new Entry(new NameType('Descent'), new NumericType($metrics->scale($program->descent))),
            new Entry(new NameType('CapHeight'), $capHeight),
            new Entry(new NameType('StemV'), $stemV),
            new Entry(new NameType('FontFile2'), new IndirectReference($fontFile)),
            ...$entries,
        );
    }
}
