<?php

declare(strict_types=1);

namespace PDF\Document\Barcodes;

use PDF\Graphics\XObjects\ImageXObject;

/**
 * Explicit application capability for PDF417, QR Code or Data Matrix.
 * Adobe supplement, physical page 47, delegates those symbologies to their own specifications.
 * The encoder owns symbology/ECC/module/quiet-zone correctness and its allocation limits.
 */
interface BarcodeImageEncoder
{
    /** Return a normalized, opaque device-colour image within the request's budgets. */
    public function encode(BarcodeEncodingRequest $request): ImageXObject;
}
