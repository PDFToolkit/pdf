<?php

declare(strict_types=1);

namespace PDF\Document\Barcodes;

/** Application-selected budgets for Adobe barcode preparation and native appearances. */
final readonly class BarcodeLimits
{
    public function __construct(
        public int $maximumInputBytes = 16777216,
        public int $maximumPreparedBytes = 16777216,
        public int $maximumWidgets = 255,
        public int $maximumImagePixels = 16777216,
        public int $maximumImageBytes = 67108864,
    ) {
        if (min($maximumInputBytes, $maximumPreparedBytes, $maximumWidgets, $maximumImagePixels, $maximumImageBytes) < 1) {
            throw new \InvalidArgumentException('Barcode processing budgets are positive integers.');
        }
    }
}
