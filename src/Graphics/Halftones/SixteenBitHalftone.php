<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Specification\Definition\Type16HalftoneDictionaryTable133Definition;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Type16: one or two threshold rectangles, high-order byte first. */
final class SixteenBitHalftone extends ThresholdHalftone
{
    public function __construct(public readonly ThresholdRectangle $first, public readonly ?ThresholdRectangle $second = null, HalftoneOptions $options = new HalftoneOptions(), Filter ...$filters)
    {
        if ($first->thresholds->bits !== 16 || ($second !== null && $second->thresholds->bits !== 16)) {
            throw new \InvalidArgumentException('Type16 halftones require sixteen-bit thresholds in every rectangle.');
        }
        $entries = [new Entry(new NameType('Width'), new IntegerType($first->dimensions->width)), new Entry(new NameType('Height'), new IntegerType($first->dimensions->height))];
        if ($second !== null) {
            array_push($entries, new Entry(new NameType('Width2'), new IntegerType($second->dimensions->width)), new Entry(new NameType('Height2'), new IntegerType($second->dimensions->height)));
        }
        parent::__construct(16, $first->thresholds->bytes . ($second?->thresholds->bytes ?? ''), new Type16HalftoneDictionaryTable133Definition(), $options, $entries, ...$filters);
    }
}
