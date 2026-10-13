<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** CalRGB's three positive gamma exponents (table 64, PDF page 154). */
final class GammaValues extends ArrayType
{
    public function __construct(public readonly NumericType $red = new NumericType(1), public readonly NumericType $green = new NumericType(1), public readonly NumericType $blue = new NumericType(1))
    {
        if ($red->value <= 0 || $green->value <= 0 || $blue->value <= 0) {
            throw new \InvalidArgumentException('Colour gamma exponents must be positive.');
        }
        parent::__construct($red, $green, $blue);
    }
}
