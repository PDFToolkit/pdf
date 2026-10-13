<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\IntegerType;

/** An index in the shared-object hint table, not an indirect object number. */
final readonly class SharedObjectReference
{
    public function __construct(public IntegerType $identifier, public IntegerType $numerator = new IntegerType(0))
    {
        if ($identifier->integer() < 0 || $numerator->integer() < 0) {
            throw new \InvalidArgumentException('A shared hint reference and its numerator are unsigned.');
        }
    }
}
