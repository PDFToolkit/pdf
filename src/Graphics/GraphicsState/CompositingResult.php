<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Syntax\Object\NumericType;

/** A zero-alpha result has undefined colour, represented explicitly by null. */
final readonly class CompositingResult
{
    public function __construct(public NumericType $alpha, public ?ColourComponents $colour)
    {
    }
}
