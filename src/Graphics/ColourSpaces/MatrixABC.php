<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** Nine CalRGB matrix coefficients in PDF order XA YA ZA XB YB ZB XC YC ZC (page 154). */
final class MatrixABC extends ArrayType
{
    /** @var array{NumericType,NumericType,NumericType,NumericType,NumericType,NumericType,NumericType,NumericType,NumericType} */
    private readonly array $coefficients;
    public function __construct(
        NumericType $xa = new NumericType(1),
        NumericType $ya = new NumericType(0),
        NumericType $za = new NumericType(0),
        NumericType $xb = new NumericType(0),
        NumericType $yb = new NumericType(1),
        NumericType $zb = new NumericType(0),
        NumericType $xc = new NumericType(0),
        NumericType $yc = new NumericType(0),
        NumericType $zc = new NumericType(1),
    ) {
        $this->coefficients = [$xa, $ya, $za, $xb, $yb, $zb, $xc, $yc, $zc];
        parent::__construct($xa, $ya, $za, $xb, $yb, $zb, $xc, $yc, $zc);
    }

    public function transform(NumericType $a, NumericType $b, NumericType $c): TristimulusValues
    {
        $v = $this->coefficients;
        return new TristimulusValues(
            new NumericType($v[0]->value * $a->value + $v[3]->value * $b->value + $v[6]->value * $c->value),
            new NumericType($v[1]->value * $a->value + $v[4]->value * $b->value + $v[7]->value * $c->value),
            new NumericType($v[2]->value * $a->value + $v[5]->value * $b->value + $v[8]->value * $c->value),
        );
    }
}
