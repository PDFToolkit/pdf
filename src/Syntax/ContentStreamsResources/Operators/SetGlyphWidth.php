<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** d0 — ISO 32000-1, 9.6.4. */
final readonly class SetGlyphWidth extends Operator
{
    public const string SYMBOL = "d0";

    public function __construct(public NumericType $wx, public NumericType $wy)
    {
        if ($wy->value != 0) {
            throw new \InvalidArgumentException('The vertical displacement of a Type 3 glyph must be zero.');
        }
        parent::__construct($wx, $wy);
    }
}
