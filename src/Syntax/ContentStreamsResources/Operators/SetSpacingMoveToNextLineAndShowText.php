<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** " — ISO 32000-1, 9.4.3. */
final readonly class SetSpacingMoveToNextLineAndShowText extends Operator
{
    public const string SYMBOL = "\"";

    public function __construct(public NumericType $wordSpacing, public NumericType $characterSpacing, public StringType $text)
    {
        parent::__construct($wordSpacing, $characterSpacing, $text);
    }
}
