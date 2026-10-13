<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** 9.8.3.3 and its examples: class-specific metric/classification overrides, no font programs. */
final class CIDFontMetricOverride extends DictionaryType
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
    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct new glyph-class metric overrides.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Construct new glyph-class metric overrides.');
    }
}
