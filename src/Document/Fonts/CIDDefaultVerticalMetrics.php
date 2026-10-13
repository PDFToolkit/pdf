<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** DW2 order is [positionY displacementY]; the default X position is half W/DW. */
final class CIDDefaultVerticalMetrics extends ArrayType
{
    public function __construct(public readonly NumericType $positionY = new NumericType(880), public readonly NumericType $displacementY = new NumericType(-1000))
    {
        parent::__construct($positionY, $displacementY);
    }
    public function metric(NumericType $horizontalWidth): CIDVerticalMetric
    {
        return new CIDVerticalMetric($this->displacementY, new NumericType($horizontalWidth->value / 2), $this->positionY);
    }
}
