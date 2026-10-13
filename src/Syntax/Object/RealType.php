<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Serialization\SerializationContext;

/** 7.3.3: explicitly real syntax, including whole-valued reals such as the seed parser version3.0. */
final class RealType extends NumericType
{
    public function toPdf(SerializationContext $context): string
    {
        $number = parent::toPdf($context);
        return str_contains($number, '.') ? $number : $number . '.0';
    }
}
