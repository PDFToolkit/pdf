<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Graphics\GraphicsState\LineCapStyle;
use PDF\Syntax\Object\NumericType;

/** J — ISO 32000-1, 8.4.4. */
final readonly class SetLineCapStyle extends Operator
{
    public const string SYMBOL = "J";

    public function __construct(public LineCapStyle $style)
    {
        parent::__construct(new NumericType($style->value));
    }
}
