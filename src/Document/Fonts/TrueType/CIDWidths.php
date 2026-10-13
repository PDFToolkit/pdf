<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

/** Table 117 W array in PDF's 1000-unit glyph coordinate system. */
final class CIDWidths extends ArrayType
{
    public function __construct(public readonly CharacterMap $map)
    {
        $widths = [new NumericType($map->program->metrics->pdfWidth(0))];
        foreach ($map->mappings as $mapping) {
            $widths[] = new NumericType($mapping->width);
        }
        parent::__construct(new IntegerType(0), new ArrayType(...$widths));
    }
}
