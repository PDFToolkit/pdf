<?php

declare(strict_types=1);

namespace PDF\Document\Barcodes;

use PDF\Document\Forms\BarcodePaperMetadata;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\NumericType;

/** One planned widget, retaining its actual PMD and rectangle (Adobe physical pages 46–47). */
final readonly class BarcodeWidgetParameters
{
    public NumericType $width;
    public NumericType $height;

    public function __construct(public Rectangle $rectangle, public BarcodePaperMetadata $metadata)
    {
        $width = abs($rectangle->upperRightX->value - $rectangle->lowerLeftX->value);
        $height = abs($rectangle->upperRightY->value - $rectangle->lowerLeftY->value);
        if (!is_finite((float) $width) || !is_finite((float) $height) || $width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Producing a barcode appearance requires finite positive normalized widget extents.');
        }
        $metadata->validate();
        $this->width = new NumericType($width);
        $this->height = new NumericType($height);
    }
}
