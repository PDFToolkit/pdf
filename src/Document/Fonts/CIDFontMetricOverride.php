<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** 9.8.3.3 and its examples: class-specific metric/classification overrides, no font programs. */
final class CIDFontMetricOverride extends DictionaryType implements VersionedComponent
{
    public function __construct(Entry ...$metrics)
    {
        foreach ($metrics as $metric) {
            $key = $metric->key()->value;
            $value = $metric->value;
            if ($key === 'Type' && $value instanceof NameType && $value->value === 'FontDescriptor') {
                continue;
            }
            if ($key === 'FontName' && $value instanceof NameType) {
                continue;
            }
            if (($key === 'FontBBox' && $value instanceof Rectangle)
                || ($key === 'FontFamily' && $value instanceof StringType)
                || ($key === 'FontStretch' && $value instanceof NameType && FontStretch::tryFrom($value->value) !== null)
                || ($key === 'FontWeight' && $value instanceof NumericType && $value->value >= 1 && $value->value <= 1000 && floor($value->value) == $value->value)) {
                continue;
            }
            if ($key === 'Flags' && $value instanceof IntegerType && $value->integer() >= 0 && ($value->integer() & ~0x7006F) === 0 && ($value->integer() & 36) !== 36) {
                continue;
            }
            if (in_array($key, ['ItalicAngle', 'Ascent', 'Descent', 'Leading', 'CapHeight', 'XHeight', 'StemV', 'StemH', 'AvgWidth', 'MaxWidth', 'MissingWidth'], true) && $value instanceof NumericType) {
                continue;
            }
            throw new \InvalidArgumentException('A CID glyph-class descriptor overrides only explicit font metrics/classification.');
        }
        parent::__construct(...$metrics);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $weight = $this->get('FontWeight');
        if ($weight !== null && $version->value === PdfVersion::PDF20 && !$weight instanceof IntegerType) {
            throw new \LogicException('PDF 2.0 glyph-class FontWeight requires IntegerType (EC3 #152).');
        }
        if ($weight instanceof NumericType && $version->value !== PdfVersion::PDF20
            && !in_array($weight->value, [100, 200, 300, 400, 500, 600, 700, 800, 900], false)) {
            throw new \LogicException('Glyph-class FontWeight outside the traditional 100-step weights requires PDF 2.0 (EC3 #474).');
        }
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct new glyph-class metric overrides.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Construct new glyph-class metric overrides.');
    }
}
