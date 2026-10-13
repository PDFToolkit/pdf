<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\IntegerType;

/** Exact integer width, including a zero-bit field for the value zero. */
final readonly class HintBitWidth
{
    public IntegerType $bits;

    public function __construct(public IntegerType $maximum)
    {
        if ($maximum->integer() < 0 || $maximum->integer() > 4_294_967_295) {
            throw new \InvalidArgumentException('Hint fields are unsigned values of at most 32 bits.');
        }
        $bits = 0;
        for ($value = $maximum->integer(); $value !== 0; $value >>= 1) {
            ++$bits;
        }
        $this->bits = new IntegerType($bits);
    }
}
