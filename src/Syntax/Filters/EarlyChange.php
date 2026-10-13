<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\IntegerType;

/** Table 8, physical PDF page 36: the PDF LZW code-size transition policy. */
final readonly class EarlyChange
{
    public function __construct(public IntegerType $value = new IntegerType(1))
    {
        if (!in_array($value->integer(), [0, 1], true)) {
            throw new \InvalidArgumentException('LZW EarlyChange is zero or one.');
        }
    }
}
