<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** T* — ISO 32000-1, 9.4.2. */
final readonly class MoveToNextTextLine extends Operator
{
    public const string SYMBOL = "T*";

    public function __construct()
    {
        parent::__construct();
    }
}
