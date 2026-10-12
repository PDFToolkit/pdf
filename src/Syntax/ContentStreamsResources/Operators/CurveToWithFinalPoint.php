<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** y — ISO 32000-1, 8.5.2. */
final readonly class CurveToWithFinalPoint extends Operator
{
    public const string SYMBOL = "y";

    public function __construct(public NumericType $x1, public NumericType $y1, public NumericType $x3, public NumericType $y3)
    {
        parent::__construct($x1, $y1, $x3, $y3);
    }
}
