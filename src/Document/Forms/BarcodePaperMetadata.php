<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\Adobe\Definition\PaperMetaDataGenerationParametersDictionaryTable8_39bDefinition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Adobe pp.46–47: validated instructions to an explicitly selected external barcode encoder. */
final class BarcodePaperMetadata extends SchemaDictionary
{
    public function __construct(BarcodeSymbology $symbology, NumericType $widthInches, NumericType $heightInches, IntegerType $moduleWidthPixels, ?IntegerType $moduleHeightPixels = null, ?IntegerType $errorCorrection = null, NumericType $resolution = new IntegerType(300), IntegerType $version = new IntegerType(1), Entry ...$entries)
    {
        if ($widthInches->value <= 0 || $heightInches->value <= 0 || $moduleWidthPixels->integer() <= 0 || $resolution->value <= 0 || $version->integer() < 1) {
            throw new \InvalidArgumentException('Barcode dimensions, module width, resolution and version are positive.');
        }
        if ($symbology === BarcodeSymbology::Pdf417 && $moduleHeightPixels === null) {
            throw new \InvalidArgumentException('PDF417 requires an explicit module height.');
        }
        if ($moduleHeightPixels !== null) {
            $ratio = $moduleHeightPixels->integer() / $moduleWidthPixels->integer();
            if ($ratio < 1 || $ratio > ($symbology === BarcodeSymbology::Pdf417 ? 4 : 1) || $moduleHeightPixels->integer() % $moduleWidthPixels->integer() !== 0) {
                throw new \InvalidArgumentException('Barcode module height/width ratio is integral, 1–4 for PDF417 and exactly 1 otherwise.');
            }
        }
        if ($symbology !== BarcodeSymbology::DataMatrix && $errorCorrection === null) {
            throw new \InvalidArgumentException('PDF417 and QRCode require explicit ECC.');
        }
        if ($errorCorrection !== null && ($errorCorrection->integer() < 0 || ($symbology !== BarcodeSymbology::DataMatrix && $errorCorrection->integer() > ($symbology === BarcodeSymbology::Pdf417 ? 8 : 3)))) {
            throw new \InvalidArgumentException('Barcode ECC is 0–8 for PDF417 and 0–3 for QRCode.');
        }
        $managed = [new Entry(new NameType('Type'), new NameType('PaperMetaData')), new Entry(new NameType('Version'), $version), new Entry(new NameType('Symbology'), new NameType($symbology->value)), new Entry(new NameType('Width'), $widthInches), new Entry(new NameType('Height'), $heightInches), new Entry(new NameType('Resolution'), $resolution), new Entry(new NameType('XSymWidth'), $moduleWidthPixels)];
        if ($moduleHeightPixels !== null) {
            $managed[] = new Entry(new NameType('XSymHeight'), $moduleHeightPixels);
        }
        if ($errorCorrection !== null) {
            $managed[] = new Entry(new NameType('ECC'), $errorCorrection);
        }
        parent::__construct(new PaperMetaDataGenerationParametersDictionaryTable8_39bDefinition(), ...[...$managed, ...$entries]);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Barcode paper metadata is supplied by its constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Barcode paper metadata is supplied by its constructor.');
    }
}
