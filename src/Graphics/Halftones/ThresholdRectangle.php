<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Document\Images\ImageDimensions;

final readonly class ThresholdRectangle
{
    public function __construct(public ImageDimensions $dimensions, public ThresholdArray $thresholds)
    {
        if ($dimensions->pixels() !== $thresholds->count) {
            throw new \InvalidArgumentException('Threshold rectangle dimensions must match the exact sample count.');
        }
    }
}
