<?php

declare(strict_types=1);

namespace PDF\Document\Barcodes;

use PDF\Specification\Adobe\Dictionary\BarcodeFieldTable8_39a\DataPrepEntry;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

/** Adobe supplement pp. 46–47: DataPrep prepares existing bytes before symbology encoding. */
final readonly class BarcodeDataPreparation
{
    public function __construct(public NumericType $DataPrep = new IntegerType(0), private FlateDecode $flate = new FlateDecode())
    {
        if ($DataPrep->value != 0 && $DataPrep->value != 1) {
            throw new \InvalidArgumentException('Barcode /DataPrep must be 0 or 1.');
        }
        if ($DataPrep->value == 1 && $flate->predictor !== null) {
            throw new \InvalidArgumentException('Barcode V compression does not apply an image-sample predictor.');
        }
    }

    /** Byte values remain explicit; partitioning follows complete preparation in BarcodeMessagePartition. */
    public function prepare(StringType $value): StringType
    {
        if ($this->DataPrep->value == 0) {
            return $value;
        }
        return new HexadecimalString($this->flate->encode($value->value));
    }

    public function entry(): DataPrepEntry
    {
        return new DataPrepEntry($this->DataPrep);
    }
}
