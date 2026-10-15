<?php

declare(strict_types=1);

namespace PDF\Document\Barcodes;

/** Exact part/PMD association submitted to an explicit symbology encoder; Adobe physical page 47. */
final readonly class BarcodeEncodingRequest
{
    public function __construct(public BarcodeMessage $message, public BarcodeWidgetParameters $widget, public BarcodeLimits $limits = new BarcodeLimits())
    {
        if ($message->total > $limits->maximumWidgets || strlen($message->value->value) > $limits->maximumPreparedBytes) {
            throw new \LengthException('Barcode encoding request exceeds its widget or prepared-byte budget.');
        }
    }
}
