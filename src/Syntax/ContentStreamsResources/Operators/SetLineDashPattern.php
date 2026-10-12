<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Graphics\GraphicsState\LineDashPattern;

/** d — ISO 32000-1, 8.4.4. */
final readonly class SetLineDashPattern extends Operator
{
    public const string SYMBOL = "d";

    public function __construct(public LineDashPattern $pattern)
    {
        parent::__construct($pattern->dashArray, $pattern->dashPhase);
    }
}
