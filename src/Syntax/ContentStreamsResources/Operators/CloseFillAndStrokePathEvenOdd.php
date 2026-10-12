<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** b* — ISO 32000-1, 8.5.3. */
final readonly class CloseFillAndStrokePathEvenOdd extends Operator
{
    public const string SYMBOL = "b*";

    public function __construct()
    {
        parent::__construct();
    }
}
