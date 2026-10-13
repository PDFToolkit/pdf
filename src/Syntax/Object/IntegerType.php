<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

class IntegerType extends NumericType
{
    public function __construct(int $value)
    {
        parent::__construct($value);
    }

    /** Return the integer leaf without widening it to the parent number's float union. */
    final public function integer(): int
    {
        if (!is_int($this->value)) {
            throw new \LogicException('An IntegerType must contain an integer.');
        }
        return $this->value;
    }
}
