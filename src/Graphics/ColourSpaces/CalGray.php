<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\NumberInterval;
use PDF\Functions\Range;
use PDF\Specification\Dictionary\CalGrayColourSpaceDictionaryTable63;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** CalGray declaration and prescribed A-to-XYZ gamma transformation (PDF pages 153–154). */
final class CalGray extends ArrayColourSpace
{
    public function __construct(public readonly WhitePoint $whitePoint, public readonly NumericType $gamma = new NumericType(1), public readonly BlackPoint $blackPoint = new BlackPoint())
    {
        if ($gamma->value <= 0) {
            throw new \InvalidArgumentException('A calibrated gray gamma must be positive.');
        }
        parent::__construct(ColourSpaceFamily::CalGray, new Range(new NumberInterval(new NumericType(0), new NumericType(1))), new CalGrayColourSpaceDictionaryTable63(
            new Entry(new NameType('WhitePoint'), $whitePoint),
            new Entry(new NameType('Gamma'), $gamma),
            new Entry(new NameType('BlackPoint'), $blackPoint),
        ));
    }

    public function toXYZ(ColourComponents $colour): TristimulusValues
    {
        if ($colour->space !== $this) {
            throw new \InvalidArgumentException('The calibrated colour must belong to this colour space.');
        }
        $a = $colour->clipped()->numbers[0]->value ** $this->gamma->value;
        return new TristimulusValues(new NumericType($this->whitePoint->x->value * $a), new NumericType($this->whitePoint->y->value * $a), new NumericType($this->whitePoint->z->value * $a));
    }
}
