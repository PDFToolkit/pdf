<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** BT — ISO 32000-1, 9.4.1. */
final readonly class BeginTextObject extends Operator
{
    public const string SYMBOL = "BT";

    public function __construct()
    {
        parent::__construct();
    }
}
