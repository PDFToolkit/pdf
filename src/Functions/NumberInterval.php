<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Syntax\Object\NumericType;

/** Closed numerical interval used by a function domain or range. */
final readonly class NumberInterval
{
    public function __construct(public NumericType $minimum, public NumericType $maximum)
    {
        if ($minimum->value > $maximum->value) {
            throw new \InvalidArgumentException('An interval minimum cannot exceed its maximum.');
        }
    }
}
