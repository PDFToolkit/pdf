<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

final class IconAlignment extends ArrayType
{
    public function __construct(public readonly NumericType $left = new NumericType(0.5), public readonly NumericType $bottom = new NumericType(0.5))
    {
        if ($left->value < 0 || $left->value > 1 || $bottom->value < 0 || $bottom->value > 1) {
            throw new \InvalidArgumentException('Icon alignment fractions lie between zero and one.');
        }
        parent::__construct($left, $bottom);
    }
}
