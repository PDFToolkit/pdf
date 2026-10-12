<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** TL — ISO 32000-1, 9.3.5. */
final readonly class SetTextLeading extends Operator
{
    public const string SYMBOL = "TL";

    public function __construct(public NumericType $leading)
    {
        parent::__construct($leading);
    }
}
