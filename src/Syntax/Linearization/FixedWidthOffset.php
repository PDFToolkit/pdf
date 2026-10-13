<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\IntegerType;

/** A direct integer whose ten-byte spelling keeps two-pass layout stable. */
final class FixedWidthOffset extends IntegerType
{
    public function __construct(int $value)
    {
        if ($value < 0 || $value > 4_294_967_295) {
            throw new \InvalidArgumentException('A linearization offset fits the unsigned 32-bit hint address space.');
        }
        parent::__construct($value);
    }

    public function toPdf(SerializationContext $context): string
    {
        return sprintf('%010d', $this->integer());
    }
}
