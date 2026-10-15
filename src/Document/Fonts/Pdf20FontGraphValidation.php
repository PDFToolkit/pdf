<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/**
 * Effective simple-font metrics, including raw and retained revision graphs.
 * ISO32000-2:2020, 9.6.2.1/Table109 pp328–329, 9.6.4/Table110
 * pp332–333 and 9.8.1/Table120 pp358–360 of the retained source PDF.
 * This validates PDF value relationships, not the decoded font program,
 * actual glyph outlines, substitution availability or profile conformance.
 */
final class Pdf20FontGraphValidation
{
    public function validate(BasicType $value, DictionaryType $dictionary, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        if ($version->value !== PdfVersion::PDF20) {
            return;
        }
        $type = $this->resolved($dictionary, 'Type', $graph);
        $subtype = $this->resolved($dictionary, 'Subtype', $graph);
        if ($type instanceof NameType && $type->value === 'Font') {
            $this->font($dictionary, $graph);
        }

        // Resources is a PDF key only in its owning container. An application
        // dictionary may use the same spelling for unrelated private data.
        $pageOwner = $type instanceof NameType && in_array($type->value, ['Page', 'Pages'], true);
        $type3Owner = $type instanceof NameType && $type->value === 'Font' && $subtype instanceof NameType && $subtype->value === 'Type3';
        $pattern = $this->resolved($dictionary, 'PatternType', $graph);
        $formOwner = $value instanceof StreamObject && $subtype instanceof NameType && $subtype->value === 'Form' && ($type === null || $type instanceof NameType && $type->value === 'XObject');
        $patternOwner = $value instanceof StreamObject && $pattern instanceof IntegerType && $pattern->integer() === 1 && ($type === null || $type instanceof NameType && $type->value === 'Pattern');
        if (!$pageOwner && !$type3Owner && !$formOwner && !$patternOwner) {
            return;
        }
        $resources = $this->resolved($dictionary, 'Resources', $graph);
        if (!$resources instanceof DictionaryType) {
            return;
        }
        $fonts = $this->resolved($resources, 'Font', $graph);
        if ($fonts === null) {
            return;
        }
        if (!$fonts instanceof DictionaryType) {
            throw new \InvalidArgumentException('Resources.Font requires a font resource dictionary.');
        }
        foreach ($fonts->entries() as $entry) {
            $projection = $entry->value instanceof IndirectReference ? $graph : $graph->forDirectValue($entry->value);
            $font = $projection->dictionary($entry->value);
            if ($font === null || $entry->value instanceof StreamObject || $projection->resolve($entry->value) instanceof StreamObject) {
                throw new \InvalidArgumentException('Each font resource requires a font dictionary, not a stream.');
            }
            $this->font($font, $projection);
        }
    }

    private function font(DictionaryType $font, DocumentGraphContext $graph): void
    {
        $type = $this->resolved($font, 'Type', $graph);
        $subtype = $this->resolved($font, 'Subtype', $graph);
        if (!$type instanceof NameType || $type->value !== 'Font' || !$subtype instanceof NameType) {
            throw new \InvalidArgumentException('A font dictionary requires Type Font and a name Subtype.');
        }
        $isType3 = $subtype->value === 'Type3';
        if (!$isType3 && !in_array($subtype->value, ['Type1', 'MMType1', 'TrueType'], true)) {
            return;
        }
        $first = $this->resolved($font, 'FirstChar', $graph);
        $last = $this->resolved($font, 'LastChar', $graph);
        $widths = $this->resolved($font, 'Widths', $graph);
        if (!$first instanceof IntegerType || !$last instanceof IntegerType || $first->integer() < 0 || $last->integer() > 255 || $first->integer() > $last->integer() || !$widths instanceof ArrayType || count($widths) !== $last->integer() - $first->integer() + 1) {
            throw new \InvalidArgumentException('PDF 2.0 simple-font FirstChar, LastChar and Widths must define one complete byte-code metric range (Tables109/110).');
        }
        foreach ($widths as $width) {
            if (!$graph->resolve($width) instanceof NumericType) {
                throw new \InvalidArgumentException('Every simple-font Widths member must resolve to a number.');
            }
        }
        $baseFont = $this->resolved($font, 'BaseFont', $graph);
        if (!$isType3 && (!$baseFont instanceof NameType || $baseFont->value === '')) {
            throw new \InvalidArgumentException('A simple font requires a nonempty BaseFont name.');
        }
        $reference = $graph->entry($font, 'FontDescriptor');
        if ($reference === null && $isType3 && !$this->tagged($graph)) {
            return;
        }
        if (!$reference instanceof IndirectReference || $graph->resolve($reference) instanceof StreamObject) {
            throw new \InvalidArgumentException('PDF 2.0 simple fonts require an indirect FontDescriptor; Type3 may omit it only outside Tagged PDF.');
        }
        $descriptor = $graph->dictionary($reference);
        if ($descriptor === null) {
            throw new \InvalidArgumentException('FontDescriptor must resolve to a dictionary.');
        }
        $fontName = $isType3 ? $this->resolved($font, 'Name', $graph) : $baseFont;
        if ($fontName !== null && !$fontName instanceof NameType) {
            throw new \InvalidArgumentException('A font Name or BaseFont must be a PDF name.');
        }
        $this->descriptor($descriptor, $graph, $isType3, $fontName);
    }

