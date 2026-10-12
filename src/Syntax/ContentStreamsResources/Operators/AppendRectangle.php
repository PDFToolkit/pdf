<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** re — ISO 32000-1, 8.5.2. */
final readonly class AppendRectangle extends Operator
{
    public const string SYMBOL = "re";

    public function __construct(public NumericType $x, public NumericType $y, public NumericType $width, public NumericType $height)
    {
        parent::__construct($x, $y, $width, $height);
    }
}
