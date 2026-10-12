<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** Td — ISO 32000-1, 9.4.2. */
final readonly class MoveTextPosition extends Operator
{
    public const string SYMBOL = "Td";

    public function __construct(public NumericType $tx, public NumericType $ty)
    {
        parent::__construct($tx, $ty);
    }
}
