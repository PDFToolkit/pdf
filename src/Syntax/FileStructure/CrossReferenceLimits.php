<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IntegerType;

/** Explicit capacity for the cumulative cross-reference index, including object zero. */
final readonly class CrossReferenceLimits
{
    public function __construct(public IntegerType $maximumEntries = new IntegerType(100000))
    {
        if ($maximumEntries->value < 1) {
            throw new \InvalidArgumentException('The cross-reference entry limit must be positive.');
        }
    }
}
