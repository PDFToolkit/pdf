<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\NumberInterval;
use PDF\Functions\Range;
use PDF\Specification\Dictionary\LabColourSpaceDictionaryTable65;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** L*a*b* declaration and the specified piecewise transformation into XYZ (pages 156–157). */
final class Lab extends ArrayColourSpace
{
    public function __construct(public readonly WhitePoint $whitePoint, NumberInterval $a = new NumberInterval(new NumericType(-100), new NumericType(100)), NumberInterval $b = new NumberInterval(new NumericType(-100), new NumericType(100)), public readonly BlackPoint $blackPoint = new BlackPoint())
    {
        parent::__construct(ColourSpaceFamily::Lab, new Range(new NumberInterval(new NumericType(0), new NumericType(100)), $a, $b), new LabColourSpaceDictionaryTable65(
            new Entry(new NameType('WhitePoint'), $whitePoint),
            new Entry(new NameType('BlackPoint'), $blackPoint),
            new Entry(new NameType('Range'), new ArrayType($a->minimum, $a->maximum, $b->minimum, $b->maximum)),
        ));
    }

    public function toXYZ(ColourComponents $colour): TristimulusValues
    {
        if ($colour->space !== $this) {
            throw new \InvalidArgumentException('The Lab colour must belong to this colour space.');
        }
        $v = $colour->clipped()->numbers;
        $m = ($v[0]->value + 16) / 116;
        return new TristimulusValues(new NumericType($this->whitePoint->x->value * $this->g($m + $v[1]->value / 500)), new NumericType($this->whitePoint->y->value * $this->g($m)), new NumericType($this->whitePoint->z->value * $this->g($m - $v[2]->value / 200)));
    }

    private function g(float $value): float
    {
        return $value >= 6 / 29 ? $value ** 3 : 108 / 841 * ($value - 4 / 29);
    }
}
