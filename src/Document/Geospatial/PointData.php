<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Table 272, physical page 621: unordered tuples with explicit column semantics. */
final class PointData extends GeospatialDictionary
{
    public function __construct(PointDataNames $names, ArrayType ...$tuples)
    {
        foreach ($tuples as $tuple) {
            if (count($tuple) !== count($names)) {
                throw new \InvalidArgumentException('Each XPTS tuple has exactly one value for every Names column.');
            }
            foreach ($names->items as $index => $name) {
                $value = $tuple->items[$index];
                if ($name instanceof NameType && in_array($name->value, ['LAT', 'LON', 'ALT'], true) && !$value instanceof NumericType) {
                    throw new \InvalidArgumentException('LAT, LON and ALT point-data columns contain PDF numbers.');
                }
                if ($name instanceof NameType && $name->value === 'LAT' && $value instanceof NumericType && abs($value->value) > 90) {
                    throw new \InvalidArgumentException('Point-data latitude is in degrees between minus 90 and 90.');
                }
            }
        }
        parent::__construct(new Entry(new NameType('Type'), new NameType('PtData')), new Entry(new NameType('Subtype'), new NameType('Cloud')), new Entry(new NameType('Names'), $names), new Entry(new NameType('XPTS'), new ArrayType(...$tuples)));
    }
}
