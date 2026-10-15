<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\DictionaryType;

final readonly class CalibratedColour implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $white = (new DictionaryValues())->numbers($dictionary, 'WhitePoint');
        if ($white !== null && (count($white) !== 3 || $white[0] <= 0 || $white[1] != 1 || $white[2] <= 0)) {
            throw new \InvalidArgumentException('/WhitePoint requires positive X and Z with Y equal to one.');
        }
    }
}
