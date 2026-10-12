<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** m — ISO 32000-1, 8.5.2. */
final readonly class MoveTo extends Operator
{
    public const string SYMBOL = "m";

    public function __construct(public NumericType $x, public NumericType $y)
    {
        parent::__construct($x, $y);
    }
}
