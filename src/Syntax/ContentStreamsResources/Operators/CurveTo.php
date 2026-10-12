<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** c — ISO 32000-1, 8.5.2. */
final readonly class CurveTo extends Operator
{
    public const string SYMBOL = "c";

    public function __construct(public NumericType $x1, public NumericType $y1, public NumericType $x2, public NumericType $y2, public NumericType $x3, public NumericType $y3)
    {
        parent::__construct($x1, $y1, $x2, $y2, $x3, $y3);
    }
}
