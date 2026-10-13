<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\StringObject;

use PDF\Syntax\Object\StringType;
use PDF\Serialization\SerializationContext;

final class HexadecimalString extends StringType
{
    public function __construct(string|HexadecimalDigits $value)
    {
        parent::__construct($value instanceof HexadecimalDigits ? $value->bytes() : $value);
    }

    public function toPdf(SerializationContext $context): string
    {
        return '<' . strtoupper(bin2hex($this->value)) . '>';
    }

}
