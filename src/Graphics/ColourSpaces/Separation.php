<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\NumberInterval;
use PDF\Functions\Range;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** One named colorant, including the prescribed All/None names (pages 165–167). */
final class Separation extends ArrayColourSpace
{
    public function __construct(public readonly NameType $colorant, public readonly TintTransform $tintTransform)
    {
        if ($tintTransform->inputs !== 1) {
            throw new \InvalidArgumentException('A Separation tint transform has exactly one input.');
        }
        parent::__construct(ColourSpaceFamily::Separation, new Range(new NumberInterval(new NumericType(0), new NumericType(1))), $colorant, $tintTransform->alternate->value(), $tintTransform->value());
    }
}
