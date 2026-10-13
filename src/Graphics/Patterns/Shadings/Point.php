<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Syntax\Object\NumericType;

/** Explicit point in a shading's target coordinate space. */
final readonly class Point
{
    public function __construct(public NumericType $x, public NumericType $y)
    {
    }
}
