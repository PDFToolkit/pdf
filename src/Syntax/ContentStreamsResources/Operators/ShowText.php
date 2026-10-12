<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\StringType;

/** Tj — ISO 32000-1, 9.4.3. */
final readonly class ShowText extends Operator
{
    public const string SYMBOL = "Tj";

    public function __construct(public StringType $text)
    {
        parent::__construct($text);
    }
}
