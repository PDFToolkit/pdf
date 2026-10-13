<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Document\Barcodes\BarcodeDataPreparation;
use PDF\Specification\Adobe\Definition\BarcodeFieldTable8_39aDefinition;
use PDF\Specification\TableDefinition;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\IndirectReference;

/** Adobe p.46: text-field semantics with explicit preparation before an external barcode codec. */
final class BarcodeField extends TextField
{
    public function __construct(?PartialFieldName $name, public readonly BarcodeDataPreparation $preparation, ?TextString $value = null, Entry ...$entries)
    {
        parent::__construct($name, null, $value, null, FieldTypeDeclaration::Explicit, $preparation->entry(), ...$entries);
    }

    protected function textDefinition(): TableDefinition
    {
        return new BarcodeFieldTable8_39aDefinition();
    }

    public function validate(): void
    {
        parent::validate();
        $preparation = $this->get('DataPrep');
        if (!$preparation instanceof NumericType || !in_array($preparation->value, [0, 1, 0.0, 1.0], true)) {
            throw new \InvalidArgumentException('Barcode DataPrep is 0 or 1.');
        }
        foreach ($this->widgets() as $widget) {
            $metadata = $widget->get('PMD');
            $metadata = $metadata instanceof IndirectReference ? $metadata->target() : $metadata;
            if (!$metadata instanceof BarcodePaperMetadata) {
                throw new \InvalidArgumentException('A barcode widget must explicitly supply its paper metadata PMD.');
            }
            $metadata->validate();
        }
    }
}
