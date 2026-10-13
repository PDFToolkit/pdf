<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\NumericType;

/** W2 triple: vertical displacement followed by horizontal/vertical origin position. */
final readonly class CIDVerticalMetric
{
    public function __construct(public NumericType $displacementY, public NumericType $positionX, public NumericType $positionY)
    {
    }
    /** @return list<NumericType> */
    public function operands(): array
    {
        return [$this->displacementY, $this->positionX, $this->positionY];
    }
}
