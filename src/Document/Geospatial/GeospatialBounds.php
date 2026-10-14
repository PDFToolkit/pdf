<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Graphics\CoordinateSystems\Point;
use PDF\Syntax\Object\ArrayType;

/** Table 269 Bounds: an optionally closed neatline in the owner's unit square. */
final class GeospatialBounds extends ArrayType
{
    public function __construct(Point $first, Point $second, Point $third, Point ...$following)
    {
        $numbers = [];
        foreach ([$first, $second, $third, ...$following] as $point) {
            if ($point->x->value < 0 || $point->x->value > 1 || $point->y->value < 0 || $point->y->value > 1) {
                throw new \InvalidArgumentException('Geospatial neatline positions belong to the unit square.');
            }
            array_push($numbers, $point->x, $point->y);
        }
        parent::__construct(...$numbers);
    }
}
