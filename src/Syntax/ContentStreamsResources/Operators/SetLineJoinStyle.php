<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Graphics\GraphicsState\LineJoinStyle;
use PDF\Syntax\Object\NumericType;

/** j — ISO 32000-1, 8.4.4. */
final readonly class SetLineJoinStyle extends Operator
{
    public const string SYMBOL = "j";

    public function __construct(public LineJoinStyle $style)
    {
        parent::__construct(new NumericType($style->value));
    }
}
