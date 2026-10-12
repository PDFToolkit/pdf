<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** d1 — ISO 32000-1, 9.6.4. */
final readonly class SetGlyphWidthAndBoundingBox extends Operator
{
    public const string SYMBOL = "d1";

    public function __construct(public NumericType $wx, public NumericType $wy, public NumericType $llx, public NumericType $lly, public NumericType $urx, public NumericType $ury)
    {
        if ($wy->value != 0) {
            throw new \InvalidArgumentException('The vertical displacement of a Type 3 glyph must be zero.');
        }
        if ($urx->value < $llx->value || $ury->value < $lly->value) {
            throw new \InvalidArgumentException('The glyph bounding box must have ordered corners.');
        }
        parent::__construct($wx, $wy, $llx, $lly, $urx, $ury);
    }
}
