<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Specification\Dictionary\LZWDecodeAndFlateDecodeFiltersTable8;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Predictor 1 has no row-layout assumptions or Colors/Columns parameters. */
final readonly class NoPrediction implements Predictor
{
    public function encode(string $bytes): string
    {
        return $bytes;
    }

    public function parameters(): DictionaryType
    {
        return new LZWDecodeAndFlateDecodeFiltersTable8(new Entry(new NameType('Predictor'), new IntegerType(1)));
    }
}
