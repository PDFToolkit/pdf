<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Specification\Definition\Type6HalftoneDictionaryTable131Definition;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Type6 row-major 8-bit threshold rectangle. */
final class RectangularHalftone extends ThresholdHalftone
{
    public function __construct(public readonly ThresholdRectangle $rectangle, HalftoneOptions $options = new HalftoneOptions(), Filter ...$filters)
    {
        if ($rectangle->thresholds->bits !== 8) {
            throw new \InvalidArgumentException('Type6 halftones require eight-bit thresholds.');
        }
        parent::__construct(6, $rectangle->thresholds->bytes, new Type6HalftoneDictionaryTable131Definition(), $options, [new Entry(new NameType('Width'), new IntegerType($rectangle->dimensions->width)), new Entry(new NameType('Height'), new IntegerType($rectangle->dimensions->height))], ...$filters);
    }
}
