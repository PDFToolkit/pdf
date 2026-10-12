<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** v — ISO 32000-1, 8.5.2. */
final readonly class CurveToWithInitialPoint extends Operator
{
    public const string SYMBOL = "v";

    public function __construct(public NumericType $x2, public NumericType $y2, public NumericType $x3, public NumericType $y3)
    {
        parent::__construct($x2, $y2, $x3, $y3);
    }
}
