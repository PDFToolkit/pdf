<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** g — ISO 32000-1, 8.6.8. */
final readonly class SetNonstrokingGray extends Operator
{
    public const string SYMBOL = "g";

    public function __construct(public NumericType $gray)
    {
        parent::__construct($gray);
    }
}
