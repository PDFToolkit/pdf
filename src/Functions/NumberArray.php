<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** A PDF array that accepts only number objects. */
final class NumberArray extends ArrayType
{
    /** @var list<NumericType> */
    public readonly array $numbers;

    public function __construct(NumericType ...$numbers)
    {
        $this->numbers = array_values($numbers);
        parent::__construct(...$numbers);
    }
}
