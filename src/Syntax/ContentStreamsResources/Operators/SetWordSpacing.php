<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** Tw — ISO 32000-1, 9.3.3. */
final readonly class SetWordSpacing extends Operator
{
    public const string SYMBOL = "Tw";

    public function __construct(public NumericType $spacing)
    {
        parent::__construct($spacing);
    }
}
