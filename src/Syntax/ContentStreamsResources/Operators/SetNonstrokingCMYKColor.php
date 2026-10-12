<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** k — ISO 32000-1, 8.6.8. */
final readonly class SetNonstrokingCMYKColor extends Operator
{
    public const string SYMBOL = "k";

    public function __construct(public NumericType $cyan, public NumericType $magenta, public NumericType $yellow, public NumericType $black)
    {
        parent::__construct($cyan, $magenta, $yellow, $black);
    }
}
