<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** G — ISO 32000-1, 8.6.8. */
final readonly class SetStrokingGray extends Operator
{
    public const string SYMBOL = "G";

    public function __construct(public NumericType $gray)
    {
        parent::__construct($gray);
    }
}
