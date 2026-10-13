<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** CIE XYZ diffuse white point: X/Z positive and Y exactly 1 (tables 63–65, pages 153–156). */
final class WhitePoint extends ArrayType
{
    public function __construct(public readonly NumericType $x, public readonly NumericType $y, public readonly NumericType $z)
    {
        if ($x->value <= 0 || $z->value <= 0 || $y->value != 1) {
            throw new \InvalidArgumentException('A CIE white point needs positive X/Z and Y equal to one.');
        }
        parent::__construct($x, $y, $z);
    }
}