    private function descriptor(DictionaryType $descriptor, DocumentGraphContext $graph, bool $isType3, ?NameType $baseFont): void
    {
        $type = $this->resolved($descriptor, 'Type', $graph);
        $name = $this->resolved($descriptor, 'FontName', $graph);
        $flags = $this->resolved($descriptor, 'Flags', $graph);
        if (!$type instanceof NameType || $type->value !== 'FontDescriptor' || (!$isType3 && $name === null) || ($name !== null && (!$name instanceof NameType || $name->value === '')) || !$flags instanceof IntegerType || !$this->resolved($descriptor, 'ItalicAngle', $graph) instanceof NumericType) {
            throw new \InvalidArgumentException('A FontDescriptor requires Type FontDescriptor, integer Flags, numeric ItalicAngle and FontName for non-Type3 fonts (EC3 #11, Table120).');
        }
        new FontDescriptorFlags($flags->integer());
        if ($baseFont !== null && $name instanceof NameType && $baseFont->value !== $name->value) {
            throw new \InvalidArgumentException('Font BaseFont or Type3 Name and FontDescriptor FontName must agree (EC3 #11).');
        }
        if (!$isType3) {
            $bounds = $this->resolved($descriptor, 'FontBBox', $graph);
            if (!$bounds instanceof ArrayType || count($bounds) !== 4) {
                throw new \InvalidArgumentException('A non-Type3 FontDescriptor requires a four-number FontBBox.');
            }
            foreach ($bounds as $coordinate) {
                if (!$graph->resolve($coordinate) instanceof NumericType) {
                    throw new \InvalidArgumentException('FontDescriptor FontBBox coordinates must resolve to numbers.');
                }
            }
            foreach (['Ascent', 'Descent', 'StemV'] as $key) {
                if (!$this->resolved($descriptor, $key, $graph) instanceof NumericType) {
                    throw new \InvalidArgumentException('A non-Type3 FontDescriptor requires numeric ' . $key . '.');
                }
            }
            // Repertoire cannot be inferred from arbitrary PDF names or Flags.
            // The named Latin standard fonts do provide that explicit fact.
            $standard = $baseFont === null ? null : StandardFont::tryFrom($baseFont->value);
            if ($standard !== null && !in_array($standard, [StandardFont::Symbol, StandardFont::ZapfDingbats], true) && !$this->resolved($descriptor, 'CapHeight', $graph) instanceof NumericType) {
                throw new \InvalidArgumentException('A Latin standard-font descriptor requires numeric CapHeight.');
            }
        }
        foreach (['Ascent', 'Descent', 'CapHeight', 'StemV'] as $key) {
            $number = $this->resolved($descriptor, $key, $graph);
            if ($number !== null && (!$number instanceof NumericType || ($key === 'Descent' ? $number->value > 0 : $number->value < 0))) {
                throw new \InvalidArgumentException('FontDescriptor metric ' . $key . ' has an invalid type or sign.');
            }
        }
    }

    private function tagged(DocumentGraphContext $graph): bool
    {
        $markInfo = $this->resolved($graph->catalog, 'MarkInfo', $graph);
        $marked = $markInfo instanceof DictionaryType ? $this->resolved($markInfo, 'Marked', $graph) : null;
        return $marked instanceof BooleanType && $marked->value;
    }

    private function resolved(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): ?BasicType
    {
        $value = $graph->entry($dictionary, $key);
        return $value === null ? null : $graph->resolve($value);
    }
}
