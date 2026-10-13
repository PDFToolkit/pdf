<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\IntegerType;

/** Legacy encryption Length in bits: 40–128, in complete bytes. */
final readonly class HistoricalKeyLength
{
    public function __construct(public IntegerType $bits)
    {
        if ($bits->integer() < 40 || $bits->integer() > 128 || $bits->integer() % 8 !== 0) {
            throw new \InvalidArgumentException('A legacy encryption key length must be 40–128 bits in multiples of eight.');
        }
    }

    public function bytes(): int
    {
        return intdiv($this->bits->integer(), 8);
    }
}
