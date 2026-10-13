<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Functions\NumberArray;
use PDF\Syntax\Object\IntegerType;

/** Reuses four previous controls and two colours according to tables 85–86, pages 206/209. */
final readonly class PatchContinuation
{
    public function __construct(public IntegerType $edge, public PatchControlPoints $newControls, public NumberArray $thirdColour, public NumberArray $fourthColour)
    {
        if (!in_array($edge->integer(), [1, 2, 3], true)) {
            throw new \InvalidArgumentException('A patch continuation uses edge flag one, two or three.');
        }
    }
}
