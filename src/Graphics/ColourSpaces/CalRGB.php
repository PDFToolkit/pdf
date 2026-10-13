<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\NumberInterval;
use PDF\Functions\Range;
use PDF\Specification\Dictionary\CalRGBColourSpaceDictionaryTable64;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** CalRGB declaration and prescribed gamma/matrix conversion into CIE XYZ (pages 154–155). */
final class CalRGB extends ArrayColourSpace
{
    public function __construct(public readonly WhitePoint $whitePoint, public readonly GammaValues $gamma = new GammaValues(), public readonly MatrixABC $matrix = new MatrixABC(), public readonly BlackPoint $blackPoint = new BlackPoint())
    {
        parent::__construct(ColourSpaceFamily::CalRGB, new Range(...array_fill(0, 3, new NumberInterval(new NumericType(0), new NumericType(1)))), new CalRGBColourSpaceDictionaryTable64(
            new Entry(new NameType('WhitePoint'), $whitePoint),
            new Entry(new NameType('Gamma'), $gamma),
            new Entry(new NameType('Matrix'), $matrix),
            new Entry(new NameType('BlackPoint'), $blackPoint),
        ));
    }

    public function toXYZ(ColourComponents $colour): TristimulusValues
    {
        if ($colour->space !== $this) {
            throw new \InvalidArgumentException('The calibrated colour must belong to this colour space.');
        }
        $v = $colour->clipped()->numbers;
        return $this->matrix->transform(new NumericType($v[0]->value ** $this->gamma->red->value), new NumericType($v[1]->value ** $this->gamma->green->value), new NumericType($v[2]->value ** $this->gamma->blue->value));
    }
}
