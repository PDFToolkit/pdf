<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Tf — ISO 32000-1, 9.3.1. */
final readonly class SetTextFontAndSize extends Operator
{
    public const string SYMBOL = "Tf";

    public function __construct(public NameType $font, public NumericType $size)
    {
        parent::__construct($font, $size);
    }
}
