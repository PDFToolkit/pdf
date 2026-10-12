<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** S — ISO 32000-1, 8.5.3. */
final readonly class StrokePath extends Operator
{
    public const string SYMBOL = "S";

    public function __construct()
    {
        parent::__construct();
    }
}
