<?php

declare(strict_types=1);

namespace PDF\Document\Barcodes;

use PDF\Document\Forms\BarcodeField;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\NumericType;

/**
 * Explicit fresh-field operation, Adobe supplement, section 8.6.3, physical pages 45–47.
 * Rendering returns unattached widgets in message order; it never changes the field or invokes JavaScript.
 */
final readonly class BarcodeFieldPreparation
{
    public BarcodeMessagePartition $partition;
    /** @var list<BarcodeEncodingRequest> */
    public array $requests;

    public function __construct(public BarcodeField $field, public BarcodeLimits $limits = new BarcodeLimits(), BarcodeWidgetParameters ...$widgets)
    {
        $widgets = array_values($widgets);
        if ($field->widgets() !== []) {
            throw new \LogicException('Prepare explicit appearances for a fresh barcode field without attached widgets.');
        }
        $value = $field->effective('V');
        $declared = $field->get('DataPrep');
        if (!$value instanceof TextString || !$declared instanceof NumericType || $declared->value != $field->preparation->DataPrep->value) {
            throw new \InvalidArgumentException('Barcode preparation starts from the actual explicitly encoded field V and DataPrep.');
        }
        $this->partition = new BarcodeMessagePartition($value, $field->preparation, count($widgets), $limits);
        $requests = [];
        foreach ($widgets as $index => $widget) {
            $requests[] = new BarcodeEncodingRequest($this->partition->messages[$index], $widget, $limits);
        }
        $this->requests = $requests;
    }

    /**
     * @return list<BarcodeWidgetAppearance> Attach these widgets explicitly in this order.
     * The selected external encoder must enforce its own allocation/work limits before returning.
     */
    public function render(BarcodeImageEncoder $encoder): array
    {
        if ($this->field->widgets() !== []) {
            throw new \LogicException('The planned barcode field acquired widgets before explicit rendering.');
        }
        if ($this->field->effective('V') !== $this->partition->value) {
            throw new \LogicException('The planned barcode messages belong to the exact effective field value.');
        }
        $appearances = [];
        $pixels = 0;
        $bytes = 0;
        foreach ($this->requests as $request) {
            if ($pixels >= $this->limits->maximumImagePixels || $bytes >= $this->limits->maximumImageBytes) {
                throw new \LengthException('The cumulative barcode image budget is exhausted before the next encoder call.');
            }
            $image = $encoder->encode($request);
            $imagePixels = $image->samples->dimensions->pixels();
            $imageBytes = strlen($image->samples->bytes);
            if ($imagePixels > $this->limits->maximumImagePixels - $pixels || $imageBytes > $this->limits->maximumImageBytes - $bytes) {
                throw new \LengthException('Generated barcode images exceed the cumulative pixel or sample-byte budget.');
            }
            $appearances[] = new BarcodeWidgetAppearance($request, $image);
            $pixels += $imagePixels;
            $bytes += $imageBytes;
        }
        return $appearances;
    }
}
