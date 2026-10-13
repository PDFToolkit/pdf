<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Syntax\Object\NumericType;

/** Radial shading circle; zero radius is an allowed point (table 81, PDF page 195). */
final readonly class Circle
{
    public function __construct(public Point $centre, public NumericType $radius)
    {
        if ($radius->value < 0) {
            throw new \InvalidArgumentException('A radial shading radius cannot be negative.');
        }
    }
}
