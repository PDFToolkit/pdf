<?php

declare(strict_types=1);

namespace PDF\Document\Measurements;

use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** A displayed value and its unit label, both explicit PDF objects. */
final readonly class MeasuredDistance
{
    public function __construct(public NumericType $value, public StringType $units)
    {
    }
}
