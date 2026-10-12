<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** B — ISO 32000-1, 8.5.3. */
final readonly class FillAndStrokePathNonzero extends Operator
{
    public const string SYMBOL = "B";

    public function __construct()
    {
        parent::__construct();
    }
}
