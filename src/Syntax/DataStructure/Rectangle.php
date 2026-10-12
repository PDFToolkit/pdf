<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** Four coordinates; reversed corners remain legal (ISO 32000-1, 7.9.5). */
final class Rectangle extends ArrayType
{
    public function __construct(
        public readonly NumericType $lowerLeftX,
        public readonly NumericType $lowerLeftY,
        public readonly NumericType $upperRightX,
        public readonly NumericType $upperRightY,
    ) {
        parent::__construct($lowerLeftX, $lowerLeftY, $upperRightX, $upperRightY);
    }
}
