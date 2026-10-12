<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

class IntegerType extends NumericType
{
    public function __construct(int $value)
    {
        parent::__construct($value);
    }
}
