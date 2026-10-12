<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** Ts — ISO 32000-1, 9.3.7. */
final readonly class SetTextRise extends Operator
{
    public const string SYMBOL = "Ts";

    public function __construct(public NumericType $rise)
    {
        parent::__construct($rise);
    }
}
