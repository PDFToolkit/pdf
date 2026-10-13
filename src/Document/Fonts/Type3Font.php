<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Document\Fonts\Encodings\EncodingDictionary;
use PDF\Document\CatalogComponent;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Specification\Definition\Type3FontDictionaryTable112Definition;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\BooleanType;

/** Table 112: Type 3 byte encoding, CharProcs graph, widths, matrix and explicit resources. */
final class Type3Font extends ManagedFontDictionary implements CatalogComponent, VersionedComponent
{
    public const string TABLE = '112';
    public function __construct(
        public readonly Rectangle $bounds,
        public readonly Matrix $matrix,
        public readonly Type3CharProcs $charProcs,
        public readonly EncodingDictionary $encoding,
        public readonly SimpleWidths $widths,
        ?DictionaryType $resources = null,
        ?FontDescriptor $descriptor = null,
        ?ToUnicodeCMap $toUnicode = null,
        Entry ...$entries,
    ) {
        if ($encoding->base !== null || $encoding->builtIn !== null) {
            throw new \InvalidArgumentException('A Type 3 encoding specifies its complete vector through Differences.');
        }
        foreach ($encoding->differences->glyphs as $code => $name) {
            if ($name->value === '.notdef' && !isset($charProcs->glyphs['.notdef'])) {
                continue;
            }
            $glyph = $charProcs->glyphs[$name->value] ?? throw new \InvalidArgumentException('Every encoded Type 3 glyph identifies its CharProc.');
            if ($widths->width(new IntegerType($code))->value != $glyph->width->value) {
                throw new \InvalidArgumentException('Type 3 Widths and d0/d1 displacement agree.');
            }
        }
        foreach ($widths->items as $offset => $width) {
            $code = new IntegerType($widths->first->integer() + $offset);
            if ($encoding->differences->glyph($code) === null && $width instanceof NumericType && $width->value != 0) {
                throw new \InvalidArgumentException('Unencoded Type 3 byte codes have zero width.');
            }
        }
        $allZero = $bounds->lowerLeftX->value == 0 && $bounds->lowerLeftY->value == 0 && $bounds->upperRightX->value == 0 && $bounds->upperRightY->value == 0;
        foreach ($charProcs->glyphs as $glyph) {
            if (!$allZero && $glyph->bounds !== null && (
                $glyph->bounds->lowerLeftX->value < min($bounds->lowerLeftX->value, $bounds->upperRightX->value)
                || $glyph->bounds->lowerLeftY->value < min($bounds->lowerLeftY->value, $bounds->upperRightY->value)
                || $glyph->bounds->upperRightX->value > max($bounds->lowerLeftX->value, $bounds->upperRightX->value)
                || $glyph->bounds->upperRightY->value > max($bounds->lowerLeftY->value, $bounds->upperRightY->value)
            )) {
                throw new \InvalidArgumentException('The nonzero Type 3 FontBBox encloses all declared d1 glyph boxes.');
            }
        }
        $managed = ['Type', 'Subtype', 'FontBBox', 'FontMatrix', 'CharProcs', 'Encoding', 'FirstChar', 'LastChar', 'Widths', 'Resources', 'FontDescriptor', 'ToUnicode'];
        foreach ($entries as $entry) {
            if (in_array($entry->key()->value, $managed, true)) {
                throw new \InvalidArgumentException('The Type 3 graph manages /' . $entry->key()->value . '.');
            }
        }
        $required = [
            new Entry(new NameType('Type'), new NameType('Font')),
            new Entry(new NameType('Subtype'), new NameType('Type3')),
            new Entry(new NameType('FontBBox'), $bounds),
            new Entry(new NameType('FontMatrix'), new ArrayType(...$matrix->operands())),
            new Entry(new NameType('CharProcs'), $charProcs),
            new Entry(new NameType('Encoding'), $encoding),
            new Entry(new NameType('FirstChar'), $widths->first),
            new Entry(new NameType('LastChar'), $widths->last),
            new Entry(new NameType('Widths'), new IndirectReference($widths)),
        ];
        if ($resources !== null) {
            $required[] = new Entry(new NameType('Resources'), $resources);
        }
        if ($descriptor !== null) {
            $descriptor->assertFor(FontSubtype::Type3);
            $required[] = new Entry(new NameType('FontDescriptor'), new IndirectReference($descriptor));
        }
        if ($toUnicode !== null) {
            foreach ($toUnicode->codeSpaces()->ranges as $space) {
                if ($space->low->length() !== 1) {
                    throw new \InvalidArgumentException('Type 3 ToUnicode maps byte codes.');
                }
            }
            $required[] = new Entry(new NameType('ToUnicode'), new IndirectReference($toUnicode));
        }
        parent::__construct(new Type3FontDictionaryTable112Definition(), $managed, ...[...$required, ...$entries]);
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $markInfo = $catalog->get('MarkInfo');
        $markInfo = $markInfo instanceof IndirectReference ? $markInfo->target() : $markInfo;
        $marked = $markInfo instanceof DictionaryType ? $markInfo->get('Marked') : null;
        if ($marked instanceof BooleanType && $marked->value && !$this->has('FontDescriptor')) {
            throw new \InvalidArgumentException('A Type 3 font used in Tagged PDF requires an indirect FontDescriptor.');
        }
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        if ($version->value === PdfVersion::PDF10 && !$this->has('Name')) {
            throw new \LogicException('PDF 1.0 Type3 dictionaries require the resource Name.');
        }
    }
}
