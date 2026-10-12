<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Text\TextRenderingMode;
use PDF\Syntax\Object\NumericType;

/** Tr — ISO 32000-1, 9.3.6. */
final readonly class SetTextRenderingMode extends Operator
{
    public const string SYMBOL = "Tr";

    public function __construct(public TextRenderingMode $mode)
    {
        parent::__construct(new NumericType($mode->value));
    }
}
