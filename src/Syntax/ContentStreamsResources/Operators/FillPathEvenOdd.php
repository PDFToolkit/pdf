<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** f* — ISO 32000-1, 8.5.3. */
final readonly class FillPathEvenOdd extends Operator
{
    public const string SYMBOL = "f*";

    public function __construct()
    {
        parent::__construct();
    }
}
