<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** l — ISO 32000-1, 8.5.2. */
final readonly class LineTo extends Operator
{
    public const string SYMBOL = "l";

    public function __construct(public NumericType $x, public NumericType $y)
    {
        parent::__construct($x, $y);
    }
}
