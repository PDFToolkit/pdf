<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** Tz — ISO 32000-1, 9.3.4. */
final readonly class SetHorizontalTextScaling extends Operator
{
    public const string SYMBOL = "Tz";

    public function __construct(public NumericType $scale)
    {
        parent::__construct($scale);
    }
}
