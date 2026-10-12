<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

use PDF\Syntax\Object\NumericType;

final readonly class Number implements Instruction
{
    public function __construct(public NumericType $value)
    {
    }

    public function toPostScript(): string
    {
        return (string) $this->value;
    }
}
