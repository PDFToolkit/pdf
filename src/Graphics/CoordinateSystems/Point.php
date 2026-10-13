<?php

declare(strict_types=1);

namespace PDF\Graphics\CoordinateSystems;

use PDF\Syntax\Object\NumericType;

/** A point in an explicitly selected coordinate system (PDF32000_2008.pdf, 8.3, PDF pp.123–129). */
final readonly class Point
{
    public function __construct(public NumericType $x, public NumericType $y)
    {
    }
}
