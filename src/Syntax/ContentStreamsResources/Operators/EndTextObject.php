<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** ET — ISO 32000-1, 9.4.1. */
final readonly class EndTextObject extends Operator
{
    public const string SYMBOL = "ET";

    public function __construct()
    {
        parent::__construct();
    }
}
