<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** Non-negative CIE XYZ black point (tables 63–65). */
final class BlackPoint extends ArrayType
{
    public function __construct(public readonly NumericType $x = new NumericType(0), public readonly NumericType $y = new NumericType(0), public readonly NumericType $z = new NumericType(0))
    {
        if ($x->value < 0 || $y->value < 0 || $z->value < 0) {
            throw new \InvalidArgumentException('A CIE black point must have non-negative coordinates.');
        }
        parent::__construct($x, $y, $z);
    }
}
