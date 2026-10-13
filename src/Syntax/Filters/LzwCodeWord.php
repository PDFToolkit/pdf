<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\IntegerType;

/** A code word has a real width of 9–12 bits, independent of byte boundaries. */
final readonly class LzwCodeWord
{
    public function __construct(public IntegerType $code, public IntegerType $bits)
    {
        if ($bits->integer() < 9 || $bits->integer() > 12 || $code->integer() < 0 || $code->integer() >= 1 << $bits->integer()) {
            throw new \InvalidArgumentException('A PDF LZW word must fit its width of 9–12 bits.');
        }
    }
}
