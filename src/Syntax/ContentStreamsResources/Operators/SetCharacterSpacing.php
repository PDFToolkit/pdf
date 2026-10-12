<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** Tc — ISO 32000-1, 9.3.2. */
final readonly class SetCharacterSpacing extends Operator
{
    public const string SYMBOL = "Tc";

    public function __construct(public NumericType $spacing)
    {
        parent::__construct($spacing);
    }
}
