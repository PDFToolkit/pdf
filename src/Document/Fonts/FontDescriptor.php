<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Specification\Definition\FontDescriptorsTable122Definition;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\DictionaryType;

/** Table 122 descriptor built solely from explicit PDF values. */
final class FontDescriptor extends ManagedFontDictionary implements VersionedComponent
{
    public const string TABLE = '122';
    public readonly FontDescriptorOptions $options;
    public readonly NameType $fontName;
    public readonly ?SubsetFontName $subset;

    public function __construct(
        public readonly (FontStream&EmbeddedFontProgram)|null $fontFile,
        NameType|SubsetFontName $fontName,
        FontDescriptorFlags $flags,
        ?Rectangle $fontBBox,
        NumericType $italicAngle,
        ?NumericType $ascent,
        ?NumericType $descent,
        ?NumericType $capHeight,
        ?NumericType $stemV,
        Entry|FontDescriptorOptions ...$entries,
    ) {
        if ($fontName instanceof NameType && preg_match('/^[A-Z]{6}\+/', $fontName->value) === 1) {
            throw new \InvalidArgumentException('A reserved subset-name prefix is represented explicitly by SubsetFontName.');
        }
        $this->subset = $fontName instanceof SubsetFontName ? $fontName : null;
        $this->fontName = $fontName instanceof SubsetFontName ? $fontName->name() : $fontName;
        if (($stemV !== null && $stemV->value < 0) || ($capHeight !== null && $capHeight->value < 0) || ($ascent !== null && $ascent->value < 0) || ($descent !== null && $descent->value > 0)) {
            throw new \InvalidArgumentException('Font descriptor heights, ascent, descent and StemV must have valid signs.');
        }
        $managed = ['Type', 'FontName', 'Flags', 'FontBBox', 'ItalicAngle', 'Ascent', 'Descent', 'CapHeight', 'StemV', 'FontFile', 'FontFile2', 'FontFile3'];
        $additional = [];
        $options = null;
        foreach ($entries as $entry) {
            if ($entry instanceof FontDescriptorOptions) {
                if ($options !== null) {
                    throw new \InvalidArgumentException('A font descriptor has exactly one repertoire declaration.');
                }
                $options = $entry;
                continue;
            }
            if (in_array($entry->key()->value, $managed, true)) {
                throw new \InvalidArgumentException('The font descriptor manages /' . $entry->key()->value . '.');
            }
            $key = $entry->key()->value;
            $value = $entry->value;
            if ($key === 'FontWeight' && (!$value instanceof NumericType || ($value->value < 1 || $value->value > 1000))) {
                throw new \InvalidArgumentException('FontWeight is a number between 1 and 1000 inclusive (EC3 #474).');
            }
            if ($key === 'FontStretch' && (!$value instanceof NameType || FontStretch::tryFrom($value->value) === null)) {
                throw new \InvalidArgumentException('FontStretch selects a Table 122 stretch name.');
            }
            if ($key === 'FontFamily' && !$value instanceof StringType) {
                throw new \InvalidArgumentException('FontFamily is a byte string.');
            }
            if ($key === 'CharSet' && (!$value instanceof FontCharSet || $fontFile === null || $this->subset === null)) {
                throw new \InvalidArgumentException('CharSet identifies the explicit glyph names of an embedded Type 1 subset.');
            }
            if ($key === 'CIDSet' && (!$value instanceof IndirectReference || !$value->target() instanceof CIDSet || $fontFile === null || $this->subset === null)) {
                throw new \InvalidArgumentException('CIDSet is an indirect presence stream for an explicitly named embedded CID subset.');
            }
            if ($key === 'Style' && !$value instanceof FontStyle) {
                throw new \InvalidArgumentException('CID Style is constructed from an explicit twelve-byte Panose.');
            }
            if ($key === 'FD' && !$value instanceof CIDFontDescriptors) {
                throw new \InvalidArgumentException('FD is an explicit character-collection-bound glyph-class descriptor graph.');
            }
            if ($key === 'Lang' && (!$value instanceof NameType || preg_match('/^[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*$/D', $value->value) !== 1)) {
                throw new \InvalidArgumentException('Font Lang is an RFC 3066 language tag name.');
            }
            $additional[] = $entry;
        }
        $this->options = $options ?? new FontDescriptorOptions();
        $required = [
            new Entry(new NameType('Type'), new NameType('FontDescriptor')),
            new Entry(new NameType('FontName'), $this->fontName),
            new Entry(new NameType('Flags'), $flags),
            new Entry(new NameType('ItalicAngle'), $italicAngle),
        ];
        foreach (['FontBBox' => $fontBBox, 'Ascent' => $ascent, 'Descent' => $descent, 'CapHeight' => $capHeight, 'StemV' => $stemV] as $key => $value) {
            if ($value !== null) {
                $required[] = new Entry(new NameType($key), $value);
            }
        }
        if ($fontFile !== null) {
            $required[] = new Entry(new NameType($fontFile->descriptorKey()), new IndirectReference($fontFile));
        }
        parent::__construct(
            new FontDescriptorsTable122Definition(),
            [...$managed, 'FontWeight', 'FontStretch', 'FontFamily', 'CharSet', 'CIDSet', 'Style', 'Lang', 'FD'],
            ...[...$required, ...$additional],
        );
    }

    public function assertFor(FontSubtype $subtype): void
    {
        if ($subtype !== FontSubtype::Type3) {
            foreach (['FontBBox', 'Ascent', 'Descent', 'StemV'] as $key) {
                if (!$this->has($key)) {
                    throw new \InvalidArgumentException('A non-Type3 descriptor requires /' . $key . '.');
                }
            }
            if ($this->options->hasLatinCharacters && !$this->has('CapHeight')) {
                throw new \InvalidArgumentException('A font with Latin characters requires CapHeight.');
            }
        }
        if ($this->fontFile !== null && !$this->fontFile->supports($subtype)) {
            throw new \InvalidArgumentException('The embedded font stream format is incompatible with the PDF font subtype.');
        }
        if ($this->has('CharSet') && !in_array($subtype, [FontSubtype::Type1, FontSubtype::MMType1], true)) {
            throw new \InvalidArgumentException('CharSet is meaningful only for Type 1 font subsets.');
        }
        if (!in_array($subtype, [FontSubtype::CIDFontType0, FontSubtype::CIDFontType2], true) && ($this->has('CIDSet') || $this->has('Style') || $this->has('FD'))) {
            throw new \InvalidArgumentException('CIDSet, Style and FD belong to CID font descriptors.');
        }
    }

    public function assertCollection(\PDF\Document\Fonts\CMaps\CMapSystemInfo $collection): void
    {
        $classes = $this->get('FD');
        if ($classes instanceof CIDFontDescriptors) {
            $classes->assertCollection($collection);
        }
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $weight = $this->get('FontWeight');
        if ($weight instanceof NumericType && $version->value !== PdfVersion::PDF20
            && !in_array($weight->value, [100, 200, 300, 400, 500, 600, 700, 800, 900], false)) {
            throw new \LogicException('FontWeight outside the traditional 100-step weights requires PDF 2.0 (EC3 #474).');
        }
        foreach (['Style', 'FD', 'CIDSet'] as $key) {
            if ($this->has($key)) {
                $version->require(PdfVersion::PDF12, 'CID font descriptor ' . $key);
            }
        }
        if ($this->has('Lang')) {
            $version->require(PdfVersion::PDF15, 'Font descriptor language');
        }
    }
}
