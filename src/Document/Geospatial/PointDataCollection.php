<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\ArrayType;

/** 12.10.5 allows a dictionary or an array of point-data dictionaries. */
final class PointDataCollection extends ArrayType
{
    public function __construct(PointData $first, PointData ...$following)
    {
        parent::__construct($first, ...$following);
    }
}
