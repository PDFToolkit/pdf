<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\Object\IntegerType;

/** Typed flag collection; field-specific constraints are checked after inheritance. */
abstract readonly class FieldFlags
{
    public IntegerType $value;

    protected function __construct(CommonFieldFlag|TextFieldFlag|ChoiceFieldFlag|ButtonFieldFlag ...$flags)
    {
        $bits = 0;
        foreach ($flags as $flag) {
            $bits |= $flag->value;
        }
        $this->value = new IntegerType($bits);
    }
}
