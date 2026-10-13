<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Specification\Definition\Type10HalftoneDictionaryTable132Definition;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Type10: complete X square followed by complete Y square. */
final class TwoSquareHalftone extends ThresholdHalftone
{
    public function __construct(IntegerType $xSquare, IntegerType $ySquare, public readonly ThresholdArray $thresholds, HalftoneOptions $options = new HalftoneOptions(), Filter ...$filters)
    {
        $x = $xSquare->integer();
        $y = $ySquare->integer();
        if ($x < 0 || $y < 0 || $x > (int) sqrt(PHP_INT_MAX / 2) || $y > (int) sqrt(PHP_INT_MAX / 2) || $x * $x + $y * $y !== $thresholds->count || $thresholds->bits !== 8) {
            throw new \InvalidArgumentException('Type10 needs Xsquare²+Ysquare² eight-bit thresholds and representable nonnegative sides.');
        }
        parent::__construct(10, $thresholds->bytes, new Type10HalftoneDictionaryTable132Definition(), $options, [new Entry(new NameType('Xsquare'), $xSquare), new Entry(new NameType('Ysquare'), $ySquare)], ...$filters);
    }
}
