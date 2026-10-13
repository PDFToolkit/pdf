<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\IntegerType;

/** One unsigned field in an Annex F.4 high-order-bit-first hint table. */
final readonly class HintField
{
    public function __construct(public IntegerType $value, public IntegerType $bits)
    {
        if ($bits->integer() < 0 || $bits->integer() > 32 || $value->integer() < 0 || $value->integer() > (1 << $bits->integer()) - 1) {
            throw new \InvalidArgumentException('A hint field must fit its unsigned width of zero through 32 bits.');
        }
    }
}
