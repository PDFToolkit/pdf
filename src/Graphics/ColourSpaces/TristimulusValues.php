<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\NumericType;

/** Result of the PDF's prescribed calibrated-space transformation into CIE XYZ. */
final readonly class TristimulusValues
{
    public function __construct(public NumericType $x, public NumericType $y, public NumericType $z)
    {
    }
}
