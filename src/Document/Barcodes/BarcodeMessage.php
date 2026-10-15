<?php

declare(strict_types=1);

namespace PDF\Document\Barcodes;

use PDF\Syntax\Object\StringType;

/** One binary part in widget order; Adobe supplement, section 8.6.3, physical pages 46–47. */
final readonly class BarcodeMessage
{
    public function __construct(public StringType $value, public int $index, public int $total, public int $offset)
    {
        if ($total < 1 || $index < 0 || $index >= $total || $offset < 0 || strlen($value->value) > PHP_INT_MAX - $offset) {
            throw new \InvalidArgumentException('A barcode message has a bounded byte offset and a zero-based index in its widget sequence.');
        }
    }
}
