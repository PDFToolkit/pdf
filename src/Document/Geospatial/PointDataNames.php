<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NameType;

/** Table 272: LAT, LON and ALT identify numeric columns. */
final class PointDataNames extends ArrayType
{
    public function __construct(NameType $first, NameType ...$following)
    {
        $seen = [];
        foreach ([$first, ...$following] as $name) {
            if ($name->value === '' || isset($seen[$name->value])) {
                throw new \InvalidArgumentException('Point-data column names are nonempty and distinct.');
            }
            $seen[$name->value] = true;
        }
        parent::__construct($first, ...$following);
    }
}
