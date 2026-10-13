<?php

declare(strict_types=1);

namespace PDF\Document\Barcodes;

use PDF\Specification\Adobe\Dictionary\PaperMetaDataGenerationParametersDictionaryTable8_39b\XSymWidthEntry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

/** Adobe supplement p. 47: XSymWidth = ceil(distance in inches * Resolution). */
final readonly class BarcodeModuleWidth
{
    public IntegerType $XSymWidth;

    /** Resolution defaults to 300 dpi, as specified in table 8.39b, PDF p. 46. */
    public function __construct(public NumericType $distanceInches, public NumericType $resolution = new NumericType(300))
    {
        if ($distanceInches->value <= 0 || $resolution->value <= 0) {
            throw new \InvalidArgumentException('A barcode module distance and resolution must be positive.');
        }
        $pixels = $distanceInches->value * $resolution->value;
        if (!is_finite($pixels) || $pixels <= 0) {
            throw new \OutOfRangeException('The barcode module width exceeds the representable numeric range.');
        }
        if (is_int($pixels)) {
            $this->XSymWidth = new IntegerType($pixels);
            return;
        }
        $rounded = ceil($pixels);
        // On a 64-bit runtime PHP_INT_MAX rounds upwards to 2^63 as a float.
        // On a 32-bit runtime its float representation remains exact.
        if ((PHP_INT_SIZE === 8 && $rounded >= (float) PHP_INT_MAX) || (PHP_INT_SIZE === 4 && $rounded > (float) PHP_INT_MAX)) {
            throw new \OutOfRangeException('The barcode module width cannot be represented by a PDF IntegerType.');
        }
        $this->XSymWidth = new IntegerType((int) $rounded);
    }

    public function entry(): XSymWidthEntry
    {
        return new XSymWidthEntry($this->XSymWidth);
    }
}
