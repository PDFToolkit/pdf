<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;

/** Fixed-width integers allow finalizing offsets without shifting any later byte. */
final class ByteRangeReservation extends ArrayType
{
    public readonly int $width;

    public function __construct()
    {
        $this->width = strlen((string) PHP_INT_MAX);
        parent::__construct(new IntegerType(0), new IntegerType(0), new IntegerType(0), new IntegerType(0));
    }

    public function toPdf(SerializationContext $context): string
    {
        return '[0 ' . str_repeat('0', $this->width) . ' ' . str_repeat('0', $this->width) . ' ' . str_repeat('0', $this->width) . ']';
    }

    public function filled(SignatureByteRange $range): string
    {
        return '[0 ' . str_pad((string) $range->before, $this->width, '0', STR_PAD_LEFT)
            . ' ' . str_pad((string) $range->afterOffset, $this->width, '0', STR_PAD_LEFT)
            . ' ' . str_pad((string) $range->afterLength, $this->width, '0', STR_PAD_LEFT) . ']';
    }
}
