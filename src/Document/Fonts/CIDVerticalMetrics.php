<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

final readonly class CIDVerticalMetrics
{
    public function __construct(public CIDDefaultVerticalMetrics $defaults = new CIDDefaultVerticalMetrics(), public CIDVerticalWidths $widths = new CIDVerticalWidths())
    {
    }
    public function metric(IntegerType $cid, NumericType $horizontalWidth): CIDVerticalMetric
    {
        return $this->widths->metric($cid) ?? $this->defaults->metric($horizontalWidth);
    }
}
