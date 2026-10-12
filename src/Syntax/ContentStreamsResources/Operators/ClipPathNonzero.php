<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** W — ISO 32000-1, 8.5.4. */
final readonly class ClipPathNonzero extends Operator
{
    public const string SYMBOL = "W";

    public function __construct()
    {
        parent::__construct();
    }
}
