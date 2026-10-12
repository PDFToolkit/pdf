<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** rg — ISO 32000-1, 8.6.8. */
final readonly class SetNonstrokingRGBColor extends Operator
{
    public const string SYMBOL = "rg";

    public function __construct(public NumericType $red, public NumericType $green, public NumericType $blue)
    {
        parent::__construct($red, $green, $blue);
    }
}
