<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

final class RealType extends NumericType
{
    public function __construct(float $value)
    {
        parent::__construct($value);
    }
}
