<?php

declare(strict_types=1);

namespace PDF\Document\Barcodes;

use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

/** Adobe supplement, physical pages 46–47: compress the complete V before dividing bytes. */
final readonly class BarcodeMessagePartition
{
    public StringType $prepared;
    /** @var list<BarcodeMessage> */
    public array $messages;

    public function __construct(public StringType $value, public BarcodeDataPreparation $preparation, int $widgetCount, public BarcodeLimits $limits = new BarcodeLimits())
    {
        if ($widgetCount < 1 || $widgetCount > $limits->maximumWidgets) {
            throw new \LengthException('Barcode widget count exceeds its positive processing budget.');
        }
        if (strlen($value->value) > $limits->maximumInputBytes) {
            throw new \LengthException('Barcode V bytes exceed the input budget.');
        }
        $prepared = $preparation->prepare($value);
        $length = strlen($prepared->value);
        if ($length > $limits->maximumPreparedBytes) {
            throw new \LengthException('Prepared barcode bytes exceed the processing budget.');
        }
        $this->prepared = $prepared;
        $size = intdiv($length, $widgetCount);
        $remainder = $length % $widgetCount;
        $messages = [];
        $offset = 0;
        for ($index = 0; $index < $widgetCount; ++$index) {
            // Difference in part lengths is at most one; earlier widgets receive the remainder.
            $partLength = $size + ($index < $remainder ? 1 : 0);
            $messages[] = new BarcodeMessage(new HexadecimalString(substr($prepared->value, $offset, $partLength)), $index, $widgetCount, $offset);
            $offset += $partLength;
        }
        $this->messages = $messages;
    }
}
