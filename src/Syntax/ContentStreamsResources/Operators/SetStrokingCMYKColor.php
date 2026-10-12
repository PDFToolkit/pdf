<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** K — ISO 32000-1, 8.6.8. */
final readonly class SetStrokingCMYKColor extends Operator
{
    public const string SYMBOL = "K";

    public function __construct(public NumericType $cyan, public NumericType $magenta, public NumericType $yellow, public NumericType $black)
    {
        parent::__construct($cyan, $magenta, $yellow, $black);
    }
}
